.DEFAULT_GOAL := help

COMPOSE ?= docker compose
SERVICE ?=
TAIL ?= 100
BROKER_SERVICE ?= rabbitmq

ifneq ($(BROKER_SERVICE),rabbitmq)
ifneq ($(BROKER_SERVICE),rabbitmq-test)
$(error BROKER_SERVICE must be rabbitmq or rabbitmq-test)
endif
endif

.PHONY: help config build start stop restart status logs logs-follow requirements console diagnose test-migrate test-db-create test-db-refresh coverage-generate coverage-download-report
.PHONY: rabbitmq-policy rabbitmq-topology rabbitmq-check test-rabbitmq

help:
	@echo "Available targets:"
	@echo "  help          Show this help"
	@echo "  config        Validate the Docker Compose configuration"
	@echo "  build         Validate configuration and build images"
	@echo "  start         Build and start the environment, then show status"
	@echo "  stop          Stop containers and networks; preserve named volumes"
	@echo "  restart       Stop and start the environment"
	@echo "  status        Show all container states"
	@echo "  logs          Show recent logs"
	@echo "  logs-follow   Follow logs"
	@echo "  requirements  Check PHP platform and Yii requirements"
	@echo "  console       Check the Yii console"
	@echo "  diagnose      Diagnose an already running environment"
	@echo "  rabbitmq-policy    Apply the tracked local broker policy"
	@echo "  rabbitmq-topology  Declare the configured application topology"
	@echo "  rabbitmq-check     Verify local broker topology and effective policy"
	@echo "  test-rabbitmq      Prepare the isolated broker and run transport tests"
	@echo "  test-migrate  Test migrate"
	@echo "  test-db-create  Test db create"
	@echo "  test-db-refresh  Test db refresh"
	@echo "  coverage-generate  coverage-generate"
	@echo "  coverage-download-report  coverage-download-report"
	@echo ""
	@echo "Parameters:"
	@echo "  SERVICE=<name>  Limit logs to a Compose service"
	@echo "  TAIL=<lines>    Number of log lines (default: 100)"
	@echo "  BROKER_SERVICE=<rabbitmq|rabbitmq-test>  Broker for policy/check (default: rabbitmq)"

config:
	$(COMPOSE) config --quiet

build:
	$(COMPOSE) config --quiet
	$(COMPOSE) build --pull

start:
	$(COMPOSE) config --quiet
	$(COMPOSE) up --detach --build --wait
	$(COMPOSE) ps

stop:
	$(COMPOSE) down

restart:
	$(MAKE) stop
	$(MAKE) start

status:
	$(COMPOSE) ps --all

logs:
	$(COMPOSE) logs --tail=$(TAIL) $(SERVICE)

logs-follow:
	$(COMPOSE) logs --follow --tail=$(TAIL) $(SERVICE)

requirements:
	$(COMPOSE) exec -T php-fpm composer check-platform-reqs
	$(COMPOSE) exec -T php-fpm php requirements.php

console:
	$(COMPOSE) exec -T php-fpm php yii

rabbitmq-policy:
	$(COMPOSE) exec -T $(BROKER_SERVICE) su-exec rabbitmq sh /etc/ideakit-rabbitmq/apply-policy.sh

rabbitmq-topology:
	$(COMPOSE) exec -T php-fpm php yii platform-messaging/declare

rabbitmq-check:
	$(COMPOSE) exec -T $(BROKER_SERVICE) su-exec rabbitmq sh /etc/ideakit-rabbitmq/apply-policy.sh --check

test-rabbitmq:
	$(COMPOSE) config --quiet
	$(COMPOSE) build php-fpm
	$(COMPOSE) up -d --wait php-fpm
	$(COMPOSE) --profile messaging-test up -d --wait rabbitmq-test
	$(COMPOSE) exec -T rabbitmq-test su-exec rabbitmq sh /etc/ideakit-rabbitmq/apply-policy.sh
	$(COMPOSE) exec -T -e APP_ENV=test -e TEST_RABBITMQ_HOST=rabbitmq-test -e TEST_RABBITMQ_PORT=5672 -e TEST_RABBITMQ_USER=transport-test -e TEST_RABBITMQ_PASSWORD=local-transport-test-only -e TEST_RABBITMQ_VHOST=ideakit_transport_test php-fpm vendor/bin/codecept run integration tests/integration/modules/platform/infrastructure/rabbitmq --no-colors

diagnose:
	$(COMPOSE) config --quiet
	$(COMPOSE) ps
	$(COMPOSE) exec -T postgres pg_isready
	$(COMPOSE) exec -T redis redis-cli ping
	$(COMPOSE) exec -T rabbitmq rabbitmq-diagnostics -q check_running
	$(COMPOSE) exec -T rabbitmq rabbitmq-diagnostics -q check_local_alarms
	$(COMPOSE) exec -T php-fpm composer check-platform-reqs
	$(COMPOSE) exec -T php-fpm php requirements.php
	$(COMPOSE) exec -T php-fpm php yii help
	$(COMPOSE) exec -T nginx wget --quiet --spider http://127.0.0.1/health/live
	$(COMPOSE) exec -T nginx wget --quiet --spider http://127.0.0.1/health/ready

test-migrate:
	php tests/bin/yii migrate/up --interactive=0

test-db-create:
	$(COMPOSE) exec postgres createdb -U ideakit ideakit_test

# Пересоздать тестовую БД (удалить + создать)
test-db-refresh:
	php tests/bin/yii migrate/fresh --interactive=0

coverage-generate:
	$(COMPOSE) exec php-fpm vendor/bin/codecept run unit --coverage-html

coverage-download-report:
	$(COMPOSE) cp php-fpm:/app/tests/_output/coverage ./docs/coverage_report
