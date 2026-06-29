# Step 6: Repository Structure

We will separate the Nextcloud app into its own repository to keep the main Synaplan repo clean and follow Nextcloud community standards.

## Repository: `synaplan-nextcloud`

### Directory Layout
```text
synaplan-nextcloud/
├── .github/
│   ├── workflows/
│   │   ├── lint.yml          # PHP/JS Linting
│   │   ├── test.yml          # PHPUnit / Jest
│   │   └── release.yml       # Auto-publish to App Store
│   └── ISSUE_TEMPLATE/
├── appinfo/
│   ├── info.xml              # App metadata
│   ├── routes.php            # Route definitions
│   └── database.xml          # DB migrations (if needed)
├── img/
│   ├── app.svg               # Synaplan Logo
│   └── screenshots/          # For App Store
├── lib/
│   ├── Controller/           # PHP Controllers (Glue code)
│   ├── Service/              # Business Logic (Synaplan Client)
│   └── Settings/             # Admin Settings
├── src/                      # Vue.js Frontend
│   ├── components/
│   │   ├── ChatSidebar.vue
│   │   └── SummaryModal.vue
│   ├── services/             # JS API Client
│   └── main.js               # Entry point
├── templates/                # PHP Templates (Settings)
├── tests/                    # Unit/Integration tests
├── .gitignore
├── composer.json             # PHP dependencies
├── package.json              # JS dependencies
├── Makefile                  # Build commands
└── README.md                 # User documentation
```

### Shared Logic Strategy
If we create `synaplan-opencloud` later, we should structure `src/` and `lib/Service/` to be as framework-agnostic as possible.

- **`lib/Service/SynaplanClient.php`**: Pure PHP class, no Nextcloud dependencies. Can be copied to other projects.
- **`src/components/`**: Vue components. Can be reused if the target platform supports Vue (Nextcloud does, OwnCloud 10 does via custom build, oCIS does).

## "Synaplan OpenCloud" Repository
For the generic/open integration:

```text
synaplan-opencloud/
├── docs/
│   ├── INTEGRATION_GUIDE.md  # How to integrate Synaplan into ANY app
│   └── API_SPEC.yaml         # OpenAPI spec for Synaplan Integration
├── examples/
│   ├── php-client/           # Generic PHP client
│   ├── js-client/            # Generic JS/TS client
│   └── curl/                 # Shell examples
└── plugins/
    └── owncloud/             # OwnCloud 10 specific plugin code
```
