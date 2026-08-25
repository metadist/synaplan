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

# Restart Vite first so a stale optimize-deps cache can't serve 504s (blank app
# -> openApp timeout), then wait until it actually re-serves a core module —
# without the wait the first run races the optimize-deps rebuild and hits the
# very blank-app it was meant to prevent. Costs a few seconds; skip both with
# SKIP_FRONTEND_RESTART=1 when targeting a non-dev BASE_URL (e.g. :8001 stack).
test-e2e: ## Run e2e tests (fast loop, dev stack :5173 or BASE_URL)
	@[ -n "$(SKIP_FRONTEND_RESTART)" ] || { \
		docker compose restart frontend; \
		echo "Waiting for Vite to re-optimize deps on :5173 ..."; \
		i=0; until curl -fsS -o /dev/null http://localhost:5173/src/main.ts 2>/dev/null; do \
			i=$$((i+1)); [ $$i -ge 60 ] && { echo "frontend :5173 not ready after 60s" >&2; exit 1; }; \
			sleep 1; done; }
	$(MAKE) -C frontend test-e2e

test-e2e-full: ## Build test stack + run all E2E tests (CI-like, port 8001)
	$(MAKE) test-stack-build
	cd frontend && BASE_URL=http://localhost:8001 npm run test:e2e

test-e2e-plugin-castingdata: ## Run Casting Data plugin e2e tests (CastApp + Synaplan must be running)
	$(MAKE) -C frontend test-e2e-plugin-castingdata

test-stack-build: ## Build frontend + widget + test Docker image + start test stack on port 8001
	docker compose -f docker-compose.test.yml down 2>/dev/null || true
	rm -rf frontend/dist frontend/dist-widget || ( \
		echo "$(MAKE): dist not removable as current user; cleaning via Docker (no sudo)..." && \
		docker run --rm -v "$(CURDIR)/frontend:/t" alpine:3.20 sh -c 'rm -rf /t/dist /t/dist-widget' )
	cd frontend && npm run build && npm run build:widget
	docker compose -f docker-compose.test.yml build
	docker compose -f docker-compose.test.yml up -d --wait --wait-timeout 600

audit: ## Run security audit (backend)
	$(MAKE) -C backend audit

## Building
build: ## Build everything (frontend app + widget)
	$(MAKE) -C frontend build

## Dependencies
deps: ## Install all dependencies (backend + frontend)
	$(MAKE) -C backend deps
	$(MAKE) -C frontend deps
