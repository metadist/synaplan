.PHONY: help lint format test build deps audit

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

format: ## Fix code formatting (backend only)
	$(MAKE) -C backend format

test: ## Run all tests (backend + frontend)
	$(MAKE) -C backend test
	$(MAKE) -C frontend test

audit: ## Run security audit (backend)
	$(MAKE) -C backend audit

## Building
build: ## Build everything (frontend app + widget)
	$(MAKE) -C frontend build

## Dependencies
deps: ## Install all dependencies (backend + frontend)
	$(MAKE) -C backend deps
	$(MAKE) -C frontend deps
