.PHONY: up down composer-validate test test-integration style smoke verify

up:
	docker compose up -d --build

down:
	docker compose down

composer-validate:
	docker compose exec -T app composer validate --strict

test:
	docker compose exec -T app php artisan test

test-integration:
	docker compose exec -T -e RUN_GATEWAY_INTEGRATION_TESTS=true app php artisan test --testsuite=Integration

style:
	docker compose exec -T app vendor/bin/pint --test

smoke:
	docker compose exec -T app php scripts/smoke.php

verify: composer-validate style test test-integration smoke
