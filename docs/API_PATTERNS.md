> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 5: Developer & Contributor Guide (6/11)**  
> **← Previous:** [5.5 Frontend Vue & TypeScript Conventions](FRONTEND_CONVENTIONS.md) · **Next:** [5.7 Realtime WebSocket Architecture →](REALTIME.md)

# OpenAPI & Zod Schema Pipeline

> **In this guide:** How Synaplan keeps backend PHP controllers and frontend TypeScript API clients in lockstep—writing `@OA\*` attributes in Symfony, generating Zod schemas into `frontend/src/generated/api-schemas.ts`, and validating every response at runtime through `httpClient`.

```
PHP Controller (#[OA\Response]) ──► /api/doc.json ──► make -C frontend generate-schemas ──► src/generated/api-schemas.ts ──► httpClient({ schema })
```

## Frontend: Zod Schema Validation (Required)

All HTTP requests **MUST** use Zod schema validation for type safety and runtime validation.

### Using Generated Schemas

Schemas are auto-generated from backend OpenAPI annotations:

```typescript
import { httpClient } from '@/services/api/httpClient'
import { GetWidgetResponseSchema } from '@/generated/api-schemas'
import { z } from 'zod'

// Types inferred from schema (single source of truth)
type Widget = z.infer<typeof GetWidgetResponseSchema>

// Use schema with httpClient
const widget = await httpClient('/api/v1/widgets/123', {
  schema: GetWidgetResponseSchema
})
// widget is typed and validated at runtime
```

### Benefits

- **Type safety**: Types inferred from schemas
- **Runtime validation**: Catches API contract violations immediately
- **Better errors**: Zod provides detailed validation errors
- **Maintainability**: Schema changes automatically update types
- **Auto-generated**: From backend OpenAPI annotations

### Regenerating Schemas

After modifying OpenAPI annotations in PHP controllers:

```bash
make -C frontend generate-schemas

# Or restart frontend container (auto-generates on startup)
docker compose restart frontend
```

## Backend: OpenAPI Annotations

Write **detailed annotations** on all API endpoints to enable schema generation:

```php
#[OA\Response(
    response: 200,
    description: 'Widget details',
    content: new OA\JsonContent(
        required: ['id', 'widgetId', 'name', 'config', 'isActive'],
        properties: [
            new OA\Property(property: 'id', type: 'integer', example: 123),
            new OA\Property(property: 'widgetId', type: 'string', example: 'wgt_abc123'),
            new OA\Property(property: 'name', type: 'string', example: 'Support Chat'),
            new OA\Property(
                property: 'config',
                type: 'object',
                required: ['primaryColor'],
                properties: [
                    new OA\Property(property: 'primaryColor', type: 'string', example: '#007bff'),
                ]
            ),
            new OA\Property(property: 'isActive', type: 'boolean', example: true),
        ]
    )
)]
```

## Using API Clients

Always use existing clients in `services/api/`:

```typescript
import { createWidget } from '@/services/api/widgetsApi'

// Good ✅
const widget = await createWidget(name, config)

// Bad ❌ - Don't write raw fetch calls
const response = await fetch('/api/v1/widgets', { ... })
```

If an API client doesn't exist or needs extension, ask how to modify it for the use case.

## Schema Generation Pipeline

Schemas are generated:
1. **Dev container startup** - waits for backend, then generates
2. **Frontend build** - pre-build hook
3. **CI pipeline** - from OpenAPI artifact
4. **Manual** - `npm run generate:schemas` in frontend/

The script:
1. Fetches OpenAPI spec from `http://backend/api/doc.json`
2. Generates Zod schemas to `src/generated/api-schemas.ts`
3. Fixes Zod v4 compatibility issues
4. Creates PascalCase aliases (e.g., `GetWidgetResponseSchema`)

> **CI & E2E parity checks:** CI verifies that the full and minimal stacks emit an identical OpenAPI spec (`OpenAPI identical (full vs minimal)`), and Playwright's `global-setup.ts` fails immediately if `src/generated/api-schemas.ts` has drifted from the backend OpenAPI contract. Always run `make -C frontend generate-schemas` and `docker compose exec -T frontend npm run check:types` after editing controller `@OA\*` annotations.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 5: Developer & Contributor Guide (6/11)**  
> **← Previous:** [5.5 Frontend Vue & TypeScript Conventions](FRONTEND_CONVENTIONS.md) · **Next:** [5.7 Realtime WebSocket Architecture →](REALTIME.md)
