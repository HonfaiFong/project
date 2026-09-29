.PHONY: all bootstrap proto lint analyse phpstan test up down restart build-runtime rebuild-runtime migrate seed e2e logs clean

PHP_RUNTIME_IMAGE := project-php-runtime:dev
GATEWAY_IMAGE     := project-realtime-gateway:latest

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

# 日常开发入口:镜像缺失时才构建,日常改代码不触发任何 build
up:
	@if ! docker image inspect $(PHP_RUNTIME_IMAGE) >/dev/null 2>&1; then \
		echo ">>> $(PHP_RUNTIME_IMAGE) 不存在,先构建 PHP runtime..."; \
		docker compose build php-runtime; \
	fi
	@if ! docker image inspect $(GATEWAY_IMAGE) >/dev/null 2>&1; then \
		echo ">>> $(GATEWAY_IMAGE) 不存在,先构建 realtime gateway..."; \
		docker compose build realtime-gateway; \
	fi
	docker compose up -d

down:
	docker compose down

restart:
	docker compose down
	docker compose up -d

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
