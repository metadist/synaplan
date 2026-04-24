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

## Known Limitation: DBAL 3.x Schema Comparator

`doctrine:schema:validate` (without `--skip-sync`) reports the database as "not in
sync" with the entity mapping even when the DB is **objectively correct** (verified
via `SHOW CREATE TABLE`). The drift list looks roughly like this:

```
ALTER TABLE BMESSAGES CHANGE BMESSTYPE BMESSTYPE VARCHAR(4) DEFAULT 'WA' NOT NULL …
```

Applying that ALTER changes nothing — `SHOW CREATE TABLE` already shows
`varchar(4) NOT NULL DEFAULT 'WA'` — but the comparator keeps proposing it on
every run. This is a known [doctrine/dbal 3.x bug](https://github.com/doctrine/dbal)
caused by MariaDB 11.x returning string defaults with surrounding quotes in
`information_schema.COLUMNS.COLUMN_DEFAULT` (`'WA'` instead of `WA`), which the 3.x
schema comparator does not normalize.

We are pinned to DBAL 3.x because `nesbot/carbon → carbonphp/carbon-doctrine-types 2.x` conflicts with DBAL 4.x. A targeted upgrade would cascade into Stripe v20+,
PHPUnit 12.5.23+ and ~25 Symfony 7.4.x bumps — out of scope for a schema-cleanup
PR.

**Practical consequence:** CI runs `doctrine:schema:validate --skip-sync`. This
catches broken ORM mappings (wrong `targetEntity`, mismatched `mappedBy`/`inversedBy`,
missing join columns) but cannot detect entity↔DB drift introduced by a future PR
that forgets to ship a migration. Until DBAL is upgraded, that gap is closed by
**code review** — every entity change MUST land with a matching migration.

`Version20260423000000` brings the live schema to the canonical entity-defined
state at the SQL layer (defaults, NOT NULL, JSON nullability, comments) so that
once DBAL is upgraded the validate step can be flipped to full mode without
generating a wall of phantom ALTERs.

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

