# LockSend 协议（版本 1）

[English](../PROTOCOL.md)

所有密码学运算都在浏览器里完成，使用 WebCrypto 加上一个固定版本的 Argon2id WASM 构建，没有自创算法。
参考实现在 `web/src/crypto/`；固定测试向量在 `web/tests/vectors.json`，并由 `server/tests/VectorsTest.php`
用 PHP 里独立的实现（libsodium、`hash_hkdf`、OpenSSL）重新计算一遍作为交叉验证。

> 本文中的公式、字段名和测试向量与英文版逐字一致；如有出入，以英文版和代码为准。

## 1. 常量

| 名称 | 取值 |
|---|---|
| `CHUNK`（明文分块） | 4 194 304 字节（4 MiB） |
| `TAG` | 16 字节（AES-GCM） |
| `CCHUNK`（密文分块） | `CHUNK + 16` = 4 194 320。除最后一块外，每一块都恰好是这个大小 |
| 空文件 | `chunk_count = 1`，这一块是空明文的 16 字节认证标签 |
| Argon2id，`kdf_v = 1` | `m = 65536 KiB（64 MiB）`、`t = 3`、`p = 1`，输出 32 字节。参数写死在服务端，由 `GET /api/config` 和 `GET /api/shares/{id}/challenge` 下发。客户端不能自选，并且会**拒绝**比这更弱的参数 |
| `share_id` | `base64url(random_bytes(16))`，22 个字符，由服务器签发 |
| `salt` | 16 字节随机数，公开，由服务器保存 |
| `K_link` | 32 字节随机数，只存在于 URL 片段（`#…`，base64url，43 个字符）中 |

## 2. 取件码

* 使用 EFF Large Wordlist（7776 个词；源文件 SHA-256 为 `addd35536511597a02fa0a9ff1e5284677b8883b83e986e43f15a3db996b903e`，
  保存在 `web/vendor-lock/`），并生成为 `web/src/crypto/wordlist-eff.js`。
* 默认 4 个词，高安全选项 6 个词，用 `-` 连接（`river-amber-lunar-fox`）。
* 选词使用 `crypto.getRandomValues`，并对 32 位随机数做**拒绝采样**
  （`limit = floor(2^32 / n) * n`；大于等于 `limit` 的随机数直接丢弃），不存在取模偏差。
* 使用前先归一化：`trim → NFKC → 转小写 → 连续的空白或下划线换成 "-"`。
* 自定义取件码（高级选项）归一化后至少 12 个字符，并且 zxcvbn 4.4.2 评分不低于 4
  （zxcvbn 作为单独的、带 SRI 的文件按需加载）。
* 取件码绝不放进 URL、Cookie、`localStorage`、请求或日志。

熵：4 个词约 51.7 位，6 个词约 77.5 位。单靠取件码**不能**抵御离线攻击，真正起保护作用的还有 `K_link`，见下文。

## 3. 密钥派生

```
share_id_bytes = base64url_decode(share_id)                      # 16 bytes
M       = Argon2id(normalize(code), salt, kdf_v1)                # 32 bytes
ikm     = M || K_link                                            # 64 bytes
auth    = HKDF-SHA256(ikm, salt = share_id_bytes, info = "share-auth-v1",   L = 32)   # sent to server
K_file  = HKDF-SHA256(ikm, salt = share_id_bytes, info = "file-master-v1",  L = 32)
K_data  = HKDF-SHA256(K_file, salt = "" (empty),  info = "data-v1",          L = 32)
K_meta  = HKDF-SHA256(K_file, salt = "" (empty),  info = "meta-v1",          L = 32)
```

服务器保存 `auth_verifier = hex(HMAC-SHA256(server_pepper, auth))`，比较时使用 `hash_equals`。
`server_pepper`（至少 32 字节）来自环境变量 `LOCKSEND_PEPPER`；未设置时来自 `data/secrets/` 下权限为 `0600` 的文件——
绝不放进数据库。由于 `auth` 里混入了 `K_link`，只拿到数据库无法离线验证取件码的猜测。
`K_link`、取件码、`M`、`K_data`、`K_meta` 都不会离开浏览器。

## 4. 分块加密

```
iv_i   = 0x00000000 || uint64_be(i)                       # 12 bytes
aad_i  = "v1" || share_id_bytes(16) || uint32_be(i) || uint8(is_last)
C_i    = AES-256-GCM(K_data, iv_i, P_i, aad_i)            # ciphertext || 16-byte tag
```

下载端必须做到：核对 `chunk_count` 与解密出的元数据一致；核对每一块的密文长度
（非最后一块为 `CCHUNK`，最后一块为 `明文长度 + 16`）；只有最后一块用 `is_last = 1` 解密；
遇到第一个认证失败就中止；并核对明文总长度等于 `meta.size`。
调换顺序、截断、重复，或把一个分享的分块放到另一个分享里，都会改变 `iv`/`aad`，从而认证失败。

## 5. 元数据

```
meta     = JSON { name, size, mime, chunk_size, chunk_count, v: 1 }
enc_meta = iv(12 random) || AES-256-GCM(K_meta, iv, meta, aad = "meta-v1" || share_id_bytes)
```

`chunk_size` 必须等于 4 194 304，`chunk_count` 必须等于 `max(1, ceil(size / CHUNK))`，否则客户端拒绝。
`enc_meta` 只在 `unlock` 成功之后才由服务器返回。

## 6. 管理员可见的文件名（ECIES，P-256）

```
(e, E)      = fresh ECDH P-256 key pair per upload
secret      = ECDH(e, admin_pub)                                  # 32 bytes
key         = HKDF-SHA256(secret, salt = E (65 bytes), info = "name-seal-v1", L = 32)
sealed_name = E(65, uncompressed) || iv(12) || AES-256-GCM(key, iv, name_utf8, aad = "name-seal-v1")
```

管理员密钥对在**管理员的浏览器里、安装时**生成。私钥（PKCS#8）只以
`iv || AES-256-GCM(wrapKey, pkcs8, aad = "admin-wrap-v1")` 的形式保存。

## 7. 管理员密码

```
M2          = Argon2id(NFKC(password), wrap_salt, kdf_v1)
login_secret = HKDF-SHA256(M2, salt = "", info = "admin-login-v1", L = 32)   # sent to server (verified as HMAC(pepper, ·))
wrapKey      = HKDF-SHA256(M2, salt = "", info = "admin-wrap-v1",  L = 32)   # never leaves the browser
```

登录还需要一个 TOTP 动态码。两个 HKDF 输出互相独立，所以登录用的秘密对恢复 `wrapKey` 没有帮助。
服务器无法解密文件名；管理员忘记密码后，已有的密封文件名就再也读不出来了。

## 8. HTTP 接口（摘要）

除特别说明外，请求和响应都是 JSON；错误格式为 `{"error": "<code>"}`。Bearer 令牌是 32 字节随机数（base64url），
在 Redis 里只保存它的 SHA-256。

| 方法和路径 | 作用 |
|---|---|
| `GET /api/config` | KDF 参数、有效期限制、`max_file_bytes`、可选项、`admin_pub` |
| `POST /api/shares/reserve` | 签发 `share_id` 和上传令牌（有频率限制） |
| `POST /api/shares/{id}` | 登记分享（当 `chunk_count` 超过 `ceil(max_file_bytes / CHUNK)`（默认 120）时返回 `413 file_too_large`）：`kdf_v, salt, auth, enc_meta, sealed_name, chunk_count, max_fails∈{3,5,10}, fail_action∈{destroy,freeze_24h}, ttl_days (1…max), burn_after_complete, max_completions` |
| `PUT /api/shares/{id}/chunks/{n}` | 上传一个密文分块（幂等，并检查大小） |
| `GET /api/shares/{id}/upload-status` | 已收到的分块范围，用于断点续传 |
| `POST /api/shares/{id}/finalize` | 确认所有分块都已到齐，状态置为 `ready` |
| `DELETE /api/shares/{id}` | 中止一个未完成的上传 |
| `GET /api/shares/{id}/challenge` | `{salt, kdf}`——对于不存在、已关闭、已过期的分享，返回完全相同的 `410` |
| `POST /api/shares/{id}/unlock` | `{auth}` → `{download_token, enc_meta, chunk_count, expires_at}`，或 `401 bad_code` / `410` / `423` |
| `GET /api/shares/{id}/chunks/{n}` | 密文分块（需要下载令牌）；配置了 `X-Accel-Redirect` 时由 nginx 直接发送文件 |
| `POST /api/shares/{id}/complete` | 客户端确认下载完成；服务器核对所有分块都已发出，再按配置销毁分享 |
| `GET /api/admin/salt`、`POST /api/admin/login`、`GET /api/admin/shares`、`DELETE /api/admin/shares/{id}`、`POST /api/admin/logout` | 管理员 |
| `GET /api/install/status`、`POST /api/install/begin`、`POST /api/install/finish` | 首次安装（安装完成后一律返回 404） |

### 取件码错误的处理策略

`unlock` 在一个写事务（`BEGIN IMMEDIATE`）里执行，所以并发的猜测会被串行化，永远不会超过 `max_fails`。
第 *n* 次连续失败时：`destroy` 会删除密文，并清空 `enc_meta`、`sealed_name`、`salt`、`auth_verifier`（状态变为 `destroyed`）；
`freeze_24h` 在 24 小时内拒绝所有尝试（包括正确的取件码），之后计数清零。输入正确的取件码会让失败计数清零。

## 9. 测试向量

输入：`share_id = AQIDBAUGBwgJCgsMDQ4PEA`（字节为 `01..10`），`K_link = a0a1…bf`，`salt = 3031…3f`，
取件码输入为 `River Amber_lunar-fox`（归一化后为 `river-amber-lunar-fox`），`kdf_v1`。

```
auth    = 91b15d2de2935fc787d37a96239aecb15e26faaa384d7cedde4050a3f4105948
K_data  = a3db3cec955cc417748a079547f2e6c048aecd1b91f912ca33b45db2def10dea
K_meta  = 8d12e1a7205c495adf55b9bdde86c088dad91752372eb3a596ed7b0f8d609195

chunk 0 (not last)  plain  68656c6c6f2c206c6f636b73656e64  ("hello, locksend")
  iv     000000000000000000000000
  aad    76310102030405060708090a0b0c0d0e0f100000000000
  cipher fafa6b40504c102ac77bc7d2e8765de730abd97639d6fb892a753171295e35   (plaintext || tag, 31 bytes)

chunk 1 (last)      plain  7461696c  ("tail")
  iv     000000000000000000000001
  aad    76310102030405060708090a0b0c0d0e0f100000000101
  cipher ff2d492bc8aced781010fffbdcbf6c9e095779d6

empty file (index 0, last)
  iv     000000000000000000000000
  aad    76310102030405060708090a0b0c0d0e0f100000000001
  cipher 1482ae3f630e8172f2076eab4351b6ed

iv for chunk index 2^32 + 2 = 000000000000000100000002

meta_json = {"name":"report.pdf","size":15,"mime":"application/pdf","chunk_size":4194304,"chunk_count":1,"v":1}
meta_iv   = 707172737475767778797a7b
enc_meta  = 707172737475767778797a7b4fd0eeedd5bd0f1c392ae2d942388bb9c7dcaa4b4f1732014682312b3164cbf33902ee31545dfc3dbe52b0190097b46e7d95435fc35db488ac7ce2cdd418c7a71aa3fe8736c72dc4857e80c7e8efa0faec23e89252e918a5a83529db0f0ee40c5437d57c43d5e9268596f64f62b723d241f4bd

HKDF-SHA256 RFC 5869 test case 1 (sanity check of the HKDF primitive)
  okm = 3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865
```

用 `node web/tests/gen-vectors.mjs` 重新生成；用 `npm test`（web）和 `vendor/bin/phpunit`（server）验证。
