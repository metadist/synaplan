# synaplan-compute — secure compute sidecar (protocol 1)
.PHONY: build test lint format hostile images

GO ?= go
GOTOOLCHAIN ?= local
export GOTOOLCHAIN
BIN := bin/synaplan-compute

build:
	$(GO) build -o $(BIN) ./cmd/synaplan-compute
	$(GO) build -o bin/compute-spike ./cmd/compute-spike

test:
	$(GO) test -race ./...

lint:
	./scripts/no-shell-guard.sh
	$(GO) vet ./...
	@test -z "$$(gofmt -l cmd internal pkg tests)" || { echo "gofmt: run make format"; gofmt -l cmd internal pkg tests; exit 1; }
	@if command -v node >/dev/null 2>&1; then node --check tests/hostile/node/*.js && echo "node --check: ok"; else echo "node not installed; skipping node --check"; fi
	@if command -v golangci-lint >/dev/null 2>&1; then golangci-lint run ./...; else echo "golangci-lint not installed; no-shell-guard passed"; fi

format:
	gofmt -w cmd internal pkg tests

hostile:
	$(GO) test ./tests/hostile/ ./internal/runner/ ./internal/audit/

images:
	docker build -t synaplan-compute-python ./images/python
	docker build -t synaplan-compute-node ./images/node
	@echo
	@echo "Local tags: synaplan-compute-python:latest  synaplan-compute-node:latest"
	@echo "The sidecar reads digest-pinned refs from internal/images/map.go and never pulls."
	@echo "Paste these Id digests there (synaplan-compute-<name>@<id>), then rebuild the sidecar:"
	@printf '  python %s\n' "$$(docker inspect --format '{{.Id}}' synaplan-compute-python:latest)"
	@printf '  node   %s\n' "$$(docker inspect --format '{{.Id}}' synaplan-compute-node:latest)"
