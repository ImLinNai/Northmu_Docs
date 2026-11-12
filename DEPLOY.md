# Northmu Docs 部署文档（宝塔面板）

本文以 **宝塔面板 + Nginx + PHP 8.2 + MySQL 5.7** 为例。Apache 部署见第 11 节。

## 1. 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 8.1 及以上，推荐 8.2 |
| MySQL | 5.7 及以上 |
| PHP 扩展 | `pdo_mysql`、`mbstring`、`gd`、`zip`、`fileinfo` |
| Redis | 可选，5.0+；不启用时直接读取数据库 |
| Web 服务器 | Nginx 推荐；Apache 需开启 `mod_rewrite` |
| 额外依赖 | 无。`vendor/` 已附带（含 Predis），**不需要 Composer 或 Node.js** |

在宝塔「软件商店」安装 Nginx、MySQL 5.7、PHP 8.2。进入「PHP 8.2 → 设置 → 安装扩展」，确认 `fileinfo`、`gd`、`zip`、`mbstring`、`pdo_mysql` 已启用（`zip` 用于数据备份，`gd` 用于登录验证码）。

建议 PHP 配置（「PHP 设置 → 配置修改」）：

```ini
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
memory_limit = 256M
```

Nginx 对应调整上传体积上限（在站点配置 `server { }` 内）：

```nginx
client_max_body_size 100m;
```

## 2. 创建站点与数据库

1. 宝塔「网站 → 添加站点」：
   - 域名：填写实际域名
   - 根目录：`/www/wwwroot/northmu-docs`
   - PHP 版本：8.2
   - 数据库：MySQL，字符集 `utf8mb4`
2. 记录数据库名、用户名、密码，安装向导中需要填写。

## 3. 上传程序

将交付压缩包解压后，把**项目根目录中的全部文件**上传到 `/www/wwwroot/northmu-docs/`，最终结构应为：

```
/www/wwwroot/northmu-docs/
├── app/
├── config/
├── database/
├── public/          <-- 网站运行目录
├── route/
├── view/
├── vendor/
├── data/            <-- （可空，需可写）
├── runtime/         <-- （可空，需可写）
├── think
├── composer.json
└── .example.env
```

注意：

- 压缩包已包含 `vendor/`，不要再执行 `composer install`。
- 压缩包**不包含** `.env`、`data/database.php`、`data/redis.php`、`data/install.lock`，这些文件由安装向导生成。
- 不要把整个项目直接暴露为网站根目录，必须通过第 4 节把运行目录指向 `public`。

## 4. 设置运行目录与伪静态

### 运行目录

宝塔「网站 → 设置 → 网站目录」：

- 网站目录：`/www/wwwroot/northmu-docs`
- 运行目录：选择 `/public`
- 勾选「保存」

这样 `app/`、`data/`、`vendor/` 等目录不会被 Web 直接访问。

### 伪静态

「网站 → 设置 → 伪静态」，选择 `thinkphp` 预设，或填入：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^(.*)$ /index.php?s=$1 last;
    }
}
```

保存后重载 Nginx。

宝塔默认的 PHP 处理规则会把 `.php` 请求交给 PHP-FPM，无需额外配置。

### 静态资源压缩与缓存（推荐）

字体、样式与脚本都存放在 `public/static/`，开启 gzip 与浏览器长缓存可进一步加快首次安装和前台首屏。在站点「设置 → 配置文件」中加入：

```nginx
gzip on;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_types text/css application/javascript application/json image/svg+xml;

location ~* ^/static/ {
    expires 30d;
    add_header Cache-Control "public, max-age=2592000, immutable";
}
```

`.woff2` 本身已压缩，无需再加入 gzip 列表。请确认 Nginx 的 `mime.types` 包含 `font/woff2 woff2;`（宝塔默认包含），否则图标字体可能因 MIME 类型不正确而加载失败。

> 如果使用「网站目录直接指向 public、项目文件放在上一级」的方式，宝塔的防跨站 `open_basedir` 可能限制程序写入上级 `data/`、`runtime/`。此时请在站点「PHP 设置 → 配置修改」中把项目根目录加入 `open_basedir`，或关闭该站点的防跨站攻击。

## 5. 目录权限

SSH 登录服务器执行（宝塔默认运行用户为 `www`）：

```bash
cd /www/wwwroot/northmu-docs
chown -R www:www .
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 runtime data public/uploads
```

- `runtime/`：框架缓存、日志、会话
- `data/`：数据库配置、Redis 配置、安装锁、备份文件
- `public/uploads/`：附件与站点媒体（Logo、favicon、登录页素材、首页背景等）

安装向导运行时需要项目根目录可写（用于生成 `.env`），安装完成后可保持 `www` 属主。

## 6. 执行安装向导

浏览器访问：

```
https://你的域名/install
```

向导共 7 步，顶部会显示当前步骤、总进度与已完成状态：

1. **欢迎**：了解安装内容与准备事项。
2. **环境检测**：逐项检查 PHP 版本、扩展与目录权限；未通过时可修复后点击「重新检测」。
3. **数据库配置**：填写地址、端口、数据库名、用户名与密码，可点击「测试连接」验证；数据库不存在时部署阶段会尝试自动创建。
4. **Redis 缓存（可跳过）**：勾选启用后填写地址、端口、密码、数据库编号、TTL 与键前缀，可「测试连接」；点击「跳过并下一步」会关闭 Redis。
5. **账号设置**：站点名称、管理员账号与密码（至少 6 位，建议强密码）。
6. **开始部署**：核对配置摘要后点击「开始部署」，页面会实时展示校验、连接数据库、建表、写入数据、生成配置等阶段；失败时会标红对应阶段并显示原因与处理建议，可修改配置后重试。
7. **部署完成**：展示站点、数据库、MySQL 版本、数据表数量、Redis 与耗时等信息，并提供「进入前台」「登录后台」按钮。

部署完成后系统会：

- 导入 `database/schema.sql` 建表
- 写入管理员账号与默认设置
- 生成 `data/database.php`、`data/install.lock`、`.env`（`APP_DEBUG = false`）
- 按安装时的选择生成 `data/redis.php`（跳过时为未启用）

安装完成后：

- 前台：`https://你的域名/`
- 后台：`https://你的域名/admin/login`

> 安装锁存在时再次访问 `/install` 会自动跳回首页。即使删除 `install.lock`，安装程序检测到数据库中已有管理员也会拒绝重复安装；若部署中途失败并已写入部分数据，请清空该数据库后重试。

### Redis 缓存（可选）

- 安装向导中勾选「启用 Redis」并填写连接信息即可；也可以先跳过，安装后在后台「系统设置 → 缓存管理」中启用。
- 宝塔「软件商店」安装 Redis 后，建议在 Redis 配置中设置 `requirepass`，并把密码填入向导或后台。
- 配置保存在非公开的 `data/redis.php`，后台表单不会回显密码明文；修改时留空表示保持原密码。
- 默认键前缀为 `northmu:`。后台「清空缓存」只删除该前缀下的本站键，不会影响同库其他应用的数据。
- 缓存使用稳定键，不会因为反复保存设置而生成 `v1:`、`v2:` 等新版本键；系统设置、分类、文档列表 / 正文 / 搜索、标签与后台统计会写入缓存。
- 修改、编辑、删除文档，或调整分类、标签、站点设置时，系统会自动定向失效相关缓存；同时以 TTL 兜底刷新，Redis 中不会残留旧代际数据。
- 关闭 Redis 后站点自动回退为数据库直读，无需卸载 Redis；连接失败时也会回退，不影响页面访问。

### 登录页与浏览器标签图标（可选）

- 浏览器标签图标：后台「前台设置 → 品牌信息 → 浏览器标签图标」，可上传 ICO / PNG / JPG / GIF / WebP，或直接填写在线图片链接。
- 登录页外观：后台「前台设置 → 登录页」，可自定义标题、介绍、图标、背景图片与遮罩透明度，页面右下角实时预览保存后的效果。
- 所有图片类设置均支持「上传文件」或「填写在线图片链接」两种方式；同时填写时以上传文件为准。
- 浏览器可能缓存旧 favicon，更换后如未生效请强制刷新（Ctrl / Cmd + Shift + R）或更换浏览器标签页重新打开。

## 7. 上传目录安全（Nginx 必做）

程序会在 `public/uploads/` 写入 Apache 用的 `.htaccess`，但 **Nginx 不读取 .htaccess**，需要在站点配置中显式禁止上传目录执行 PHP：

```nginx
location ~* ^/uploads/.*\.(php|php[0-9]?|phtml|phar)$ {
    deny all;
    return 403;
}
```

同时在后台「系统设置 → 上传设置」中保持扩展名白名单，不要加入 `php`、`phtml`、`html`、`js`、`svg` 等类型（系统会自动过滤这些扩展名）。

## 8. HTTPS 与安全加固

1. 宝塔「网站 → SSL」申请 Let’s Encrypt 证书，开启「强制 HTTPS」。
2. 安装完成后立即在「系统设置 → 管理员账号」修改初始密码。
3. 保持「启用登录验证码」开启，并按需调整失败次数与锁定时长。
4. 定期在「数据备份」创建备份，并下载到本地或异地保存。
5. 建议限制后台访问来源（宝塔防火墙 / Nginx `allow`、`deny`），或为 `/admin` 增加额外的访问控制。
6. 不要将 `data/`、`runtime/`、`.env` 暴露到 Web 根目录；按第 4 节设置运行目录即可避免。

## 9. 数据备份与恢复

### 创建与下载

后台「数据备份 → 立即创建备份」，系统会导出：

- `database.sql`：建表语句 + 全量数据（MySQL 5.7 兼容，含 `DROP TABLE`）
- `uploads/`：附件与站点媒体文件（Logo、favicon、登录页素材、首页背景等）

备份文件保存在服务器 `data/backups/`，不在公开目录中；通过后台「下载」按钮获取（需登录）。

### 恢复

1. 在后台先创建一份当前备份，防止误操作。
2. 解压备份 ZIP。
3. 将 `uploads/` 目录覆盖到 `public/uploads/`（保持 `www` 属主）。
4. 将 `database.sql` 导入目标数据库：

   ```bash
   mysql -u数据库用户 -p 数据库名 < database.sql
   ```

   或在宝塔「数据库 → 管理 → 导入」中上传 SQL 文件。

5. 清理 `runtime/` 缓存（宝塔「网站 → 设置 → 清除缓存」或删除 `runtime/cache/*`）。

> `database.sql` 内含 `DROP TABLE IF EXISTS`，会覆盖同名表，请确认目标数据库无误后再导入。

## 10. 程序升级

1. 备份数据库与 `public/uploads/`。
2. 用新版本覆盖程序文件（`app/`、`config/`、`database/`、`route/`、`view/`、`public/` 中的静态资源等）。
3. **保留** `data/`（数据库配置、Redis 配置、安装锁、备份）与 `public/uploads/`（附件与站点媒体）。
4. 删除 `runtime/cache/` 下的缓存文件，访问后台确认运行正常。
5. 从 v1.2.x 升级到 v1.3.0 无需修改数据库结构；覆盖文件后清理一次缓存即可。新增的登录页、favicon 与在线图片链接设置直接保存在现有 `settings` 表中。
6. v1.4.0 同样无需修改数据库结构：七步安装向导只影响新安装流程，上传限制改为文件 / 图片 / 视频三类分别配置，旧版 `upload_ext` / `upload_max_mb` 设置会自动兼容，覆盖文件后清理一次缓存即可。
7. v1.4.1 无需修改数据库结构：Tabler Icons 改为字体子集（约 21 KB），并修复 4 个不存在的图标类名。覆盖 `public/static/vendor/tabler-icons/` 后清理一次缓存；若 CDN 或浏览器仍使用旧字体，请强制刷新或先删除该目录再上传新文件。
8. v1.4.2 需要执行数据库升级脚本（新增文档 SEO 与百度推送字段）：

   ```bash
   mysql -u数据库用户 -p 数据库名 < database/upgrade_v1.4.2.sql
   ```

   随后覆盖程序文件（包含新增的 `extend/certs/` 目录），保留 `data/` 与 `public/uploads/`，清理 `runtime/cache/*`。升级后可在「系统设置 → SEO 优化 / 百度收录」配置全局 SEO 与收录 Token；文档地址将统一为 `/doc/1.html`，旧地址会自动 301 跳转。
9. v1.4.3 需要执行数据库升级脚本（新增文档禁止复制字段）：

   ```bash
   mysql -u数据库用户 -p 数据库名 < database/upgrade_v1.4.3.sql
   ```

   随后覆盖程序文件，保留 `data/` 与 `public/uploads/`，清理 `runtime/cache/*`。升级后可在「系统设置 → 前台交互」选择右键菜单模式，在文档编辑页「发布设置」中按文档开启禁止复制；后台「检测更新」可检查 GitHub / Gitee 发行版。
10. v1.4.4 无需修改数据库结构：重新设计全站分页器样式（桌面端页码窗口 + 省略号 + 当前页高亮，手机端紧凑页码计数），后台日志页页脚统一为「共 N 条」。覆盖程序文件后清理 `runtime/cache/*` 即可。
11. v1.4.5 无需修改数据库结构：前台首页版权固定在屏幕底部，设置首页背景后导航自动改为沉浸式；文档页主内容贴边铺满、隐藏右侧目录后正文向右延展；左侧分类栏支持折叠为图标轨道（状态保存在浏览器 localStorage）。覆盖程序文件后清理 `runtime/cache/*` 并强制刷新浏览器缓存（Ctrl+F5）即可。
12. v1.5.0 无需修改数据库结构：后台全面启用新设计系统，侧栏按「概览 / 内容管理 / 站点设置 / 系统工具」重新分组并可折叠；操作日志、缓存、备份、检测更新、系统设置、前台设置与登录页重新设计；右上角通知改为页面底部居中 Toast；登录安全新增「登录态有效期」12 小时 / 24 小时 / 7 天（保存在 `settings` 表，默认 24 小时，修改后对下一次登录生效）。覆盖程序文件后清理 `runtime/cache/*` 并强制刷新浏览器缓存（Ctrl+F5）即可。
13. v1.6.0 需要执行数据库升级脚本（新增公告 / 通知表）：

    ```bash
    mysql -u数据库用户 -p 数据库名 < database/upgrade_v1.6.0.sql
    ```

    随后覆盖程序文件，保留 `data/` 与 `public/uploads/`，清理 `runtime/cache/*` 并强制刷新浏览器缓存（Ctrl+F5）。升级后可在「内容管理 → 公告通知」创建前台横幅与访问弹窗；「系统设置 → 维护模式」可开启维护模式并选择 macOS / 赛博朋克 / 代码终端 / 极简留白四套维护页模板；「前台设置」导航样式新增「胶囊」选项；加密文档解锁改为正文区横向印章式布局。
14. v1.7.0 无需修改数据库结构：新增「前台交互 → 禁止复制全站内容」「系统设置 → 纪念日」两项开关（手动或按起止时间自动启用灰度，可选全站或仅首页）；移动端顶栏的搜索与主题切换并入菜单二级菜单；后台保存浮动条仅在内容有改动时显示；修复富文本编辑器下拉菜单深色模式；新建文档支持导入 Markdown / 纯文本 / HTML，本地图片可按提示上传到附件库并自动替换链接。覆盖程序文件后清理 `runtime/cache/*` 并强制刷新浏览器缓存（Ctrl+F5）即可。
15. v1.7.1 需要执行数据库升级脚本（新增通行密钥表）：

    ```bash
    mysql -u数据库用户 -p 数据库名 < database/upgrade_v1.7.1.sql
    ```

    随后覆盖程序文件，保留 `data/` 与 `public/uploads/`，清理 `runtime/cache/*` 并强制刷新浏览器缓存（Ctrl+F5）。升级后可在「系统设置 → 登录安全」开启通行密钥并注册（需 HTTPS 或 `localhost`），登录页即可选择免密码登录；成功登录进入后台会显示一次欢迎提示。前台新增音乐歌单入口，可在「系统设置 → 前台交互」配置歌名、作者、歌词、封面与音频链接。
16. v1.7.2 无需修改数据库结构：首页按钮新增图标（GitHub / Gitee / QQ / 微信 / Bilibili / 微博 / X / Telegram 等品牌图标及通用图标）、自定义颜色与「仅显示图标」模式；后台设置页切换 Tab 后保存不再跳回第一个 Tab，刷新后仍停留在当前 Tab；移除前台登录用户按钮的背景方框；文档列表页新增「列表 / 卡片」视图切换，卡片视图一行四列并在悬停时呈现边框辉光；「系统设置 → 前台交互」新增「外链中转提醒」开关，开启后访客点击站外链接会先进入中转提醒页，居中展示站点标识、标题、目标地址与安全提示，提供「继续前往」与「返回站点」按钮，站内链接、邮件与电话链接不受影响。覆盖程序文件后清理 `runtime/cache/*` 并强制刷新浏览器缓存（Ctrl+F5）即可。

## 11. Apache 部署（可选）

1. 将 Apache 站点的 `DocumentRoot` 指向项目的 `public/` 目录。
2. 确保启用了 `mod_rewrite`，并允许 `.htaccess` 生效：

   ```apache
   <Directory "/www/wwwroot/northmu-docs/public">
       AllowOverride All
       Require all granted
   </Directory>
   ```

3. 项目自带 `public/.htaccess`，无需额外伪静态规则。
4. 上传目录的 PHP 禁止执行由 `public/uploads/.htaccess` 自动生效。

## 12. 常见问题

| 现象 | 排查方向 |
| --- | --- |
| 访问 `/admin`、`/docs` 返回 404 | 未设置伪静态；确认第 4 节的 Nginx `rewrite` 已生效并重载 |
| 页面 500 / 白屏 | 查看 `runtime/log/`；确认 PHP ≥ 8.1、`runtime/` 可写、运行目录为 `public` |
| 安装页提示数据库连接失败 | 检查数据库地址 / 端口 / 账号密码；确认 MySQL 5.7+、`pdo_mysql` 已启用 |
| 安装页提示目录不可写 | 按第 5 节设置 `runtime`、`data`、`public/uploads` 权限；检查宝塔 `open_basedir` |
| 上传附件失败 | 检查 PHP `upload_max_filesize`、`post_max_size`，Nginx `client_max_body_size`，以及后台允许的扩展名与大小 |
| 登录验证码不显示 | 未安装或未启用 PHP `gd` 扩展 |
| 创建备份失败 | 未启用 `zip` 扩展，或 `max_execution_time` 过短；附件过多时可适当调大执行时间 |
| Logo 上传失败 | `public/uploads/` 不可写，或图片超过 2 MB / 格式不在 png、jpg、gif、webp 内 |
| Redis 测试连接失败 | 确认 Redis 已启动、端口与密码正确、database 编号在 0–15；未使用 Redis 时保持关闭即可 |
| 首页背景图片 / 视频不显示 | 确认「前台设置 → 背景」类型与文件已保存；视频需为 mp4 / webm / ogg 且不超过 30 MB，浏览器会以静音方式自动播放 |
| 修改前台设置后页面未更新 | 保存时会自动失效相关缓存；若使用 CDN / 反向代理，请同时清理其页面缓存。也可在「缓存管理 → 清空缓存」手动刷新 |
| 浏览器标签图标没有变化 | 浏览器会缓存 favicon，请强制刷新或重新打开标签页；确认「前台设置 → 品牌信息 → 浏览器标签图标」已保存 |
| 编辑器上传图片后看不到 | 图片会先上传到附件库并自动刷新列表、选中预览；若列表未更新，检查上传接口是否返回成功及 `public/uploads/` 写入权限 |
| 私密内容被访客看到 | 检查文档与所属分类的可见性（子分类继承父级限制，取最严格一档）；私有内容直接访问返回 404 |
| 加密文档输入密码后仍无法查看 | 解锁状态与会话绑定，清理 Cookie 或更换浏览器后需重新输入；连续失败会临时锁定 10 分钟 |
| 安装页 / 前台图标不显示或加载很慢 | 确认使用的是 v1.4.1 及以上版本的 `public/static/vendor/tabler-icons/`（字体约 21 KB）；检查 `fonts/tabler-icons-subset.woff2` 是否 404、Nginx 是否以 `font/woff2` 返回；CDN / 浏览器缓存旧字体时强制刷新 |
| 备份下载 302 回登录页 | 下载必须登录后台；登录态过期后重新登录再下载 |

## 13. 交付清单

压缩包内应包含：

- 完整源码：`app/`、`config/`、`database/`、`route/`、`view/`、`public/`、`extend/`
- 依赖：`vendor/`（含 ThinkPHP、Parsedown、think-captcha 等）
- 本地化静态资源：`public/static/vendor/`（Tabler、WangEditor、marked、highlight.js、diff2html、turndown）
- 安装脚本：`database/schema.sql` + `/install` 向导
- 部署与使用文档：`README.md`、`DEPLOY.md`
- 空目录：`data/`、`runtime/`、`public/uploads/`（含执行保护文件）

压缩包内**不应包含**：

- `.env`、`data/database.php`、`data/redis.php`、`data/install.lock`
- 本机数据库备份、测试附件、`runtime/` 缓存与日志
- 开发测试脚本与截图
