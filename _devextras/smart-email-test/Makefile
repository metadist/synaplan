# Local Smart Email testing (MailHog → app:process-emails).
# Requires the main Synaplan stack: docker compose up -d (from repo root).

ROOT := $(abspath ../..)
COMPOSE := docker compose -f $(ROOT)/docker-compose.yml \
	-f $(ROOT)/_devextras/smart-email-test/docker-compose.yml

.PHONY: help watch-smart-email process send

help:
	@echo "Smart Email local testing (MailHog port 1025)"
	@echo ""
	@echo "  make watch-smart-email   Poll and process smart@ mails (INTERVAL=10)"
	@echo "  make process             Process once"
	@echo "  make send                Send test mail (BODY=..., SUBJECT=..., FROM=..., TO=...)"
	@echo ""
	@echo "  KEEP=1                   Keep processed inbound mails in MailHog (debug only)"
	@echo ""
	@echo "Example:  make watch-smart-email"
	@echo "           make send BODY=\"Create an image of a red cat.\""
	@echo "Inbox:     http://localhost:8025 (Re: … replies stay; inbound smart@ removed after process)"

INTERVAL ?= 10
# Prod-like default: remove processed smart@ inbound from MailHog (no 3/10 re-poll noise).
KEEP_FLAG := $(if $(KEEP),--keep,)

watch-smart-email:
	$(COMPOSE) up -d backend mailhog
	$(COMPOSE) exec backend php bin/console app:process-emails --watch --interval=$(INTERVAL) $(KEEP_FLAG)

process:
	$(COMPOSE) up -d backend mailhog
	$(COMPOSE) exec -T backend php bin/console app:process-emails $(KEEP_FLAG)

send:
	$(COMPOSE) up -d backend mailhog
	$(COMPOSE) exec -T backend php /var/www/smart-email-test/send-smart-email.php \
		$(if $(SUBJECT),--subject=$(SUBJECT),) \
		$(if $(FROM),--from=$(FROM),) \
		$(if $(TO),--to=$(TO),) \
		$(if $(BODY),--body=$(BODY),)
