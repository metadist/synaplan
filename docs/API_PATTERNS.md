# API Patterns

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
