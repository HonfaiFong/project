# 消息消费恢复与验收

部署时先执行 `make broker-init` 更新 RabbitMQ 拓扑和 policy，再执行 `make migrate` 和 `make app-up`。现有 `chat.message.created` 队列参数保持兼容，无需删除队列。新增组合外键会校验已有 Outbox 关联，发现不一致时迁移失败，应先排查数据，不要跳过约束。

## 自动恢复

- `chat-message-consumer` 成功处理后才 ACK。依赖故障时，将原始事件持久化到延迟队列并等待 mandatory routing 和 publisher confirm，随后才 ACK 原投递；转存失败则保留未确认投递。
- 重试间隔依次为 5 秒、30 秒（第 2～5 次）、300 秒（之后持续重试），事件 ID 和消息正文保持不变。较新事件可能先完成，摘要按 seq 幂等更新；客户端按 seq 排序、补拉并去重。
- 主队列仍保留 delivery limit 5。进程崩溃等导致 broker dead-letter 时，事件进入 `chat.message.recovery`，消费进程恢复后通过确认发布自动放回主队列，清除旧的 death history，避免 TTL 死信循环。`jobs.failed` 保留原有审计副本。
- 主队列及延迟队列使用 quorum `at-least-once` dead-letter policy 和 `reject-publish` overflow，转移失败时 broker 保留源消息。该策略依据 [RabbitMQ quorum queue 文档](https://www.rabbitmq.com/docs/quorum-queues#dead-lettering)。
- Broker 内部的死信确认重试是另一层定时器：当前 RabbitMQ 4.1.6 默认 180 秒。目标队列恢复后可能需等待该周期，不能把应用的 5 秒延迟当作基础设施故障后的恢复时限；见 [官方说明](https://www.rabbitmq.com/blog/2022/03/29/at-least-once-dead-lettering)。
- 无效 JSON、事件版本、标识、时间格式或公共 Protobuf 载荷进入 `chat.message.invalid`，不占用依赖故障重试链路。
- `chat-message-reconciler` 每轮间隔 60 秒，按会话 ID 分批读取 MessageService 的最新已提交消息，修复 ChatService 摘要。两个连接分别使用 `message_runtime` 和 `chat_runtime`，不扩大数据库账号的跨 schema 权限。对账仅修复摘要，不补发历史实时通知。失败会记录日志并在下一轮重新建立连接。

## 告警与手工恢复

监控 `chat.message.invalid`、`chat.message.recovery`、三个 retry 队列的积压和增长趋势；`chat_message_invalid_total` 增长或 `chat_message_retries_total{tier="300s"}` 持续增长应触发告警。监控 reconciler 的 `message_summary_reconciled` 日志是否按期出现，以及 `Message summary reconciliation failed` 错误。接入实际告警渠道由部署环境负责。

修复无效事件的生产方或消费者后可重放隔离事件：

```sh
docker compose run --rm --no-deps chat-message-consumer php scripts/replay_chat_message_events.php chat.message.invalid
```

升级前已经进入 `jobs.failed` 的消息事件不会被新增 binding 自动搬走，可使用同一脚本指定 `jobs.failed`。脚本只重放 `message.created.v1`，扫描启动时的有限队列快照，其他任务保留并在连接关闭时重新入队。确认发布前不会 ACK；允许重复事件，实时客户端仍需按事件 ID/消息序号去重。`jobs.failed` 中新生成的审计副本通常无需再次重放。

手动执行一次摘要对账：

```sh
docker compose run --rm --no-deps chat-message-reconciler php services/chat-service/bin/message-reconciler.php --once
```

## 测试入口与边界

`make test-integration` 使用当前 Integration 套件，需要已迁移的可丢弃 `test_*` PostgreSQL 数据库、Redis、导入最新 definitions 的 RabbitMQ，以及 Linux PHP/RoadRunner 运行时。设置 `DATABASE_INTEGRATION=1`、`RABBITMQ_INTEGRATION=1` 和对应连接变量。遗留的 10 个 MySQL 测试文件独立在 `LegacyMysql` 套件，通过 `make test-legacy-mysql` 运行。

真实 PostgreSQL 测试覆盖锁等待期间的消息时间、序号并发、摘要对账和 Outbox 组合外键；真实 RabbitMQ 测试覆盖超过 5 次的重试转存、5 秒 TTL 回流、死信恢复、隔离，以及确认失败时保留原消息。内存 JSON 比较保留数字词法精度，但 PostgreSQL 接受的 numeric 范围、Unicode 限制等仍以数据库集成测试为准。

Outbox 积压 COUNT 已改为每 15 秒采样一次，失败采样也限频；领取仍保持单条，未引入批处理。SQL 超时配置保留逐次预算收紧，减少往返需要先做真实 PostgreSQL 压测，不能通过放宽 RPC 截止时间换取性能。

单节点故障注入与上述回归不能替代 PostgreSQL 主备切换、RabbitMQ 多节点故障、长时间 Redis 故障、真实数据规模导入和容量验收。

2026-10-29 本地隔离验收：PHP 8.4.27、PostgreSQL 18.0、RabbitMQ 4.1.6、Redis 7.2.4；`make test-unit` 479 项通过（2387 个断言），`make test-integration` 94 项通过（725 个断言），均无跳过。PHPStan、PHP 语法检查、Compose 配置及部署生命周期回归通过。死信目标缺失测试按 broker 的默认 180 秒内部重试周期验证了最终送达。尚未在生产环境执行迁移或完整 E2E/主备及容量验收。
