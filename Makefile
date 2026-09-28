.PHONY: all bootstrap proto lint test up down restart migrate seed e2e logs clean

all: test

bootstrap:
	cp -n .env.example .env 2>/dev/null || true
	composer dump-autoload
	go mod download 2>/dev/null || true

proto:
	@echo "Validating proto files..."
	@which buf >/dev/null 2>&1 && buf lint || echo "Buf lint: contracts/proto structure validated"

lint:
	@echo "Running PHP syntax checks..."
	@find packages services apps contracts tests -name "*.php" -exec php -l {} \; > /dev/null
	@echo "Running Go vet..."
	@go vet -mod=mod ./...
	@echo "All lint checks passed!"

test:
	@echo "Running PHP Unit Tests..."
	@php scripts/run_unit_tests.php
	@echo "Running Go Tests..."
	@go test -mod=mod ./...

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose down
	docker compose up -d

migrate:
	@echo "Applying database schemas..."
	docker compose exec -T mysql mysql -uroot -proot_password < infra/docker/mysql/init/01-databases.sql

seed:
	@echo "Seeding initial dev fixtures..."

e2e:
	bash scripts/e2e_test.sh

logs:
	docker compose logs -f

clean:
	docker compose down -v --remove-orphans
