# PostgreSQL 消息存储与升级

2026-10-29 起，所有业务持久化统一使用 PostgreSQL 18。MessageService 使用独立的 `message_db` schema 与账号，消息正文为 JSONB。会话授权继续经 ChatService RPC，摘要与在线推送继续经 Outbox、RabbitMQ、ChatService Consumer 和 Redis；共用数据库不改变服务的数据边界。

## 存储与一致性

- `messages` 每条消息一行，路由、排序、身份快照和时间使用普通列，`content` 使用 JSONB。联合唯一索引 `(conversation_id, seq)` 同时支持历史倒序分页和 `after_seq` 增量查询，无 COUNT、无 OFFSET；正文暂不建立 GIN 索引。
- `(sender_user_id, client_message_id)` 保持发送者范围的幂等约束。内容通过 JSONB 等值比较，忽略对象键顺序与空白，区分对象、数组和不同的精确大整数；数组顺序有意义。
- `conversation_sequences` 通过事务内 `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` 递增。计数行锁持有至提交，失败事务不会单独留下序号推进；不能用不参与回滚的 PostgreSQL sequence 替换。
- 序号、消息和 `message.created.v1` Outbox 在同一个 PDO 事务提交。只重试提交前明确回滚的死锁/序列化失败；COMMIT 未确认时不重放写回调，客户端用原幂等键恢复。
- 连接错误或提交确认丢失后丢弃旧连接，在下一次请求或健康探测时重建。重连不会自动重放原写入。健康探测同时检查消息表可读，未迁移的实例不会进入就绪状态。
- Message Relay 使用 `message_seq` 检查同会话前序，不能使用预先生成的消息/事件 ID 推断提交顺序。SQL Outbox 的到期判断使用数据库时间；租约 token 防止旧 worker 确认新领取的事件。
- JSONB 入库直接绑定 JSON 文本，避免二次编码。API 的 `content` 字段仍为字符串，但不保证空白、对象键顺序或数字写法逐字保留。`{}`、`[]`、`null`、数字和字符串均为可用 JSON 顶层值。JSONB 不接受的 Unicode NUL 等输入返回消息参数错误。
- ID/seq 对外仍为十进制字符串；UTC 毫秒时间及冻结的匿名身份快照保持不变。

## 配置和部署

在环境配置中增加两个相互独立的密码：

```dotenv
PG_MESSAGE_RUNTIME_PASSWORD=<独立随机值>
PG_MESSAGE_OUTBOX_RELAY_PASSWORD=<另一独立随机值>
```

MessageService 使用 `message_runtime`，只可读取/插入消息、维护序号、插入 Outbox。Message Relay 使用 `message_outbox_relay`，仅有消息 Outbox 的 SELECT/UPDATE。两者无其他服务数据权限及 DDL 权限。

新卷按顺序执行 PostgreSQL 初始化脚本。已有 PostgreSQL 卷不会重新执行入口初始化，`make migrate` 因此会先由 PostgreSQL 容器内管理员运行可重复的 `02-message-storage.sh`，补充角色与 schema，再以 `migration_ddl` 执行 Phinx 增量迁移。不会修改已应用的旧迁移或清空数据。

```bash
make build-runtime
docker compose up -d postgres
make postgres-ready
make migrate
make app-up
```

首次启动使用 `make up`。MongoDB 服务、PHP 依赖、迁移命令和环境变量已退出运行路径；已有旧数据卷不会自动删除。变更 PHP 扩展后应重建运行时镜像。`bash scripts/run_migrations.sh status` 仅查询迁移状态。

## 保留旧消息时的离线导入

无旧消息的开发环境只需执行上述迁移。有需要保留的消息时，先停止消息写入、消息 Relay、会话消费者并备份，冻结导出时间点；不要从仍在写入的源库分批导出三个集合。

用 MongoDB Database Tools 从冻结的旧消息数据库分别导出 `messages`、`conversation_sequences`、`outbox_events`，使用 `mongoexport --jsonFormat=canonical` 生成每行一个文档的文件。认证信息通过工具支持的配置方式提供。不要使用包含用户账号数据的无关导出。

在已迁移且消息三表为空的 PostgreSQL 上，以迁移账号运行：

```bash
docker compose run --rm --no-deps --entrypoint php database-migrate \
  scripts/import_message_archive.php \
  --messages=/app/backup/messages.jsonl \
  --sequences=/app/backup/conversation_sequences.jsonl \
  --outbox=/app/backup/outbox_events.jsonl
```

导入工具不依赖 MongoDB PHP 扩展。它在单事务中导入三个文件，保留原 ID、seq、时间、身份快照、事件 ID、已发布状态和待发布事件，核对会话最大 seq 与计数、消息与事件数量以及关联约束。不一致或格式错误会整体回滚；目标非空时拒绝导入，不覆盖现有消息。该工具适用于上线前可停写的数据量，不提供在线双写或断点迁移。

旧租约不能转移到新 Relay，因此处理中事件重新排队，旧 token 清除。原发布结果未知时可能重复推送，客户端继续按事件/消息 ID 去重。核对导入数量、历史分页、断线同步和会话摘要后再启动消费者与消息服务。确认迁移完成前保留源库备份及原数据卷。

## 验证

真实数据库测试必须使用独立的 `test_*` 数据库并设置 `DATABASE_INTEGRATION=1`。测试会清空消息三表，不应指向有用的开发数据。

```bash
make test-message
make test-postgres
make test-unit
make analyse
bash scripts/test_deployment_lifecycle.sh
```

消息套件覆盖事务原子性、并发发送/幂等竞争、精确 JSONB 比较、分页索引、大整数与时间映射、Outbox 顺序/租约、行锁超时、已回滚事务重试、提交确认丢失恢复和旧档案导入。提交确认丢失通过真实提交后隐藏确认进行故障注入；这些测试不代表已经完成真实网络分区、数据库主备切换或峰值容量验收。
