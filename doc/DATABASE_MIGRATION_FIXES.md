# PostgreSQL / MongoDB 审查修复与验证

## 启动与升级

1. 将 `.env.example` 新增的 `MONGODB_ADMIN_PASSWORD`、`MONGODB_RUNTIME_PASSWORD`、`MONGODB_RELAY_PASSWORD`、`MONGODB_MIGRATION_PASSWORD` 合并到本地配置，分别设置密码。生产环境使用独立随机密码。
2. 执行 `make build-runtime`，安装 PostgreSQL 和 MongoDB PHP 扩展。
3. 执行 `make up`。顺序为基础设施、PostgreSQL 查询就绪、MongoDB 副本集和账号初始化、两类数据库迁移、应用启动。
4. 单独运行迁移使用 `make migrate`；只检查关系库迁移状态可用 `bash scripts/run_migrations.sh status`。

PostgreSQL 18 的持久化卷挂载到 `/var/lib/postgresql`。初始化脚本使用 `POSTGRES_DB`，支持非默认数据库名。运行时账号只拥有各自 schema 的 DML 权限；迁移仍使用 `migration_ddl`。

新 PostgreSQL 数据卷显式使用 SCRAM 主机认证，包含容器内 TCP 回环连接；初始化期间的 Unix socket 保留本地认证。已有卷不重新执行 initdb，需单独核对 `pg_hba.conf` 的 TCP 规则；环境变量不会自动覆盖既有认证配置。

MongoDB 启用认证和副本集 keyfile。`message_runtime` 只能读写消息所需集合，`message_outbox_relay` 只能查询、更新 Outbox；`message_migration` 在指定消息数据库内执行 DDL。密码通过连接选项传递，无需拼接进 URI。已存在的无认证 MongoDB 数据卷须先在隔离维护环境中创建管理员和业务账号，再启用认证；初始化环境变量仅对空数据卷创建管理员，不会自动改写已有数据卷。不要用删除业务数据卷的方式升级。

## MongoDB 迁移兼容

保留 `20261020000100_message_collections_v1` 的定义和 checksum，新增 `20261020000200_outbox_claimed_at_date`，通过 `collMod` 将 `outbox_events.claimed_at` 改为 `date|null`。已有 v1 环境和全新环境均执行 v2。重复运行会核对 checksum、修复该 validator，并释放迁移租约。

部分建表后中断的迁移可重跑：捕获驱动的 `CommandException`，仅在 `NamespaceExists`（48）时执行 `collMod`，其他错误继续失败。

## 消息请求与发布

- RPC 使用独立的 MongoDB 连接配置，连接、选主、socket 单阶段最多 50ms；迁移和后台 Relay 保留较长超时。
- 消息查询、事务读写及提交前检查同一个单调时钟预算，并为传输阶段保留时间。查询、序号分配、提交另有服务端时间上限。回调耗尽预算时中止事务；提交结果未知时只重试同一事务的提交，不重放回调。
- 这些限制用于及时返回超时；网络故障下已经发送的提交仍可能成功，客户端应使用原 `client_message_id` 重试，以恢复确定结果。
- Outbox 扫描使用跨轮次保留的复合游标，扫描到末尾后循环。单个会话有超过 2000 条阻塞事件时，其他会话仍会获得发布机会。每次 claim 仍检查会话前序事件，并用 token 防止旧租约确认新任务。
- PostgreSQL 会话列表游标统一编码为 UTC 毫秒时间，查询 PostgreSQL 时带显式时区，支持原先无时区游标格式和相同时间下的 ID 排序。
- MessageService 只有 MongoDB 探测成功后才进入启动完成状态，健康检查持续探测依赖。

## 回归验证

真实数据库测试要求显式设置 `DATABASE_INTEGRATION=1`，并强制数据库名称以 `test_` 开头；MongoDB 测试会清空该测试库中的消息、序号和 Outbox 集合。请使用独立 Compose 项目和独立数据卷。

CI 在一次性 PostgreSQL / MongoDB 环境中运行：

- `tests/Integration/PostgresMigrationRuntimeTest.php`：时区游标、schema 权限、唯一冲突与 savepoint、Outbox 租约 fencing。
- `tests/Integration/MongoMessagePersistenceTest.php`：重复和部分迁移、validator 升级、事务提交与回滚、过期预算、认证与账号隔离、发布顺序、旧租约 fencing、超过扫描上限时的跨会话公平性。
- 原有单元测试、SQLite 集成测试、Redis 回归与 HTTP / gRPC 社交 E2E。社交 E2E 的数据库断言已改用 PostgreSQL。

历史 MySQL 专用迁移及测试文件仍保留，便于核对旧实现；新的 CI 数据库验证使用 PostgreSQL / MongoDB，不再启动已移除的 `mysql` Compose 服务。

本次本地验证：450 项单元测试、47 项 SQLite 集成测试、4 项 PostgreSQL 集成测试、7 项 MongoDB 集成测试通过；PHPStan、Compose 配置、CI YAML、Shell 语法和部署生命周期回归通过。完整 HTTP / gRPC E2E 尚未在本次会话运行。
