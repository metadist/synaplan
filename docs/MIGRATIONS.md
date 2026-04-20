# Database Migrations & Seeding

This project uses **Doctrine Migrations** for schema evolution and **idempotent seed
commands** for production-essential catalog data. Demo/test data lives in DataFixtures.

| Concern                              | Owned by                                         | Runs in    |
|--------------------------------------|--------------------------------------------------|------------|
| Schema (CREATE / ALTER / DROP)       | `backend/migrations/Version*.php`                | dev + prod |
| AI model catalog (`BMODELS`)         | `App\Seed\ModelSeeder` / `app:model:seed`        | dev + prod |
| System prompts (`BPROMPTS`)          | `App\Seed\PromptSeeder` / `app:prompt:seed`      | dev + prod |
| Default model config (`BCONFIG`)     | `App\Seed\DefaultModelConfigSeeder` / `app:config:seed-defaults` | dev + prod |
| Rate-limit config (`BCONFIG`)        | `App\Seed\RateLimitConfigSeeder` / `app:ratelimit:seed-defaults` | dev + prod |
| Demo widget config (`BCONFIG`)       | `App\Seed\DemoWidgetConfigSeeder`                | dev + test only |
| Demo users (`BUSER`)                 | `App\DataFixtures\UserFixtures`                  | dev + test only |

The orchestrator `app:seed` runs all idempotent seeders in the correct dependency order
(models → prompts → defaults → rate-limits → demo-widget).

## Daily Workflow

### I changed an entity — how do I migrate?

```bash
# 1. Generate the migration from ORM ↔ DB diff
make -C backend migrate-diff

# 2. Review the generated file in backend/migrations/Version*.php
#    - check VECTOR(...) columns, JSON columns, FK constraints
#    - delete spurious ALTER TABLEs (DBAL sometimes diffs CHARACTER SET noise)

# 3. Apply it (dev DB)
make -C backend migrate

# 4. Apply to test DB so PHPUnit picks it up
docker compose exec backend php bin/console doctrine:migrations:migrate --env=test

# 5. Run tests
make -C backend test
```

### I need to add a model / prompt / default config

Don't edit migrations or fixtures — extend the catalog source of truth:

- **Models** → add to `App\Model\ModelCatalog::all()`, then `make -C backend seed-models`
- **Prompts** → add to `App\Prompt\PromptCatalog`, then `make -C backend seed-prompts`
- **Default config** → add to `App\Seed\DefaultModelConfigSeeder::ROWS`, then `make -C backend seed-defaults`
- **Rate-limit defaults** → `App\Seed\RateLimitConfigSeeder::ROWS`, then `make -C backend seed-ratelimits`

All seeders are idempotent (`INSERT … ON DUPLICATE KEY UPDATE` for models/prompts,
`INSERT … WHERE NOT EXISTS` for `BCONFIG`), so re-running is always safe.

### I want a clean dev DB

```bash
docker compose down -v       # blows away the db volume
docker compose up -d         # entrypoint runs migrations + fixtures + seed
```

## Container Startup Behaviour

`_docker/backend/docker-entrypoint.sh` does the following on every start:

1. Wait for the DB.
2. **Bootstrap migrations metadata** if the DB has app tables (`BUSER`) but no
   `doctrine_migration_versions` table — i.e. legacy production. The entrypoint
   manually creates the metadata table with the platform-default charset and then
   `INSERT IGNORE`s every shipped `Version*.php` so the baseline is marked applied
   **without** re-executing its DDL:
    ```sql
    CREATE TABLE IF NOT EXISTS doctrine_migration_versions (...) DEFAULT CHARACTER SET utf8mb4 ...;
    INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time)
        VALUES ('DoctrineMigrations\\VersionXYZ', NOW(), 0);
    ```
   We bypass `doctrine:migrations:sync-metadata-storage` + `version --add --all` because
   the DBAL MariaDB schema comparator wrongly reports the auto-created metadata table as
   "not up to date" (column-level charset mismatch on `version`), which then breaks every
   subsequent migrations command.
3. Run `doctrine:migrations:migrate` (fresh DB → full schema; existing DB → only newer migrations).
4. Repeat steps 2 and 3 for the test DB (dev only).
5. **Dev/test only:** load `UserFixtures` if `BUSER` is empty (this purges entity tables first).
6. **Always:** run `app:seed` to (re-)populate models/prompts/config catalogs.

## Production Notes

- **Never run `doctrine:schema:update --force`** against production. It bypasses migrations,
  leaves no audit trail, and can drop columns Doctrine doesn't know about.
- **Never load `doctrine:fixtures:load` in production.** It purges entity tables.
- The first `app` container start against a legacy production DB is safe: the
  bootstrap step just registers the baseline as "applied".
- Adding a new migration follows the standard flow: commit the new
  `backend/migrations/Version*.php`, deploy, and the entrypoint applies it on next start.

## Testing the Migration Path Locally

Simulate a legacy production DB before deploying:

```bash
# 1. Reset
docker compose down -v && docker compose up -d
# Wait for entrypoint to finish — you now have a freshly migrated dev DB.

# 2. Drop the migration metadata to simulate "legacy prod"
docker compose exec db mariadb -u root -proot_password synaplan \
    -e "DROP TABLE IF EXISTS doctrine_migration_versions"

# 3. Restart backend — bootstrap should run and re-register the baseline
docker compose restart backend
docker compose logs backend | grep -E "Existing schema|migrations"
```

You should see the bootstrap message and *no* DDL changes.

## Files of Interest

```
backend/
├── migrations/
│   └── Version20260417000000.php     # Baseline — full ORM-derived schema
├── src/
│   ├── Command/
│   │   ├── SeedAllCommand.php        # app:seed orchestrator
│   │   ├── ModelSeedCommand.php      # app:model:seed
│   │   ├── PromptSeedCommand.php     # app:prompt:seed
│   │   ├── ConfigSeedDefaultsCommand.php
│   │   └── RateLimitSeedDefaultsCommand.php
│   ├── Seed/
│   │   ├── SeedResult.php            # Reporting DTO
│   │   ├── BConfigSeeder.php         # INSERT-IF-NOT-EXISTS helper for BCONFIG
│   │   ├── ModelSeeder.php
│   │   ├── PromptSeeder.php
│   │   ├── DefaultModelConfigSeeder.php
│   │   ├── RateLimitConfigSeeder.php
│   │   └── DemoWidgetConfigSeeder.php
│   ├── Model/ModelCatalog.php        # Source of truth for AI models
│   └── Prompt/PromptCatalog.php      # Source of truth for system prompts
└── Makefile                          # make migrate / migrate-diff / seed / fixtures
```
