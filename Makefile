.DEFAULT_GOAL := help

COMPOSE ?= docker compose
SERVICE ?=
TAIL ?= 100

.PHONY: help config build start stop restart status logs logs-follow requirements console diagnose

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
	@echo ""
	@echo "Parameters:"
	@echo "  SERVICE=<name>  Limit logs to a Compose service"
	@echo "  TAIL=<lines>    Number of log lines (default: 100)"

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
