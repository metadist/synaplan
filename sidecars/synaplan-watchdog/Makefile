# synaplan-watchdog — per-server capacity agent (AU6). No relay.
.PHONY: build test lint format

GO ?= go
GOTOOLCHAIN ?= local
export GOTOOLCHAIN
BIN := bin/synaplan-watchdog
# Master plan: 2,000 lines of Go outside tests.
SIZE_BUDGET := 2000

build:
	$(GO) build -o $(BIN) ./cmd/synaplan-watchdog

test: lint
	$(GO) test -race ./...

lint:
	$(GO) vet ./...
	@test -z "$$($(GO)fmt -l cmd internal)" || { echo "gofmt: run make format"; $(GO)fmt -l cmd internal; exit 1; }
	@lines=$$(find cmd internal -name '*.go' ! -name '*_test.go' -print0 | xargs -0 wc -l | awk 'END { print $$1 }'); \
		echo "go lines outside tests: $$lines (budget $(SIZE_BUDGET))"; \
		test "$$lines" -le $(SIZE_BUDGET)

format:
	gofmt -w cmd internal
