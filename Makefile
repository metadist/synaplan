.PHONY: lint format
lint:
	./vendor/bin/php-cs-fixer check

format:
	./vendor/bin/php-cs-fixer fix