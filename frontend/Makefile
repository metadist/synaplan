.PHONY: help lint format check-types test test-e2e test-e2e-plugin-castingdata deps deps-host build build-widget generate-schemas

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

## Code Quality
lint: ## Check code formatting and linting
	docker compose -f ../docker-compose.yml exec frontend npm run format:check
	docker compose -f ../docker-compose.yml exec frontend npm run lint

format: ## Fix code formatting and linting
	docker compose -f ../docker-compose.yml exec frontend npm run format
	docker compose -f ../docker-compose.yml exec frontend npm run lint:fix

check-types: ## TypeScript type checking
	docker compose -f ../docker-compose.yml exec -T frontend npm run check:types

## Building
build: ## Build frontend and widget
	docker compose -f ../docker-compose.yml exec -T frontend npm run build
	docker compose -f ../docker-compose.yml exec -T frontend npm run build:widget

build-widget: ## Build widget only
	docker compose -f ../docker-compose.yml exec -T frontend npm run build:widget

## Code Generation
generate-schemas: ## Generate Zod schemas from OpenAPI spec
	docker compose -f ../docker-compose.yml exec -T frontend npm run generate:schemas

## Testing
test: ## Run unit tests
	docker compose -f ../docker-compose.yml exec -T frontend npm run test -- --run

test-e2e: ## Run e2e tests
	npm run test:e2e

test-e2e-plugin-castingdata: ## Run Casting Data plugin e2e tests (requires CastApp + Synaplan running)
	npm run test:e2e:plugin:castingdata

## Dependencies
deps-host: ## Install npm dependencies on host (not in container) for playwright
	npm ci

deps: ## Install npm dependencies
	docker compose -f ../docker-compose.yml exec -T frontend npm ci
