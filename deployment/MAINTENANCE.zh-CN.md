# Wallos 个人定制维护

基线上游 ellite/Wallos v5.11.1，分支 custom/5.11.1-zh-cn；保留 upstream remote，不自动升级上游。当前定制版本 5.11.1-qiingshu.2。沿用现有灰绿样式和原生 PHP/JS，无新前端框架或集成。

## 凭据

`includes/credentials.php` 统一管理现有凭据：站点 SMTP、OIDC Client Secret、站点通知认证字段，个人 SMTP、各通知渠道的 Token/认证头/Webhook、AI、SerpAPI、个人 API Key，以及三种需要密钥的汇率服务。原有 SQLite 字段继续存放原有集成凭据；汇率按提供商分别存入 credential_values。网页和读取接口只显示配置状态。密码框留空表示保留；删除需要独立确认。网站管理和个人提醒的 SMTP 本来就是两个配置范围，分别管理。

服务端接口 `endpoints/settings/credentials.php` 要求登录、POST、CSRF；共享凭据修改还要求管理员。保存/删除和元信息在一个 SQLite 事务中提交，失败回滚。AI 删除密钥会关闭调用，关闭 AI 本身只改 enabled。定时和手动调用都检查启用状态；不为验证调用付费模型。

首次部署前，保留旧挂载，以旧环境运行 `php deployment/migrate_credentials.php /var/www/html/db/wallos.db`。迁移事务成功后，数据库的迁移标记永久关闭旧环境/文件回退。随后从 Compose 移除旧密钥挂载和环境配置，将原文件停用并放入 root 专属回滚目录。迁移器重复执行会跳过，删除后不会重新导入。不要删除迁移标记。

密钥测试在服务器执行。汇率、SerpAPI 使用最小查询；SMTP 仅建立连接和认证、不发信；AI 关闭时不请求。没有无副作用鉴权接口的通知渠道明确返回“仅格式检查”，并不会声称外部鉴权通过；原有通知测试仍可由用户主动执行。所有外部错误响应统一脱敏，不记录原始凭据或提供商错误正文。个人 API Key 不再展示，需使用自己输入的密钥。

数据库目录 750、数据库文件 640（容器 uid/gid 82），Nginx 拒绝 `/db/`、`/deployment/` 和原有受保护目录。PWA 对设置、个人资料、管理、登录和配置接口只走网络且 no-store；升级缓存并删除旧页面缓存。不要把真实数据库、密钥、上传目录、会话或截图加入 Git/构建上下文。

## 汇率

提供商 0 Fixer、1 APILayer、2 Frankfurter、3 AllRatesToday、4 ExchangeRate-API 各一项。2/3 无需密钥。查询站 https://cx.imqing.cc 是独立查询工具，不是所有提供商的支持币种目录。

每日任务复用成功缓存；显式 force 跳过当天缓存。缓存签名包含提供商、密钥代次与摘要、主币种、币种集合；变更后不当作有效缓存。失败保留最后成功汇率，必要时先重定主币种基准。ExchangeRate-API 的 USD 基准按 `API[currency]/API[main]` 写入，现有汇总仍用 `amount/rate`，不改订阅原金额或币种。

用户级 flock 防止并发重复请求，汇率和状态一同提交 SQLite 事务，提交前重核配置签名；状态表 exchange_refresh_status 是唯一新状态写入源。旧 JSON 成功状态在迁移时导入。主币种保存避免与刷新并发写入。普通失败当日不会自动连发请求，仍可手动重试。

## 部署、备份和回滚

从固定提交构建：

```sh
docker build -f deployment/Dockerfile --build-arg REVISION=FULL_SHA -t wallos-personal:FULL_SHA .
```

运行环境固定官方 5.11.1 镜像摘要。镜像标签使用完整提交；记录本地 Image ID，若未上传镜像仓库则 RepoDigest 为空，二者不可混称。生产 `/opt/wallos/compose.json` 仅保留 Asia/Shanghai 环境与原 db/logos 挂载。

沿用原 `/opt/wallos/backup.py` 和 timer：每日 04:20，最多 14 份/512 MiB 目标并保留最新一份。凭据入库后已被 SQLite 快照备份覆盖；备份目录保持 root 专属。历史备份和本轮回滚快照可能保留旧凭据，必须按现有受保护保留策略处理，不公开下载或提交仓库。

本轮回滚入口：`sudo python3 /opt/wallos/rollback-round2/rollback.py`。脚本先将当前有效凭据转为上一版能读取的受保护挂载，再启用旧镜像；不恢复旧数据库、不覆盖新订阅或图标。被删除的密钥导出为空，不能用旧快照悄悄复活。`--prepare-only` 仅准备兼容配置，可在隔离目录通过 WALLOS_ROOT 演练。若当前数据库不可读，先保护现状，再由操作员选择受保护备份恢复，不自动覆盖。

本轮之前的官方回滚入口继续保留，但不得绕过本轮凭据兼容步骤直接恢复旧 Compose。

## 验证

`php tests/custom/round2.php` 使用内存数据库和模拟汇率响应验证保存、失败回滚、删除、缓存、force、换主币种、配置并发和 AI 关闭。浏览器验证在生产数据库的隔离副本进行，使用临时密码及模拟凭据，不修改生产账号。Logo 搜索响应可模拟，但选择后的公开 PNG 由实际服务端下载保存，再验证重新打开与上传。

只做本轮受影响功能验收。浏览器 iPhone 尺寸及缩小视口用于布局检查，不等同于真机 Safari 软键盘验证。SMTP 配置值保持一致时不重复发送邮件。未完成的外部实际鉴权、收件确认和真机检查应如实记录；不宣称 Mistral/DeepSeek 双审通过。


### qiingshu.3：带图订阅保存修复

保存时显示进度，图像转换、校验、上传和响应解析失败时保留输入并在弹窗内说明原因。上传图片限制 10 MiB；浏览器将大于 1600px 的可解码图片缩小，SVG 转为 PNG。服务端验证真实图片格式与解码结果，限制直接上传的图片为 800 万像素，统一写为随机文件名 PNG；图标失败不保存订阅。HEIC/HEIF 无法解码时需先转为 JPEG。原有图片不批量转换。

针对性浏览器回归：在无外部任务的隔离副本设置临时账号，启动 SSH 本机隧道，然后使用已安装 Playwright 的环境执行：

```sh
WALLOS_TEST_URL=http://127.0.0.1:19284/ WALLOS_TEST_LOGIN_FILE=/protected/test-login.json node tests/custom/subscription_save.cjs
```

测试登录文件仅含隔离账号的 username/password，权限 600，不提交仓库。测试只接受本机地址，并清理其创建的隔离订阅。请勿将隧道指向生产容器。此次镜像回滚只需恢复本次保存的 Compose 与发布记录，无数据库迁移，不恢复旧数据库覆盖新数据。


### qiingshu.4：上传不完整的币种错误误报

在同一个隔离订阅固定字段对照中，收到的两张 JPEG 均可保存，金额、币种和其他非图标字段不变；未复现真机上仅青蛙图成功的差异，不据此认定图库故障已完全解决。

已确定的错误分支：旧请求顺序在文件之后传输 currency_id，PHP 接收部分 multipart 时可能只有文件前的字段，文件错误为 UPLOAD_ERR_PARTIAL。原接口先做币种归属校验，将缺字段误报成币种不存在/不属于账号。现先检查上传传输错误，缺少或格式无效的币种字段单独报错；合法 ID 仍执行原有账号归属 SQL 校验。表单编码改为标量字段在前、文件在后；不从数据库猜测或补填用户未提交的币种。

针对性 PHP 测试：`php tests/custom/subscription_transfer.php`。不完整上传、缺币种、无效币种的 HTTP 对照均须确认返回失败且订阅记录和旧图标不变。此次无数据库迁移；回滚只恢复本次保存的 Compose、镜像、发布记录与源码指针，不恢复旧数据库覆盖新数据。原始图片、实收字段观察器和测试数据库不提交仓库、不加入正式镜像。
