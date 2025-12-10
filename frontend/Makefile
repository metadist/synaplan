.PHONY: help lint test deps build build-widget check-types

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

## Building
build: ## Build frontend and widget
	docker compose -f ../docker-compose.yml exec -T frontend npm run build
	docker compose -f ../docker-compose.yml exec -T frontend npm run build:widget

build-widget: ## Build widget only
	docker compose -f ../docker-compose.yml exec -T frontend npm run build:widget

## Testing & Type Checking
lint: ## TypeScript type checking (alias for check-types)
	docker compose -f ../docker-compose.yml exec -T frontend npm run check:types

check-types: ## TypeScript type checking
	docker compose -f ../docker-compose.yml exec -T frontend npm run check:types

test: ## Run tests
	docker compose -f ../docker-compose.yml exec -T frontend npm run test

## Dependencies
deps: ## Install npm dependencies
	docker compose -f ../docker-compose.yml exec -T frontend npm ci
