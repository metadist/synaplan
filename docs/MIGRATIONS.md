# Database Migrations & Seeding

This project uses **Doctrine Migrations** for schema evolution and **idempotent seed
commands** for production-essential catalog data. Demo/test data lives in DataFixtures.


| Concern                          | Owned by                                                         | Runs in         |
| -------------------------------- | ---------------------------------------------------------------- | --------------- |
| Schema (CREATE / ALTER / DROP)   | `backend/migrations/Version*.php`                                | dev + prod      |
| AI model catalog (`BMODELS`)     | `App\Seed\ModelSeeder` / `app:model:seed`                        | dev + prod      |
| System prompts (`BPROMPTS`)      | `App\Seed\PromptSeeder` / `app:prompt:seed`                      | dev + prod      |
| Default model config (`BCONFIG`) | `App\Seed\DefaultModelConfigSeeder` / `app:config:seed-defaults` | dev + prod      |
| Rate-limit config (`BCONFIG`)    | `App\Seed\RateLimitConfigSeeder` / `app:ratelimit:seed-defaults` | dev + prod      |
| Demo widget config (`BCONFIG`)   | `App\Seed\DemoWidgetConfigSeeder`                                | dev + test only |
| Demo users (`BUSER`)             | `App\DataFixtures\UserFixtures`                                  | dev + test only |


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
- **Default config** → add to `App\Seed\DefaultModelConfigSeeder::PROD_MODEL_DEFAULTS` / `PROD_FLAGS` (and `TEST_DEFAULTS` for PHPUnit/E2E coverage), then `make -C backend seed-defaults`. Model bindings use `service:providerId:tag` keys resolved via `ModelCatalog::findBidByKey()`; never hard-code numeric BIDs here.
- **Rate-limit defaults** → add to `App\Seed\RateLimitConfigSeeder::DEFAULTS`, then `make -C backend seed-ratelimits`

All seeders are idempotent and safe to re-run any number of times:

- **Models** use a **content-fingerprint protection** scheme on top of
  `INSERT … ON DUPLICATE KEY UPDATE` (see `App\Model\ModelCatalog::fingerprint()`).
  Every successful write embeds the SHA-256 of the row's catalog-owned fields into
  `BJSON.__catalog_fingerprint`. On the next seed run, `App\Seed\ModelSeeder` decides
  per row:

  | DB state                                         | Action     | Why |
  | ------------------------------------------------ | ---------- | --- |
  | Row missing                                      | INSERT     | New model in code |
  | Stored fingerprint matches row, code unchanged   | SKIP       | Already in sync, no write |
  | Stored fingerprint matches row, code changed     | UPDATE     | Roll out catalog update to a row nobody touched |
  | Stored fingerprint mismatches row                | PRESERVE   | Admin edited the row via `/config/ai-models`; never overwrite |
  | Legacy row with no fingerprint, matches catalog  | UPDATE     | Silently adopt as catalog-managed |
  | Legacy row with no fingerprint, diverges         | PRESERVE   | Could be a forgotten manual edit; err on safety |

  This means **container restarts no longer overwrite manual UI edits** to model
  prices, names, JSON, etc. — admin changes survive forever (until the admin
  deletes them or re-aligns the row with the catalog values exactly). Operator
  toggles (`BSELECTABLE`, `BACTIVE`, `BISDEFAULT`, `BSHOWWHENFREE`) are still
  excluded from the upsert UPDATE clause as a second layer of defence.

- **Prompts** use `INSERT … ON DUPLICATE KEY UPDATE` on catalog-owned columns
  only — operator toggles are preserved across re-seeds, but corrected
  names/contents DO propagate to existing installs.
- **`BCONFIG`** (defaults + rate limits) uses `INSERT IGNORE`, race-safe via the
`UNIQUE(BOWNERID, BGROUP, BSETTING)` index added in `Version20260420000000`. This
means **`BCONFIG` defaults are bootstrap-only**: if you change a default value in
code, existing installs keep their stored value (operator override or stale default).
Defaults that need to be force-rolled-out across all instances must ship as a
dedicated migration that explicitly UPDATEs the rows.

### I want a clean dev DB

```bash
docker compose down -v       # blows away the db volume
docker compose up -d         # entrypoint runs migrations + fixtures + seed
```

## Container Startup Behaviour

`_docker/backend/docker-entrypoint.sh` does the following on every start:

1. Wait for the DB.
2. **Bootstrap migrations metadata** (self-healing) via the sourceable library
   `_docker/backend/lib/migrations-bootstrap.sh`. On every start the bootstrap inspects
   the DB and, if app tables (`BUSER`) exist, ensures both the `doctrine_migration_versions`
   table and the **baseline migration row** (`Version20260417000000`) are present — so the
   baseline DDL is never replayed against a pre-existing schema:
    ```sql
    CREATE TABLE IF NOT EXISTS doctrine_migration_versions (...)
        DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB;
    INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time)
        VALUES ('DoctrineMigrations\\Version20260417000000', NOW(), 0);
    ```
   The bootstrap self-heals **every production-breaking state** a legacy DB can be in:
    | State                                                              | Action taken                         |
    |--------------------------------------------------------------------|--------------------------------------|
    | Fresh DB (no `BUSER`)                                              | no-op, Doctrine creates everything   |
    | Legacy schema, metadata table missing                              | create table + insert baseline row   |
    | Legacy schema, metadata table exists but empty                     | insert baseline row                  |
    | Legacy schema, metadata table has unrelated rows (no baseline row) | insert baseline row                  |
    | Legacy schema, metadata table already has baseline row             | no-op (idempotent)                   |

   **All post-baseline migrations are deliberately left unregistered** so step 3 below
   applies them against the legacy DB just like on a fresh install. This is critical:
   pre-marking later migrations as applied would silently skip schema changes on
   upgrade.

   We bypass `doctrine:migrations:sync-metadata-storage` + `version --add --all` because
   the DBAL MariaDB schema comparator wrongly reports the auto-created metadata table as
   "not up to date" (column-level charset mismatch on `version`), which then breaks every
   subsequent migrations command.

   The bootstrap logic is covered by `_docker/backend/tests/test-migrations-bootstrap.sh`,
   which runs in CI (backend job) and simulates each of the five states above in-process
   without touching a real database. Run it locally with:
    ```bash
    bash _docker/backend/tests/test-migrations-bootstrap.sh
    ```
3. Run `doctrine:migrations:migrate` (fresh DB → full schema; legacy DB → every migration newer than the baseline).
4. Repeat steps 2 and 3 for the test DB (dev only).
5. **Dev/test only:** load `UserFixtures` if `BUSER` is empty (this purges entity tables first).
6. **Always:** run `app:seed` to (re-)populate models/prompts/config catalogs.

## Schema Validation in CI

CI runs `php bin/console doctrine:schema:validate` (without `--skip-sync`) on a
freshly migrated database. It fails any PR that changes an entity without also
shipping a matching Doctrine migration — no more silent drift, no more "closed by
code review".

### What finally unblocked full validation

Three independent fixes had to land before the `--skip-sync` gate could be
removed:

1. **doctrine/dbal 4.x upgrade** (#781). DBAL 4's schema comparator is the
   baseline for how column metadata is compared; DBAL 3 still works, but the
   new migrations-diff workflow assumes 4.x semantics.
2. **`server_version: 'mariadb-11.8.2'`** in `backend/config/packages/doctrine.yaml`
   (and the `serverVersion=mariadb-…` query string on every `DATABASE_*_URL`).
   DBAL detects MariaDB by a simple `stripos($version, 'mariadb') !== false`
   check (`AbstractMySQLDriver::getDatabasePlatform`). The previous value
   `'11.8'` did not contain the literal string `mariadb`, so DBAL routed all
   introspection through `MySQL84Platform`, whose string-default parsing does
   not strip the surrounding quotes that MariaDB >= 10.2.7 wraps around
   `information_schema.COLUMNS.COLUMN_DEFAULT`. That single config mismatch
   was the real cause of the "phantom diffs" that previously forced
   `--skip-sync` — not a DBAL bug.
3. **`Version20260423000000` + `Version20260429000000`** reconcile legacy
   drift in the live schema: column defaults / NOT NULL / JSON nullability
   (20260423), and the stale `DC2Type` column comments that DBAL 3 used to
   emit and DBAL 4 no longer expects (20260429).

### Gotchas when generating new migrations

- The `serverVersion=mariadb-…` prefix matters for **every** tool that reads
  the DSN — containers, CI workflow env, local `.env` files. If you add a new
  compose service, set `serverVersion=mariadb-11.8.2` (not bare `11.8`).
- For `decimal` columns with a zero default, write the default as a string
  that matches MariaDB's `information_schema` representation, e.g.
  `options: ['default' => '0.000000']` for `decimal(10,6)`. Writing
  `'default' => 0` will round-trip to `'0.000000'` in the DB and the
  comparator will flag a diff. See `Entity/UseLog::$cost` and
  `Entity/Subscription::$costBudgetMonthly` for the pattern.
- `LONGTEXT` cannot meaningfully carry a DB-level default in MariaDB's InnoDB
  without the comparator disagreeing about it. Prefer entity-level
  initialisation (`private string $field = ''`) and omit the `options.default`
  in the attribute.

## Production Notes

- **Never run `doctrine:schema:update --force`** against production. It bypasses migrations,
leaves no audit trail, and can drop columns Doctrine doesn't know about.
- **Never load `doctrine:fixtures:load` in production.** It purges entity tables.
- The first `app` container start against a legacy production DB is safe: the
bootstrap step just registers the baseline as "applied".
- Adding a new migration follows the standard flow: commit the new
`backend/migrations/Version*.php`, deploy, and the entrypoint applies it on next start.
- **When combining migrations via `doctrine:migrations:rollup`** (i.e. collapsing the
existing migration history into a new single baseline), the `BASELINE_MIGRATION`
constant in `_docker/backend/lib/migrations-bootstrap.sh` MUST be updated in the
same commit to point at the new rolled-up version. Otherwise the self-healing
bootstrap will try to mark a version that no longer exists as applied, and
`doctrine:migrations:migrate` will fail on legacy DBs with "unknown version".

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
_docker/backend/
├── docker-entrypoint.sh              # Startup orchestrator
├── lib/
│   └── migrations-bootstrap.sh       # Self-healing bootstrap (sourced by entrypoint + tests)
└── tests/
    └── test-migrations-bootstrap.sh  # Bash test suite for the bootstrap library

backend/
├── migrations/
│   ├── Version20260417000000.php     # Baseline — full ORM-derived schema
│   ├── Version20260420000000.php     # UNIQUE(BOWNERID, BGROUP, BSETTING) on BCONFIG
│   ├── Version20260422000000.php     # Drop unused BRATELIMITS_CONFIG table
│   └── Version20260423000000.php     # Reconcile schema with entity defaults/nullability
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

