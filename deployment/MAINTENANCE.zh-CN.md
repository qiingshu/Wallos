# 个人定制维护

基线为上游 ellite/Wallos v5.11.1，保留 upstream remote；工作分支 custom/5.11.1-zh-cn。不自动升级上游。

UI 样式参考实际 cx.imqing.cc：#f4f6f3 背景、#28674e 主色、系统中文字体、白色圆角卡片。styles/personal.css 最后加载，保留原生 PHP/JS 流程。includes/version.php 与 service-worker.php 的页面及静态缓存随定制版本更新。

汇率提供商编号 4 为 ExchangeRate-API；原 0–3 继续保留。密钥仅从 EXCHANGERATE_API_KEY_FILE 读取，服务端只读挂载；设置页面不回填密钥。每天 02:00 官方 cron 更新，重启和当日多次请求复用缓存。统一请求 USD 基准后写入 rate[currency] = API[currency] / API[main]；现有换算使用 amount/rate。主币种变化会使当日缓存失效。异常响应、缺失币种和非正汇率均不写入。状态保存 db/exchangerate-status-USERID.json。订阅原金额和币种不变。已有非本提供商的密钥仍由原数据库安全保存。

默认货币名称仅对「代码 + 原版英文默认名」做显示翻译，自定义名称原样保留。默认分类按新账号的语言生成，既有分类不批量改名。中文词条与硬编码校验提示分别位于 includes/i18n/zh_cn.php、scripts/i18n/zh_cn.js、includes/zh_custom.php。邮件发件名为「订阅提醒」，正文使用收件人的 zh_cn 词条。

构建：docker build -f deployment/Dockerfile --build-arg REVISION=FULL_SHA -t wallos-personal:FULL_SHA .
镜像基于固定摘要官方 5.11.1 的运行环境，覆盖仓库内 PHP/JS/CSS 代码，无容器内临时补丁。记录 docker image inspect 的镜像 ID（本地镜像内容摘要）；不用 latest。

生产仍用 /opt/wallos/compose.json 和原 db、logos 挂载。备份脚本与 timer 保持原策略。部署前运行原备份并验证归档 SQLite；保留原 Compose、镜像及最小配置快照在 rollback-official-5.11.1。

回滚：在 OVH 执行 /opt/wallos/rollback-official-5.11.1/rollback.sh。只恢复原镜像和本次汇率/SMTP/导航配置，不将数据库整体恢复到旧快照，避免丢失上线后的订阅。完整灾难恢复先保留现有数据再使用指定归档，禁止覆盖未备份数据。

凭据、实际数据库、图标和个人数据不进入 Git 或构建上下文。Resend SMTP 密钥通过 WALLOS_SMTP_PASSWORD_FILE 只读挂载，不写进数据库或网页；非敏感主机信息保存在部署配置。提醒每天 09:00（Asia/Shanghai），收件人自行确认实际送达；SMTP 接受不等于收件成功。

验证：tests/custom/exchangerate.php 用内存数据库及模拟响应验证方向、失败保留和每日缓存；其余使用隔离副本进行登录、保存订阅与图标及手机布局检查。不要把生产数据、截图或会话放入仓库。
