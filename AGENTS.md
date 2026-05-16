# AGENTS.md

## Project Overview

This is a **Laravel 13** web application running in a **Docker** development environment.

- **PHP:** 8.3 (minimum required by Laravel 13)
- **Framework:** Laravel 13
- **Database:** PostgreSQL 18
- **Cache/Queue/Session:** Redis 8 (ephemeral, no persistence)
- **Web Server:** Nginx (Alpine)
- **Container Runtime:** Docker + Docker Compose
- **Dev Tools:** Make, Composer, Node.js/npm

## Project Structure

```
roofr/
├── src/                  # Laravel application root (created by `make install`)
│   ├── app/
│   ├── config/
│   ├── database/
│   ├── routes/
│   ├── resources/
│   ├── public/           # Web entry point (index.php)
│   └── ...
├── compose.yml           # Docker Compose: app, nginx, db, redis
├── Dockerfile            # PHP 8.3-FPM + extensions + Composer + Node + Xdebug
├── nginx.conf            # Nginx vhost for Laravel
├── entrypoint.sh         # Container entrypoint (UID/GID sync, auto composer install)
├── Makefile              # Common dev commands
├── .env.example          # Template .env for Laravel (PostgreSQL + Redis defaults)
└── .dockerignore         # Build context exclusions
```

## Development Environment

All services run inside Docker. You do **not** need PHP, PostgreSQL, Redis, or Nginx installed locally.

### Prerequisites

- Docker + Docker Compose
- Make
- Port 8000 available (app), 5432 (PostgreSQL), 6379 (Redis)

### Initial Setup

```bash
make install        # Bootstraps Laravel 13 into src/ (one-time)
cp .env.example src/.env
make up             # Build images and start all services
make key            # Generate APP_KEY
make migrate        # Run database migrations
```

App is available at: `http://localhost:8000`

## Commands

Use `make` for all common tasks. The `app` container handles both PHP-FPM and CLI (Artisan, Composer, npm).

| Task | Command |
|------|---------|
| Start services | `make up` |
| Stop services | `make down` |
| Restart services | `make restart` |
| Build images | `make build` |
| Run migrations | `make migrate` |
| Fresh migrate + seed | `make fresh` |
| Run seeders | `make seed` |
| Run tests | `make test` |
| Open Tinker | `make tinker` |
| Run any Artisan command | `make artisan CMD="make:model Post -mf"` |
| Run any Composer command | `make composer CMD="require laravel/sanctum"` |
| Run any npm command | `make npm CMD="run build"` |
| Shell into app container | `make shell` |
| View logs | `make logs` |
| Cache config/routes/views | `make optimize` |

### Running Commands Inside the Container

If `make` is not available, use Docker Compose directly:

```bash
# Run artisan
docker compose exec --user $(id -u):$(id -g) app php artisan migrate

# Run composer
docker compose exec --user $(id -u):$(id -g) app composer require package/name

# Run npm
docker compose exec --user $(id -u):$(id -g) app npm run dev

# Shell
docker compose exec --user $(id -u):$(id -g) app bash
```

All `exec` commands should include `--user $(id -u):$(id -g)` to ensure files are created with the host user's ownership.

## Code Style

Follow Laravel and PHP conventions:

- **PHP:** PSR-12 compliant
- **Strings:** Use double quotes (`"`) for all PHP strings unless single quotes are semantically required (e.g., to avoid escaping). All generated PHP code must follow this convention.
- **Naming:**
  - Classes: `PascalCase`
  - Methods/functions: `camelCase`
  - Variables: `camelCase`
  - Database tables: `snake_case`, plural
  - Columns: `snake_case`
- **Imports:** Group and sort `use` statements alphabetically
- **Type hints:** Use explicit types on method signatures and return types where possible
- **DocBlocks:** Use sparingly; prefer type hints over `@param`/`@return` when types are obvious
- **Artisan commands:** Use descriptive names (`make:command` generates the stub)
- **Routes:** Prefer route model binding and resource controllers
- **Validation:** Use Form Request classes for complex validation
- **Database:** Use migrations for all schema changes; prefer Eloquent over raw SQL
- **Blade:** Use components for reusable UI; keep logic minimal in views

## Testing

```bash
make test       # Runs PHPUnit via `php artisan test`
```

- Tests live in `src/tests/`
- Feature tests in `tests/Feature/`
- Unit tests in `tests/Unit/`
- Use factories for test data, not manual inserts
- Run tests before committing changes

## Database

- **Connection:** PostgreSQL via `pdo_pgsql`
- **Host:** `db` (Docker service name)
- **Migrations:** Always create migration files; never modify the database directly
- **Seeding:** Use `DatabaseSeeder` and factory classes
- **Never** commit production database credentials

## Git Workflow

- Branch from `main` for new features
- Write meaningful commit messages
- Run `make test` before pushing
- Do not commit `src/.env`, `src/vendor/`, or `src/node_modules/`

## Boundaries

- **Always do:**
  - Run `make artisan` / `make composer` / `make npm` through the Makefile or with `--user $(id -u):$(id -g)`
  - Write migrations for any schema changes
  - Follow Laravel conventions for routing, validation, and Eloquent
  - Run tests before suggesting changes are complete

- **Ask first:**
  - Adding new Composer packages or npm dependencies
  - Modifying Docker configs (`Dockerfile`, `compose.yml`, `nginx.conf`, `entrypoint.sh`)
  - Changing database connection or cache driver settings
  - Modifying `.env.example` or environment defaults

- **Never do:**
  - Commit secrets, API keys, or database passwords
  - Run `composer install` or `npm install` directly on the host (use the container)
  - Modify files in `src/vendor/` or `src/node_modules/`
  - Delete or weaken existing tests
  - Run `php artisan optimize` or caching commands on development code without reason
