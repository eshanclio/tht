COMPOSE = docker compose
APP = $(COMPOSE) exec --user $(shell id -u):$(shell id -g) app

.DEFAULT_GOAL := help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

build: ## Build images
	$(COMPOSE) build

up: ## Start services
	$(COMPOSE) up -d

down: ## Stop services
	$(COMPOSE) down --remove-orphans

restart: down up ## Restart services

install: ## Install Laravel 13 into src/
	docker run --rm -v "$(CURDIR)/src:/app" -w /app composer:latest create-project laravel/laravel:^13.0 .

key: ## Generate app key
	$(APP) php artisan key:generate

migrate: ## Run migrations
	$(APP) php artisan migrate

fresh: ## Migrate fresh + seed
	$(APP) php artisan migrate:fresh --seed

seed: ## Run seeders
	$(APP) php artisan db:seed

test: ## Run PHPUnit
	$(APP) php artisan test

tinker: ## Start Tinker
	$(APP) php artisan tinker

artisan: ## Run artisan command (CMD=...)
	$(APP) php artisan $(CMD)

composer: ## Run composer command (CMD=...)
	$(APP) composer $(CMD)

npm: ## Run npm command (CMD=...)
	$(APP) npm $(CMD)

shell: ## Open bash shell in app container
	$(APP) bash

logs: ## Follow logs
	$(COMPOSE) logs -f

optimize: ## Cache config, routes, views
	$(APP) php artisan config:cache
	$(APP) php artisan route:cache
	$(APP) php artisan view:cache
