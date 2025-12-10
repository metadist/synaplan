.PHONY: help lint test deps build build-widget check-types

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

## Building
build: ## Build frontend and widget
	npm run build
	npm run build:widget

build-widget: ## Build widget only
	npm run build:widget

## Testing & Type Checking
lint: ## TypeScript type checking (alias for check-types)
	npm run check:types

check-types: ## TypeScript type checking
	npm run check:types

test: ## Run tests
	npm run test

## Dependencies
deps: ## Install npm dependencies
	npm ci
