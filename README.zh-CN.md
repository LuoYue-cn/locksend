# LockSend

[English](README.md)

**在线使用：<https://locksend.h666h.com>**（作者自己部署的实例，单个文件最大 500 MB，最长保留 30 天）。
它由一个人运行，没有经过独立的安全审计；涉及敏感内容时，请自己部署一份（见 [docs/zh-CN/DEPLOY.md](docs/zh-CN/DEPLOY.md)）。
文件虽然在你的浏览器里加密，但你仍然需要信任提供页面的那个人（见 [docs/zh-CN/SECURITY.md](docs/zh-CN/SECURITY.md) 里的“设计上就不能防御什么”）。

匿名文件分享，在浏览器里做端到端加密（默认单个文件最大 500 MB）。服务器只保存密文。下载需要同时具备两样东西：
链接（`#` 后面那一段是一个 256 位密钥，永远不会发给服务器）和系统生成的取件码（4 个或 6 个单词）。取件码连续输错太多次，
分享会被销毁；默认情况下，分享在一次确认下载完成后也会被销毁。

这不是“绝对安全”的产品。请先阅读 [docs/zh-CN/SECURITY.md](docs/zh-CN/SECURITY.md)，了解威胁模型、哪些情况不能防御，以及哪些内容测过、哪些没测过。

* 服务端：PHP（不用框架）、Nginx、SQLite（WAL）、纯内存的 Redis。前端：原生 ES 模块，用 esbuild 构建，每个页面一个带内容哈希的 bundle，并带 SRI。
* 密码学：WebCrypto（AES-256-GCM、HKDF-SHA256、ECDH P-256）和 Argon2id（WASM，64 MiB，t=3）。见 [docs/zh-CN/PROTOCOL.md](docs/zh-CN/PROTOCOL.md)（含测试向量）。
* 首次运行：打开 `/install`（需要用 `server/bin/admin-setup-token.php` 生成的一次性令牌）。安装完成后这个页面会自动封闭。

```
docs/     SECURITY PROTOCOL DEPLOY PRIVACY ABUSE QUESTIONS DEPENDENCIES（中文版在 docs/zh-CN/）
server/   public/（入口 index.php + 构建好的静态文件）  src/  migrations/  bin/  tests/  templates/（构建好的安装页）
web/      src/（crypto、upload、download、admin、install、sw）  tests/  build.mjs
deploy/   nginx  php-fpm  redis  relay  wireguard  systemd
scripts/  audit_privacy.sh  e2e/  package.sh  check-nginx-tls.sh
```

## 功能

* 匿名上传，没有账号体系。文件名、大小、类型和内容都在浏览器里加密，服务器看不到。
* 分块加密（每块 4 MiB）、断点续传、流式下载。
* 取件码连续输错到上限（可选 3/5/10 次，默认 5）后销毁分享，或者冻结 24 小时。
* 下载完成后自动销毁（可关闭）。有效期默认 7 天，最长 30 天。
* 管理员只能看到文件名（在浏览器里解密）和删除，看不到内容、大小、访客 IP 和下载情况。管理员登录需要密码加 TOTP 动态码。
* 页面不加载任何第三方资源；服务器不记录访客 IP。

## 快速开始（开发）

```sh
cd web && npm ci && npm run build && npm test        # JS 密码学测试（固定测试向量）
cd ../server && composer install && vendor/bin/phpunit && vendor/bin/phpcs
bash ../scripts/check-nginx-tls.sh                    # nginx -t，并对 deploy/nginx 做只接受 TLS 1.3 的握手检查
PW_PATH=/path/to/playwright-core CHROME=/path/to/chromium bash ../scripts/e2e/run.sh   # 临时的 nginx+php+redis，完整的浏览器测试
bash ../scripts/audit_privacy.sh
```

部署见 [docs/zh-CN/DEPLOY.md](docs/zh-CN/DEPLOY.md)，其中包含用宝塔面板部署的附录。

构建好的前端也提交在仓库里（`server/public/`、`server/templates/`），所以服务器上不需要 Node；改了前端代码后用
`cd web && npm ci && npm run build` 重新构建。用 `scripts/package.sh` 可以打出一个只含运行文件、可以直接上传的服务端目录。

## 文档

| 文档 | 内容 |
|---|---|
| [PROTOCOL](docs/zh-CN/PROTOCOL.md) | 加密协议、接口、测试向量 |
| [SECURITY](docs/zh-CN/SECURITY.md) | 威胁模型、已知局限、测试情况 |
| [DEPLOY](docs/zh-CN/DEPLOY.md) | 部署步骤、配置项，以及宝塔面板附录 |
| [PRIVACY](docs/zh-CN/PRIVACY.md) | 保存什么、保存多久、不保存什么 |
| [ABUSE](docs/zh-CN/ABUSE.md) | 滥用处理与内置限制 |
| [QUESTIONS](docs/zh-CN/QUESTIONS.md) | 需求不明确处所做的决定 |
| [DEPENDENCIES](docs/zh-CN/DEPENDENCIES.md) | 依赖及其用途 |

## 状态

由一个人在自动化工具的协助下编写和测试；**没有经过独立的安全评审。**请先阅读
[docs/zh-CN/SECURITY.md](docs/zh-CN/SECURITY.md)：里面列出了哪些被保护、哪些没有，以及具体测过什么、没测过什么。
发现安全问题请私下报告（GitHub 的“Report a vulnerability”），不要发在公开的 issue 里。

## 许可证

[MIT](LICENSE)
