# Synaplan Testing Makefile
# =========================
#
# All testing commands in one place
# Run from testing/ directory

.PHONY: help test test-reports test-docker test-setup test-teardown clean

# Default target
help: ## Show this help message
	@echo "Synaplan Testing Commands"
	@echo "========================="
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "Examples:"
	@echo "  make test             # Run tests with Docker DB"
	@echo "  make test-reports     # Generate HTML reports"
	@echo "  make test-docker      # Run everything in Docker"

# Run tests with Docker database
test: ## Run unit tests with Docker database
	@echo "🧪 Running tests with Docker database..."
	@cd .. && docker compose up -d db
	@sleep 5
	@../vendor/bin/phpunit --configuration config/phpunit.xml

# Generate HTML reports with coverage (requires Docker with Xdebug)
test-reports: ## Generate HTML test reports
	@echo "📊 Generating HTML test reports..."
	@docker compose -f config/docker-compose.test.yml up --abort-on-container-exit app-test
	@echo "✅ Reports generated:"
	@echo "📄 TestDox: file://$(PWD)/reports/testdox.html"

# Generate simple reports without coverage
test-simple: ## Generate simple reports without coverage
	@echo "📄 Generating simple test reports..."
	@cd .. && docker compose up -d db
	@sleep 5
	@mkdir -p reports
	@../vendor/bin/phpunit --configuration config/phpunit.xml --testsuite=Unit --testdox-html reports/testdox.html --log-junit reports/junit.xml
	@echo "✅ Simple reports generated:"
	@echo "📄 TestDox: file://$(PWD)/reports/testdox.html"
	@echo "🔧 JUnit: file://$(PWD)/reports/junit.xml"

# Run everything in Docker containers
test-docker: ## Run tests completely in Docker
	@echo "🐳 Running tests in Docker containers..."
	@docker compose -f config/docker-compose.test.yml up --abort-on-container-exit app-test

# Setup test environment
test-setup: ## Setup test database
	@echo "🔧 Starting test database..."
	@cd .. && docker compose up -d db
	@sleep 5

# Teardown test environment  
test-teardown: ## Stop test database
	@echo "🛑 Stopping test database..."
	@cd .. && docker compose down

# Clean up all test artifacts
clean: ## Clean up test reports and containers
	@echo "🧹 Cleaning up test environment..."
	@rm -rf reports/ coverage/
	@docker compose -f config/docker-compose.test.yml down -v --remove-orphans 2>/dev/null || true
	@cd .. && docker compose down 2>/dev/null || true
	@echo "✅ Test environment cleaned!"