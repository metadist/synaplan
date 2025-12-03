# API Documentation mit Swagger/OpenAPI

## Setup

Die Swagger/OpenAPI-Dokumentation wurde mit dem NelmioApiDocBundle implementiert.

### Installation abschließen

Falls das Bundle noch nicht installiert ist (vendor Permissions-Problem):

```bash
# Im Docker Container oder mit korrekten Rechten:
composer require nelmio/api-doc-bundle --ignore-platform-req=ext-redis

# Oder im Docker Container:
docker-compose exec backend composer require nelmio/api-doc-bundle
```

### Zugriff auf die API-Dokumentation

Nach der Installation ist die Swagger UI unter folgenden URLs erreichbar:

- **Swagger UI (interaktive Dokumentation):** `http://localhost:8000/api/doc`
- **OpenAPI JSON Spec:** `http://localhost:8000/api/doc.json`

## Konfiguration

### Konfigurationsdateien

1. **config/packages/nelmio_api_doc.yaml** - Hauptkonfiguration
2. **config/routes/nelmio_api_doc.yaml** - Routing für Swagger UI
3. **config/bundles.php** - Bundle-Registrierung

### Authentifizierung in Swagger UI

Die API unterstützt zwei Authentifizierungsmethoden:

#### 1. JWT Bearer Token
- Erhalte ein JWT Token über `/api/auth/login`
- Klicke in Swagger UI auf den "Authorize" Button
- Gib `Bearer <your_token>` ein

#### 2. API Key
- Erstelle einen API Key über `/api/v1/apikeys` (POST)
- Klicke in Swagger UI auf den "Authorize" Button
- Gib den API Key in das "X-API-Key" Feld ein

## Dokumentierte Controller

Folgende Controller sind bereits mit OpenAPI Annotations dokumentiert:

### ✅ ChatController
- `GET /api/v1/chats` - Liste aller Chats
- `POST /api/v1/chats` - Neuen Chat erstellen
- `GET /api/v1/chats/{id}` - Chat-Details abrufen
- `PATCH /api/v1/chats/{id}` - Chat aktualisieren
- `DELETE /api/v1/chats/{id}` - Chat löschen
- `POST /api/v1/chats/{id}/share` - Chat teilen
- `GET /api/v1/chats/{id}/messages` - Chat-Nachrichten abrufen
- `GET /api/v1/chats/shared/{token}` - Öffentlichen geteilten Chat abrufen

### ✅ MessageController
- `POST /api/v1/messages/send` - Nachricht senden und AI-Antwort erhalten

### ✅ ApiKeyController
- `GET /api/v1/apikeys` - Alle API Keys auflisten
- `POST /api/v1/apikeys` - Neuen API Key erstellen
- `DELETE /api/v1/apikeys/{id}` - API Key widerrufen

### ✅ UsageStatsController
- `GET /api/v1/usage/stats` - Nutzungsstatistiken abrufen
- `GET /api/v1/usage/export` - Nutzungsdaten als CSV exportieren

### Noch zu dokumentieren
- AuthController (Login, Register, etc.)
- StreamController (SSE Streaming)
- ConfigController (Konfigurationen)
- PhoneVerificationController
- WebhookController

## Erweiterte Nutzung

### Eigene Annotations hinzufügen

```php
use OpenApi\Attributes as OA;

#[Route('/api/v1/example', name: 'api_example')]
#[OA\Post(
    path: '/api/v1/example',
    summary: 'Example endpoint',
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'John')
            ]
        )
    ),
    tags: ['Examples'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Success',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'success', type: 'boolean')
                ]
            )
        )
    ]
)]
public function example(Request $request): JsonResponse
{
    // ...
}
```

### Tags organisieren

Die API ist in folgende Bereiche (Tags) gruppiert:
- **Chats** - Chat-Management
- **Messages** - Nachrichten und AI-Kommunikation
- **API Keys** - API Key Verwaltung
- **Usage Statistics** - Nutzungsstatistiken
- **Authentication** - Login/Register
- **Configuration** - System-Konfiguration

### Security Schemas

Zwei Security Schemas sind konfiguriert:
- **Bearer** - JWT Token Authentifizierung
- **ApiKey** - API Key Authentifizierung (X-API-Key Header)

## Produktion

In der Produktion kann die Swagger UI optional deaktiviert werden durch:

```yaml
# config/packages/prod/nelmio_api_doc.yaml
nelmio_api_doc:
    areas:
        disable_default_routes: true
```

## Weiterführende Links

- [NelmioApiDocBundle Dokumentation](https://symfony.com/bundles/NelmioApiDocBundle/current/index.html)
- [OpenAPI Specification](https://swagger.io/specification/)
- [Swagger UI](https://swagger.io/tools/swagger-ui/)

