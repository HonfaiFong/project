.PHONY: all bootstrap proto lint analyse phpstan test up infra-up broker-init storage-up app-up down restart build-runtime rebuild-runtime migrate preflight-phone mysql-ready redis-ready seed e2e logs clean

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
	@find packages services apps contracts tests -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null
	@echo "Running Go vet..."
	@go vet -mod=mod ./...
	@echo "All lint checks passed!"

test:
	@echo "Running PHP Unit Tests..."
	@vendor/bin/phpunit
	@echo "Running Go Tests..."
	@go test -mod=mod ./...

infra-up:
	docker compose up -d mysql redis rabbitmq
	$(MAKE) mysql-ready redis-ready
	$(MAKE) broker-init

broker-init:
	docker compose run --rm rabbitmq-init

storage-up:
	docker compose --profile storage up -d rustfs
	docker compose --profile storage run --rm storage-init

app-up:
	docker compose up -d auth-service user-service user-outbox-relay api-gateway realtime-gateway

# 标准开发入口: 镜像校验/构建 -> 启动基础设施 -> 等待 MySQL 就绪 -> 迁移数据库 -> 启动应用服务
up:
	@if ! docker image inspect $(PHP_RUNTIME_IMAGE) >/dev/null 2>&1; then \
		echo ">>> $(PHP_RUNTIME_IMAGE) 不存在,先构建 PHP runtime..."; \
		docker compose build php-runtime; \
	fi
	@if ! docker image inspect $(GATEWAY_IMAGE) >/dev/null 2>&1; then \
		echo ">>> $(GATEWAY_IMAGE) 不存在,先构建 realtime gateway..."; \
		docker compose build realtime-gateway; \
	fi
	@echo ">>> 启动基础设施 (MySQL / Redis / RabbitMQ)..."
	docker compose up -d mysql redis rabbitmq
	@$(MAKE) broker-init
	@$(MAKE) mysql-ready redis-ready
	@echo ">>> 执行数据库基线与增量迁移..."
	@$(MAKE) migrate
	@echo ">>> 启动业务应用与网关服务..."
	docker compose up -d auth-service user-service user-outbox-relay api-gateway realtime-gateway

down:
	docker compose down

restart: down up

# 仅当 PHP 版本 / 扩展 / RoadRunner / Dockerfile 变化时才需要
build-runtime:
	docker compose build php-runtime realtime-gateway

# 缓存全弃用,排查 Dockerfile 问题时用
rebuild-runtime:
	docker compose build --no-cache php-runtime

migrate:
	@echo "Applying baseline schema (idempotent)..."
	docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$$MYSQL_ROOT_PASSWORD"' < infra/docker/mysql/init/01-databases.sql
	bash scripts/run_migrations.sh
	@$(MAKE) preflight-phone

mysql-ready:
	@echo ">>> 等待 MySQL SQL readiness..."
	@ready=0; \
	for i in $$(seq 1 60); do \
		if docker compose exec -T mysql sh -c 'mysql -h 127.0.0.1 -uroot -p"$$MYSQL_ROOT_PASSWORD" -e "SELECT 1" >/dev/null 2>&1'; then \
			ready=1; \
			break; \
		fi; \
		sleep 1; \
	done; \
	if [ "$$ready" -ne 1 ]; then \
		echo ">>> 错误: MySQL SQL readiness 等待超时"; \
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
	@echo "Checking historical user phones before starting services..."
	docker compose run --rm --no-deps user-service php /app/scripts/normalize_user_phones.php --require-clean

seed:
	@echo "Seeding initial dev fixtures..."

analyse:
	vendor/bin/phpstan analyse --no-progress --memory-limit=512M

phpstan: analyse

e2e:
	bash scripts/e2e_test.sh

logs:
	docker compose logs -f

clean:
	docker compose down -v --remove-orphans
