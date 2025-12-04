.PHONY: help dev-up dev-down test-up test-down test test-unit test-integration migrate-test fixtures-test clean lint format phpstan

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

## Code Quality
lint: ## Check code formatting
	docker compose -f ../docker-compose.yml exec -T backend composer lint

format: ## Fix code formatting
	docker compose -f ../docker-compose.yml exec -T backend composer lint:fix

phpstan: ## Run static analysis
	docker compose -f ../docker-compose.yml exec -T backend composer phpstan

## Development Environment
dev-up: ## Start dev environment
	docker compose -f ../docker-compose.yml up -d

dev-down: ## Stop dev environment
	docker compose -f ../docker-compose.yml down

dev-logs: ## Show dev logs
	docker compose -f ../docker-compose.yml logs -f backend

dev-shell: ## Open shell in dev container
	docker compose -f ../docker-compose.yml exec backend bash

## Test Environment
test-up: ## Start test infrastructure (basic)
	docker compose -f ../docker-compose.test.yml up -d

test-up-full: ## Start test infrastructure with integration services
	docker compose -f ../docker-compose.test.yml --profile integration up -d

test-down: ## Stop test infrastructure
	docker compose -f ../docker-compose.test.yml down

test-clean: ## Stop test infrastructure and remove volumes
	docker compose -f ../docker-compose.test.yml down -v

## Testing
test: test-up migrate-test ## Run all tests
	docker compose -f ../docker-compose.test.yml exec -T app_test php bin/phpunit
	$(MAKE) test-down

test-unit: test-up migrate-test ## Run unit tests only
	docker compose -f ../docker-compose.test.yml exec -T app_test php bin/phpunit --testsuite unit
	$(MAKE) test-down

test-integration: test-up-full migrate-test ## Run integration tests
	docker compose -f ../docker-compose.test.yml exec -T app_test php bin/phpunit --testsuite integration
	$(MAKE) test-down

test-watch: test-up migrate-test ## Run tests in watch mode
	docker compose -f ../docker-compose.test.yml exec app_test php bin/phpunit --watch

## Database
migrate: ## Run dev migrations
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:migrations:migrate --no-interaction

migrate-test: ## Run test migrations
	docker compose -f ../docker-compose.test.yml exec -T app_test php bin/console doctrine:migrations:migrate --no-interaction --env=test

fixtures: ## Load dev fixtures
	docker compose -f ../docker-compose.yml exec -T backend php bin/console doctrine:fixtures:load --no-interaction

fixtures-test: ## Load test fixtures
	docker compose -f ../docker-compose.test.yml exec -T app_test php bin/console doctrine:fixtures:load --no-interaction --env=test

## Cleanup
clean: ## Clean all containers and volumes
	docker compose -f ../docker-compose.yml down -v
	docker compose -f ../docker-compose.test.yml down -v

cache-clear: ## Clear Symfony cache
	docker compose -f ../docker-compose.yml exec -T backend php bin/console cache:clear

cache-clear-test: ## Clear test cache
	docker compose -f ../docker-compose.test.yml exec -T app_test php bin/console cache:clear --env=test

