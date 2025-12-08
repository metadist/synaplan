.PHONY: help lint format phpstan

help: ## Show this help
	@echo "Top-level commands that run on all subdirectories:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

lint: ## Run linting checks on backend
	$(MAKE) -C backend lint

format: ## Format code in backend
	$(MAKE) -C backend format
