# 依赖

[English](../DEPENDENCIES.md)

所有版本都由锁文件固定（`server/composer.lock`、`web/package-lock.json`）。运行时不会下载任何东西。

## 运行时（服务端，Composer）

| 包 | 版本 | 用途 |
|---|---|---|
| `spomky-labs/otphp` | 11.5.0 | 管理员 TOTP（RFC 6238）。需求文档明确允许使用 |
| `paragonie/constant_time_encoding` | 3.1.3 | `otphp` 的间接依赖（常量时间的 base32） |
| `symfony/deprecation-contracts`、`psr/clock` | 见锁文件 | `otphp` 的间接依赖 |

PHP 扩展：`sodium`（用 secretbox 加密保存 TOTP 密钥）、`pdo_sqlite`（或 `pdo_mysql`）、`openssl`。推荐 `redis` 扩展；如果没有，
会使用一个约 100 行的、基于 stream socket 的内置 RESP 客户端。不使用任何框架：路由、请求/响应处理、数据库封装和迁移都在 `server/src/Support` 里。

## 运行时（浏览器，已打包进各页面的脚本）

| 包 | 版本 | 用途 | 完整性 |
|---|---|---|---|
| `argon2-browser` | 1.18.0 | Argon2id（C 语言参考实现编译成 WASM）。WebCrypto 没有 Argon2 | `dist/argon2.wasm` 的 SHA-256 为 `0c2149886c13e4eae4a6ca25ee71d47423c5c8740a874cf04ff816d1b2c901d7`；嵌入在每个页面的 bundle 中，所以被该 bundle 的 SRI 哈希覆盖；也列在 `build-manifest.json` 里 |
| `zxcvbn` | 4.4.2 | 检查用户自定义取件码的强度（需求要求）。从单独的、带哈希的文件懒加载，只有使用自定义取件码输入框时才加载 | `dist/zxcvbn.js` 的 SHA-256 为 `f42c651f40506acb6b662490f338dd47a5951d3312039c4ab8fe5090484f351a` |
| EFF Large Wordlist | 2016-07-18 | 取件码用的词表 | 文件 SHA-256 为 `addd35536511597a02fa0a9ff1e5284677b8883b83e986e43f15a3db996b903e`（保存在 `web/vendor-lock/`） |

其余全部使用浏览器提供的 WebCrypto（AES-GCM、HKDF、ECDH、SHA）。

## 仅用于构建和测试（不发布，生产环境不运行）

| 包 | 版本 | 用途 |
|---|---|---|
| `esbuild` | 0.25.10 | 打包 ES 模块、计算哈希、在 `web/build.mjs` 里注入 SRI |
| `phpunit/phpunit` | 11.5.x | 服务端测试（`require-dev`） |
| `squizlabs/php_codesniffer` | 3.x | PSR-12 检查，`vendor/bin/phpcs`（`require-dev`） |
| `playwright-core` | 1.57.0 | 只被 `scripts/e2e/e2e.mjs` 使用；不是本仓库的依赖（需要和 Chromium 可执行文件一起另外安装） |

Node 只在构建时使用；生产系统里没有 Node 进程。
