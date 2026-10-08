# 匿名社交 App 后端平台 (Social Backend Monorepo)

本项目是一个面向弱关系社交、类似匿名树洞 / 漂流罐头 / 情绪社交产品的后端基础平台。支持从早期 MVP 平滑扩展至百万级在线连接与高并发读写。

---

## 1. 架构简介

- **外部入口**:
  - HTTP REST BFF: `apps/api-gateway` (Webman 2.x / PHP 8.4, 负责鉴权入口、参数校验、gRPC Client 聚合，**禁止直接访问业务数据库**)。
  - 实时连接 Gateway: `gateways/realtime-gateway` (Go 1.27 + WebSocket；当前 Skeleton 使用 JSON Envelope；Phase 3 切换 Protobuf Binary Envelope，仅负责连接管理、心跳、Ticket 鉴权、消息 Push，**禁止业务 SQL 耦合**)。
- **内部核心微服务**:
  - 同步调用：全部基于 **gRPC**，携带 trace_id、request_id、user_id 与 deadline。
  - 异步通信：基于 **RabbitMQ Streams（领域事件）和 Quorum Queues（任务）**；领域事件写入 Transactional Outbox，消费者按事件 ID 幂等处理。
- **数据源与基础设施**:
  - 关系型业务最终事实数据源：MySQL 8.4 (分逻辑库，无跨库 JOIN)。
  - 高速缓存与即时状态：Redis 7.2 (统一 Key 规范，设置明确 TTL，禁止 `KEYS *`)。
  - 消息引擎：RabbitMQ 4.1（Streams + Quorum Queues）。
  - 媒体对象存储：RustFS（S3 兼容，客户端直传 Presigned URL，不经后端流式转发）。

```text
                   Client
                     |
            +--------+--------+
            |                 |
         HTTP API          WebSocket
            |                 |
            v                 v
       Webman API      Realtime Gateway
       (BFF :8787)        (Go :8080)
            |                 |
            +-------gRPC------+
                     |
         +-----------+------------+
         |                        |
    auth-service             user-service
      (:50051)                 (:50052)
         |                        |
      auth_db                  user_db
```

---

## 2. 服务列表

| 服务目录 | 服务名称 | 运行时 / 技术栈 | 职责与边界 |
| :--- | :--- | :--- | :--- |
| `apps/api-gateway` | API Gateway (BFF) | Webman / PHP 8.4 | 对外 HTTP API 入口、JWT 认证、gRPC 客户端聚合 (**Phase 1 已实现**) |
| `gateways/realtime-gateway` | Realtime Gateway | Go 1.27 / WebSocket | 实时长连接维持、心跳、多端连接管理、下行 Push (**Skeleton**：连接/心跳/票据校验已实现，当前使用 JSON Envelope，Phase 3 切换 Protobuf Binary Envelope；消息协议与 Presence 见其 README) |
| `services/auth-service` | AuthService | RoadRunner / PHP 8.4 | 账号认证、Token 签发 / 轮转、设备管理、WS Ticket (**Phase 1 已实现**) |
| `services/user-service` | UserService | RoadRunner / PHP 8.4 | 用户主体、Persona 分身管理 (**User != Persona**) (**Phase 1 已实现**) |
| `services/content-service` | ContentService | RoadRunner / PHP 8.4 | 帖子、图文媒体元数据、一级与回复评论 (**骨架，未实现**) |
| `services/social-service` | SocialService | RoadRunner / PHP 8.4 | 点赞、分身关注、用户级双向拉黑 (**骨架，未实现**) |
| `services/discovery-service` | DiscoveryService | RoadRunner / PHP 8.4 | 漂流罐头、情绪池、随机发现、曝光过滤 (**骨架，未实现**) |
| `services/feed-service` | FeedService | RoadRunner / PHP 8.4 | 首页 Feed 规则召回、ZSET Timeline 聚合 (**骨架，未实现**) |
| `services/recommend-service` | RecommendService | RoadRunner / PHP 8.4 | 用户兴趣画像、候选打分排序 (**骨架，未实现**) |
| `services/chat-service` | ChatService | RoadRunner / PHP 8.4 | 私聊会话、会话成员、已读点、权限校验 (**骨架，未实现**) |
| `services/message-service` | MessageService | RoadRunner / PHP 8.4 | 消息持久化、递增 seq、幂等去重、分片路由 (**骨架，未实现**) |
| `services/risk-service` | RiskService | RoadRunner / PHP 8.4 | 同步风险决策 (ALLOW/DENY/CHALLENGE) (**骨架，未实现**) |
| `services/moderation-service` | ModerationService | RoadRunner / PHP 8.4 | 内容敏感词与多媒体审核流转 (**骨架，未实现**) |
| `services/notification-service` | NotificationService | RoadRunner / PHP 8.4 | 站内通知与离线推送通道 (**骨架，未实现**) |
| `services/media-service` | MediaService | RoadRunner / PHP 8.4 | 预签名上传 URL 签发与对象元数据确认 (**骨架，未实现**) |
| `services/search-service` | SearchService | RoadRunner / PHP 8.4 | OpenSearch 内容与标签检索 (**骨架，未实现**) |
| `services/config-service` | ConfigService | RoadRunner / PHP 8.4 | 客户端 RouteProfile 与动态功能开关 (**骨架，未实现**) |
| `apps/admin-api` | Admin API | Webman / PHP 8.4 | 运营后台、审核队列处理、封禁管理 (**骨架，未实现**) |

---

## 3. 环境依赖

全部依赖均封装于 Docker 与 `compose.yaml` 中：
- **Docker Engine**: >= 24.0.0
- **Docker Compose**: >= v2.20.0
- (本地开发可选): PHP >= 8.2 (带 pdo_mysql, redis, bcmath), Go >= 1.27, Composer >= 2.0

---

## 4. 本地启动

```bash
# 1. 复制配置文件，并设置其中的 MYSQL_PASSWORD / JWT_SECRET / WS_TICKET_SECRET
#    以及三个独立的 INTERNAL_RPC_*_TOKEN
#    （compose.yaml 对这些变量做强校验，缺失会拒绝启动）
cp .env.example .env

# 2. 一键启动全流程
#    （make up 内部严格执行安全生命周期：镜像编译 -> 基础设施就绪 -> 自动探活 -> 执行数据迁移 -> 业务与网关服务启动）
make up

# 3. 检查服务健康状态
#    网关会对 auth/user 服务发起真实的 gRPC 健康探测；任一下游不可用返回 503
curl http://127.0.0.1:8787/health
```

> **启动与迁移顺序说明**：`make up` 依次启动 MySQL、Redis、RabbitMQ，导入 broker 拓扑，执行数据库迁移，再启动业务服务。对象存储按需运行 `make storage-up`。

AuthService 与 UserService 的 gRPC 端口只在 Compose 内部网络暴露，不发布到宿主机；Gateway→Auth、Gateway→User、Auth→User 分别使用独立的 `INTERNAL_RPC_*_TOKEN`，并通过 `SERVICE_NAME` 做调用方和方法授权。部署前必须把 `.env` 中的三个 token 替换为至少 32 字节的随机值。

MySQL、Redis 和 RabbitMQ 的开发端口默认只绑定 `127.0.0.1`；Compose 服务之间继续使用内部 hostname 通信，需要外部访问时再显式设置对应的 `*_BIND_ADDRESS`。

生产配置从 `.env.production.example` 开始，填入独立随机凭证，保持 `ENABLE_DEV_LOGIN=false`、`ENABLE_DEV_SMS=false` 且固定验证码为空。当前没有生产短信适配器，QQ/微信开关必须保持关闭；强行开启时 AuthService 拒绝启动。代理部署必须配置实际受控代理的 `SOCIAL_TRUSTED_PROXY_CIDRS`，否则限流使用 socket peer；Gateway 也应只允许这些代理访问。内部 RPC token 是服务身份凭证，跨主机部署仍需受控网络和 TLS/mTLS，不能把凭证作为传输加密使用。

内部 gRPC 当前明确使用明文传输：`UnaryGrpcClient` 使用 `ChannelCredentials::createInsecure()`，RoadRunner 未配置 TLS。请求、手机号、会话数据和 `x-service-token` 都可能被能够监听内部网络的主体读取。当前部署仅适用于单机、受控且隔离的 Compose 网络；不得发布 Auth/User RPC 端口，不得把不可信容器接入该网络。应用层 token 和方法白名单提供调用授权，不提供传输机密性。

跨主机、共享集群或不可信网络部署前，必须同时配置服务端 TLS 与客户端 CA/服务名验证；采用 mTLS 时还应校验客户端证书身份，并保留现有调用方及方法授权。证书和三个独立 RPC 凭证应由密钥管理系统注入，禁止写入镜像、Git 或日志。建立定期轮换、到期告警和泄露后的紧急撤销流程。当前每个调用方只接受一个 token，尚不支持双凭证无缝轮换：应在维护窗口停止相关调用方流量，替换服务端与客户端凭证并重启 worker，运行 `scripts/test_internal_rpc.php` 验证新凭证成功、旧凭证失败后恢复流量。未来无停机轮换需要有时限的双凭证接受窗口，待调用方全部切换后撤销旧凭证；TLS/mTLS 与双凭证窗口均为后续能力，当前未实现。

已签发 JWT 的用户状态策略：AuthService 每次 `VerifyToken` 验签及撤销检查后，先校验设备仍属于 JWT 中的用户，再通过已授权的 `UserService.GetUser` 读取主库用户状态，不缓存 active 状态。封禁（非 active）或软/硬删除后，下一次受保护 HTTP 请求即拒绝旧 JWT，刷新与新登录同样拒绝；Redis、数据库或用户目录超时、不可用时拒绝访问并返回 HTTP 503（`SERVICE_UNAVAILABLE` / `RPC_TIMEOUT`），不会将基础设施故障报告为凭证失效的 401。因此每次认证新增一次 Auth DB 设备归属查询和一次内部 RPC/用户主库查询，部署时需要为数据库及 UserService 的容量和可用性留出预算。此策略是状态暂停：解封后，尚未到期且未被登出撤销的 JWT 可恢复访问；删除后的 ID 不得复用。已完成认证的在途请求不被回滚，已建立的 WebSocket 连接或先前签发的短期 WS ticket 不属于 JWT 校验路径，尚无封禁广播断连能力。

设备隔离迁移 `0006_device_user_scope.sql` 必须在暂停所有 AuthService 会话写入、备份 `devices`/`refresh_tokens` 后执行，再部署本次 Repository 与 JWT 校验代码、重启全部 worker 后恢复流量。它保留现有设备 ID 和当前归属，先撤销设备缺失或用户归属错配的历史 refresh token，再建立 `(user_id, device_uuid)` 唯一索引并删除旧 UUID 全局唯一索引。历史设备行被覆盖前的归属无法可靠重建，因此不把错配 token 迁给新设备；受影响用户需重新登录。旧 JWT 的设备归属不符时同样被拒绝，正确归属的会话继续有效。MySQL DDL 隐式提交，迁移不能依赖事务回滚；迁移失败时保持暂停状态，修复并重跑后确认索引与错配 token 撤销结果。禁止在新索引上回滚运行旧 UUID 查询代码。

Snowflake Worker ID 说明：MVP 阶段 `compose.yaml` 已为 auth-service 注入 `WORKER_ID=1`、user-service 注入 `WORKER_ID=2`；容器内多个 RoadRunner worker 进程由 `WorkerId::resolve()` 自动按 `pid % 32` 消散，在单容器小并发阶段可正常工作。注意：`pid % 32` 在数学上无法杜绝跨进程碰撞，在生产环境横向扩容（多 Pod × 多 RoadRunner Worker）前必须切换为基于 Redis `SET NX` 租约分发的动态 WorkerId Allocator（0-1023）并定时续期。

---

## 5. Proto 与协议维护

接口协议定义位于 `contracts/proto/`：
```text
contracts/proto/
├── common/v1/          # 分页、健康检查、元数据、错误定义
├── auth/v1/            # 登录、Token 轮转、Ticket 协议
├── user/v1/            # 用户、Persona 分身协议
├── content/v1/         # 帖子、评论协议
├── social/v1/          # 社交互动协议
├── chat/v1/            # 会话协议
├── message/v1/         # 消息协议
└── ...
```

代码生成命令：
```bash
make proto
```

---

## 6. 数据库设计与迁移

数据库初始化基线位于 `infra/docker/mysql/init/01-databases.sql`，容器首次启动时自动挂载执行。后续结构变更使用 Phinx，迁移文件放在 `database/migrations/`，由 `meta.phinxlog` 追踪。历史 `infra/docker/mysql/migrations/0001` 至 `0006` SQL 和 `meta.schema_migrations` 保留，由首次 Phinx 迁移接管；未完成的旧迁移按实际字段/索引补齐，已完成记录不重放。
- `auth_db`: 存储 `devices`, `refresh_tokens`, `outbox_events`
- `user_db`: 存储 `users`, `personas`, `outbox_events`
- `content_db`: 存储 `posts`, `post_media`, `comments`, `outbox_events`
- `social_db`: 存储 `likes`, `follows`, `blocks`, `outbox_events`
- `chat_db`: 存储 `conversations`, `conversation_members`, `outbox_events`
- `message_db`: 存储 `messages`, `outbox_events` (预置 ShardRouter 槽位)

安装 Composer 依赖后应用待执行迁移（使用现有 PHP runtime 镜像）：
```bash
make migrate
```

查看状态、新建迁移：
```bash
bash scripts/run_migrations.sh status
bash scripts/run_migrations.sh create CreateExampleTable
```

`phinx.php` 在同一 PDO 会话中取得 MySQL `GET_LOCK` 后执行 Phinx，Docker 入口和直接使用 `vendor/bin/phinx --configuration=phinx.php` 均受此锁保护。等待超时通过 `MYSQL_MIGRATION_LOCK_TIMEOUT` 设置（默认 30 秒，范围 0–300），超时后退出，连接关闭时释放锁。历史 SQL 不再新增或修改；已合并的 Phinx 迁移也保持不变，修正通过新的迁移完成。MySQL 多条 DDL 无法由事务整体回滚，新迁移需考虑部分成功后的重跑；本次接管和身份排序规则迁移均可恢复执行，禁止自动回滚删除既有数据。

社交身份的应用 ID、命名空间和外部 subject/openid/unionid 使用 `utf8mb4_bin` 比较，区分大小写，保留字段长度、可空性和既有唯一键。部署时需要具备对应数据库 DDL 权限的迁移账号，迁移可能等待表元数据锁，应在合适的发布窗口执行。

本机直接运行 Phinx 时，显式提供 `MYSQL_HOST`、`MYSQL_PORT`、`MYSQL_USER`、`MYSQL_PASSWORD` 环境变量（配置不自动读取 `.env`）。`MYSQL_SCHEMA_PREFIX` 可为隔离测试添加库名前缀，默认空；集成测试仅使用随机 `social_test_` 前缀的独立数据库。

配置加载会连接数据库并初始化 `meta`，`status` 也可能初始化 Phinx 记录表。首次旧 Schema 接管需要查询实际表结构，尚不支持全新库上的完整 `--dry-run`；`--fake` 保留 Phinx 原有的只标记版本语义，不能用于实际部署变更。

---

## 7. 测试与质量检查

```bash
# 运行单元测试（覆盖 Snowflake, ShardRouter, 错误码, 事件模型, 业务服务）
make test

# 运行代码静态检查（PHP 语法 + Go vet）
make lint

# 运行 PHPStan 静态分析
make analyse

# Proto 契约检查（CI 同款）
buf lint
```

---

## 8. 端到端 E2E 验证

`make e2e` 运行 Phase 1 业务流及社交登录 HTTP → gRPC → MySQL/Redis 测试。社交测试复用开发短信发送器作为 Fake SMS，通过独立 `fake-oauth` 服务模拟 QQ/微信上游；不调用真实 OAuth 或短信平台。在专用本地测试数据库及 `.env.example` 配置下，可在同一 shell 中启用：

```bash
export APP_ENV=testing ENABLE_WECHAT_LOGIN=true ENABLE_QQ_LOGIN=true
export ENABLE_DEV_SMS=true DEV_SMS_CODE=123456 SMS_CODE_PEPPER=e2e-only-sms-code-pepper
export WECHAT_APP_ID=e2e-wechat-app WECHAT_APP_SECRET=e2e-wechat-secret
export QQ_APP_ID=e2e-qq-app QQ_APP_KEY=e2e-qq-key QQ_REDIRECT_URI=http://e2e.test/callback
export SOCIAL_OAUTH_ALLOW_INSECURE_HTTP=true
export WECHAT_TOKEN_ENDPOINT=http://fake-oauth:8081/sns/oauth2/access_token
export QQ_TOKEN_ENDPOINT=http://fake-oauth:8081/oauth2.0/token
export QQ_OPEN_ID_ENDPOINT=http://fake-oauth:8081/oauth2.0/me
make up
docker compose --profile e2e up -d fake-oauth
make e2e
```

测试会创建用户/会话并修改测试用户状态，应只使用专用测试环境。endpoint 覆盖和 HTTP 开关在生产环境会被启动检查拒绝。仅运行社交部分：

```bash
make e2e-social
```

MySQL/Redis/RabbitMQ 集成测试需要配置 `SOCIAL_TEST_MYSQL_*`、`SOCIAL_TEST_REDIS_*` 和 `RABBITMQ_INTEGRATION=1` 等连接参数，MySQL 测试库名称必须以 `social_test_` 开头。`make test` 执行 Unit 套件（禁止 skipped）和 Go tests；准备好上述依赖后，使用 `make test-integration` 执行 Integration 套件（禁止 skipped）。也可使用 `vendor/bin/phpunit --fail-on-skipped` 验证全套实际执行；缺少基础设施产生的跳过不代表通过。CI 的 MySQL/Redis 和 RabbitMQ 测试在各自依赖就绪后分步执行，E2E 失败同样阻止 CI 通过。

---

## 9. 常用 Make 命令

| 命令 | 说明 |
| :--- | :--- |
| `make bootstrap` | 初始化依赖、生成 autoload 与配置 |
| `make up` | 编排启动全流程（镜像检查 -> 基础设施 -> 数据迁移 -> 业务服务） |
| `make infra-up` | 启动 MySQL、Redis、RabbitMQ 并导入拓扑 |
| `make broker-init` | 重新导入 RabbitMQ exchange、stream 和 quorum queue 拓扑 |
| `make storage-up` | 启动 RustFS（storage profile） |
| `make app-up` | 仅启动业务微服务与网关容器 |
| `make down` | 停止并移除容器 |
| `make lint` | 执行 PHP 语法校验与 Go vet |
| `make analyse` | 执行 PHPStan 静态分析 |
| `make test` | 执行全套单元测试 |
| `make e2e` | 执行端到端接口冒烟测试 |
| `make logs` | 查看所有容器运行日志 |
| `make migrate` | 使用 Phinx 接管历史 Schema 并应用待执行迁移 |
| `make clean` | 停止并清除本地数据卷 |

---

## 10. 服务端口映射

| 端口 | 协议 | 服务组件 |
| :--- | :--- | :--- |
| `8787` | HTTP | API Gateway (Webman REST API) |
| `8080` | HTTP/WS | Realtime Gateway (WebSocket :8080/ws) |
| `50051` | gRPC | AuthService (RPC) |
| `50052` | gRPC | UserService (RPC) |
| `3306` | MySQL | MySQL 8.4 数据库集群 |
| `6379` | Redis | Redis 7.2 缓存与即时状态 |
| `5672` | AMQP | RabbitMQ 任务与事件发布 |
| `5552` | Stream | RabbitMQ Streams 原生协议 |
| `15672` | HTTP | RabbitMQ Management |
| `9000` | S3 API | RustFS 对象存储 API（需 `--profile storage`） |
| `9001` | HTTP | RustFS 控制台（需 `--profile storage`） |

---

## 11. RabbitMQ 事件与任务规范

`domain.events` topic exchange 按事件类型路由到独立 stream：`user.events`、`feed.events`、`search.events`、`notification.events`、`chat.events`。每个 stream 保留 7 天；新消费者可从指定 offset 重放。当前每个 stream 为单分区，消费端需串行处理才能维持其中事件顺序。扩容为 Super Stream 时，publisher 必须用 aggregate ID 一致性分区，同一 aggregate 始终进入同一 partition；topic pattern 不负责 hash 分区。UserService 的 Outbox relay 已实现：publisher confirm 和 mandatory routing 成功后标记已发布，失败重试，事件 ID 用于消费端去重。其他服务的 relay 和消费端尚未实现。

`jobs` topic exchange 将 `image.resize`、`video.transcode`、`push.send`、`moderation.image.scan` 路由到同名 Quorum Queue；超过 delivery limit 的任务进入 `jobs.failed`。publisher 应使用 confirms，consumer 应使用手动 ack 与幂等处理。

事件类型与分区 key：
- 用户领域: `user.registered.v1`, `user.updated.v1`, `persona.updated.v1` (Key: `user_id`)
- 内容领域: `post.created.v1`, `post.approved.v1`, `post.deleted.v1`, `comment.created.v1` (Key: `post_id`)
- 社交领域: `like.created.v1`, `follow.created.v1`
- 聊天领域: `conversation.created.v1`, `message.created.v1`, `message.delivered.v1`, `message.read.v1` (Key: `conversation_id`)
- 审核与风控: `moderation.completed.v1`, `report.created.v1`

Outbox 的 `aggregate_id` 标识事件所属实体；`event_key` 单独保存流分区和用户维度顺序键。用户与 Persona 事件都以 `user_id` 为 `event_key`。`trace_id` 在写入 Outbox 的请求事务中持久化，relay 发布时直接读取；迁移前的历史事件无法恢复原请求 trace，保留空值。

---

## 12. Redis Key 规范

遵循 `{domain}:{purpose}:{id}` 格式：
- 用户缓存: `cache:user:{user_id}`, `cache:persona:{persona_id}`
- 在线状态: `presence:user:{user_id}`, `presence:gateway:{gateway_id}`
- 候选池: `discovery:new`, `discovery:hot`, `discovery:emotion:{emotion}`
- 计数器: `counter:post:{post_id}`
- 频率限制: `rate:user:{user_id}:{action}`, `rate:ip:{ip_hash}:{action}`
- 幂等锁定: `idempotency:{service}:{request_id}`

---

## 13. 故障排查 (Troubleshooting)

1. **容器未就绪 / MySQL 连接拒绝**:
   MySQL 容器首次初始化需载入数据表，可通过 `docker compose logs -f mysql` 观察健康检查状态，再执行 `make migrate` 应用待执行的 Phinx 迁移；首次接管会补齐未完成的历史 SQL 迁移。
2. **WebSocket 无法连接**:
   确认已先调用 `POST /api/v1/ws/ticket` 获取合法 Ticket；Ticket 有效期 60 秒且**单次使用**（同一 Ticket 二次握手会被网关拒绝），需在 60 秒内通过 `/ws?ticket=<ticket>` 建立连接。
3. **日志排查**:
   所有微服务输出严格的 JSON 日志，包含 `trace_id` 与 `request_id`，可通过 `docker compose logs -f <service_name>` 检索单个链路。
