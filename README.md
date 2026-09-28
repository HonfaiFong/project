# 匿名社交 App 后端平台 (Social Backend Monorepo)

本项目是一个面向弱关系社交、类似匿名树洞 / 漂流罐头 / 情绪社交产品的后端基础平台。支持从早期 MVP 平滑扩展至百万级在线连接与高并发读写。

---

## 1. 架构简介

- **外部入口**:
  - HTTP REST BFF: `apps/api-gateway` (Webman 2.x / PHP 8.4, 负责鉴权入口、参数校验、gRPC Client 聚合，**禁止直接访问业务数据库**)。
  - 实时连接 Gateway: `gateways/realtime-gateway` (Go 1.24 + WebSocket + Protobuf Envelope, 仅负责连接管理、心跳、Ticket 鉴权、消息 Push，**禁止业务 SQL 耦合**)。
- **内部核心微服务**:
  - 同步调用：全部基于 **gRPC**，携带 trace_id、request_id、user_id 与 deadline。
  - 异步通信：全部基于 **Kafka**，领域事件统一使用 Transactional Outbox 投递与消费者幂等消费。
- **数据源与基础设施**:
  - 关系型业务最终事实数据源：MySQL 8.4 (分逻辑库，无跨库 JOIN)。
  - 高速缓存与即时状态：Redis 7.2 (统一 Key 规范，设置明确 TTL，禁止 `KEYS *`)。
  - 消息引擎：Apache Kafka 3.8+ (KRaft 模式)。
  - 媒体对象存储：MinIO (S3 兼容，客户端直传 Presigned URL，不经后端流式转发)。

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
| `apps/api-gateway` | API Gateway (BFF) | Webman / PHP 8.4 | 对外 HTTP API 入口、JWT 认证、gRPC 客户端聚合 |
| `gateways/realtime-gateway` | Realtime Gateway | Go 1.24 / WebSocket | 实时长连接维持、心跳、多端连接管理、下行 Push |
| `services/auth-service` | AuthService | RoadRunner / PHP 8.4 | 账号认证、Token 签发 / 轮转、设备管理、WS Ticket |
| `services/user-service` | UserService | RoadRunner / PHP 8.4 | 用户主体、Persona 分身管理 (**User != Persona**) |
| `services/content-service` | ContentService | RoadRunner / PHP 8.4 | 帖子、图文媒体元数据、一级与回复评论 |
| `services/social-service` | SocialService | RoadRunner / PHP 8.4 | 点赞、分身关注、用户级双向拉黑 |
| `services/discovery-service` | DiscoveryService | RoadRunner / PHP 8.4 | 漂流罐头、情绪池、随机发现、曝光过滤 |
| `services/feed-service` | FeedService | RoadRunner / PHP 8.4 | 首页 Feed 规则召回、ZSET Timeline 聚合 |
| `services/recommend-service` | RecommendService | RoadRunner / PHP 8.4 | 用户兴趣画像、候选打分排序 |
| `services/chat-service` | ChatService | RoadRunner / PHP 8.4 | 私聊会话、会话成员、已读点、权限校验 |
| `services/message-service` | MessageService | RoadRunner / PHP 8.4 | 消息持久化、递增 seq、幂等去重、分片路由 |
| `services/risk-service` | RiskService | RoadRunner / PHP 8.4 | 同步风险决策 (ALLOW/DENY/CHALLENGE) |
| `services/moderation-service` | ModerationService | RoadRunner / PHP 8.4 | 内容敏感词与多媒体审核流转 |
| `services/notification-service` | NotificationService | RoadRunner / PHP 8.4 | 站内通知与离线推送通道 |
| `services/media-service` | MediaService | RoadRunner / PHP 8.4 | 预签名上传 URL 签发与对象元数据确认 |
| `services/search-service` | SearchService | RoadRunner / PHP 8.4 | OpenSearch 内容与标签检索 |
| `services/config-service` | ConfigService | RoadRunner / PHP 8.4 | 客户端 RouteProfile 与动态功能开关 |
| `apps/admin-api` | Admin API | Webman / PHP 8.4 | 运营后台、审核队列处理、封禁管理 |

---

## 3. 环境依赖

全部依赖均封装于 Docker 与 `docker-compose.yml` 中：
- **Docker Engine**: >= 24.0.0
- **Docker Compose**: >= v2.20.0
- (本地开发可选): PHP >= 8.2 (带 pdo_mysql, redis, bcmath), Go >= 1.22, Composer >= 2.0

---

## 4. 本地启动

```bash
# 1. 复制配置文件
cp .env.example .env

# 2. 一键启动所有基础依赖与微服务容器
docker compose up -d

# 3. 检查服务健康状态
curl http://127.0.0.1:8787/health
```

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

数据库初始化脚本位于 `infra/docker/mysql/init/01-databases.sql`，容器启动时自动挂载执行：
- `auth_db`: 存储 `devices`, `refresh_tokens`, `outbox_events`
- `user_db`: 存储 `users`, `personas`, `outbox_events`
- `content_db`: 存储 `posts`, `post_media`, `comments`, `outbox_events`
- `social_db`: 存储 `likes`, `follows`, `blocks`, `outbox_events`
- `chat_db`: 存储 `conversations`, `conversation_members`, `outbox_events`
- `message_db`: 存储 `messages`, `outbox_events` (预置 ShardRouter 槽位)

手动重新执行迁移：
```bash
make migrate
```

---

## 7. 测试与质量检查

```bash
# 运行单元测试（覆盖 Snowflake, ShardRouter, 错误码, 事件模型, 业务服务）
make test

# 运行代码静态检查与语法验证
make lint
```

---

## 8. 端到端 E2E 验证

运行完整的 Phase 1 业务流测试（注册/登录 -> 获取主账号资料 -> 创建分身 Persona -> 列出分身 -> 修改分身资料 -> 获取 WS Ticket -> 刷新 Token）：
```bash
make e2e
```

---

## 9. 常用 Make 命令

| 命令 | 说明 |
| :--- | :--- |
| `make bootstrap` | 初始化依赖、生成 autoload 与配置 |
| `make up` | 启动全部 Docker 容器 |
| `make down` | 停止并移除容器 |
| `make lint` | 执行 PHP 语法校验与 Go vet |
| `make test` | 执行全套单元测试 |
| `make e2e` | 执行端到端接口冒烟测试 |
| `make logs` | 查看所有容器运行日志 |
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
| `9092` | Kafka | Apache Kafka KRaft Broker |
| `9000` | S3 API | MinIO 对象存储 API |
| `9001` | HTTP | MinIO 控制台 Console |

---

## 11. Kafka Topics 规范

统一 Partition Key 与去重 Envelope：
- 用户领域: `user.registered.v1`, `user.updated.v1`, `persona.updated.v1` (Key: `user_id`)
- 内容领域: `post.created.v1`, `post.approved.v1`, `post.deleted.v1`, `comment.created.v1` (Key: `post_id`)
- 社交领域: `like.created.v1`, `follow.created.v1`
- 聊天领域: `conversation.created.v1`, `message.created.v1`, `message.delivered.v1`, `message.read.v1` (Key: `conversation_id`)
- 审核与风控: `moderation.completed.v1`, `report.created.v1`

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
   MySQL 容器首次初始化需载入数据表，可通过 `docker compose logs -f mysql` 观察健康检查状态，或执行 `make migrate` 重新校验。
2. **WebSocket 无法连接**:
   确认已先调用 `POST /api/v1/ws/ticket` 获取合法 Ticket，在 60 秒内通过 `/ws?ticket=<ticket>` 建立握手连接。
3. **日志排查**:
   所有微服务输出严格的 JSON 日志，包含 `trace_id` 与 `request_id`，可通过 `docker compose logs -f <service_name>` 检索单个链路。
