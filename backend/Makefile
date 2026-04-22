.PHONY: help lint format phpstan phpstan-baseline audit deps migrate migrate-status migrate-diff migrate-generate seed seed-models seed-prompts seed-defaults seed-ratelimits fixtures schema-update schema-diff console cache-clear shell test

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

## Code Quality
lint: ## Check code formatting (PSR-12)
	docker compose -f ../docker-compose.yml exec -T backend composer lint

format: ## Fix code formatting
	docker compose -f ../docker-compose.yml exec -T backend composer lint:fix

phpstan: ## Run static analysis
	docker compose -f ../docker-compose.yml exec -T backend composer phpstan

phpstan-baseline: ## Regenerate PHPStan baseline
	docker compose -f ../docker-compose.yml exec -T backend vendor/bin/phpstan analyse --generate-baseline

audit: ## Run security audit
	docker compose -f ../docker-compose.yml exec -T backend composer audit

## Dependencies
deps: ## Install composer dependencies
	docker compose -f ../docker-compose.yml exec -T backend composer install

## Database — Migrations (preferred)
migrate: ## Apply pending Doctrine migrations
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

migrate-status: ## Show migration status
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:migrations:status

migrate-diff: ## Generate a new migration from ORM ↔ DB diff
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:migrations:diff --no-interaction

migrate-generate: ## Create an empty migration skeleton
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:migrations:generate

## Database — Seeding (idempotent, prod-safe)
seed: ## Run all idempotent seeders (models, prompts, defaults, rate-limits, demo)
	docker compose -f ../docker-compose.yml exec -T backend php bin/console app:seed --no-interaction

seed-models: ## Seed AI model catalog (BMODELS)
	docker compose -f ../docker-compose.yml exec -T backend php bin/console app:model:seed --no-interaction

seed-prompts: ## Seed system prompts (BPROMPTS)
	docker compose -f ../docker-compose.yml exec -T backend php bin/console app:prompt:seed --no-interaction

seed-defaults: ## Seed DEFAULTMODEL config (BCONFIG)
	docker compose -f ../docker-compose.yml exec -T backend php bin/console app:config:seed-defaults --no-interaction

seed-ratelimits: ## Seed rate-limit config (BCONFIG)
	docker compose -f ../docker-compose.yml exec -T backend php bin/console app:ratelimit:seed-defaults --no-interaction

## Database — Demo data
fixtures: ## Load demo user fixtures (PURGES entity tables — dev/test only)
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction

## Database — Emergency only (do NOT use in production)
schema-update: ## ⚠️  Emergency dev-only: force schema sync. Prefer 'make migrate-diff && make migrate'.
	@echo "⚠️  schema-update bypasses migrations and can corrupt prod state."
	@echo "   Generate a migration instead: make migrate-diff && make migrate"
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:schema:update --force

schema-diff: ## Show schema differences (debugging only — for migrations use migrate-diff)
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:schema:update --dump-sql

## Symfony Console
console: ## Open Symfony console (pass args: make console -- list)
	docker compose -f ../docker-compose.yml exec backend php bin/console $(filter-out $@,$(MAKECMDGOALS))

## Utilities
shell: ## Open bash shell in backend container
	docker compose -f ../docker-compose.yml exec backend bash

cache-clear: ## Clear Symfony cache
	docker compose -f ../docker-compose.yml exec -T backend php bin/console cache:clear

test: ## Run all PHPUnit tests
	docker compose -f ../docker-compose.yml exec -T backend php bin/phpunit

# Allow passing arguments to console target
%:
	@:

