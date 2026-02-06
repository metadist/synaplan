# Step 5: Publishing Guide

## Nextcloud App Store
The official marketplace for Nextcloud apps is [apps.nextcloud.com](https://apps.nextcloud.com).

### Prerequisites
1.  **Developer Account:** Register at [apps.nextcloud.com/developer](https://apps.nextcloud.com/developer).
2.  **Certificate:** You need a certificate to sign your app.
    ```bash
    # Generate private key
    openssl genrsa -out ~/.nextcloud/certificates/synaplan_integration.key 4096
    # Generate public certificate
    openssl req -new -x509 -key ~/.nextcloud/certificates/synaplan_integration.key -out ~/.nextcloud/certificates/synaplan_integration.crt -days 1095
    ```
3.  **Code Signing:**
    - Nextcloud requires apps to be signed to verify integrity.
    - Use the `occ` tool:
      ```bash
      occ integrity:sign-app --privateKey=/path/to/key --certificate=/path/to/crt synaplan_integration
      ```
    - This updates `appinfo/signature.json`.

### Release Workflow
1.  **Prepare Release:**
    - Bump version in `appinfo/info.xml`.
    - Update `CHANGELOG.md`.
    - Run `occ app:check-code synaplan_integration` to ensure no forbidden function usage.
2.  **Build:**
    - Run `npm run build` (for Vue frontend).
    - Create a tarball: `tar -czf synaplan_integration.tar.gz synaplan_integration/`.
    - **Exclude:** `node_modules`, `.git`, tests, dev files.
3.  **Sign:**
    - Sign the app folder *before* tarballing, or sign the tarball (check current Nextcloud docs, usually folder is signed then packed).
4.  **Upload:**
    - Log in to App Store.
    - "Register new app" (first time) or "Add release".
    - Provide URL to the tarball (e.g., GitHub Release asset).
    - Provide signature.

### Automation (GitHub Actions)
Use the official [Nextcloud App Store Push Action](https://github.com/nextcloud-releases/nextcloud-appstore-push-action).

```yaml
name: Release
on:
  release:
    types: [published]
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Build
        run: npm ci && npm run build
      - name: Sign and Publish
        uses: nextcloud-releases/nextcloud-appstore-push-action@v1
        with:
          app_name: synaplan_integration
          app_private_key: ${{ secrets.APP_PRIVATE_KEY }}
          nextcloud_appstore_token: ${{ secrets.APPSTORE_TOKEN }}
```

---

## OwnCloud Marketplace
If we target OwnCloud with `synaplan-opencloud` (or a separate codebase), the process is similar but distinct.

1.  **Platform:** [marketplace.owncloud.com](https://marketplace.owncloud.com).
2.  **Review:** Apps go through "Experimental" -> "Approved" -> "Official".
3.  **Structure:** OwnCloud 10 apps are similar to Nextcloud. OwnCloud Infinite Scale (oCIS) uses a completely different Go/Vue architecture. **Note:** Our planning currently targets PHP-based clouds (Nextcloud/OwnCloud 10).

## "Synaplan OpenCloud" Strategy
To support a generic "OpenCloud" plugin:
1.  **Codebase:** Keep the core logic (API client, UI components) in a shared library or strictly separated from the Nextcloud-specific "glue" code (Controllers, Hooks).
2.  **Distribution:**
    - **Nextcloud:** `synaplan-nextcloud` repo -> App Store.
    - **Generic/OwnCloud:** `synaplan-opencloud` repo -> ZIP download or Marketplace.
