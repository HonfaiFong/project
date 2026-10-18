# 健康检查瞬时 503：复现与修复

验证日期：2026-10-18。Linux PHP 8.4.26、gRPC 1.84.0、RoadRunner 2025.1.15。

## 确认的触发链路

网关保持运行、复用已经连接的 gRPC channel；AuthService/UserService 重启或网络地址变化后，channel 仍可能保留旧 HTTP/2 连接及已解析的地址。首次健康 RPC 遇到连接重置/旧地址连接失败，默认 fail-fast 行为直接返回 `UNAVAILABLE`，网关将其映射成 HTTP 503。下一次 RPC 重新连接后成功。

本轮在真实容器中捕获了 HTTP 503 响应：UserService 的失败详情尝试连接 `172.19.0.6:50052`，当时 UserService 实际地址已是 `172.19.0.5`，旧地址由 AuthService 使用。另一次捕获的详情为 `Stream removed (recvmsg:Connection reset by peer (104))`。

旧验证记录的执行顺序也包含：重启六个后端服务、保持网关运行、空闲 35 秒、首次 `/health` 返回 503。不过旧记录没有保存响应体，因此无法追溯证明旧请求的具体 gRPC 错误；本轮确认并修复的是可重复重现、与该时序一致的连接恢复缺陷。

## 对照实验

使用同一真实 AuthService，分别预热默认客户端与修复后的健康调用配置。只交换 AuthService/UserService 的容器 IP，保留服务名 DNS 别名和运行进程，等待 35 秒，再发起调用。不修改 RPC 截止时间，不注入租约失效。

| 调用 | 结果 | 耗时 |
| --- | --- | --- |
| 默认客户端预热 | gRPC OK / SERVING | 26.7 ms |
| 默认客户端地址变化后首次探测 | gRPC 14 / Connection reset | 0.48 ms |
| 默认客户端下一次探测 | gRPC OK / SERVING | 6.2 ms |
| 修复配置预热 | gRPC OK / SERVING | 27.9 ms |
| 修复配置地址变化后首次探测 | gRPC OK / SERVING | 18.4 ms |
| 实际修复后网关首次 `/health` | HTTP 200 / SERVING | 28.4 ms |

随后网关连续 10 次探测全部 HTTP 200，耗时 9.6–28.4 ms。

另以独立全新 MySQL 跑三轮原有 MySQL/Redis 集成测试：每轮 69 项、854 个断言通过。并行健康采样共 324 次，0 次失败，最大耗时 98.5 ms。并行测试负载本身没有在这些样本中触发故障。

## 实现边界

`UnaryGrpcClient` 的原生 gRPC method config 仅匹配 `common.v1.HealthService/Check`：启用 `waitForReady`，仅对 `UNAVAILABLE` 允许最多两次尝试，退避 10 ms。重连、退避和重试共享原始 RPC deadline，不重置元数据中的绝对截止时间。业务 RPC 不匹配此策略。

`NOT_SERVING` 响应、鉴权失败、已经提交响应头的应用错误不通过重试伪装为健康。真实后端持续不可达或处理超时时，仍按原始 deadline 失败并由网关返回 503。

## 可重复回归

```sh
docker compose run --rm --no-deps auth-service php /app/vendor/bin/phpunit /app/tests/Integration/HealthRetryTest.php --fail-on-skipped
```

该测试启动真实 RoadRunner/gRPC 服务和可控 TCP 转发器。转发器在预热连接的下一次发送时发送 TCP RST：负向对照断言原始 channel 返回 UNAVAILABLE；生产 `UnaryGrpcClient` 应在同一调用内恢复。还验证 NOT_SERVING、鉴权失败、应用错误和 100 ms 总截止时间，包括持续不可达的目标。需要 Linux、gRPC、sockets 和 RoadRunner；CI 在构建后的 PHP 运行时中强制执行，不允许跳过。

本轮其他检查：完整 Linux 单测 443 项、2131 个断言通过；通用 E2E、社交 E2E 通过；PHPStan 和 diff 格式检查通过。独立新增的连接恢复测试为 1 项、25 个断言。

所有地址变更和旧指标进程实现的负向对照仅发生在独立验证环境中；源码工作区未还原旧实现。旧指标实现没有在本轮复现空闲后 503，不能据此将该现象归因于 fork 或租约丢失。
