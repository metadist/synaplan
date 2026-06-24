# Step 8: App Skeleton Blueprint (NC34)

This document provides the exact file contents for the initial app skeleton (Phase 1). All code follows NC34 conventions, PHP 8.2+ strict types, and modern patterns.

## Directory Structure

```
synaplan_integration/
├── appinfo/
│   ├── info.xml                    # App manifest
│   └── routes.php                  # Route definitions
├── img/
│   └── app.svg                     # App icon (Synaplan logo)
├── lib/
│   ├── AppInfo/
│   │   └── Application.php         # Bootstrap entry point
│   ├── Controller/
│   │   ├── PageController.php      # Page rendering (Research Chat)
│   │   ├── SettingsController.php  # Admin settings API
│   │   └── SynaplanController.php  # Summarize, translate, chat API
│   ├── Service/
│   │   ├── SynaplanClient.php      # HTTP client for Synaplan API
│   │   └── FileContentService.php  # Extract text from NC files
│   └── Settings/
│       └── SynaplanAdmin.php       # Admin settings page
├── src/
│   ├── main.js                     # Research Chat entry
│   ├── settings.js                 # Settings page entry
│   ├── files-actions.js            # File context menu actions
│   ├── components/
│   │   ├── AdminSettings.vue       # Settings form
│   │   ├── SummaryModal.vue        # Summarization modal
│   │   ├── TranslateModal.vue      # Translation modal
│   │   ├── ChatSidebar.vue         # Document chat sidebar
│   │   └── ResearchChat.vue        # Full-page research chat
│   └── services/
│       ├── synaplanApi.js          # Frontend API client
│       └── sseClient.js            # SSE streaming helper
├── templates/
│   ├── index.php                   # Research Chat page template
│   └── settings/
│       └── admin.php               # Admin settings template
├── l10n/                           # Translations
│   ├── en.json
│   └── de.json
├── tests/
│   ├── Unit/
│   │   └── Service/
│   │       └── SynaplanClientTest.php
│   └── jest/
│       └── components/
├── .gitignore
├── composer.json
├── package.json
├── webpack.config.js
├── Makefile
└── README.md
```

---

## Core Files

### `appinfo/info.xml`

```xml
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
    <id>synaplan_integration</id>
    <name>Synaplan Integration</name>
    <summary>AI-powered document summarization, translation, and chat</summary>
    <description><![CDATA[
Bring Synaplan's AI capabilities directly into Nextcloud:

- **Summarize** documents with one click
- **Translate** files to multiple languages
- **Chat** about specific documents (RAG)
- **Research** with a general AI assistant

Requires a running Synaplan instance and API key.
    ]]></description>
    <version>0.1.0</version>
    <licence>agpl</licence>
    <author mail="info@synaplan.com" homepage="https://synaplan.com">Synaplan</author>
    <namespace>SynaplanIntegration</namespace>
    <category>integration</category>
    <category>office</category>
    <bugs>https://github.com/metadist/synaplan-nextcloud/issues</bugs>
    <repository>https://github.com/metadist/synaplan-nextcloud</repository>
    <screenshot>https://raw.githubusercontent.com/metadist/synaplan-nextcloud/main/img/screenshots/summary.png</screenshot>
    <dependencies>
        <php min-version="8.2"/>
        <nextcloud min-version="30" max-version="34"/>
    </dependencies>
    <settings>
        <admin>OCA\SynaplanIntegration\Settings\SynaplanAdmin</admin>
    </settings>
    <navigations>
        <navigation>
            <name>Synaplan</name>
            <route>synaplan_integration.page.index</route>
            <icon>app.svg</icon>
            <order>10</order>
        </navigation>
    </navigations>
</info>
```

### `appinfo/routes.php`

```php
<?php

declare(strict_types=1);

return [
    'routes' => [
        // Page routes
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Settings API
        ['name' => 'settings#getSettings', 'url' => '/api/v1/settings', 'verb' => 'GET'],
        ['name' => 'settings#saveSettings', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
        ['name' => 'settings#testConnection', 'url' => '/api/v1/settings/test', 'verb' => 'POST'],

        // Synaplan proxy API
        ['name' => 'synaplan#summarize', 'url' => '/api/v1/summarize', 'verb' => 'POST'],
        ['name' => 'synaplan#translate', 'url' => '/api/v1/translate', 'verb' => 'POST'],
        ['name' => 'synaplan#chatStart', 'url' => '/api/v1/chat/start', 'verb' => 'POST'],
        ['name' => 'synaplan#chatStream', 'url' => '/api/v1/chat/stream', 'verb' => 'GET'],
        ['name' => 'synaplan#chatMessages', 'url' => '/api/v1/chat/{chatId}/messages', 'verb' => 'GET'],
    ],
];
```

### `lib/AppInfo/Application.php`

```php
<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SynaplanIntegration\Listeners\LoadFilesScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'synaplan_integration';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // Register listener to inject scripts into the Files app
        $context->registerEventListener(
            LoadAdditionalScriptsEvent::class,
            LoadFilesScriptsListener::class
        );
    }

    public function boot(IBootContext $context): void
    {
        // Nothing needed at boot time for now
    }
}
```

### `lib/Service/SynaplanClient.php`

```php
<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Service;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for the Synaplan API.
 *
 * All requests use X-API-Key authentication.
 * This class has NO Nextcloud-specific logic beyond config/HTTP —
 * it can be reused in other contexts.
 */
final class SynaplanClient
{
    private const TIMEOUT = 30;
    private const STREAM_TIMEOUT = 120;

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Health check — verifies Synaplan is reachable.
     *
     * @return array{status: string, providers?: array}
     */
    public function healthCheck(): array
    {
        $response = $this->request('GET', '/api/health');
        return json_decode($response, true);
    }

    /**
     * Generate a document summary.
     *
     * @param string $text Document content
     * @param string $summaryType abstractive|extractive|bullet-points
     * @param string $length short|medium|long
     * @param string $outputLanguage en|de|fr|es|it
     * @return array{success: bool, summary: string, metadata?: array}
     */
    public function summarize(
        string $text,
        string $summaryType = 'bullet-points',
        string $length = 'medium',
        string $outputLanguage = 'en',
    ): array {
        $response = $this->request('POST', '/api/v1/summary/generate', [
            'text' => $text,
            'summaryType' => $summaryType,
            'length' => $length,
            'outputLanguage' => $outputLanguage,
        ]);
        return json_decode($response, true);
    }

    /**
     * Create a new chat session.
     *
     * @return array{id: int, title: string}
     */
    public function createChat(string $title): array
    {
        $response = $this->request('POST', '/api/v1/chats', [
            'title' => $title,
        ]);
        return json_decode($response, true);
    }

    /**
     * Upload a file to Synaplan for processing.
     *
     * @param string $filePath Path to the file
     * @param string $fileName Original filename
     * @param string $mimeType MIME type
     * @return array File upload response
     */
    public function uploadFile(
        string $filePath,
        string $fileName,
        string $mimeType,
    ): array {
        // File upload uses multipart/form-data — handled specially
        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . '/api/v1/files/upload';

        $response = $client->post($url, [
            'headers' => ['X-API-Key' => $this->getApiKey()],
            'multipart' => [
                [
                    'name' => 'files[]',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => $fileName,
                    'headers' => ['Content-Type' => $mimeType],
                ],
                [
                    'name' => 'group_key',
                    'contents' => 'nextcloud',
                ],
                [
                    'name' => 'process_level',
                    'contents' => 'full',
                ],
            ],
            'timeout' => self::STREAM_TIMEOUT,
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * Build the SSE stream URL for chat.
     * The frontend will connect to this via EventSource (through our proxy).
     */
    public function getStreamUrl(
        string $message,
        int $chatId,
        ?string $fileIds = null,
        bool $webSearch = false,
    ): string {
        $params = [
            'message' => $message,
            'chatId' => $chatId,
        ];
        if ($fileIds !== null) {
            $params['fileIds'] = $fileIds;
        }
        if ($webSearch) {
            $params['webSearch'] = '1';
        }

        return $this->getBaseUrl()
            . '/api/v1/messages/stream?'
            . http_build_query($params);
    }

    public function getApiKey(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'api_key', '');
    }

    public function getBaseUrl(): string
    {
        return rtrim(
            $this->config->getAppValue(Application::APP_ID, 'synaplan_url', 'http://localhost:8000'),
            '/'
        );
    }

    /**
     * Generic HTTP request to Synaplan API.
     */
    private function request(string $method, string $path, ?array $body = null): string
    {
        $client = $this->clientService->newClient();
        $url = $this->getBaseUrl() . $path;

        $options = [
            'headers' => [
                'X-API-Key' => $this->getApiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => self::TIMEOUT,
        ];

        if ($body !== null) {
            $options['body'] = json_encode($body);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, $options),
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'DELETE' => $client->delete($url, $options),
            };
            return $response->getBody();
        } catch (\Exception $e) {
            $this->logger->error('Synaplan API error: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'method' => $method,
                'path' => $path,
            ]);
            throw $e;
        }
    }
}
```

### `lib/Settings/SynaplanAdmin.php`

```php
<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Settings;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class SynaplanAdmin implements ISettings
{
    public function __construct(
        private IConfig $config,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        Util::addScript(Application::APP_ID, 'synaplan_integration-settings');

        return new TemplateResponse(Application::APP_ID, 'settings/admin');
    }

    public function getSection(): string
    {
        return 'connected-accounts';
    }

    public function getPriority(): int
    {
        return 10;
    }
}
```

### `lib/Controller/SettingsController.php`

```php
<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Controller;

use OCA\SynaplanIntegration\AppInfo\Application;
use OCA\SynaplanIntegration\Service\SynaplanClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller
{
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private SynaplanClient $synaplanClient,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function getSettings(): JSONResponse
    {
        return new JSONResponse([
            'synaplan_url' => $this->config->getAppValue(
                Application::APP_ID, 'synaplan_url', 'http://localhost:8000'
            ),
            'api_key_set' => $this->config->getAppValue(
                Application::APP_ID, 'api_key', ''
            ) !== '',
        ]);
    }

    public function saveSettings(): JSONResponse
    {
        $url = $this->request->getParam('synaplan_url');
        $apiKey = $this->request->getParam('api_key');

        if ($url !== null) {
            $this->config->setAppValue(Application::APP_ID, 'synaplan_url', rtrim($url, '/'));
        }
        if ($apiKey !== null && $apiKey !== '') {
            $this->config->setAppValue(Application::APP_ID, 'api_key', $apiKey);
        }

        return new JSONResponse(['success' => true]);
    }

    public function testConnection(): JSONResponse
    {
        try {
            $result = $this->synaplanClient->healthCheck();
            return new JSONResponse([
                'success' => true,
                'status' => $result['status'] ?? 'unknown',
                'providers' => $result['providers'] ?? [],
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
```

### `lib/Listeners/LoadFilesScriptsListener.php`

```php
<?php

declare(strict_types=1);

namespace OCA\SynaplanIntegration\Listeners;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SynaplanIntegration\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Util;

/**
 * Injects the file actions script (Summarize, Translate, Chat)
 * into the Nextcloud Files app.
 *
 * Only loads if a Synaplan API key is configured.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptsListener implements IEventListener
{
    public function __construct(
        private IConfig $config,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        // Only inject scripts if Synaplan is configured
        $apiKey = $this->config->getAppValue(Application::APP_ID, 'api_key', '');
        if ($apiKey === '') {
            return;
        }

        Util::addScript(Application::APP_ID, 'synaplan_integration-files-actions');
        Util::addStyle(Application::APP_ID, 'synaplan_integration-files-actions');
    }
}
```

### `templates/settings/admin.php`

```php
<?php

declare(strict_types=1);

/** @var array $_ */
?>
<div id="synaplan-integration-admin-settings"></div>
```

### `templates/index.php`

```php
<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript('synaplan_integration', 'synaplan_integration-main');
Util::addStyle('synaplan_integration', 'synaplan_integration-main');

/** @var array $_ */
?>
<div id="synaplan-integration-app"></div>
```

---

## Build Configuration

### `package.json`

```json
{
  "name": "synaplan-integration",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "build": "webpack --node-env production --progress",
    "dev": "webpack --node-env development --watch --progress",
    "lint": "eslint src/",
    "lint:fix": "eslint src/ --fix"
  },
  "dependencies": {
    "@nextcloud/axios": "^2.5.0",
    "@nextcloud/initial-state": "^2.2.0",
    "@nextcloud/l10n": "^3.1.0",
    "@nextcloud/router": "^3.0.1",
    "@nextcloud/vue": "^8.0.0",
    "vue": "^2.7.0"
  },
  "devDependencies": {
    "@nextcloud/eslint-config": "^8.4.1",
    "@nextcloud/webpack-vue-config": "^6.0.1"
  }
}
```

> **Note**: NC34 bundles Vue 2.7 by default. Check if NC34's frontend has moved to Vue 3 — if so, use `@nextcloud/vue@^9.0.0` and `vue@^3.0.0`.

### `webpack.config.js`

```javascript
const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// Add additional entry points
webpackConfig.entry = {
    main: path.join(__dirname, 'src', 'main.js'),
    settings: path.join(__dirname, 'src', 'settings.js'),
    'files-actions': path.join(__dirname, 'src', 'files-actions.js'),
}

module.exports = webpackConfig
```

### `composer.json`

```json
{
  "name": "synaplan/nextcloud-integration",
  "description": "Synaplan AI Integration for Nextcloud",
  "type": "nextcloud-app",
  "license": "AGPL-3.0-or-later",
  "require": {
    "php": ">=8.2"
  },
  "autoload": {
    "psr-4": {
      "OCA\\SynaplanIntegration\\": "lib/"
    }
  }
}
```

### `Makefile`

```makefile
.PHONY: build dev lint test clean

app_name := synaplan_integration

build: ## Build frontend for production
	npm run build

dev: ## Start frontend dev mode (watch)
	npm run dev

lint: ## Run linters
	npm run lint
	# PHP lint if php-cs-fixer is available
	@which php-cs-fixer > /dev/null 2>&1 && php-cs-fixer fix --dry-run --diff || true

test: ## Run tests
	@echo "Running PHP tests..."
	phpunit --configuration tests/phpunit.xml
	@echo "Running JS tests..."
	npm test

clean: ## Clean build artifacts
	rm -rf js/ css/

package: build ## Create release tarball
	tar -czf $(app_name).tar.gz \
		--exclude='.git' \
		--exclude='node_modules' \
		--exclude='src' \
		--exclude='tests' \
		--exclude='.github' \
		--transform 's,^,$(app_name)/,' \
		appinfo img js css l10n lib templates \
		CHANGELOG.md LICENSE README.md

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  %-15s %s\n", $$1, $$2}'
```

---

## Important NC34 Notes

1. **Vue Version**: NC34 may use Vue 2.7 or Vue 3. Check `@nextcloud/vue` docs for the correct version. The components above are Vue 2.7-compatible. If NC34 uses Vue 3, update imports accordingly.

2. **Script Naming**: Webpack output files are named `{appId}-{entryName}.js`. So `settings` entry → `synaplan_integration-settings.js`. This is what `Util::addScript()` expects (without `.js` extension).

3. **OCS vs Regular Routes**: We use regular routes (not OCS) for simplicity. OCS routes would be under `/ocs/v2.php/apps/synaplan_integration/...` and require `OCSController`. Regular routes are at `/index.php/apps/synaplan_integration/...`.

4. **CSRF**: All POST/PUT routes automatically have CSRF protection. For AJAX calls from the frontend, use `@nextcloud/axios` which includes the CSRF token automatically.

5. **Initial State**: Use `\OCP\AppFramework\Services\IInitialState` to pass data from PHP to Vue without extra API calls. Inject in controller, read in Vue with `@nextcloud/initial-state`.
