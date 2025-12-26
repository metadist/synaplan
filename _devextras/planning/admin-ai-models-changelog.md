# AI Models Admin Tool (Change Log)

This file documents the implementation added for an **administrator-only** AI models management tool at `/config/ai-models`.

## What was added

- **Backend admin API** for CRUD + AI-assisted import of `BMODELS`
- **Frontend admin UI** under existing `/config/ai-models` route:
  - **Add Models (Import)**: paste URLs and/or provider text dumps → generate SQL → validate → apply
  - **Edit Models**: create/update/delete models directly
- **Live-table only**: the tool edits the live `BMODELS` table in the current installation. `_devextras/db-loadfiles/BMODELS.sql` is not used by this tool.

## Backend

### New endpoints (admin-only)

All endpoints require authentication and admin privileges (checked via `#[IsGranted('ROLE_ADMIN')]` or manual `$user->isAdmin()` check):

- **List**: `GET /api/v1/admin/models`
- **Create**: `POST /api/v1/admin/models`
- **Update**: `PATCH /api/v1/admin/models/{id}`
- **Delete**: `DELETE /api/v1/admin/models/{id}`
- **Import preview (AI)**: `POST /api/v1/admin/models/import/preview`
- **Import apply**: `POST /api/v1/admin/models/import/apply`

Implementation: `backend/src/Controller/AdminModelsController.php`

Request/response validation uses DTOs: `AdminModelCreateRequest`, `AdminModelUpdateRequest`

### AI model used for import

- The import uses the **configured default SORT model** (from `BCONFIG` group `DEFAULTMODEL`, setting `SORT`), via `ModelConfigService::getDefaultModel('SORT', $adminUserId)`.
- Provider/model resolution uses:
  - `ModelConfigService::getProviderForModel($modelId)` → provider name (lowercased `BMODELS.BSERVICE`)
  - `ModelConfigService::getModelName($modelId)` → actual provider model id (prefers `BPROVID`)

### SQL safety rules (validator)

The server rejects SQL that does not comply with:

- Only `INSERT`, `UPDATE`, `DELETE`
- Only table `BMODELS`
- No comments / DDL / `SELECT`
- `UPDATE` and `DELETE` must include `WHERE` with **BSERVICE + BTAG + BPROVID** (unique identifier)

Implementation: `backend/src/Service/Admin/ModelSqlValidator.php`

## Frontend

### Admin panel integration

`/config/ai-models` now shows an extra panel when `authStore.isAdmin`:

- `frontend/src/components/config/AIModelsAdminPanel.vue`
- Integrated into `frontend/src/components/config/AIModelsConfiguration.vue`

### New frontend API client

- `frontend/src/services/api/adminModelsApi.ts`

## Tests

- `backend/tests/Unit/Admin/ModelSqlValidatorTest.php`

## Notes / limitations

- URL fetching is not performed server-side yet; URLs are included as strings for context. For best results, paste the relevant provider text dump.


