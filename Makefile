.PHONY: help lint format test build deps audit test-stack-build

help: ## Show this help
	@echo "Common commands (runs in backend and/or frontend as appropriate):"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "For backend/frontend specific commands: make -C backend help | make -C frontend help"

## Quality Checks (runs in both backend and frontend)
lint: ## Check code quality (backend PSR-12 + frontend types)
	$(MAKE) -C backend lint
	$(MAKE) -C frontend lint

format: ## Fix code formatting (backend + frontend)
	$(MAKE) -C backend format
	$(MAKE) -C frontend format

test: ## Run all tests (backend + frontend unit tests)
	$(MAKE) -C backend test
	$(MAKE) -C frontend test

test-e2e: ## Run e2e tests
	$(MAKE) -C frontend test-e2e

test-e2e-plugin-castingdata: ## Run Casting Data plugin e2e tests (CastApp + Synaplan must be running)
	$(MAKE) -C frontend test-e2e-plugin-castingdata

test-stack-build: ## Build frontend + widget + test Docker image + start test stack on port 8001
	docker compose -f docker-compose.test.yml down 2>/dev/null || true
	cd frontend && npm run build && npm run build:widget
	docker compose -f docker-compose.test.yml build
	docker compose -f docker-compose.test.yml up -d

audit: ## Run security audit (backend)
	$(MAKE) -C backend audit

## Building
build: ## Build everything (frontend app + widget)
	$(MAKE) -C frontend build

## Dependencies
deps: ## Install all dependencies (backend + frontend)
	$(MAKE) -C backend deps
	$(MAKE) -C frontend deps
