DC ?= docker compose
EXEC ?= $(DC) exec app

.PHONY: build up down restart logs sh install migrate seed reset routes-refresh test clean db-migrate db-seed db-reset

build:
	$(DC) build

up:
	$(DC) up -d

down:
	$(DC) down

restart: down up

logs:
	$(DC) logs -f

sh:
	$(EXEC) sh

install:
	$(EXEC) composer install

migrate:
	$(EXEC) php bin/console doctrine:migrations:migrate --no-interaction

seed:
	$(EXEC) php bin/console app:seed --purge

reset: migrate seed

routes-refresh:
	$(EXEC) php bin/console app:trips:refresh-routes

test:
	$(EXEC) bin/phpunit

clean:
	rm -rf var/cache/* var/log/*

