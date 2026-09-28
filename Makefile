.PHONY: all bootstrap proto lint test up down restart migrate seed e2e logs clean

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

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose down
	docker compose up -d

migrate:
	@echo "Applying database schemas..."
	docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$$MYSQL_ROOT_PASSWORD"' < infra/docker/mysql/init/01-databases.sql

seed:
	@echo "Seeding initial dev fixtures..."

e2e:
	bash scripts/e2e_test.sh

logs:
	docker compose logs -f

clean:
	docker compose down -v --remove-orphans
