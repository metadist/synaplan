# Local mail-handler testing (Greenmail + MailHog).
# Requires the main Synaplan stack: docker compose up -d (from repo root).

ROOT := $(abspath ../..)
COMPOSE := docker compose -f $(ROOT)/docker-compose.yml \
	-f $(ROOT)/_devextras/mail-handler-test/docker-compose.greenmail.yml

.PHONY: help up down ps logs setup send process flow watch

help:
	@echo "Mail handler local testing"
	@echo ""
	@echo "  make up       Start Greenmail (main stack must be running)"
	@echo "  make down     Stop and remove Greenmail"
	@echo "  make ps       Show Greenmail container status"
	@echo "  make logs     Follow Greenmail logs"
	@echo "  make setup    Create/update handler in DB (visible in /channels/email)"
	@echo "  make send     Send test email (TYPE=sales|support|hr|spam)"
	@echo "  make process  Run app:process-mail-handlers once"
	@echo "  make watch    Optional: poll handlers in a loop (INTERVAL=10 seconds)"
	@echo "  make flow     send + process (TYPE=support by default)"
	@echo ""
	@echo "Example:  make up setup && make flow TYPE=support"
	@echo "Forwards:  http://localhost:8025 (MailHog)"

up:
	$(COMPOSE) up -d greenmail

down:
	docker stop synaplan-greenmail 2>/dev/null || true
	docker rm synaplan-greenmail 2>/dev/null || true

ps:
	docker ps --filter name=synaplan-greenmail --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'

logs:
	docker logs -f synaplan-greenmail

setup:
	$(COMPOSE) up -d backend
	$(COMPOSE) exec -T backend php /var/www/mail-test/setup-local-mail-handler.php

send:
	$(COMPOSE) exec -T backend php /var/www/mail-test/send-test-email.php $(if $(TYPE),--type=$(TYPE),)

process:
	$(COMPOSE) exec -T backend php bin/console app:process-mail-handlers

# Simulates production cron (* * * * * app:process-mail-handlers). Default: every 10s.
INTERVAL ?= 10

watch:
	$(COMPOSE) up -d greenmail
	@echo "Polling mail handlers every $(INTERVAL)s (Ctrl+C to stop)..."
	@while true; do \
		echo "--- $$(date -Iseconds) ---"; \
		$(COMPOSE) exec -T backend php bin/console app:process-mail-handlers || true; \
		sleep $(INTERVAL); \
	done

flow:
	$(MAKE) send TYPE=$(or $(TYPE),support)
	sleep 2
	$(MAKE) process
