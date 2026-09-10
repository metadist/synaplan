# synaplan-compute — Wave 4 Secure Compute sidecar
.PHONY: build test lint format hostile images

GO ?= go
GOTOOLCHAIN ?= local
export GOTOOLCHAIN
BIN := bin/synaplan-compute

build:
	$(GO) build -o $(BIN) ./cmd/synaplan-compute
	$(GO) build -o bin/compute-spike ./cmd/compute-spike

test:
	$(GO) test ./...

lint:
	./scripts/no-shell-guard.sh
	@if command -v golangci-lint >/dev/null 2>&1; then golangci-lint run ./...; else echo "golangci-lint not installed; no-shell-guard passed"; fi

format:
	gofmt -w cmd internal pkg tests

hostile:
	$(GO) test ./tests/hostile/ ./internal/runner/ ./internal/audit/

images:
	docker build -t synaplan-compute-python ./images/python
	docker build -t synaplan-compute-node ./images/node
