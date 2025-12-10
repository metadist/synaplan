.PHONY: help lint format phpstan audit deps migrate fixtures schema-update schema-diff console cache-clear shell test

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

## Code Quality
lint: ## Check code formatting (PSR-12)
	docker compose -f ../docker-compose.yml exec -T backend composer lint

format: ## Fix code formatting
	docker compose -f ../docker-compose.yml exec -T backend composer lint:fix

phpstan: ## Run static analysis
	docker compose -f ../docker-compose.yml exec -T backend composer phpstan

audit: ## Run security audit
	docker compose -f ../docker-compose.yml exec -T backend composer audit

## Dependencies
deps: ## Install composer dependencies
	docker compose -f ../docker-compose.yml exec -T backend composer install

## Database
migrate: ## Run migrations
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:migrations:migrate --no-interaction

fixtures: ## Load fixtures
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:fixtures:load --no-interaction

schema-update: ## Update database schema (dev only!)
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:schema:update --force

schema-diff: ## Show schema differences
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

