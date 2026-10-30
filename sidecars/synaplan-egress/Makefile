.PHONY: test format

GO ?= go
export GOTOOLCHAIN ?= local

test:
	$(GO) test ./...

format:
	gofmt -w cmd internal
