.PHONY: up down composer-validate prepare-test-mysql-db test test-critical-mysql test-integration style smoke verify

up:
	docker compose up -d --build

down:
	docker compose down

composer-validate:
	docker compose exec -T app composer validate --strict

prepare-test-mysql-db:
	docker compose exec -T mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS betalent_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON betalent_test.* TO 'betalent'@'%'; FLUSH PRIVILEGES;"

test:
	docker compose exec -T app php artisan test

test-critical-mysql: prepare-test-mysql-db
	docker compose exec -T \
		-e RUN_CRITICAL_MYSQL_TESTS=true \
		-e TEST_DB_CONNECTION=mysql \
		-e TEST_DB_HOST=mysql \
		-e TEST_DB_PORT=3306 \
		-e TEST_DB_DATABASE=betalent_test \
		-e TEST_DB_USERNAME=betalent \
		-e TEST_DB_PASSWORD=betalent \
		app php artisan test --testsuite=CriticalMySql

test-integration:
	docker compose exec -T -e RUN_GATEWAY_INTEGRATION_TESTS=true app php artisan test --testsuite=Integration

style:
	docker compose exec -T app vendor/bin/pint --test

smoke:
	docker compose exec -T app php scripts/smoke.php

verify: composer-validate style test test-critical-mysql test-integration smoke
