# S7 — The system settings schema as per-tab sections

| | |
| - | - |
| **V3 §7** | #7 |
| **Branch** | `refactor/cleanup-v4-s7-system-config`, from S6's branch |
| **Pull request** | `refactor(admin): system settings schema as per-tab sections` |
| **Class** | backend-only |
| **Owner decision** | — |
| **Chore** | C7 |

## 1. Goal

The admin system-settings schema stops being one 1,534-line method of
data. Each settings tab owns its fields in a class of its own, collected
in tab order by the container: the tagged-list pattern of V3 (seeders,
erasers). The schema the admin UI receives stays byte for byte the same.

## 2. Measured (2026-10-16)

`Service/Admin/SystemConfigService.php`: 2,961 lines, 13 constructor
parameters. `buildSchema()` has 1,534 lines (field definitions), plus
`getSchema()` 182, `getValues()` 138, `setValue()` 103. The fields name
13 tabs: `features`, `auth`, `sharing`, `routing`, `channels`,
`processing`, `interface`, `branding`, `mobile`, `guest_landing`, `ai`,
`email`, `vectordb`. Tests: `SystemConfigServiceTest`,
`SystemConfigServiceComputeTest`.

## 3. Target shape

```
Service/Admin/SystemConfig/
  SystemConfigSection            interface: tab(), fields(): array<string, FieldDefinition>
  Section/FeaturesSection … VectorDbSection   one per tab, tagged app.system_config.section, priority = today's order
  FieldDefinition                final readonly: the shape of today's field array (tab, section, type, sensitive, …)
  SystemConfigValues             getValues() / setValue() — env, database-backed and encrypted sources
SystemConfigService              getSchema() over the tagged sections; ≤ 10 deps
```

A field keeps its key, its position and every attribute. When a field's
definition depends on runtime state (a feature module, the compute
setting), its section receives that dependency, not the service.

## 4. Steps

| Step | Commit | What |
| ---- | ------ | ---- |
| S7.1 | `test(admin): snapshot of the system settings schema` | `SystemConfigSchemaCharacterizationTest`: `getSchema()` and `getValues()` as JSON, with modules on and off and compute on and off, masked secrets included. Recorded on the parent. |
| S7.2 | `refactor(admin): a typed field definition` | `FieldDefinition`, built from today's arrays; snapshot unchanged. |
| S7.3 | `refactor(admin): one section class per settings tab` | 13 tagged sections; an order test pins the tab order; `buildSchema()` is deleted. |
| S7.4 | `refactor(admin): values and writes in their own class` | `getValues()` / `setValue()` move; each method < 100 lines. |
| C7 | `chore(planning): close V4 S7 — sprint test, status, ledgers` | Loop §7 |

## 5. Sprint test

- **Proof:** the schema snapshot recorded on S6's head, unchanged after
  every step. The tab-order test. A coverage test: every field key in the
  snapshot belongs to exactly one section. Mutation check: move one field
  to another tab and see the snapshot fail.
- **Regression:** both existing service tests green with unchanged
  assertions; `runtime_config.json` unchanged; `generate-schemas` zero
  diff. Unfiltered gates.
- **Journey:** `admin-panel.spec.ts` (system settings tabs) and
  `feature-status.spec.ts` in `make test-e2e`. Then open Admin → System
  settings in the browser, open every tab and save one value: the admin
  sees the same tabs, fields and masking as before.

## 6. Reviewer focus

Secret masking (`MASK`) and encrypted database-backed fields: the value
path must never return a secret it masked before.

## 7. Exit

- `SystemConfigService` < 800 lines, ≤ 10 deps, no method > 100; it
  leaves all three ledgers.
- Adding a settings field means editing one section class.
  `docs/ARCHITECTURE.md` "Tagged lists" names the new collection.

## 8. Not in S7

The admin UI. The config pyramid (row 15).
