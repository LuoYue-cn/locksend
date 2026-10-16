# 部署

[English](../DEPLOY.md)

目标环境：Debian/Ubuntu、nginx、PHP 8.3-FPM（`sodium`、`pdo_sqlite`，推荐 `redis`）、Redis，可选的中继 + WireGuard。
“本地测试”一节里的命令，以及 nginx/PHP-FPM/Redis 的配置文件，在开发过程中实际执行过；Docker、中继、WireGuard 和 systemd 文件没有执行过。
请在你自己的环境里审阅并测试所有内容（见 [SECURITY.md](SECURITY.md) 的“验证情况”）。

## 1. 构建

```sh
cd web    && npm ci && npm run build      # 生成 server/public/*.html、assets/、sw.js、build-manifest.json、server/templates/install.html
cd ../server && composer install --no-dev --optimize-autoloader
```

保留 `server/public/build-manifest.json`（每个发布文件的 SHA-256）。公布它，并让别人从源码重新构建后对比，是应对前端被篡改的主要缓解手段。

仓库里已经提交了构建好的前端，所以不装 Node 也能部署；只有改了 `web/` 下的代码才需要重新构建。
要得到一个只含运行文件的上传包，运行 `bash scripts/package.sh`（需要本机有 PHP 和 Composer），输出到 `dist/locksend-server/`。

## 2. 服务器上的目录布局

```
/var/www/locksend/server/{public,src,bin,migrations,templates,vendor}   # 代码，对 www-data 只读
/var/lib/locksend/                                                      # 数据：sqlite、blobs/、tmp/、secrets/ —— 仅 www-data 可访问，权限 0750，放在网站根目录之外
/etc/locksend/env                                                       # 可选，给清理定时器用的 EnvironmentFile
```

```sh
install -d -o www-data -g www-data -m 0750 /var/lib/locksend
```

nginx 的工作进程必须能读取 `/var/lib/locksend/blobs`（通过 `X-Accel-Redirect` 发送）；默认的 `user www-data;` 满足这个条件。
`/var/lib/locksend` 请放在加密的文件系统上。

## 3. 密钥

只生成一次 pepper，并且不要放进数据库和代码仓库：

```sh
echo "base64:$(openssl rand -base64 32)"
```

把它写进 `deploy/php-fpm/locksend.conf`，格式为 `env[LOCKSEND_PEPPER] = "base64:…"`（**必须保留引号**：不加引号的 `=` 填充符会让 PHP-FPM 拒绝启动；
文件权限 `0640 root:www-data`），同时写进给清理定时器用的 `/etc/locksend/env`。如果不设置，安装程序会兜底生成 `data/secrets/pepper`（安全性较弱，见 SECURITY.md）。
请单独备份 pepper；没有它，所有分享和管理员登录都无法使用。

## 4. 各项服务

| 文件 | 安装到 |
|---|---|
| `deploy/php-fpm/locksend.conf` | `/etc/php/8.3/fpm/pool.d/`（停用默认的 `www` 进程池） |
| `deploy/redis/redis.conf` | `/etc/locksend/redis.conf`；配合 `deploy/systemd/locksend-redis.service`（或者让系统自带的 Redis 使用同样的设置）。必须保持 `save ""` 和 `appendonly no` |
| `deploy/nginx/locksend.conf` | 在 `http {}` 里 include；同时把 `locksend-locations.conf`、`locksend-headers.conf`、`locksend-fastcgi.conf` 复制到 `/etc/nginx/` |
| `deploy/systemd/locksend-cleanup.{service,timer}` | 放到 `/etc/systemd/system/`；执行 `systemctl enable --now locksend-cleanup.timer` |

TLS：申请证书（推荐 DNS-01 验证，这样源站不需要对外开放 80 端口），并在 `locksend.conf` 里设置 `ssl_certificate*`。它**只接受 TLS 1.3**。
`access_log off` 是有意为之，请不要重新打开。

## 5. 首次安装

1. 启动所有服务，然后在服务器上执行：`sudo -u www-data php /var/www/locksend/server/bin/admin-setup-token.php`——会打印一个一次性令牌。
2. 打开 `https://<域名>/install`。页面会做环境检查（sodium、数据库驱动、数据目录、Redis、密钥）。
3. 输入令牌，设置管理员密码（至少 12 个字符，在浏览器里处理），扫描 TOTP 密钥，输入一个动态码。
4. 成功后服务器写入 `data/installed.lock`、删除令牌，此后 `/install` 和所有安装接口都返回 404。
   如果想有意重新安装，必须在服务器上自己删除 `installed.lock` 和数据库。

## 6. 中继 / 隐藏源站（可选，但推荐）

见 `deploy/relay/README.md`、`deploy/relay/nginx-stream.conf`、`deploy/wireguard/`。简单说：DNS 只指向中继；中继通过 WireGuard 把 TCP 80/443 原样转发到源站；
源站的防火墙（`deploy/wireguard/origin-firewall.nft`）只接受隧道流量。
部署后运行 `scripts/audit_privacy.sh`，并用外部扫描确认源站不会对公网应答。

## 7. Docker（参考）

`docker-compose.yml` 会构建 PHP-FPM 镜像（`server/Dockerfile`），并运行 nginx（127.0.0.1:8080 上的明文 HTTP）、PHP 和纯内存的 Redis。
请在它前面放置你自己的 TLS 1.3 终结层。**开发过程中没有执行过**（构建环境里没有 Docker），请当作起点使用。必须设置 `LOCKSEND_PEPPER`。

## 8. 切换到 MariaDB

设置 `LOCKSEND_DB_DSN=mysql:host=…;dbname=locksend;charset=utf8mb4`、`LOCKSEND_DB_USER`、`LOCKSEND_DB_PASS`，并安装 `pdo_mysql`。
表结构和查询都是可移植的（二进制值以 base64url 文本保存；`Db::forUpdate()` 会加上 `FOR UPDATE`）。
整套 PHPUnit 测试（包括并行进程的竞争测试）在 MariaDB 11.4.5 上通过；第 10 节说明了如何在你自己的服务器上运行它。
浏览器端到端测试只在 SQLite 上跑过。

## 9. 配置参考（环境变量）

| 变量 | 默认值 | 含义 |
|---|---|---|
| `LOCKSEND_DATA_DIR` | `server/data` | sqlite、blobs、tmp、secrets、安装锁 |
| `LOCKSEND_PEPPER` | – | 至少 32 字节；`base64:…`、十六进制或原始字符串 |
| `LOCKSEND_REDIS` | `tcp://127.0.0.1:6379` | 也接受 `unix:///路径` |
| `LOCKSEND_ACCEL_PREFIX` | 空 | 设为 `/_blobs` 让 nginx 直接发送分块；为空则由 PHP 发送 |
| `LOCKSEND_DB_DSN/USER/PASS` | 数据目录里的 sqlite 文件 | |
| `LOCKSEND_TTL_MAX_DAYS`、`LOCKSEND_TTL_DEFAULT_DAYS` | 30、7 | 分享有效期的上限和默认值 |
| `LOCKSEND_CREATE_PER_HOUR` | 20 | 每个客户端桶每小时的新上传次数 |
| `LOCKSEND_MIN_FREE_BYTES` | 512 MiB | 剩余空间低于此值时拒绝上传 |
| `LOCKSEND_MAX_FILE_BYTES` | 500000000（500 MB） | 接受的最大文件；实现为分块数上限 |
| `LOCKSEND_TRUST_REAL_IP` | 0 | 只用于限速分桶，绝不记录 |

## 10. 本地测试（实际运行过的）

```sh
# 需要 php 8.3（sodium、pdo_sqlite、pdo_mysql、intl、redis 扩展）、php-fpm、nginx、redis-server、node、composer、
# openssl、python3、playwright-core 和一个 Chromium 可执行文件；MariaDB 只在可选的数据库测试时需要
cd web && npm ci && npm test && cd ..
cd server && composer install && vendor/bin/phpunit && vendor/bin/phpcs && cd ..
LOCKSEND_TEST_DB_DSN="mysql:host=127.0.0.1;port=3306" LOCKSEND_TEST_DB_USER=... LOCKSEND_TEST_DB_PASS=... \
  server/vendor/bin/phpunit -c server/phpunit.xml        # 同一套测试跑在 MariaDB 上；该用户需要对 locksend_test% 有 CREATE/DROP 权限
bash scripts/check-nginx-tls.sh                          # nginx -t + 对 deploy/nginx 做真实的只接受 TLS 1.3 的握手检查
bash scripts/audit_privacy.sh
export PW_PATH=/path/to/node_modules/playwright-core CHROME=/path/to/chrome
STACK_SCRIPT=start-fpm-stack.sh bash scripts/e2e/run.sh  # nginx + php-fpm（随仓库的进程池文件）+ 通过 unix socket 的 redis
STACK_SCRIPT=start-fpm-stack.sh BIG=1 bash scripts/e2e/run.sh   # 额外加上 490 MB 的上传/下载用例
bash scripts/e2e/run.sh                                  # 同样的测试跑在 php-cgi 环境上
```

所有内容都运行在 `/tmp/ls-run` 下，结束后会被删除。

---

## 附录：用宝塔面板部署（Baota）

这一节不是项目自带的测试环境，而是在一台真实的宝塔服务器（nginx 1.30、PHP 8.3、Redis，前面是 Cloudflare）上部署时遇到的做法和坑，
按这个顺序做可以少走弯路。**请在你自己的环境里验证。**

### A. 上传哪些文件

只需要 `server/` 里的内容，建议用 `bash scripts/package.sh` 生成 `dist/locksend-server/`（已经装好了运行时依赖，不含测试文件）。
把它里面的东西传到例如 `/www/wwwroot/locksend/`：

```
public/       运行目录
src/  bin/  migrations/  templates/  vendor/
data/         空目录，运行时存放数据库和密文
composer.json  composer.lock
```

`web/`、`scripts/`、`docs/`、`deploy/` 都不用传。

### B. 建站设置

* 网站目录：`/www/wwwroot/locksend`；**运行目录：`/public`**。
* PHP 版本选 8.3，安装 `redis` 扩展（没装也能用，会改用内置客户端）。
* 数据目录权限：`chown -R www:www /www/wwwroot/locksend/data && chmod 750 /www/wwwroot/locksend/data`。
* **防跨站攻击（open_basedir）：**运行目录设成 `/public` 后，宝塔默认把 PHP 限制在 `public/` 里，会报
  `open_basedir restriction in effect … vendor/autoload.php`。修改 `public/.user.ini`，改成
  `open_basedir=/www/wwwroot/locksend/:/tmp/`（或者在面板里关闭防跨站），然后重启 PHP。
* 必须使用 **HTTPS**（浏览器只在 HTTPS 或 localhost 下提供加密接口 `crypto.subtle`）。

### C. 伪静态

在站点的“伪静态”里粘贴（`/api/` 和 `/install` 交给 `index.php`，其余是静态页面）：

```nginx
location = /          { try_files /index.html =404; }
location ^~ /s/       { try_files /s.html =404; }
location = /admin     { try_files /admin.html =404; }
location ^~ /api/     { rewrite ^ /index.php last; }
location = /install   { rewrite ^ /index.php last; }
location ^~ /assets/  { expires 1y; try_files $uri =404; }
location /            { try_files $uri =404; }
```

### D. 站点配置里要改的地方

* `access_log off;`——宝塔默认会写访问日志，里面有访客 IP。`error_log` 改成 `crit` 级别，普通的 404 就不会记录客户端地址。
* **关闭“网站监控报表”**（或删掉 `vhost/nginx/extension/<域名>/site_total.conf`）：它会把每个请求（含 Cloudflare 转发的访客真实 IP、完整 URL 和 User-Agent）
  发给宝塔统计程序，会让 `access_log off` 失效。
* `ssl_protocols TLSv1.3;`，`ssl_session_tickets off;`，`ssl_early_data off;`。**不要**写 `ssl_ciphers TLS_AES_…`：
  那条指令只对 TLS 1.2 及更早版本生效，写 TLS 1.3 的套件名会让 nginx 报 `no cipher match`。
* 加上安全响应头（`Content-Security-Policy`、`X-Content-Type-Options`、`Referrer-Policy`、`Cross-Origin-*`，内容见 `deploy/nginx/locksend-headers.conf`）。
* 不要在站点配置里再写 `location ^~ /assets/`：伪静态里已经有了，重复定义会报 `duplicate location "/assets/"`。
* 保留宝塔的 `#SSL-START … #SSL-END`、`#ERROR-PAGE-START`（以及其中的 `#error_page 404/404.html;` 注释行）等标记，改动它们会导致面板拒绝保存。
* 在宝塔里重新申请证书或保存 SSL 设置后，面板可能把 `ssl_protocols` 改回去，每次都要检查。

### E. 放在 Cloudflare 后面

* SSL/TLS 模式必须是**“完全（Full）”或“完全（严格）”**。“灵活（Flexible）”会和宝塔里 `if ($server_port != 443)` 的强制跳转互相重定向，
  出现 `ERR_TOO_MANY_REDIRECTS`。
* 在“边缘证书”里把**最低 TLS 版本设为 1.3**；否则访客到 Cloudflare 这一段仍然接受 TLS 1.2。
* 关闭 **Web Analytics（Browser Insights）**：它会自动往页面里插入 `static.cloudflareinsights.com/beacon.min.js`，
  会被页面的 CSP 拦下，但访客的浏览器仍然会向第三方发请求。
* 更新 `sw.js` 后，要在 Cloudflare 里清除它的缓存（宝塔默认给 `.js` 加了 12 小时缓存），或者在伪静态里加一条
  `location = /sw.js { add_header Cache-Control "no-cache" always; try_files $uri =404; }`。
* **不要让源站 IP 暴露：**同一台服务器上的其他子域名如果直接解析到源站 IP，别人就能绕过 Cloudflare 直接访问。
  让它们也走 Cloudflare 代理，或者在防火墙里只允许 Cloudflare 的 IP 段访问 80/443。

### F. Redis

在宝塔的 Redis 配置里设置 `save ""`、`appendonly no`，`maxmemory` **写成纯数字（字节数，如 `134217728`）**，不要写 `128mb`
（面板会因为不是数字而拒绝保存），`maxmemory-policy volatile-ttl`，`loglevel warning`。修改后必须**重启 Redis**，
并删除重启前后可能已经生成的 `dump.rdb`。

### G. 安装和定时清理

```sh
su -s /bin/sh www -c '/www/server/php/83/bin/php /www/wwwroot/locksend/bin/admin-setup-token.php'
```

然后打开 `https://你的域名/install` 完成安装。再在“计划任务”里添加一个 Shell 脚本，**每 5 分钟**执行一次：

```sh
su -s /bin/sh www -c '/www/server/php/83/bin/php /www/wwwroot/locksend/bin/cleanup.php'
```

### H. PHP 设置

宝塔全局 `php.ini` 里的 `display_errors = On` 要改成 `Off`，否则在应用代码关闭它之前出现的报错（比如 `open_basedir` 的报错）会把服务器路径显示给访客。

### I. 部署后自检

```sh
curl -I https://你的域名/                                     # 能看到 content-security-policy 等响应头
curl --tls-max 1.2 https://你的域名/                          # 应该连接失败（Cloudflare 边缘设成最低 1.3 之后）
curl -o /dev/null -w '%{http_code}\n' https://你的域名/src/Api/App.php   # 应该是 404
curl -o /dev/null -w '%{http_code}\n' https://你的域名/install            # 安装完成后应该是 404
```

另外确认：上传一个文件后能正常下载；`/www/wwwlogs/` 下没有这个站点的访问日志；源站 IP 直接访问不应返回这个站点。
