.PHONY: all bootstrap proto lint analyse phpstan test test-unit test-integration test-legacy-mysql test-postgres test-message test-database-failover up infra-up broker-init storage-up app-up down restart build-runtime rebuild-runtime migrate preflight-phone postgres-ready redis-ready seed e2e e2e-social latency-probe logs clean

PHP_RUNTIME_IMAGE := project-php-runtime:rabbitmq
GATEWAY_IMAGE     := project-realtime-gateway:redis-nonce

all: test

bootstrap:
	cp -n .env.example .env 2>/dev/null || true
	composer install --no-interaction
	go mod download

proto:
	@docker build -q -f infra/docker/proto/Dockerfile -t social-proto-tool .
	@docker run --rm -v "$(CURDIR):/app" -w /app social-proto-tool sh scripts/generate_proto.sh

lint:
	@echo "Running PHP syntax checks..."
	@find packages services apps contracts scripts tests database phinx.php -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null
	@echo "Running Go vet..."
	@go vet -mod=mod ./...
	@echo "All lint checks passed!"

test: test-unit
	@echo "Running Go Tests..."
	@go test -mod=mod ./...

test-unit:
	@echo "Running PHP Unit Tests..."
	@vendor/bin/phpunit --testsuite Unit --fail-on-skipped

test-integration:
	@echo "Running PHP Integration Tests..."
	@vendor/bin/phpunit --testsuite Integration --fail-on-skipped

test-legacy-mysql:
	@vendor/bin/phpunit --testsuite LegacyMysql --fail-on-skipped

test-postgres:
	@echo "Running PostgreSQL integration tests..."
	docker compose run --rm --no-deps -e DATABASE_INTEGRATION=1 --entrypoint php database-migrate vendor/bin/phpunit tests/Integration/PostgresMigrationRuntimeTest.php tests/Integration/EloquentPersistenceTest.php tests/Integration/UserOutboxTransactionTest.php --fail-on-skipped

test-message:
	@echo "Running PostgreSQL message persistence and recovery tests..."
	docker compose run --rm --no-deps -e DATABASE_INTEGRATION=1 --entrypoint php database-migrate vendor/bin/phpunit tests/Integration/PostgresMessagePersistenceTest.php --fail-on-skipped

test-database-failover:
	@echo "Running transaction fault injection, idempotent recovery and Redis persistence tests..."
	$(MAKE) test-message
	bash scripts/redis_revocation_persistence_test.sh

infra-up:
	docker compose up -d postgres redis rabbitmq
	$(MAKE) postgres-ready redis-ready
	$(MAKE) broker-init

broker-init:
	docker compose run --rm rabbitmq-init

storage-up:
	docker compose --profile storage up -d rustfs
	docker compose --profile storage run --rm storage-init

app-up:
	docker compose up -d auth-service user-service user-outbox-relay message-outbox-relay chat-message-consumer chat-message-reconciler content-service social-service chat-service message-service api-gateway realtime-gateway

# 标准开发入口: 镜像校验/构建 -> 启动基础设施 -> 等待 PostgreSQL 就绪 -> 迁移数据库 -> 启动应用服务
up:
	@if ! docker image inspect $(PHP_RUNTIME_IMAGE) >/dev/null 2>&1; then \
		echo ">>> $(PHP_RUNTIME_IMAGE) 不存在,先构建 PHP runtime..."; \
		docker compose build php-runtime; \
	fi
	@if ! docker image inspect $(GATEWAY_IMAGE) >/dev/null 2>&1; then \
		echo ">>> $(GATEWAY_IMAGE) 不存在,先构建 realtime gateway..."; \
		docker compose build realtime-gateway; \
	fi
	@echo ">>> 启动基础设施 (PostgreSQL / Redis / RabbitMQ)..."
	docker compose up -d postgres redis rabbitmq
	@$(MAKE) broker-init
	@$(MAKE) postgres-ready redis-ready
	@echo ">>> 执行数据库基线与增量迁移..."
	@$(MAKE) migrate
	@echo ">>> 启动业务应用与网关服务..."
	docker compose up -d auth-service user-service user-outbox-relay message-outbox-relay chat-message-consumer chat-message-reconciler content-service social-service chat-service message-service api-gateway realtime-gateway

down:
	docker compose down

restart: down up

# 仅当 PHP 版本 / 扩展 / RoadRunner / Dockerfile 变化时才需要;顺序构建避免内存争抢
build-runtime:
	docker compose build realtime-gateway
	docker compose build php-runtime

# 缓存全弃用,排查 Dockerfile 问题时用
rebuild-runtime:
	docker compose build --no-cache php-runtime

migrate:
	@echo "Applying database migrations with Phinx..."
	bash scripts/run_migrations.sh

postgres-ready:
	@echo ">>> Waiting for PostgreSQL SQL readiness..."
	@ready=0; \
	for i in $$(seq 1 60); do \
		if docker compose exec -T postgres sh -ec 'export PGPASSWORD="$$POSTGRES_PASSWORD"; psql -h 127.0.0.1 -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -c "SELECT 1" >/dev/null 2>&1'; then \
			ready=1; \
			break; \
		fi; \
		sleep 1; \
	done; \
	if [ "$$ready" -ne 1 ]; then \
		echo ">>> PostgreSQL SQL readiness timed out"; \
		exit 1; \
	fi

redis-ready:
	@echo ">>> 等待 Redis readiness..."
	@ready=0; \
	for i in $$(seq 1 30); do \
		if docker compose exec -T redis sh -c 'redis-cli ping | grep -q PONG' >/dev/null 2>&1; then \
			ready=1; \
			break; \
		fi; \
		sleep 1; \
	done; \
	if [ "$$ready" -ne 1 ]; then \
		echo ">>> 错误: Redis readiness 等待超时"; \
		exit 1; \
	fi

preflight-phone:
	@echo "Checking historical user phones (optional legacy data maintenance)..."
	docker compose run --rm --no-deps user-service php /app/scripts/normalize_user_phones.php --require-clean

seed:
	@echo "Seeding initial dev fixtures..."

analyse:
	vendor/bin/phpstan analyse --no-progress --memory-limit=512M

phpstan: analyse

e2e:
	bash scripts/e2e_test.sh
	bash scripts/social_e2e_test.sh
	bash scripts/auth_latency_probe.sh

e2e-social:
	bash scripts/social_e2e_test.sh

# 针对运行中的服务栈，报告真实社交登录调用链（网关 -> gRPC -> OAuth/PostgreSQL/Redis -> user-service）的 P50/P95/P99。
latency-probe:
	bash scripts/auth_latency_probe.sh

logs:
	docker compose logs -f

clean:
	docker compose down -v --remove-orphans
