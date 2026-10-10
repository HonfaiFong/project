# Persona 真身与分身约束迁移

`personas.type=1` 表示账号的唯一主身份（真身），`type=2` 表示分身。注册事务创建主身份；普通创建接口固定创建分身，客户端提交的旧 `type` 字段不会被用来创建主身份。

## 上线前检查

迁移会拒绝历史脏数据，不会自动选择或删除身份。先在实际的 `user_db` 上执行：

```sql
SELECT user_id, COUNT(*) AS main_count
FROM user_db.personas
WHERE type = 1
GROUP BY user_id
HAVING COUNT(*) > 1;

SELECT id, type
FROM user_db.personas
WHERE type IS NULL OR type NOT IN (1, 2);
```

重复主身份和非法类型必须由业务方明确修复后再运行迁移。重复检查包含已经软删除的行，因为软删除的主身份仍占用唯一主身份名额。

## 部署顺序

1. 备份并完成上面的数据检查和修复。
2. 运行 Phinx 迁移 `20261010000000_add_persona_identity_constraints`，它会增加类型 CHECK、生成列、唯一主身份索引和身份查询索引。
3. 先部署包含 `ValidatePersonaOwnership` 的 user-service 及对应生成的 PHP/Go Proto 产物。
4. 再部署调用该 RPC 的 API Gateway。Gateway 在签发 WebSocket Ticket 前让 user-service 校验账号归属和当前状态。

迁移失败时应保留现场并修复数据或漂移的同名结构后重试。迁移不会接受仅名称相同但定义不一致的列、索引或 CHECK 约束，也不会静默覆盖这些结构。
