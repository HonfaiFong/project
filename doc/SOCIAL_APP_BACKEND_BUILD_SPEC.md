# 匿名社交 App 后端项目构建总规范

> **身份业务规则更新（2026-10-11）**：真身与匿名分身以 [真身与匿名分身业务规则](USER_PERSONA_BUSINESS_RULES.md) 为准。下文涉及多个固定公开分身、分身关注及其他冲突的身份设计仅作历史背景；当前 ALIAS 是内部匿名能力，不能作为可追踪的公开小号。

> **架构变更（2026-09-29）**：本文是早期构建草案，其中 Kafka、Kafka Topic/Partition/Consumer 和 MinIO 的设计已被当前仓库的 `README.md`、`compose.yaml` 与 `infra/docker/rabbitmq/definitions.json` 取代。现行部署使用 RabbitMQ Streams 承载可重放领域事件、Quorum Queues 承载任务，RustFS 承载 S3 兼容对象存储。下文相关旧段落仅保留作为历史背景，不应作为实施指令。

> 本文件用于直接交给 Coding Agent（Codex / Claude Code / Gemini CLI / Cursor Agent 等）构建项目。
>
> 项目目标：构建一套类似匿名树洞 / 漂流罐头 / 弱关系社交产品的后端基础平台，支持从早期 MVP 平滑扩展到百万级在线连接。
>
> **除非遇到真正阻塞实现的问题，否则不要反复向用户确认细节。优先按本文默认值落地，并把可变项放入配置。**

---

# 0. AI 执行规则

你现在是本项目的主程和架构执行者。

必须遵守：

1. 先扫描现有仓库；如果仓库为空，则从零初始化。
2. 先建立可运行的 Monorepo、公共协议、基础设施和 CI，再逐步实现业务。
3. 不允许只生成伪代码或空 Controller。
4. 每完成一个阶段：
   - 运行测试；
   - 运行静态检查；
   - 启动本地依赖；
   - 验证关键接口；
   - 修复失败后再进入下一阶段。
5. 禁止为了“微服务”而过度拆分。
6. 禁止一个服务直接读写其他服务的数据库。
7. 禁止 API Gateway 直接访问业务数据库。
8. 所有跨服务同步调用使用 gRPC。
9. 所有领域事件、异步业务使用 Kafka。
10. Redis 不是最终事实数据源。
11. 所有关键写请求必须支持幂等。
12. 所有关键业务写入必须考虑 Transactional Outbox。
13. 所有 Kafka Consumer 必须支持重复消费。
14. 所有 RPC 必须有 deadline。
15. 禁止无限自动重试。
16. 禁止在 PHP 常驻进程里保存请求级全局可变状态。
17. 禁止把业务 Model、Repository 在多个服务间共享。
18. 优先保证代码清晰、可测试、可观测，再做局部性能优化。
19. 所有服务必须提供 health/readiness。
20. 所有配置使用环境变量，不允许把密钥写进仓库。

---

# 1. 总体目标

系统需要支持：

- 主账号 User
- 对外身份 Persona / 分身
- 匿名树洞
- 漂流罐头 / 随机发现
- 图文帖子
- 评论
- 点赞
- 收藏
- 关注
- 拉黑
- 举报
- 私聊
- 在线状态
- 消息送达 / 已读
- Feed
- 推荐
- 内容审核
- 风控
- Push
- 搜索
- 媒体上传
- 运营配置
- 埋点分析

目标规模：

```text
MVP:
几千 ~ 几万 DAU

中期:
10万 ~ 100万 DAU

长期:
百万级 WebSocket 在线连接
千万级注册用户
```

注意：

```text
100万在线 WebSocket != 100万 HTTP QPS
```

架构必须支持水平扩展，但第一阶段基础设施按开发和 MVP 规模部署。

---

# 2. 技术栈基线

## 2.1 HTTP API / BFF

使用：

```text
PHP 8.4
Webman 2.x
Workerman 5.x
Composer 2
```

Webman 只负责：

- HTTP API
- 鉴权入口
- 参数校验
- REST API
- BFF 聚合
- gRPC Client
- Rate Limit
- Request Context
- Response Formatting

Webman 不直接访问业务数据库。

## 2.2 PHP 内部微服务

使用：

```text
PHP 8.4
RoadRunner
spiral/roadrunner-grpc
Protobuf
gRPC
```

PHP 微服务通过 RoadRunner 提供 gRPC Server。

禁止假设官方 PHP gRPC 扩展本身可以直接提供原生 gRPC Server。

## 2.3 实时连接 Gateway

目标实现：

```text
Go
WebSocket
gRPC
Protobuf
```

Gateway 仅负责：

- WebSocket accept
- Ticket 鉴权
- heartbeat
- connection registry
- encode/decode
- push
- disconnect
- connection drain

Gateway 不负责：

- 业务 SQL
- Feed
- 推荐
- 内容审核
- 复杂风控
- 业务事务

第一阶段如果尚未实现完整 Go Gateway，可以先创建协议、接口、Mock 和基础可运行 Gateway，但不能把消息核心业务写死在 Gateway 内。

## 2.4 数据基础设施

生产目标：

```text
MySQL 8.4
Redis 7.x+
Kafka 4.x compatible
OpenSearch
ClickHouse
S3 Compatible Object Storage
```

开发环境：

```text
1 MySQL instance
1 Redis instance
1 Kafka broker (KRaft)
MinIO
```

多个逻辑数据库可以共用一个开发 MySQL 实例。

## 2.5 基础设施

```text
Docker
Docker Compose
Kubernetes
Prometheus
Grafana
OpenTelemetry
Loki
Tempo
```

第一阶段必须优先完成 Docker Compose 本地开发体验。

Kubernetes/Helm 在后续阶段补全。

---

# 3. 外部网络架构

线上计划：

```text
Global HTTP
    -> Cloudflare
    -> Hong Kong API LB
    -> Webman

China HTTP
    -> EdgeOne
    -> Hong Kong API LB
    -> Webman

Global WebSocket
    -> Cloudflare
    -> Hong Kong WS LB
    -> Realtime Gateway

China WebSocket
    -> Hong Kong L4 LB
    -> Realtime Gateway
```

域名规范：

```text
api-global.example.com
api-cn.example.com

ws-global.example.com
ws-cn.example.com

media-global.example.com
media-cn.example.com

admin.example.com
```

代码中不允许硬编码 Cloudflare / EdgeOne。

客户端只接收 RouteProfile：

```json
{
  "api_base": "https://api-global.example.com",
  "media_base": "https://media-global.example.com",
  "ws_base": "wss://ws-global.example.com"
}
```

后续客户端可根据测速在 CN / GLOBAL Profile 间切换。

---

# 4. 总体内部架构

```text
                   Client
                     |
            +--------+--------+
            |                 |
           HTTP              WSS
            |                 |
            v                 v
       Webman API       Realtime Gateway
            |                 |
            +-------gRPC------+
                     |
        +------------+-------------+
        |            |             |
       User        Content        Chat
      Persona      Social        Message
        |            |             |
        +------------+-------------+
                     |
        +------------+-------------+
        |            |             |
       Feed       Discovery       Risk
        |        Recommendation  Moderation
        |            |             |
        +------------+-------------+
                     |
                    Kafka
                     |
        +------------+-------------+
        |            |             |
      Search     Notification   Analytics
```

---

# 5. Monorepo 目录

创建如下结构：

```text
social-backend/
├── apps/
│   ├── api-gateway/
│   └── admin-api/
│
├── gateways/
│   └── realtime-gateway/
│
├── services/
│   ├── auth-service/
│   ├── user-service/
│   ├── content-service/
│   ├── social-service/
│   ├── discovery-service/
│   ├── feed-service/
│   ├── recommend-service/
│   ├── chat-service/
│   ├── message-service/
│   ├── risk-service/
│   ├── moderation-service/
│   ├── notification-service/
│   ├── media-service/
│   ├── search-service/
│   └── config-service/
│
├── contracts/
│   ├── proto/
│   ├── events/
│   └── errors/
│
├── packages/
│   ├── php/
│   │   ├── logging/
│   │   ├── tracing/
│   │   ├── grpc-common/
│   │   ├── id-generator/
│   │   └── outbox/
│   └── go/
│       ├── logging/
│       ├── tracing/
│       └── protocol/
│
├── infra/
│   ├── docker/
│   ├── compose/
│   ├── kubernetes/
│   ├── helm/
│   ├── prometheus/
│   └── grafana/
│
├── scripts/
├── docs/
├── Makefile
├── compose.yaml
├── .env.example
└── README.md
```

---

# 6. 服务边界

## 6.1 AuthService

负责：

- 微信 / QQ OAuth 登录与注册（默认登录方式）
- 已登录用户绑定第三方身份（BindSocialIdentity）
- 手机号验证码登录（仅 local / development / testing，生产禁用）
- Access Token
- Refresh Token
- Device
- Session
- WS Ticket
- Logout
- Token revoke

约束：

- 社交登录直接返回 `authenticated` 会话：首次登录即创建本地 User，不再有手机号绑定步骤，UserService 通过 `FindOrCreateBySocialIdentity(registration_key, register_channel)` 幂等开户；
- 认证链路不依赖短信通道，只有开发环境的手机号登录使用固定开发验证码；
- 绑定第三方身份时，被绑定的用户来自 Access Token，不信任请求体中的 user_id；
- 旧的无凭据手机号绑定 RPC（`SendSocialPhoneCode` / `BindSocialPhone`）仅为协议兼容保留，调用一律拒绝。

拥有：

```text
auth_db
```

禁止 UserService 直接写 auth_db。

---

## 6.2 UserService

负责：

- User 主账号
- Persona 分身
- 用户资料
- Persona 展示资料
- 账号状态
- Persona 状态

拥有：

```text
user_db
```

关键原则：

```text
User != Persona
```

所有公开内容绑定：

```text
persona_id
```

平台内部通过：

```text
persona.user_id
```

关联真实账号。

---

## 6.3 ContentService

负责：

- Post
- PostMedia metadata
- Comment
- Topic
- Tag
- 内容状态
- 内容可见性

拥有：

```text
content_db
```

---

## 6.4 SocialService

负责：

- Like
- Follow
- Favorite
- Block
- 社交关系状态

拥有：

```text
social_db
```

---

## 6.5 DiscoveryService

负责一罐类核心玩法：

- 漂流罐头
- 随机发现
- 陌生内容召回
- 情绪池
- Topic Pool
- 去重
- 随机扰动
- 用户历史已看过滤

核心 Redis Pool：

```text
discovery:new
discovery:hot
discovery:emotion:{emotion}
discovery:topic:{topic_id}
```

---

## 6.6 FeedService

负责：

- 首页 Feed
- Timeline
- Feed Cursor
- Feed Redis
- Read Model
- Candidate merge

正常查询优先读取 Redis，而不是实时对 MySQL 大排序。

---

## 6.7 RecommendService

负责：

- 用户兴趣画像
- Candidate Recall
- Rank
- Feature
- Rule-based ranking
- 后续 ML 接入

初期使用规则推荐，不需要一开始做机器学习。

---

## 6.8 ChatService

负责：

- Conversation
- ConversationMember
- 私聊权限
- 会话建立
- 会话设置
- 免打扰
- 拉黑校验

拥有：

```text
chat_db
```

---

## 6.9 MessageService

负责：

- message_id
- conversation seq
- 消息持久化
- 幂等
- 消息同步
- Message Outbox
- ACK 状态

拥有：

```text
message_db
```

这是未来最优先支持 Sharding 的服务。

---

## 6.10 RiskService

负责同步快速判断：

```text
ALLOW
DENY
CHALLENGE
REVIEW
```

判断场景：

- 登录
- 注册
- 发帖
- 评论
- 发消息
- 关注
- 创建 Persona

同步路径优先：

```text
Memory
Redis
```

复杂分析通过 Kafka 异步处理。

---

## 6.11 ModerationService

负责：

- 文本审核
- 图片审核状态
- 视频审核状态
- 敏感词
- 人工审核队列
- PASS / REVIEW / REJECT

审核通过后发布：

```text
post.approved
```

---

## 6.12 NotificationService

负责：

- App 内通知
- APNs
- FCM
- 离线 Push
- 通知偏好

---

## 6.13 MediaService

负责：

- Presigned Upload URL
- object_key
- Media metadata
- MIME / size 验证
- 上传授权

文件内容不经过 PHP API Gateway。

---

## 6.14 SearchService

负责：

- OpenSearch 查询
- Post 搜索
- Persona 搜索
- Topic 搜索

索引数据通过 Kafka 构建。

---

# 7. 服务通信规则

## 7.1 gRPC

用于：

```text
现在必须马上知道结果
```

例如：

```text
GetUser
BatchGetPersonas
GetPost
CheckPermission
CreateConversation
SendMessage
CheckRisk
GetFeed
```

## 7.2 Kafka

用于：

```text
某件事情已经发生
```

例如：

```text
user.registered
user.updated
persona.updated
post.created
post.approved
post.deleted
comment.created
like.created
follow.created
conversation.created
message.created
message.delivered
message.read
report.created
moderation.completed
notification.requested
analytics.event
```

禁止：

```text
Kafka Request -> Wait Kafka Response
```

来模拟 RPC。

---

# 8. RPC 规则

所有 RPC：

- 必须设置 deadline；
- 必须传播 trace_id；
- 必须传播 request_id；
- 写请求必须支持 idempotency；
- 不允许无限 retry；
- Batch 优先；
- 同步调用深度尽量 <= 3；
- 不允许 N+1 RPC。

统一 metadata：

```text
x-request-id
x-trace-id
x-user-id
x-persona-id
x-device-id
x-app-version
authorization
```

初始 deadline 建议：

```text
Memory/Redis query       50 ms
Simple DB query         100 ms
Risk                    100 ms
Normal RPC              150 ms
Feed                    250 ms
Complex BFF             300 ms
```

这里只是默认值，后续按 P95 / P99 调整。

---

# 9. ID 规则

外部核心实体：

```text
BIGINT UNSIGNED
```

ID 使用 Snowflake：

```text
user_id
persona_id
post_id
comment_id
conversation_id
message_id
notification_id
```

要求：

- 分布式生成
- 趋势递增
- 不依赖 MySQL AUTO_INCREMENT
- worker_id 可配置
- 不允许不同实例冲突

客户端请求幂等 ID 使用：

```text
UUIDv7 / ULID
```

例如：

```text
client_message_id
request_id
```

---

# 10. 数据库设计原则

- 一个服务拥有自己的逻辑数据库；
- 禁止跨库业务 JOIN；
- 数据库 migration 属于对应服务；
- 开发环境可以一个 MySQL 物理实例承载多个逻辑 DB；
- 生产按负载逐步拆；
- 默认使用 InnoDB；
- 字符集 utf8mb4；
- 时间统一存 UTC；
- 金额使用整数最小货币单位；
- JSON 只用于非强查询扩展字段；
- 高频查询必须建立明确索引；
- 不允许把大文本或媒体二进制存 MySQL。

---

# 11. 第一阶段核心表

## 11.1 auth_db.devices

```text
id BIGINT UNSIGNED PK
user_id BIGINT UNSIGNED
device_uuid VARCHAR(64)
platform VARCHAR(16)
app_version VARCHAR(32)
push_token VARCHAR(255) NULL
last_ip VARBINARY(16) NULL
last_active_at DATETIME(3)
created_at DATETIME(3)
updated_at DATETIME(3)

UNIQUE(user_id, device_uuid)
INDEX(user_id)
```

## 11.2 auth_db.refresh_tokens

```text
id BIGINT UNSIGNED PK
user_id BIGINT UNSIGNED
device_id BIGINT UNSIGNED
token_hash CHAR(64)
expires_at DATETIME(3)
revoked_at DATETIME(6) NULL
logged_out_at DATETIME(6) NULL          # 设备注销时间；轮换消费仅设置 revoked_at
created_at DATETIME(3)

UNIQUE(token_hash)
INDEX(user_id, device_id)
INDEX(expires_at)
```

---

## 11.3 user_db.users

```text
id BIGINT UNSIGNED PK
status SMALLINT NOT NULL
phone VARCHAR(32) NULL
email VARCHAR(191) NULL
register_channel VARCHAR(32)
risk_level SMALLINT DEFAULT 0
last_login_at DATETIME(3) NULL
last_active_at DATETIME(3) NULL
created_at DATETIME(3)
updated_at DATETIME(3)
deleted_at DATETIME(3) NULL

UNIQUE(phone)
UNIQUE(email)
INDEX(status)
INDEX(created_at)
```

---

## 11.4 user_db.personas

```text
id BIGINT UNSIGNED PK
user_id BIGINT UNSIGNED
type SMALLINT NOT NULL
nickname VARCHAR(64)
avatar_key VARCHAR(255) NULL
bio VARCHAR(500) NULL
status SMALLINT NOT NULL
created_at DATETIME(3)
updated_at DATETIME(3)
deleted_at DATETIME(3) NULL

INDEX(user_id, status)
INDEX(created_at)
```

规则：

```text
User 可以有多个 Persona
Post/Comment/Message 对外只暴露 persona_id
```

---

## 11.5 content_db.posts

```text
id BIGINT UNSIGNED PK
persona_id BIGINT UNSIGNED
type SMALLINT NOT NULL
content TEXT NULL
visibility SMALLINT NOT NULL
emotion VARCHAR(32) NULL
status SMALLINT NOT NULL
moderation_status SMALLINT NOT NULL
like_count BIGINT UNSIGNED DEFAULT 0
comment_count BIGINT UNSIGNED DEFAULT 0
view_count BIGINT UNSIGNED DEFAULT 0
published_at DATETIME(3) NULL
created_at DATETIME(3)
updated_at DATETIME(3)
deleted_at DATETIME(3) NULL

INDEX(persona_id, created_at)
INDEX(status, published_at)
INDEX(moderation_status, created_at)
```

---

## 11.6 content_db.post_media

```text
id BIGINT UNSIGNED PK
post_id BIGINT UNSIGNED
type SMALLINT
object_key VARCHAR(512)
mime_type VARCHAR(128)
width INT NULL
height INT NULL
duration_ms INT NULL
sort_order INT DEFAULT 0
created_at DATETIME(3)

INDEX(post_id, sort_order)
```

数据库保存：

```text
object_key
```

禁止保存完整 CDN URL。

---

## 11.7 content_db.comments

```text
id BIGINT UNSIGNED PK
post_id BIGINT UNSIGNED
persona_id BIGINT UNSIGNED
parent_id BIGINT UNSIGNED NULL
reply_to_comment_id BIGINT UNSIGNED NULL
content VARCHAR(2000)
status SMALLINT
like_count BIGINT UNSIGNED DEFAULT 0
created_at DATETIME(3)
updated_at DATETIME(3)
deleted_at DATETIME(3) NULL

INDEX(post_id, created_at)
INDEX(parent_id, created_at)
INDEX(persona_id, created_at)
```

只支持：

```text
一级评论 + 回复
```

不做无限层级树。

---

## 11.8 social_db.likes

```text
id BIGINT UNSIGNED PK
persona_id BIGINT UNSIGNED
target_type SMALLINT
target_id BIGINT UNSIGNED
created_at DATETIME(3)

UNIQUE(persona_id, target_type, target_id)
INDEX(target_type, target_id, created_at)
```

---

## 11.9 social_db.follows

```text
id BIGINT UNSIGNED PK
follower_persona_id BIGINT UNSIGNED
followee_persona_id BIGINT UNSIGNED
created_at DATETIME(3)

UNIQUE(follower_persona_id, followee_persona_id)
INDEX(followee_persona_id, created_at)
```

---

## 11.10 social_db.blocks

```text
id BIGINT UNSIGNED PK
blocker_user_id BIGINT UNSIGNED
blocked_user_id BIGINT UNSIGNED
created_at DATETIME(3)

UNIQUE(blocker_user_id, blocked_user_id)
```

平台安全边界使用 user_id，避免通过切换 Persona 绕过拉黑。

---

## 11.11 chat_db.conversations

```text
id BIGINT UNSIGNED PK
type SMALLINT
last_message_id BIGINT UNSIGNED NULL
last_message_at DATETIME(3) NULL
created_at DATETIME(3)
updated_at DATETIME(3)

INDEX(last_message_at)
```

---

## 11.12 chat_db.conversation_members

```text
id BIGINT UNSIGNED PK
conversation_id BIGINT UNSIGNED
user_id BIGINT UNSIGNED
persona_id BIGINT UNSIGNED
last_read_seq BIGINT UNSIGNED DEFAULT 0
joined_at DATETIME(3)
left_at DATETIME(3) NULL
mute_until DATETIME(3) NULL

UNIQUE(conversation_id, user_id)
INDEX(user_id, conversation_id)
```

---

## 11.13 message_db.messages

```text
message_id BIGINT UNSIGNED PK
conversation_id BIGINT UNSIGNED
seq BIGINT UNSIGNED
sender_user_id BIGINT UNSIGNED
sender_persona_id BIGINT UNSIGNED
client_message_id VARCHAR(36)
type SMALLINT
content JSON
created_at DATETIME(3)

UNIQUE(conversation_id, seq)
UNIQUE(sender_user_id, client_message_id)
INDEX(conversation_id, message_id)
INDEX(sender_user_id, created_at)
```

其中：

```text
conversation_id
```

是未来 Sharding Key。

---

## 11.14 每个需要可靠事件的服务都有 outbox_events

```text
id BIGINT UNSIGNED PK
event_id VARCHAR(36)
aggregate_type VARCHAR(64)
aggregate_id VARCHAR(64)
event_type VARCHAR(128)
event_version INT
payload JSON
status SMALLINT
available_at DATETIME(3)
published_at DATETIME(3) NULL
created_at DATETIME(3)

UNIQUE(event_id)
INDEX(status, available_at)
INDEX(aggregate_type, aggregate_id)
```

---

# 12. Message Logical Sharding

从第一天实现 ShardRouter。

不要第一天真的部署 256 张/套物理库。

定义：

```text
logical_slots = 256
slot = hash(conversation_id) % 256
```

初期：

```text
0..255 -> message_mysql_default
```

后期可以配置：

```text
0..63    -> cluster_a
64..127  -> cluster_b
128..191 -> cluster_c
192..255 -> cluster_d
```

业务层永远只调用：

```text
MessageShardRouter
```

禁止散落 `% 256` 到业务代码中。

---

# 13. Proto 目录

创建：

```text
contracts/proto/
├── common/v1/
│   ├── pagination.proto
│   ├── error.proto
│   ├── metadata.proto
│   └── health.proto
│
├── auth/v1/auth.proto
├── user/v1/user.proto
├── user/v1/persona.proto
├── content/v1/content.proto
├── social/v1/social.proto
├── discovery/v1/discovery.proto
├── feed/v1/feed.proto
├── chat/v1/chat.proto
├── message/v1/message.proto
├── risk/v1/risk.proto
├── moderation/v1/moderation.proto
├── notification/v1/notification.proto
└── media/v1/media.proto
```

使用 Buf：

```text
buf lint
buf breaking
buf generate
```

已发布字段编号禁止复用。

删除字段时：

```proto
reserved 3;
reserved "old_field";
```

---

# 14. 首批 gRPC 方法

## AuthService

```text
Login
RefreshToken
Logout
VerifyToken
CreateWsTicket
```

## UserService

```text
GetUser
BatchGetUsers
UpdateUser
CreatePersona
GetPersona
BatchGetPersonas
UpdatePersona
ListPersonas
```

## ContentService

```text
CreatePost
GetPost
BatchGetPosts
DeletePost
CreateComment
ListComments
```

## SocialService

```text
Like
Unlike
Follow
Unfollow
Block
Unblock
BatchGetInteractionState
```

## DiscoveryService

```text
GetRandomCapsule
AcknowledgeExposure
```

## FeedService

```text
GetFeed
```

## ChatService

```text
GetOrCreatePrivateConversation
GetConversation
ListConversations
CheckCanMessage
MarkRead
```

## MessageService

```text
SendMessage
SyncMessages
GetMessages
```

## RiskService

```text
CheckAction
```

## ModerationService

```text
SubmitContent
GetModerationResult
```

## MediaService

```text
CreateUploadSession
CompleteUpload
```

---

# 15. Kafka Topic

第一阶段创建：

```text
user.registered.v1
user.updated.v1
persona.updated.v1

post.created.v1
post.approved.v1
post.deleted.v1
comment.created.v1

like.created.v1
follow.created.v1

conversation.created.v1

message.created.v1
message.delivered.v1
message.read.v1

report.created.v1
moderation.completed.v1

notification.requested.v1

analytics.event.v1
```

Partition Key：

```text
user event       -> user_id
persona event    -> user_id
post event       -> post_id
message event    -> conversation_id
```

事件 Envelope：

```json
{
  "event_id": "uuidv7",
  "event_type": "message.created.v1",
  "event_version": 1,
  "occurred_at": "UTC ISO8601",
  "trace_id": "...",
  "producer": "message-service",
  "key": "conversation_id",
  "payload": {}
}
```

Consumer 必须基于：

```text
event_id
```

实现去重。

---

# 16. Redis Key 规范

统一格式：

```text
{domain}:{purpose}:{id}
```

示例：

```text
cache:user:{user_id}
cache:persona:{persona_id}
cache:post:{post_id}

presence:user:{user_id}
presence:gateway:{gateway_id}

feed:user:{user_id}

counter:post:{post_id}

rate:user:{user_id}:{action}
rate:ip:{ip_hash}:{action}

idempotency:{service}:{request_id}

discovery:new
discovery:hot
discovery:emotion:{emotion}
discovery:topic:{topic_id}

config:feature:{key}
```

禁止业务代码随意创建没有规范的新 Key。

所有 Redis Key：

- 明确 TTL；
- 没有 TTL 的必须写明原因；
- 大集合必须控制容量；
- 禁止使用 `KEYS *`；
- 生产扫描使用 SCAN；
- 防止 hot key。

---

# 17. WebSocket 协议

使用：

```text
WebSocket + Protobuf
```

定义统一 Envelope：

```proto
message Envelope {
  uint32 version = 1;
  string request_id = 2;
  uint64 sequence = 3;
  int64 timestamp_ms = 4;
  string type = 5;
  bytes payload = 6;
}
```

核心事件：

```text
AUTH
AUTH_OK

PING
PONG

MESSAGE_SEND
MESSAGE_ACK
MESSAGE_PUSH

MESSAGE_DELIVERED
MESSAGE_READ

TYPING

ERROR
```

发送消息流程：

```text
Client
 -> Gateway
 -> gRPC MessageService.SendMessage
 -> DB + Outbox
 -> ACK Client
 -> Kafka message.created
 -> Delivery Consumer
 -> Presence Lookup
 -> Gateway Push
 -> Receiver
```

---

# 18. WS Ticket

客户端连接 WebSocket 前：

```text
POST /api/v1/ws/ticket
```

Webman -> AuthService 创建短期 Ticket。

Ticket 内容：

```text
user_id
persona_id
device_id
nonce
expire_at
```

有效期：

```text
30 ~ 60 秒
```

Gateway 本地验证签名。

不要每次连接都同步调用 AuthService。

---

# 19. Presence

Redis 示例：

```text
presence:user:{user_id}
```

允许多设备：

```json
[
  {
    "gateway_id": "gw-01",
    "connection_id": "abc",
    "device_id": 1001,
    "platform": "ios",
    "last_heartbeat_ms": 0
  }
]
```

Gateway Registry：

```text
presence:gateway:{gateway_id}
```

包含：

```text
grpc_address
status
connections
updated_at
```

---

# 20. Heartbeat

默认：

```text
50 ~ 80 秒随机间隔
```

不能所有用户固定 60 秒同时发送。

客户端加入 jitter。

连接过期规则需要配置化。

---

# 21. HTTP API

统一前缀：

```text
/api/v1
```

注销请求必须携带属于同一用户、同一设备的 Bearer 和 Refresh Token。Refresh Token 不存在时返回认证错误；历史 Refresh Token 不得搭配首次撤销时间之后签发的 Bearer 注销新会话。

注销先在 MySQL 中原子记录首次撤销时间，再写入 Redis。Redis 部分失败返回 503；客户端可在原 Bearer 尚未过期时重试同一请求，即使该 Bearer 已被设备标记撤销，AuthService 仍会校验签名、用户、设备和签发时间。重试复用首次截止时间，仅补写原 Access Token 生存期内的撤销标记，不延长恢复窗口。设备截止时间通过 Redis Lua 原子取最大值，避免旧请求覆盖新注销。

部署此行为前需先运行 `make migrate`，应用 `20261018000000_preserve_refresh_revocation_precision`。已有记录丢失的小数精度无法重建，不得通过推迟历史截止时间补偿。

Logout 定义为退出当前用户的整个设备：有效 Refresh Token 在设备行锁内撤销该设备全部未撤销 Refresh Token，Refresh 轮换与新登录创建也使用同一把锁。`revoked_at` 表示不可再刷新，`logged_out_at` 仅表示设备注销；已轮换或已过期的旧 Refresh Token 请求注销返回 401，客户端必须使用当前令牌。已注销令牌仅能恢复原注销截止时间，不能影响后续重新登录。

部署前先执行 `make migrate`，应用 `20261018000100_separate_refresh_logout_state`。历史 `revoked_at` 无法区分轮换和注销，迁移不会将其推断为注销记录，旧记录不能使用重试通道。

`AUTH_ACCESS_TOKEN_REVOCATION_TTL` 默认 7200 秒，保留窗口取它与当前签发 TTL 的较大值。缩短签发 TTL 前，该配置须至少覆盖所有仍可能有效的历史 Access Token 的最大 TTL；直到这些令牌全部过期后才可降低。

`/auth/logout` 在认证前使用独立 Redis IP 与 IP/设备限流桶，默认每分钟 60/20 次，分别由 `AUTH_LOGOUT_RATE_LIMIT`、`AUTH_LOGOUT_RATE_DEVICE_LIMIT`、`AUTH_LOGOUT_RATE_WINDOW_SECONDS` 配置。设备头仅用于收紧 IP 限制，不授予权限。监控 `auth_logout_recovery_total{status="200|401|503|error"}`，观察被普通 VerifyToken 拒绝后进入注销通道的恢复结果。

首次微信/QQ 注册自动创建 MAIN Persona，公共 API 不提供 Persona 创建入口。

初期至少实现：

```text
POST /api/v1/auth/login                # 开发环境手机号登录
POST /api/v1/auth/social/login         # 微信 / QQ 登录与注册，首次创建账号返回 201
POST /api/v1/auth/social/bind          # Bearer JWT + provider/code，绑定第三方身份
POST /api/v1/auth/refresh
POST /api/v1/auth/logout

GET  /api/v1/me

GET  /api/v1/personas
GET  /api/v1/personas/{id}
PATCH /api/v1/personas/{id}

POST /api/v1/posts
GET  /api/v1/posts/{id}
DELETE /api/v1/posts/{id}

GET  /api/v1/feed

POST /api/v1/posts/{id}/like
DELETE /api/v1/posts/{id}/like

GET  /api/v1/posts/{id}/comments
POST /api/v1/posts/{id}/comments

GET  /api/v1/discovery/capsule

GET  /api/v1/conversations
POST /api/v1/conversations/private
GET  /api/v1/conversations/{id}/messages

POST /api/v1/ws/ticket

POST /api/v1/media/upload-session

POST /api/v1/reports
```

API Gateway 只调用 gRPC，不直接查数据库。

---

# 22. HTTP Response 格式

成功：

```json
{
  "code": 0,
  "message": "ok",
  "request_id": "uuidv7",
  "data": {}
}
```

失败：

```json
{
  "code": 1001001,
  "message": "invalid request",
  "request_id": "uuidv7",
  "details": {}
}
```

HTTP 状态码仍需正确使用：

```text
200
201
204
400
401
403
404
409
422
429
500
503
```

不要永远返回 HTTP 200。

---

# 23. 错误码

建立：

```text
contracts/errors/
```

错误码按域分段：

```text
100xxxx Auth
200xxxx User
300xxxx Content
400xxxx Social
500xxxx Chat
600xxxx Message
700xxxx Risk
800xxxx Moderation
900xxxx Infrastructure
```

禁止各服务随意重复定义相同数字。

---

# 24. 幂等

所有关键写操作接收：

```text
X-Request-ID
```

或 request message 内：

```text
request_id
```

例如：

```text
CreatePost
CreateComment
SendMessage
CreateConversation
```

SendMessage 还需要：

```text
client_message_id
```

DB：

```text
UNIQUE(sender_user_id, client_message_id)
```

---

# 25. Transactional Outbox

关键业务：

```text
BEGIN

INSERT business_record

INSERT outbox_events

COMMIT
```

Outbox Relay：

1. SELECT 待发布事件；
2. 锁定 / claim；
3. Produce Kafka；
4. 成功后标记 published；
5. 失败按退避重试；
6. 达到阈值进入 dead-letter / manual inspection。

不能：

```text
INSERT DB
COMMIT
produce Kafka
```

而没有恢复机制。

---

# 26. Feed 第一版

不要一开始上复杂 ML。

实现规则召回：

```text
new pool
hot pool
topic pool
emotion pool
follow pool
```

Merge 后：

- block filter
- self-content rule
- already-exposed filter
- moderation filter
- freshness score
- interest score
- random exploration

Redis：

```text
feed:user:{user_id}
```

使用 ZSET。

Feed 返回 cursor，不使用 page number。

---

# 27. Discovery / 罐头

第一版实现：

```text
POST 创建内容
 -> moderation
 -> post.approved
 -> Kafka
 -> DiscoveryConsumer
 -> 加入候选池
```

用户捞罐头：

```text
DiscoveryService.GetRandomCapsule
```

规则：

- 过滤自己；
- 过滤拉黑双方；
- 过滤已看；
- 过滤不允许内容；
- 优先兴趣 / 情绪池；
- 保留一定随机探索比例；
- 返回后记录 exposure。

---

# 28. 内容审核

第一阶段定义统一接口，不绑定单一供应商。

```text
ModerationProviderInterface
```

实现：

```text
LocalRuleProvider
RemoteProvider (placeholder/adaptor)
```

状态：

```text
PENDING
PASS
REVIEW
REJECT
```

公共推荐必须要求：

```text
PASS
```

举报和人工审核单独记录。

---

# 29. 风控

同步 CheckAction 输入：

```text
user_id
persona_id
device_id
ip_hash
action
target_type
target_id
context
```

输出：

```text
ALLOW
DENY
CHALLENGE
REVIEW
```

第一阶段规则：

- 注册频率
- 登录失败频率
- 发帖频率
- 评论频率
- 私聊频率
- 发送 URL 频率
- 被举报次数
- 设备多账号
- IP 多账号

阈值必须配置化。

---

# 30. Media

流程：

```text
Client
 -> API
 -> MediaService.CreateUploadSession
 -> Presigned URL
 -> Client uploads directly to MinIO/S3
 -> CompleteUpload
```

本地开发使用 MinIO。

DB 只保存：

```text
object_key
mime_type
size
metadata
```

不让 PHP 接收大文件流。

---

# 31. Logging

统一 JSON Log：

```json
{
  "timestamp": "",
  "level": "INFO",
  "service": "content-service",
  "instance": "",
  "trace_id": "",
  "request_id": "",
  "user_id": "",
  "message": "",
  "context": {}
}
```

禁止日志打印：

- password
- access_token
- refresh_token
- WS ticket
- 完整手机号
- 完整邮箱
- 私聊明文内容（默认）
- 隐私密钥

---

# 32. Tracing

使用 OpenTelemetry。

必须传播：

```text
traceparent
trace_id
request_id
```

覆盖：

```text
HTTP
gRPC
Kafka producer
Kafka consumer
MySQL
Redis
```

第一阶段至少保证 Request ID 全链路可追踪。

---

# 33. Metrics

所有服务至少暴露：

```text
requests_total
request_duration_seconds

grpc_requests_total
grpc_duration_seconds

db_query_duration_seconds
redis_operation_duration_seconds

kafka_produce_total
kafka_consume_total
kafka_consumer_lag

errors_total
```

Gateway：

```text
active_connections
connections_total
disconnects_total
reconnects_total
messages_in_total
messages_out_total
push_latency
```

---

# 34. Security

必须：

- 密码使用 Argon2id；
- Token 只存 hash（适用时）；
- Refresh Token 支持 revoke；
- 服务端校验 Persona 归属；
- API Rate Limit；
- gRPC 内网访问；
- Redis/MySQL/Kafka 不开放公网；
- 上传文件校验 MIME/size；
- SQL 参数化；
- 统一输入验证；
- CORS 配置化；
- 管理后台 RBAC；
- 管理后台 MFA 预留；
- Audit Log；
- Sensitive config 使用 secret/environment。

生产后续使用：

```text
mTLS
```

保护内部 RPC。

第一阶段本地开发允许 insecure gRPC，但配置必须明确区分 DEV / PROD。

---

# 35. Docker Compose 开发环境

`compose.yaml` 至少包含：

```text
mysql
redis
kafka
minio
```

可选 profile：

```text
opensearch
clickhouse
prometheus
grafana
tempo
loki
```

还要能够启动：

```text
api-gateway
auth-service
user-service
content-service
social-service
chat-service
message-service
```

要求：

```bash
docker compose up -d
```

后能运行基本 E2E。

提供：

```text
.env.example
```

以及初始化脚本。

---

# 36. Makefile

至少：

```text
make bootstrap
make proto
make lint
make test
make up
make down
make migrate
make seed
make e2e
make logs
```

确保新开发者：

```text
git clone
cp .env.example .env
make bootstrap
make up
make migrate
make e2e
```

即可跑起来。

---

# 37. 测试

PHP：

```text
PHPUnit / Pest（二选一，统一即可）
```

Go：

```text
go test ./...
```

必须包含：

## Unit

- Snowflake
- ShardRouter
- Idempotency
- Outbox
- Risk rules
- Feed ranking
- Discovery filter

## Integration

- MySQL
- Redis
- Kafka
- gRPC

## Contract

- Proto breaking check
- Event schema compatibility

## E2E

至少验证：

```text
Login
 -> Create Persona
 -> Create Post
 -> Approve Post (dev mock)
 -> Get Feed
 -> Like
 -> Create Conversation
 -> Send Message
 -> Sync Message
```

---

# 38. Static Analysis / Quality

PHP：

```text
PHPStan
PHP-CS-Fixer / Pint-like formatter
Composer audit
```

Go：

```text
gofmt
go vet
golangci-lint
```

Proto：

```text
buf lint
buf breaking
```

---

# 39. CI

GitHub Actions：

PR：

```text
lint
static analysis
unit test
proto lint
proto breaking
build
```

main：

```text
all checks
build Docker images
security scan
```

Docker image 使用：

```text
git sha
```

作为不可变 tag。

---

# 40. 配置管理

环境变量命名统一：

```text
APP_ENV
APP_NAME
SERVICE_NAME
SERVICE_INSTANCE_ID

MYSQL_DSN
REDIS_DSN
KAFKA_BROKERS

GRPC_USER_SERVICE
GRPC_CONTENT_SERVICE

OTEL_EXPORTER_OTLP_ENDPOINT

JWT_PRIVATE_KEY
JWT_PUBLIC_KEY
```

不要让不同服务使用完全不同风格的变量名。

---

# 41. 第一阶段不做的事情

避免 AI 过度工程：

第一阶段不要实现：

- Istio 全套 Service Mesh
- 跨地域多活数据库
- 真正 256 物理分片
- 复杂 ML 推荐模型
- Elasticsearch/OpenSearch 深度优化
- ClickHouse 大规模集群
- 全自动 K8s Autoscaling
- 多机房一致性协议
- 自研 MQ
- 自研数据库
- 自研对象存储

必须保留接口和扩展点，但不要提前增加复杂度。

---

# 42. 开发阶段

## Phase 0 — Repository Foundation

完成：

- Monorepo
- Composer workspace/脚本
- Go module
- Buf
- Proto generation
- Docker Compose
- MySQL
- Redis
- Kafka
- MinIO
- Logging
- Request ID
- Base gRPC server
- Base gRPC client
- Health checks
- Makefile
- CI

验收：

```text
make up
make proto
make lint
make test
```

全部通过。

---

## Phase 1 — Auth + User + Persona

完成：

- AuthService
- UserService
- API Gateway
- JWT access token
- refresh token
- device
- Persona CRUD
- migrations
- tests

E2E：

```text
login
 -> get me
 -> create persona
 -> list personas
```

---

## Phase 2 — Content + Social

完成：

- ContentService
- SocialService
- Post
- Comment
- Like
- Follow
- Block
- Media metadata
- Outbox
- Kafka events

E2E：

```text
create post
 -> get post
 -> comment
 -> like
 -> follow
```

---

## Phase 3 — Chat + Message

完成：

- ChatService
- MessageService
- logical shard router
- conversation seq
- idempotent SendMessage
- message outbox
- sync messages
- WS protocol
- Gateway skeleton

E2E：

```text
create conversation
 -> send message
 -> server ack
 -> consume message.created
 -> sync message
```

---

## Phase 4 — Feed + Discovery

完成：

- FeedService
- DiscoveryService
- Redis feed
- candidate pools
- exposure
- basic ranking
- basic random capsule

E2E：

```text
approved post
 -> Kafka
 -> feed/discovery pool
 -> user retrieves item
```

---

## Phase 5 — Moderation + Risk + Notification

完成：

- ModerationService
- RiskService
- NotificationService
- mock providers
- rate limit
- report
- push abstraction

---

## Phase 6 — Observability + Production Infra

完成：

- Prometheus
- Grafana
- OTel
- Loki
- Tempo
- Docker image
- Kubernetes manifests
- Helm
- readiness
- rolling deployment
- Gateway drain

---

# 43. 首次 Coding Agent 执行范围

如果这是一个空仓库：

**不要一次性实现全部 Phase。**

第一次执行必须：

1. 完成 Phase 0；
2. 完成 Phase 1；
3. 为后续所有 Service 创建明确目录和 README；
4. 建立所有核心 Proto，但未实现 RPC 可以明确 TODO；
5. 建立事件定义；
6. 建立统一错误码；
7. 建立本地 Docker Compose；
8. 跑通 Phase 1 E2E；
9. 输出当前状态；
10. 再继续 Phase 2。

如果环境和上下文允许连续执行，则按 Phase 顺序继续，但每个 Phase 都必须通过测试后再继续。

---

# 44. Definition of Done

一个功能只有满足以下条件才算完成：

- 有 migration；
- 有 Domain / Application 层；
- 有 transport handler；
- 有 input validation；
- 有统一错误码；
- 有日志；
- 有 request_id / trace_id；
- 有 unit test；
- 有 integration test（适用时）；
- 写操作考虑 idempotency；
- 领域事件考虑 Outbox；
- 文档已更新；
- lint/test 全绿。

---

# 45. PHP 服务内部推荐结构

每个服务：

```text
src/
├── Application/
│   ├── Command/
│   ├── Query/
│   ├── DTO/
│   └── Service/
│
├── Domain/
│   ├── Entity/
│   ├── ValueObject/
│   ├── Enum/
│   ├── Event/
│   ├── Repository/
│   └── Service/
│
├── Infrastructure/
│   ├── Database/
│   ├── Redis/
│   ├── Kafka/
│   ├── Repository/
│   └── Outbox/
│
├── Transport/
│   └── Grpc/
│
└── Support/
```

避免：

```text
GrpcHandler -> SQL
```

正确：

```text
GrpcHandler
 -> Application Service
 -> Domain
 -> Repository Interface
 -> Infrastructure Repository
```

---

# 46. Webman API Gateway 结构

```text
app/
├── controller/
├── middleware/
├── validation/
├── grpc/
├── auth/
├── response/
├── exception/
└── support/
```

Controller 必须薄。

示例：

```text
Controller
 -> validate
 -> grpc client
 -> transform
 -> response
```

禁止 API Gateway 出现业务持久化逻辑。

---

# 47. Go Gateway 目录

```text
cmd/gateway/
internal/
├── connection/
├── protocol/
├── auth/
├── presence/
├── push/
├── grpc/
├── metrics/
└── lifecycle/
```

重点测试：

- 连接建立
- Ticket 验证
- heartbeat timeout
- multi-device
- push
- disconnect cleanup
- graceful drain
- reconnect behavior

---

# 48. 性能设计

不要在第一阶段伪造“百万并发已完成”。

必须准备压测：

HTTP：

```text
k6 / wrk / vegeta
```

WebSocket：

创建专门压测工具。

记录：

```text
connections
CPU
memory
network
message rate
P50
P95
P99
error rate
reconnect rate
```

Gateway 单机连接上限必须通过实际负载确定。

---

# 49. 生产扩容边界

所有重要层必须 Stateless 或可水平扩容：

```text
Webman API
gRPC Service
Kafka Consumer
Gateway
Feed Worker
Notification Worker
```

State 放在：

```text
MySQL
Redis
Kafka
Object Storage
```

---

# 50. 关键架构不变量

以下规则后续任何功能都不应轻易破坏：

```text
User != Persona

HTTP external
 -> Webman

Realtime external
 -> Gateway

Internal sync
 -> gRPC

Internal async
 -> Kafka

Business truth
 -> MySQL

Realtime/cache
 -> Redis

Large media
 -> Object Storage

Search
 -> OpenSearch

Analytics
 -> ClickHouse
```

并且：

```text
Service cannot directly write another Service DB

Critical write
 -> Idempotent

Critical domain event
 -> Transactional Outbox

Consumer
 -> Idempotent

RPC
 -> Deadline

Large lists
 -> Cursor pagination

Feed
 -> Redis/read model

Message
 -> conversation_id sharding key
```

---

# 51. Coding Style

PHP：

- `declare(strict_types=1);`
- PHP 8.4 typing
- readonly DTO where appropriate
- enum for app status
- no magic array when typed DTO is practical
- constructor injection
- no Service Locator in domain
- no global mutable state

Go：

- context propagation
- explicit error handling
- graceful shutdown
- bounded goroutines
- no silent panic recovery
- structured logging

所有语言：

- small functions
- clear naming
- avoid premature abstraction
- no dead code
- no TODO without issue/phase context

---

# 52. README 最终必须包含

根 README：

1. 架构简介
2. 服务列表
3. 环境依赖
4. 本地启动
5. Proto 生成
6. Migration
7. Test
8. E2E
9. 常用 Make 命令
10. 服务端口
11. Kafka Topics
12. 数据库列表
13. Redis Key 规范入口
14. Troubleshooting

每个 Service README：

```text
Responsibility
Owned Data
gRPC API
Consumed Events
Produced Events
Dependencies
Local Run
Test
```

---

# 53. 最终交付检查清单

AI 在认为项目基础搭建完成前，必须检查：

```text
[ ] docker compose 能正常启动
[ ] 所有必要 container healthy
[ ] proto 可以重新生成
[ ] 没有生成代码被人工修改
[ ] migrations 可重复执行
[ ] auth-service health 正常
[ ] user-service health 正常
[ ] api-gateway health 正常
[ ] 登录 E2E 正常
[ ] Persona E2E 正常
[ ] request_id 全链路存在
[ ] gRPC deadline 已配置
[ ] 错误响应统一
[ ] secrets 未提交
[ ] PHPStan 通过
[ ] tests 通过
[ ] README 可让新开发者直接启动
```

---

# 54. AI 开始执行时的第一条动作

开始后：

```text
1. 检查仓库
2. 输出准备创建/修改的目录与组件
3. 初始化 Phase 0
4. 实际运行命令
5. 修复错误
6. 完成 Phase 0 验收
7. 开始 Phase 1
8. 完成登录 + Persona E2E
9. 汇报代码结构、测试结果和下一阶段
```

不要只给实施建议。

**直接实施。**

---

# 55. 最终原则

本项目不是为了第一天制造一个庞大的分布式系统。

目标是：

```text
小规模时：
部署简单、开发快

流量增长时：
按服务扩实例

数据增长时：
按逻辑边界拆数据库

消息增长时：
按 conversation_id 分片

连接增长时：
增加 Gateway

异步任务增长时：
增加 Kafka Partition + Consumer

整个过程中：
客户端协议和核心业务模型尽量稳定
```

优先构建：

```text
正确边界
稳定协议
可靠数据
完整测试
可观测性
```

然后才是：

```text
极限性能
```
