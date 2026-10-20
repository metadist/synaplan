# Synaplan on ZimaOS / CasaOS

`Synaplan/` is the source package for the
[IceWhaleTech/CasaOS-AppStore](https://github.com/IceWhaleTech/CasaOS-AppStore)
v2 catalog (`com.synaplan.synaplan`).

## How the pin is raised and synced

1. Publishing a release tag triggers `ci.yml`'s `release-version-pin` job, which
   runs `scripts/set-release-version.mjs` to raise:
   - `x-app-image` (`ghcr.io/metadist/synaplan:<version>`)
   - `APP_VERSION` in `x-app-environment`
   - `x-casaos.version`
2. Once `release-rollout.yml` merges the pin proposal onto `main`,
   `.github/workflows/casaos-store-sync.yml` mirrors `deploy/casaos/Synaplan/`
   into `Apps/Synaplan/` of the store fork, fetches the high-resolution PNG
   screenshots from `metadist/umbrel-apps@submission-assets` (so binary PNGs do
   not bloat this repository), injects the GitHub release notes into
   `x-casaos.release_notes.en_US` via `scripts/set-casaos-release-notes.mjs`,
   runs CasaOS's `validate_compose.py`, and updates the open submission PR or
   opens a version-bump PR against `IceWhaleTech/CasaOS-AppStore`.
