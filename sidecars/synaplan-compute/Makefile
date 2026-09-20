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
	./scripts/pin-nightly-map.py --map internal/images/map.go --check
	$(GO) vet ./...
	@test -z "$$(gofmt -l cmd internal pkg tests)" || { echo "gofmt: run make format"; gofmt -l cmd internal pkg tests; exit 1; }
	@if command -v node >/dev/null 2>&1; then node --check tests/hostile/node/*.js && echo "node --check: ok"; else echo "node not installed; skipping node --check"; fi
	@if command -v golangci-lint >/dev/null 2>&1; then golangci-lint run ./...; else echo "golangci-lint not installed; no-shell-guard passed"; fi

format:
	gofmt -w cmd internal pkg tests

hostile:
	$(GO) test ./tests/hostile/ ./internal/runner/ ./internal/audit/

# Tag :local (compose + nightly). Do not also tag :latest — nightly
# retags :local onto an ephemeral registry for real RepoDigests.
images:
	docker build -t synaplan-compute-python:local ./images/python
	docker build -t synaplan-compute-node:local ./images/node
