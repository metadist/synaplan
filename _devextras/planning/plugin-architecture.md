# Synaplan Infrastructure & Plugin Architecture Planning

This document outlines the core infrastructure improvements and the advanced plugin architecture for Synaplan.

---

## TL;DR: Writing Plugins for Synaplan

### Architecture at a Glance

```
Central Repository (/plugins/)          User Directory (uploads/.../plugins/)
┌────────────────────────┐              ┌──────────────────────────┐
│  my-plugin/            │    symlink   │  my-plugin/              │
│  ├── backend/          │◄────────────►│  ├── backend -> ...      │
│  ├── frontend/         │              │  ├── frontend -> ...     │
│  └── manifest.json     │              │  ├── frontend -> ...     │
└────────────────────────┘              │  └── up -> user root     │
                                        └──────────────────────────┘
```

**Key Concepts:**
- **Central Repository**: Admins install plugins once in `/plugins/`. Code is shared, not copied.
- **User Activation**: When enabled for a user, symlinks are created in their upload directory.
- **Isolation**: Each user's plugin config is stored in `BCONFIG` with `BGROUP = "P_{slug}"`.

### Plugin Structure

```
my-plugin/
├── manifest.json       # Plugin metadata, capabilities, required permissions
├── backend/            # PHP controllers, services, entities
├── frontend/           # Vue components, styles
└── migrations/         # SQL templates applied per-user on activation
```

### Writing a Plugin

1. **Create `manifest.json`** – Define name, version, routes, and permissions.
2. **Backend Code** – Write Symfony controllers/services in `backend/`. Use `#[OA\...]` attributes for API docs. Routes must follow: `/api/v1/user/{userId}/plugins/{pluginName}/...`
3. **Frontend Code** – Vue components in `frontend/`. Loaded dynamically when plugin is active.
4. **Migrations** – SQL files in `migrations/` run on user activation. Use placeholders for `BOWNERID`.
5. **Config Storage** – Use `BCONFIG` table with `BGROUP = "P_{your_slug}"` (max 62 chars for slug).

### Installation Flow

1. Admin places plugin in `/plugins/{pluginName}/`
2. Admin enables plugin for a user via Admin UI
3. `PluginManager` creates symlinks in user's `plugins/` directory
4. Migration runner applies `migrations/*.sql` for that user
5. Plugin routes and UI become available to the user

---

## 0. Vibe Coding Basics: Scalable Infrastructure

### A. Scalable User Directory Hashing
To support millions of users, we move away from a flat `uploads/{userId}/` structure to a 3-level hashed directory. This prevents single directories from having too many subfolders and allows for horizontal storage scaling.

**Logic (Backwards Hashing):**
1. Pad User ID to at least 5 digits (e.g., `13` -> `00013`, `809` -> `00809`).
2. **Level 1**: Last 2 digits (e.g., `/13/`, `/09/`, `/67/`).
3. **Level 2**: The 3 digits preceding the last 2 (e.g., `/000/`, `/008/`, `/345/`).
4. **Level 3**: The full User ID (e.g., `/00013/`, `/00809/`, `/1234567/`).

**Examples:**
- User ID `13` -> `uploads/13/000/00013/`
- User ID `809` -> `uploads/09/008/00809/`
- User ID `1234567` -> `uploads/67/345/1234567/`

**Implementation Note:**
- Centralize this in a `PathService` or `FileStorageService::getUserDirectory(int $userId)`.
- All database references (`filePath` in `BFILE`/`BMESSAGE`) must store the path relative to the root `uploads/` directory.

### B. Docker & Mounts
- **Backend Mount**: Current `./backend` is mounted to `/var/www/backend` (`docker-compose.yml:56`).
- **Uploads Path**: `/var/www/backend/var/uploads`.
- **Central Plugin Mount**:
  ```yaml
  volumes:
    - ./plugins:/plugins:ro  # Read-only central repository
  ```

### C. API Documentation & Schema Generation
Synaplan uses **NelmioApiDocBundle** to provide interactive API documentation.

- **UI URL**: `/api/doc` (served by `app.swagger_ui` route)
- **JSON Spec**: `/api/doc.json` (served by `app.swagger` route)
- **Mechanism**: Documentation is generated automatically from PHP attributes (`#[OA\...]`) in Controllers.
- **Frontend Sync**: The frontend uses `src/generated/api-schemas.ts`, which is generated from the JSON spec using:
  ```bash
  make -C frontend generate-schemas
  ```
  This creates Zod schemas for runtime validation and TypeScript types for the frontend.

**Plugin Documentation Requirement**:
Plugins must also use `#[OA\...]` attributes in their controllers. The `nelmio_api_doc` configuration (`backend/config/packages/nelmio_api_doc.yaml`) is configured to scan the `/api` path pattern. As long as plugin routes start with `/api/v1/user/{userId}/plugins/`, they will be automatically included in the global Swagger UI.

---

## 1. Plugin Architecture (Top Notch)

### A. Central Plugin Repository (Admin Managed)
Resides in `/plugins/` inside the container. Admins install plugins here once.
```
/plugins/
└── {pluginName}/
    ├── backend/                 # Shared PHP code
    ├── frontend/                # Shared Vue assets
    ├── migrations/              # Default SQL templates
    └── manifest.json            # Capabilities
```

### B. User-Specific Symlink Structure
Instead of copying code, we symlink the central plugin into the user's hashed directory. This is extremely storage-efficient.

**Target Path**: `uploads/{L1}/{L2}/{userId}/plugins/{pluginName}/`

```
plugins/{pluginName}/
├── backend -> /plugins/{pluginName}/backend/
├── frontend -> /plugins/{pluginName}/frontend/
└── up -> ../../../                                # Reverse link to user's root
```

**Reverse Symlink (`up/`)**:
Inside the plugin directory, `up/` points back to the user's root upload directory.
- Path from `plugins/{pluginName}/` to `{userId}/` is `../../../`.
- This allows a plugin to access user data via `./up/{year}/{month}/file.pdf`.

---

## 2. Phase 0: Foundations & Migration

### I. Infrastructure Refactoring
1. Implement the 3-level user directory hashing.
2. Update all file-related services to use the new path logic.
3. (Optional) Create a migration command to move existing files to the new hashed structure.

### II. Plugin Migration Runner
Plugins use `BCONFIG` for settings, isolated by `BOWNERID`.

**Constraints & Safety:**
- **BGROUP Limit**: The `BCONFIG.BGROUP` column is `VARCHAR(64)`.
- **Naming Convention**: To avoid truncation, the plugin internal name (used in `BGROUP`) must be limited.
- **Prefix**: We use the prefix `P_` (shorter than `PLUGIN_`) followed by a slugified version of the plugin name.
- **Max Name Length**: If using `P_{slug}`, the slug must not exceed 62 characters.
- **BSETTING Limit**: `BCONFIG.BSETTING` is `VARCHAR(96)`, which is plenty for plugin-specific keys.
- **BVALUE Limit**: `BCONFIG.BVALUE` is `VARCHAR(250)`. Complex configurations should be stored as JSON within this limit or split across multiple settings.

**Process:**
1. Scan `/plugins/{pluginName}/migrations/*.sql`.
2. Apply with `BOWNERID = {userId}` and `BGROUP = "P_{plugin_slug}"`.

---

## 3. Implementation Roadmap

1. **Step 1: Path Scalability**: Refactor `FileStorageService` and `FileServeController` to support hashed user paths.
2. **Step 2: Plugin Linker**: Implement the `PluginManager` that handles the `ln -s` creation in the user's directory.
3. **Step 3: Dynamic Integration**:
   - Backend: Use the symlinked `backend/` for dynamic service/controller loading.
   - Frontend: Serve `frontend/` assets via a public-facing symlink or specialized controller.
4. **Step 4: Admin UI**: Tools for admins to manage the central `/plugins` and enable them for specific users.

