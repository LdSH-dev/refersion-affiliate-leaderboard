HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID HOST_GID

COMPOSE := docker compose
PHP     := $(COMPOSE) run --rm --no-deps app
NODE    := $(COMPOSE) run --rm --no-deps node

.PHONY: setup build up dev down test test-js board shell logs

## First run: build the image, install dependencies, create .env, build the frontend.
setup:
	$(COMPOSE) build
	$(PHP) composer install
	@test -f .env || cp .env.example .env
	$(PHP) php artisan key:generate
	$(NODE) npm ci
	$(NODE) npm run build

## Rebuild the frontend bundle served by `make up`.
build:
	$(NODE) npm run build

## App on http://localhost:8000 (pre-built assets) + mock Refersion API on :8080.
up:
	@rm -f public/hot  # a stale Vite hot file would point the page at a dead dev server
	$(COMPOSE) up -d --wait app mock
	@echo "Ready: http://localhost:8000"

## Same as `up`, plus the Vite dev server with hot reload on :5173.
dev:
	$(COMPOSE) --profile dev up -d --wait app mock
	$(COMPOSE) --profile dev up -d node

down:
	$(COMPOSE) --profile dev down
	@rm -f public/hot

## Backend test suite (PHPUnit). HTTP is faked; no network access.
test:
	$(PHP) php artisan test

## Frontend test suite (Vitest + Testing Library).
test-js:
	$(NODE) npm test

## Print the leaderboard in the terminal. Pass ARGS="--fresh" or ARGS="--json".
board:
	$(COMPOSE) run --rm app php artisan leaderboard:show $(ARGS)

shell:
	$(COMPOSE) exec app bash

logs:
	$(COMPOSE) logs -f app mock
