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
  - 关系型业务最终事实数据源：PostgreSQL 18（固定服务 Schema，无跨服务 JOIN）。
  - 消息最终事实数据源：MongoDB 8 副本集，消息、会话 seq 与消息 Outbox 在同一事务提交。
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
| `services/content-service` | ContentService | RoadRunner / PHP 8.4 | 已实现帖子、图文媒体元数据、一级与回复评论及访问授权 |
| services/social-service | SocialService | RoadRunner / PHP 8.4 | 已实现 MAIN 点赞/关注、账号级双向拉黑及交互状态查询 |
| `services/discovery-service` | DiscoveryService | RoadRunner / PHP 8.4 | 漂流罐头、情绪池、随机发现、曝光过滤 (**骨架，未实现**) |
| `services/feed-service` | FeedService | RoadRunner / PHP 8.4 | 首页 Feed 规则召回、ZSET Timeline 聚合 (**骨架，未实现**) |
| `services/recommend-service` | RecommendService | RoadRunner / PHP 8.4 | 用户兴趣画像、候选打分排序 (**骨架，未实现**) |
| `services/chat-service` | ChatService | RoadRunner / PHP 8.4 | 已实现私聊会话、固定成员身份快照、已读点及权限校验 |
| `services/message-service` | MessageService | RoadRunner / PHP 8.4 | 已实现消息持久化、递增 seq、幂等去重及历史同步；当前运行库尚未接入分片路由 |
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
- (本地开发可选): PHP >= 8.2（带 pdo_pgsql、pdo_sqlite、mongodb、grpc、redis、bcmath），Go >= 1.27，Composer >= 2.0；并发数据库测试另需 pcntl。

---

## 4. 本地启动

```bash
# 1. 复制配置文件，并设置 PostgreSQL / MongoDB 独立账号密码、JWT_SECRET / WS_TICKET_SECRET
#    以及所有 INTERNAL_RPC_*_TOKEN
#    （compose.yaml 对这些变量做强校验，缺失会拒绝启动）
cp .env.example .env

# 2. 一键启动全流程
#    （make up 内部严格执行安全生命周期：镜像编译 -> 基础设施就绪 -> 自动探活 -> 执行数据迁移 -> 业务与网关服务启动）
make up

# 3. 检查服务健康状态
#    网关会对 auth/user 服务发起真实的 gRPC 健康探测；任一下游不可用返回 503
curl http://127.0.0.1:8787/health
```

> **启动与迁移顺序说明**：`make up` 启动 PostgreSQL、MongoDB 副本集、Redis 和 RabbitMQ，完成账号初始化、探活、broker 拓扑及数据库迁移，再启动业务服务。对象存储按需运行 `make storage-up`。

Redis 在 Compose 中启用 AOF（`appendfsync everysec`）并使用持久化命名卷
`redis_data:/data`。访问令牌和设备撤销键属于安全状态，不应按普通缓存处理；因此
不要使用 `docker compose down -v`，除非明确接受丢弃本地撤销状态。AOF 的每秒 fsync
策略允许最多约一秒的崩溃窗口，部署仍应使用受控关停和备份/监控。
当前普通缓存与撤销状态仍共用 Redis 实例和命名卷；本次修复不改变缓存隔离拓扑，
普通缓存必须继续设置 TTL，生产环境如需独立生命周期应另行拆分 Redis 实例/卷。

可用下面的真实运行验证重启恢复语义：脚本写入 token/device 撤销键，调用 Redis
7.2 的 `WAITAOF` 等待本地 AOF fsync，重启 Redis，再按撤销判定读取键并断言访问仍被拒绝：

```bash
COMPOSE_ENV_FILE=.env.example bash scripts/redis_revocation_persistence_test.sh
```

脚本会清理本次生成的唯一测试键；若 Docker/Redis 不可用则以非零状态报告未运行，不能
替代静态契约测试。没有 Docker 时仍可运行 `vendor/bin/phpunit --testsuite Unit`
验证 Compose 的 AOF、fsync 和命名卷契约；这项静态检查不声称完成重启恢复验证。

内部服务的 gRPC 端口只在 Compose 内部网络暴露，不发布到宿主机；每条调用关系使用独立的 `INTERNAL_RPC_*_TOKEN`，并通过 `SERVICE_NAME` 做调用方和方法授权。部署前必须把 `.env` 中的全部 token 替换为至少 32 字节的独立随机值，包括 Message→Chat 和 Chat→Message。

PostgreSQL、MongoDB、Redis 和 RabbitMQ 的开发端口默认只绑定 `127.0.0.1`；Compose 服务之间继续使用内部 hostname 通信，需要外部访问时再显式设置对应的 `*_BIND_ADDRESS`。

生产配置从 `.env.production.example` 开始，填入独立随机凭证，保持 `ENABLE_DEV_LOGIN=false`、`DEV_LOGIN_CODE` 为空。当前没有生产短信适配器，手机号验证码登录仅在本地/测试环境可用（Gateway 路由与 AuthService 双重门禁，生产直接关闭）；微信/QQ OAuth 登录不依赖短信，首次登录自动注册，配置真实 `WECHAT_APP_ID`/`QQ_APP_ID` 凭证并保持官方 HTTPS endpoint 后即可在生产开启。开发环境的 endpoint 覆盖（`WECHAT_TOKEN_ENDPOINT` 等）与 `SOCIAL_OAUTH_ALLOW_INSECURE_HTTP` 在生产环境会触发启动拒绝。代理部署必须配置实际受控代理的 `SOCIAL_TRUSTED_PROXY_CIDRS`，否则限流使用 socket peer；Gateway 也应只允许这些代理访问。内部 RPC token 是服务身份凭证，跨主机部署仍需受控网络和 TLS/mTLS，不能把凭证作为传输加密使用。

### 受信代理链与客户端 IP 限流

`SOCIAL_TRUSTED_PROXY_CIDRS` 接受逗号分隔的多个 CIDR（IPv4/IPv6 均可），用于描述 Gateway 前面的全部受控代理跳数。下面是**示例拓扑的示例网段**，`100.64.0.0/10` 与 `10.0.0.0/8` 只是本地测试/文档演示值，不能直接当成 Cloudflare、EdgeOne 或云厂商 SLB 的真实回源网段；生产必须替换为你实际 CDN/LB 链路的受控节点地址（厂商通常发布回源 IP 列表，且可能变更，需要纳入配置管理），同时确保源站只接受这些代理的访问、不能被客户端直连绕过：

```text
SOCIAL_TRUSTED_PROXY_CIDRS=100.64.0.0/10,10.0.0.0/8
# 客户端 -> CDN 边缘 (100.64.0.0/10) -> LB (10.0.0.0/8) -> Gateway
# Gateway 收到: X-Forwarded-For: <client>, <cdn-edge>，socket peer = <lb>
```

客户端 IP 的解析规则是「从最右侧可信代理向左走，第一个不受信地址即客户端」，因此客户端伪造的左侧 XFF 条目（包括伪造一个位于受信网段内的地址）无法改变解析结果；只有当 socket peer 本身落在受信网段内时才读取 `X-Forwarded-For`，不受信 peer 的任何转发头一律忽略。IPv4-mapped IPv6 地址（如 `::ffff:10.1.0.1`）先归一化为内嵌 IPv4 再做网段匹配，避免双栈 LB 因表示形式不同被误判为不受信、导致所有客户端共享一个限流桶。非法 CIDR（含 `0.0.0.0/0`、`::/0`）在 worker 启动时抛错拒绝服务，属于 fail-fast 而不是降级。

社交登录/绑定限流是**多层分桶**模型，一次请求必须同时通过所有命中的桶，任一超限即拒绝：

| 桶 | Key 维度 | 默认阈值 | 作用 |
| :--- | :--- | :--- | :--- |
| IP 全局 | 客户端 IP | `SOCIAL_LOGIN_RATE_LIMIT=60` / 60s | 单 IP 总天花板，登录+绑定共用 |
| IP + 场景 | 客户端 IP × login/bind | `SOCIAL_LOGIN_RATE_SCENARIO_LIMIT=30` / 60s | 登录与绑定互不挤占额度 |
| IP + 设备 | 客户端 IP × device_uuid | `SOCIAL_LOGIN_RATE_DEVICE_LIMIT=20` / 60s | 压制单设备脚本行为，辅助风险信号 |
| 用户 + 绑定 | 已认证 user_id | `SOCIAL_BIND_USER_RATE_LIMIT=10` / 3600s | 单账号反复绑定独立受限，与来源 IP 无关 |

绑定接口按「IP/场景/设备前置限流 → AuthMiddleware 验证 JWT → 已验证用户限流」执行。IP 超限时不会再调用 VerifyToken，认证后阶段只增加用户桶，每个请求不会重复增加 IP 桶。

IPv4 按完整地址分桶；IPv6 归一到 /64 前缀分桶（终端用户可自由轮换低 64 位接口标识，按全址分桶等于无限桶；/64 是单个订阅者的常规粒度）。IPv4-mapped IPv6 地址按二进制归一化为内嵌 IPv4 后再参与受信代理匹配与分桶，`::ffff:10.1.0.1`、`::FFFF:10.1.0.1`、`::ffff:a01:1` 等所有文本写法等价。目标用户多在中国大陆，部分用户经运营商 CGNAT 共享公网 IPv4，因此 IP 全局桶刻意保持宽裕、由场景/设备/用户桶承担精细限制；部署时按实际 NAT 共享度调整全局阈值。设备维度只是附加信号：伪造/轮换 `device_uuid` 只会新增设备桶，永远不会解除 IP 桶限制。限流计数依赖 Redis，生产环境 Redis 不可用时拒绝请求（HTTP 503 fail-closed），开发环境放行。

### gRPC 超时约束

各超时旋钮不是独立自由值，以下关系同时由**运行时启动校验**与测试门禁强制，违反即拒绝启动（不是只校验 `.env.example` 默认值）：

| 变量 | 默认 | 约束 | 强制位置 |
| :--- | :--- | :--- | :--- |
| `GRPC_AUTH_TIMEOUT_MS` | 300 | Gateway→Auth 普通调用外层截止时间；必须为正整数 | Gateway `config/gateway.php` 加载即校验 |
| `GRPC_USER_DIRECTORY_TIMEOUT_MS` | 250 | Auth→User 目录跳预算；必须为正整数，且 **小于** `GRPC_AUTH_TIMEOUT_MS` | `DeadlineEnv` 严格解析 + AuthService 启动时对部署对做跨服务校验（compose.yaml 会把 `GRPC_AUTH_TIMEOUT_MS` 一并注入 auth-service 容器） |
| `GRPC_SOCIAL_LOGIN_TIMEOUT_MS` | 7000 | 社交登录 RPC 截止；必须 **大于** `2 × SOCIAL_HTTP_TIMEOUT_MS`（QQ 最坏两跳 OAuth HTTP） | Gateway 配置加载即校验，`AuthServiceClient` 构造时再次保护 |
| `GRPC_SOCIAL_BIND_TIMEOUT_MS` | 7000 | 社交绑定 RPC 截止；同上 | 同上 |
| `SOCIAL_HTTP_TIMEOUT_MS` | 3000 | OAuth 上游单次 HTTP 超时；必须为正整数 | Gateway 配置加载与 AuthService 启动即校验 |
| `SOCIAL_HTTP_CONNECT_TIMEOUT_MS` | 1000 | OAuth 连接超时；必须为正整数且不大于总 HTTP 超时 | AuthService 启动即校验，OAuth 客户端构造时再次保护 |

非法值（`0`、负数、非数字、带单位的字符串）在任何部署环境都会让对应 worker 拒绝启动——不会像旧的 `max(1, (int)...)` 那样把 `abc` 悄悄钳制成 1ms。严格解析逻辑在 `Packages\GrpcCommon\DeadlineEnv` 中由 Gateway 与 AuthService 共享。

**剩余截止时间的运行时传播**：RoadRunner 不会把传入调用的 gRPC deadline 交给 PHP worker，因此网关在每次调用上以 `x-deadline-unix-ms` 元数据携带绝对截止时间；AuthService 在内部 RPC 授权通过后读取该值（`DeadlineContext`），Auth→User 目录跳的实际预算取 `min(配置值, 剩余值)`，剩余时间耗尽则立刻返回 `RPC_TIMEOUT` 而不是重新花一个完整预算。该元数据只能缩短内部跳预算、永远不能延长，伪造它的最坏效果是让自己的请求更早超时。

`GRPC_AUTH_TIMEOUT_MS − GRPC_USER_DIRECTORY_TIMEOUT_MS` 是 Auth 侧自身每调用 ~50ms 的工作预算；worker 启动预热（Eloquent、protobuf 描述符池、JWT 编解码）正是为了把暖路径成本压在该预算内，`tests/Unit/GrpcDeadlineBudgetBenchmarkTest.php` 守住编解码份额，`tests/Integration/AuthWarmPathLatencyTest.php` 用真实 PostgreSQL/Redis 和 JWT 编解码度量实际 `AuthApplicationService::verifyToken()` 的累计暖路径 P50/P95/P99，并将累计 P95 与 50ms 预算比较（UserDirectory 使用 ACTIVE stub，不包含真实 UserService 网络跳），`scripts/auth_latency_probe.sh`（`make latency-probe`，也包含在 `make e2e`）对完整链路（Gateway→gRPC→OAuth/PostgreSQL/Redis→User gRPC）输出 P50/P95/P99 报告并守住 P95 预算；`tests/Unit/GrpcDeadlineConfigContractTest.php` 校验 compose.yaml 与 .env.example 的默认值满足上述跨服务关系及启动校验本身。

已认证 HTTP 链路另有可选探针 `scripts/verify_token_latency_probe.php`，从环境变量 `VERIFY_TOKEN_PROBE_TOKEN` 读取现有测试用户的有效 access token，以 GET `/api/v1/personas`（或 `/api/v1/me`）采样；无需反复社交登录，因此不会消耗登录限流额度。配置请求数、预热数、并发数及 P95 门禁后执行，例如：

探针先对同一接口进行两次不计入延迟统计的认证预检查：无效 JWT 必须返回 HTTP 401 和整数认证错误码 `AUTH_TOKEN_INVALID` 或 `AUTH_UNAUTHORIZED`；有效 JWT 必须返回成功业务码及 `data.personas` JSON 列表，`/api/v1/me` 还要求 `data.user.id` 为非空字符串。两项均通过后才执行预热与正式采样；任一失败立即以退出码 1 停止，不输出延迟指标，避免错误目标或静态成功响应造成虚假通过。

```bash
VERIFY_TOKEN_PROBE_REQUESTS=500 VERIFY_TOKEN_PROBE_WARMUP=20 \
VERIFY_TOKEN_PROBE_CONCURRENCY=16 VERIFY_TOKEN_PROBE_BUDGET_MS=1000 \
php scripts/verify_token_latency_probe.php
```

每个样本包含 HTTP、Gateway 的 VerifyToken 调用及接口后续 UserService 查询，报告 P50/P95/P99、失败数与超时率；这些值是整个受认证 HTTP 请求的耗时，不能当作单独 VerifyToken RPC 的耗时。HTTP、业务、传输、超时错误或 P95 达到或超过预算都会让探针失败；P95/P99 的稳定性仍取决于样本量、持续时间和负载。探针不自动执行生产压测或重启服务；部署前应在专用环境中分别采集冷启动/重启、Redis/PostgreSQL 延迟扰动和不同并发度的结果，并结合连接池容量判断。

内部 gRPC 传输由 `GRPC_TLS_MODE` 控制。`.env.example` 明确选择 `plaintext` 供本地开发；当 `APP_ENV=production` 时，`GrpcTlsConfig` 和 `scripts/roadrunner-entrypoint.sh` 都拒绝明文或缺失证书。`tls` 模式从 `GRPC_TLS_CA_FILE` 读取 CA 并要求 `GRPC_TLS_SERVER_NAME`（或按目标服务命名的变量）进行服务名验证；`mtls` 另外读取 `GRPC_TLS_CERT_FILE`/`GRPC_TLS_KEY_FILE`。证书只从部署注入的文件加载，不写入 Git、镜像或日志。应用层 `x-service-token` 和方法白名单仍保留，负责调用授权而非传输加密。

RoadRunner 的 gRPC 插件在六个服务配置上通过 `grpc.tls` 的官方覆盖选项接线：生产 `tls` 使用服务端证书，生产 `mtls` 使用 `root_ca` 与 `require_and_verify_client_cert`。客户端证书是否属于哪个调用方由 TLS 层验证；PHP worker 不假装自行验证证书身份，应用层 token 仍是授权边界。若部署在无法直接提供这些文件的托管平台，应在 gRPC sidecar/ingress 终止并重新建立 mTLS，且只允许受控的后端网络访问明文后端端口。

证书轮换仍需要重启受影响的 RoadRunner worker（当前调用方只接受一个 token，尚不支持双凭证无缝轮换）。轮换后应运行 `scripts/test_internal_rpc.php` 验证新凭证成功、旧凭证失败，再恢复流量。

已签发 JWT 的用户状态策略：AuthService 每次 `VerifyToken` 验签及撤销检查后，先校验设备仍属于 JWT 中的用户，再通过已授权的 `UserService.GetUser` 读取主库用户状态，不缓存 active 状态。封禁（非 active）或软/硬删除后，下一次受保护 HTTP 请求即拒绝旧 JWT，刷新与新登录同样拒绝；Redis、数据库或用户目录超时、不可用时拒绝访问并返回 HTTP 503（`SERVICE_UNAVAILABLE` / `RPC_TIMEOUT`），不会将基础设施故障报告为凭证失效的 401。因此每次认证新增一次 Auth DB 设备归属查询和一次内部 RPC/用户主库查询，部署时需要为数据库及 UserService 的容量和可用性留出预算。此策略是状态暂停：解封后，尚未到期且未被登出撤销的 JWT 可恢复访问；删除后的 ID 不得复用。已完成认证的在途请求不被回滚，已建立的 WebSocket 连接或先前签发的短期 WS ticket 不属于 JWT 校验路径，尚无封禁广播断连能力。

当前 PostgreSQL 基线已包含设备 `(user_id, device_uuid)` 唯一约束。历史 MySQL 设备隔离 SQL 仅作为旧版本记录保留，不属于当前迁移入口；从旧库迁移前须暂停写入、备份，并单独验证设备归属与历史 refresh token 的有效性，不能通过启动空白 PostgreSQL 自动搬迁旧数据。

Snowflake Worker ID 说明：`WORKER_ID` 仍表示服务槽位（0-31），每个 RoadRunner worker 通过 Redis `SET NX EX` 在该槽位的 32 个进程槽中取得带所有权 token 的租约；租约按单调时钟定期续期，并在生成 ID 前检查所有权。Redis 故障、租约续期失败或租约已被回收时，进程拒绝继续生成 ID；正常退出会用 token 比对后释放，异常退出由 TTL 回收。因此同一 Redis 租约命名空间内的副本不会因 PID 重用或 `pid % 32` 碰撞而同时使用同一个 worker ID。部署所有使用 Snowflake 的服务时必须提供 `REDIS_HOST`/`REDIS_PORT`（可选 `REDIS_PASSWORD`），并可用 `WORKER_ID_LEASE_NAMESPACE`、`WORKER_ID_LEASE_TTL_SECONDS` 配置共享命名空间与 TTL。

当前续约由生成 ID 的调用触发，没有独立后台心跳。进程空闲超过 TTL 后，下一次写请求会因租约丢失被拒绝；RoadRunner 进程仍存活时不会自动取得新租约。本地生产配置实测已复现该行为。上线前需要补齐空闲续约或安全的进程回收及重试机制，并验证空闲超过 TTL 后的恢复；单纯增大 TTL 不能消除该限制。

---

API Gateway 的 `/metrics` 使用 `METRICS_ALLOWED_CIDRS` 作为监控 socket 对端白名单（逗号分隔 IP 或 CIDR，空值拒绝全部）。部署 Prometheus 时应填写专用监控网络的实际网段；不得把公众反向代理所在网段加入白名单。`X-Forwarded-For` 不参与授权。其余 PHP 服务的 9100 端口只应在内部监控网络开放。

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

权威生成入口使用 `infra/docker/proto/Dockerfile`：protoc 3.21.12、protoc-gen-go v1.35.1、protoc-gen-go-grpc v1.5.1、protoc-gen-php-grpc 2025.1.15。下载的 protoc 与 PHP gRPC 插件有架构对应的 SHA256 校验。不要手改 `contracts/gen`；修改源协议或升级工具后运行两次 `make proto`，第二次不得改变生成文件，再将工具链和产物一起提交。生成入口会清理旧产物，已删除协议不会留下过期文件。

---

## 6. 数据库设计与迁移

当前采用 PostgreSQL + MongoDB。`make migrate` 先执行 `database/postgresql/migrations/` 中的 Phinx 迁移，再运行 `scripts/migrate_mongodb.php`。从 MySQL 旧部署切换时，应暂停写入、备份并单独完成数据迁移与核对；当前入口不会自动导入旧 MySQL 数据。升级后重建受影响的业务服务、relay、consumer 与网关镜像，验证消息同步、推送和已读游标。

数据库初始化基线位于 `infra/docker/mysql/init/01-databases.sql`，容器首次启动时自动挂载执行。后续结构变更使用 Phinx，迁移文件放在 `database/migrations/`，由 `meta.phinxlog` 追踪。历史 `infra/docker/mysql/migrations/0001` 至 `0006` SQL 和 `meta.schema_migrations` 保留，由首次 Phinx 迁移接管；未完成的旧迁移按实际字段/索引补齐，已完成记录不重放。
- `auth_db`: 存储 `devices`, `refresh_tokens`, `outbox_events`
- `user_db`: 存储 `users`, `personas`, `outbox_events`
- `content_db`: 存储 `posts`, `post_media`, `comments`, `outbox_events`
- `social_db`: 存储 `likes`, `follows`, `blocks`, `outbox_events`
- `chat_db`: 存储 `conversations`, `conversation_members`, `outbox_events`
- MongoDB `message_db`：存储 `messages`、`conversation_sequences`、`outbox_events`；历史消息和同步均按 `seq` 分页，尚未接入分片路由。

### PostgreSQL 与 MongoDB 账号边界

PostgreSQL 空卷初始化脚本 `infra/docker/postgresql/init/01-create-roles.sh` 创建固定 Schema 和角色；MongoDB 使用 `infra/docker/mongodb/init.js` 初始化副本集与集合权限。每个账号使用独立密码，业务容器不使用管理员凭据。

| 账号 | 授权范围 | 用途 |
| :--- | :--- | :--- |
| `auth_runtime` | `auth_db.*` 的 `SELECT/INSERT/UPDATE/DELETE` | AuthService |
| `user_runtime` | `user_db.*` 的 `SELECT/INSERT/UPDATE/DELETE` | UserService |
| `content_runtime` | `content_db.*` 的 `SELECT/INSERT/UPDATE/DELETE` | ContentService |
| `social_runtime` | `social_db.*` 的 `SELECT/INSERT/UPDATE/DELETE` | SocialService |
| `chat_runtime` | `chat_db.*` 的 `SELECT/INSERT/UPDATE/DELETE` | ChatService |
| MongoDB `message_runtime` | 消息读取/插入、序号读取/插入/更新、Outbox 插入 | MessageService |
| MongoDB `message_outbox_relay` | 仅 Outbox 的 find/update | Message Outbox relay |
| `user_outbox_relay` | 仅 `user_db.outbox_events` 的 `SELECT/UPDATE` | User outbox relay |
| PostgreSQL `migration_ddl` | 固定业务 Schema 与 `meta` 的所有者；无管理员或创建角色权限 | Phinx |
| MongoDB `message_migration` | 消息库 dbAdmin/readWrite | MongoDB 迁移 |

PostgreSQL 运行时角色只能访问所属 Schema；`user_outbox_relay` 仅能访问用户 Outbox。`POSTGRES_ADMIN_PASSWORD` 只供管理员初始化，`PG_MIGRATION_PASSWORD` 只供迁移，`PG_*_RUNTIME_PASSWORD` 供各服务。MongoDB 分别使用 `MONGODB_RUNTIME_PASSWORD`、`MONGODB_RELAY_PASSWORD` 和 `MONGODB_MIGRATION_PASSWORD`。

PostgreSQL 初始化脚本只在空卷执行，不能在已有库上直接重跑包含 `CREATE ROLE` 的脚本；凭据轮换应由管理员在维护窗口执行，再重启使用该凭据的服务。MongoDB 的角色和账号初始化可通过 `docker compose run --rm mongo-init` 重新收敛，完成后也须重启相关服务。

安装 Composer 依赖后应用待执行迁移（使用现有 PHP runtime 镜像）：
```bash
make migrate
```

常规迁移、启动和 CI 不依赖历史手机号预检查。仅在维护已有手机号数据时，单独执行 `make preflight-phone`；规范化或修复数据应使用独立维护流程及相应数据库权限，不能提升业务运行时账号权限。

查看状态、新建迁移：
```bash
bash scripts/run_migrations.sh status
bash scripts/run_migrations.sh create CreateExampleTable
```

`phinx.php` 在同一 PDO 会话中取得 PostgreSQL advisory lock 后执行 Phinx。`PG_MIGRATION_LOCK_TIMEOUT` 设置等待秒数（默认 30，范围 0–300），连接关闭时释放锁。使用固定的 `auth_db`、`user_db`、`content_db`、`social_db`、`chat_db` 和 `meta` Schema，非空 `PG_SCHEMA_PREFIX` 会被拒绝。社交身份协议键使用 `C` 排序规则区分大小写；邮箱唯一性使用 ICU 不区分大小写与重音的排序规则。

本机直接运行 Phinx 时，显式提供 `PG_HOST`、`PG_PORT`、`PG_DATABASE`、`PG_MIGRATION_USER` 和 `PG_MIGRATION_PASSWORD`（配置不会自动加载 `.env`）。真实数据库测试使用一次性的 `test_*` 数据库。

MongoDB 迁移使用带所有权 token 的租约和版本校验和。当前版本为 `20261020000100_message_collections_v1`、`20261020000200_outbox_claimed_at_date`、`20261020000300_drop_message_id_paging_index`；升级保留消息和序号，移除旧 `_id` 分页索引。已记录迁移之后发现 Validator 或索引漂移会失败，不能将重新运行迁移当作自动修复。`--fake` 只标记 PostgreSQL 迁移版本，不能用于实际部署变更。

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

`make e2e` 运行 Phase 1 业务流、社交登录 HTTP → gRPC → PostgreSQL/Redis 测试，以及真实链路延迟探针（社交登录全链路 P50/P95/P99 报告，见 `make latency-probe`）。微信/QQ 社交登录通过独立 `fake-oauth` 服务模拟上游；手机号登录走开发验证码（`ENABLE_DEV_LOGIN` / `DEV_LOGIN_CODE`），该入口仅供本地与 E2E 测试，**不作为正式登录方式**，生产环境由路由环境门禁与 AuthService 双重关闭。不调用真实 OAuth 或短信平台。在专用本地测试数据库及 `.env.example` 配置下，可在同一 shell 中启用：

```bash
export APP_ENV=testing ENABLE_WECHAT_LOGIN=true ENABLE_QQ_LOGIN=true
export ENABLE_DEV_LOGIN=true DEV_LOGIN_CODE=123456
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

**fake-oauth 授权码语义（与真实上游一致的单次兑换）**：测试码格式为 `<provider>-<scenario>-<identity>~<nonce>`，`~nonce` 之前的部分决定外部身份（openid/unionid），完整码串是一次性授权码。fake 上游对每个码只接受一次兑换——包括 503 瞬时失败的尝试也会烧毁该码，重放已消费码返回上游的 invalid code 错误（业务码 `1001009` / HTTP 401）。已消费状态记录在状态文件中，读—检查—写的整个周期由 `flock(LOCK_EX)` 保护，多 worker 并发兑换同一码时仍只有一个成功（`tests/Unit/FakeOAuthCodeConsumptionTest.php` 守护）。因此 E2E 中每一次失败恢复（上游瞬时故障、跨服务超时后的重试）都必须携带同身份的**全新**授权码（新 nonce），并断言恢复的是同一本地 User、不产生重复账号；这一契约由 `tests/Unit/SocialAuthServiceTest.php` 与 `scripts/social_e2e_test.sh` 共同守护。

测试会创建用户/会话并修改测试用户状态，应只使用专用测试环境。endpoint 覆盖和 HTTP 开关在生产环境会被启动检查拒绝。仅运行社交部分：

```bash
make e2e-social
```

PostgreSQL/MongoDB 集成测试要求 `DATABASE_INTEGRATION=1`，并明确要求数据库名称以 `test_` 开头。配置 `.env` 中的测试数据库后，先运行 `make postgres-ready mongo-ready migrate`，再运行 `make test-postgres test-mongodb`；MongoDB 套件还验证运行时与 relay 的权限隔离。`make test-mongodb` 包含稳定的事务故障注入和提交确认丢失恢复测试，并由默认 CI 执行；这些测试不会触发多节点真实选主。多节点选主与高并发 RPC 延迟需在独立环境验证。

Redis/RabbitMQ 集成测试需要 `SOCIAL_TEST_REDIS_*`、`RABBITMQ_INTEGRATION=1` 等连接参数。`make test` 执行 Unit 套件和 Go tests；`make test-integration` 执行 Integration 套件，两者均禁止跳过。缺少基础设施导致 skipped 不能视为通过，E2E 失败同样阻止 CI 通过。

---

## 9. 常用 Make 命令

| 命令 | 说明 |
| :--- | :--- |
| `make bootstrap` | 初始化依赖、生成 autoload 与配置 |
| `make up` | 编排启动全流程（镜像检查 -> 基础设施 -> 数据迁移 -> 业务服务） |
| `make infra-up` | 启动 PostgreSQL、MongoDB、Redis、RabbitMQ 并导入拓扑 |
| `make broker-init` | 重新导入 RabbitMQ exchange、stream 和 quorum queue 拓扑 |
| `make storage-up` | 启动 RustFS（storage profile） |
| `make app-up` | 仅启动业务微服务与网关容器 |
| `make down` | 停止并移除容器 |
| `make lint` | 执行 PHP 语法校验与 Go vet |
| `make analyse` | 执行 PHPStan 静态分析 |
| `make test` | 执行全套单元测试 |
| `make e2e` | 执行端到端接口冒烟测试与全链路延迟探针 |
| `make latency-probe` | 社交登录真实链路 P50/P95/P99 延迟报告（需已启动的栈） |
| `make logs` | 查看所有容器运行日志 |
| `make migrate` | 应用 PostgreSQL Phinx 与 MongoDB 版本迁移 |
| `make test-mongodb` | 消息持久化、迁移升级与事务故障注入测试 |
| `make clean` | 停止并清除本地数据卷 |

---

## 10. 服务端口映射

| 端口 | 协议 | 服务组件 |
| :--- | :--- | :--- |
| `8787` | HTTP | API Gateway (Webman REST API) |
| `8080` | HTTP/WS | Realtime Gateway (WebSocket :8080/ws) |
| `50051` | gRPC | AuthService (RPC) |
| `50052` | gRPC | UserService (RPC) |
| `5432` | PostgreSQL | PostgreSQL 18 关系型业务库 |
| `27017` | MongoDB | MongoDB 8 消息副本集 |
| `6379` | Redis | Redis 7.2 缓存与即时状态 |
| `5672` | AMQP | RabbitMQ 任务与事件发布 |
| `5552` | Stream | RabbitMQ Streams 原生协议 |
| `15672` | HTTP | RabbitMQ Management |
| `9000` | S3 API | RustFS 对象存储 API（需 `--profile storage`，宿主默认仅绑定 `127.0.0.1`） |
| `9001` | HTTP | RustFS 控制台（需 `--profile storage`，宿主默认仅绑定 `127.0.0.1`） |

---

## 11. RabbitMQ 事件与任务规范

`domain.events` topic exchange 按事件类型路由到独立 stream：`user.events`、`feed.events`、`search.events`、`notification.events`、`chat.events`。每个 stream 保留 7 天；新消费者可从指定 offset 重放。当前每个 stream 为单分区，消费端需串行处理才能维持其中事件顺序。扩容为 Super Stream 时，publisher 必须用 aggregate ID 一致性分区，同一 aggregate 始终进入同一 partition；topic pattern 不负责 hash 分区。UserService 的 Outbox relay 已实现：publisher confirm 和 mandatory routing 成功后标记已发布，失败重试，事件 ID 用于消费端去重。MessageService 同样在事务中记录 `message.created.v1`，`message-outbox-relay` 使用独立只读/更新 Outbox 账号发布；`chat-message-consumer` 通过独立 quorum 队列维护会话摘要并向 Redis 发布公共消息，Go 网关订阅后定向推送。其他服务的 relay 和消费端尚未实现。

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

1. **容器未就绪 / 数据库连接拒绝**:
   通过 `docker compose logs postgres mongo mongo-init` 检查数据库和副本集初始化，再运行 `make postgres-ready mongo-ready`。就绪后执行 `make migrate`，不要通过删除已有数据卷处理迁移错误。
2. **WebSocket 无法连接**:
   确认已先调用 `POST /api/v1/ws/ticket` 获取合法 Ticket；Ticket 有效期 60 秒且**单次使用**（同一 Ticket 二次握手会被网关拒绝），需在 60 秒内通过 `/ws?ticket=<ticket>` 建立连接。
3. **日志排查**:
   所有微服务输出严格的 JSON 日志，包含 `trace_id` 与 `request_id`，可通过 `docker compose logs -f <service_name>` 检索单个链路。

## 14. Anonymous identity contract

完整中文业务规则见 [真身与匿名分身业务规则](doc/USER_PERSONA_BUSINESS_RULES.md)，涵盖身份生命周期、操作权限、匿名展示、私聊、拉黑及验收场景。迁移与部署见 [Persona 迁移说明](doc/PERSONA_IDENTITY_MIGRATION.md)。

The identity model uses one long-lived public MAIN persona and one server-owned internal ALIAS persona per new user. Registration creates exactly one of each. This replaces the old fixed-pseudonym model, including the former maximum of five client-created personas. CreatePersona and legacy alias creation remain compatibility RPCs but reject every request.

MAIN is used for profile access, likes, follows, blocks, reports, CONNECT, and profile changes. ALIAS is permitted only for creating posts, comments, conversations, and sending messages. Mode and operation values are validated before alias lookup or creation; unspecified and unknown values fail closed. WebSocket tickets are always issued for MAIN with the CONNECT operation.

Public responses use safe projections. A MAIN author may expose its public persona id and write-time profile values. An ALIAS author exposes only a scope-bound opaque HMAC reference, a system anonymous avatar, and a frozen display name. ALIAS ids, nicknames, and user ids never appear in public responses. Conversation identity is fixed when the session/member snapshot is created; SendMessage has no per-message identity selector.
The message RPC accepts PublicSendMessageRequest with only client message id, conversation id, type, and content; the session snapshot supplies its sender identity. Public* protobuf responses are a breaking contract update, so regenerate all clients and do not mix old and new consumers during rollout.

The current content, social, chat, and message services are README/protocol skeletons. The independent PublicPost, PublicComment, PublicConversation, and PublicMessage service implementations are not claimed complete by this foundation. See doc/PERSONA_IDENTITY_MIGRATION.md for schema, deployment, key rotation, and legacy protocol details.
