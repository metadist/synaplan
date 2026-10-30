#!/usr/bin/env bash
# Fail the checkout when it carries the testmetest campaign: the armed-repo
# payload, the assistant hooks that launch it, or the npm packages that
# ship it. Runs in CI before any job installs dependencies, and locally
# with `bash scripts/campaign-guard.sh`.
#
# A hit means the tree is hostile. Do not open it in an editor, and do not
# revoke a stolen token before the persistence on that machine is gone:
# the campaign's own note is that revocation triggers a wipe of the home
# directory.
set -euo pipefail

if [[ "${1:-}" == "--self-test" ]]; then
  root=$(mktemp -d)
  trap 'rm -rf "$root"' EXIT
  git -C "$root" init -q
  git -C "$root" config user.email "guard@example.com"
  git -C "$root" config user.name "guard"
  mkdir -p "$root/.github"
  printf 'payload\n' > "$root/.github/init.js"
  git -C "$root" add .github/init.js
  git -C "$root" commit -q -m "fixture"
  if bash "$0" --root "$root"; then
    echo "campaign-guard: self-test did not detect the payload" >&2
    exit 1
  fi
  echo "campaign-guard: self-test detected the payload"
  exit 0
fi

if [[ "${1:-}" == "--root" ]]; then
  cd "$2"
fi

cd "$(git rev-parse --show-toplevel)"

fail=0
report() {
  echo "campaign-guard: $*" >&2
  fail=1
}

while IFS= read -r path; do
  case "$path" in
    .github/init.js | .github/init.ps1 | .napi_cache | */.napi_cache | addon.node | */addon.node | addon_win.node | */addon_win.node)
      report "payload file tracked: $path"
      ;;
    .github/*.js | .github/*.mjs | .github/*.cjs | .github/*.ps1)
      if [[ -f "$path" ]]; then
        size=$(wc -c < "$path")
        if (( size > 1000000 )); then
          report "oversized script tracked: $path ($size bytes)"
        fi
      fi
      ;;
  esac
done < <(git ls-files)

# Present on disk even when not committed yet, so a local checkout sees it.
for path in .github/init.js .github/init.ps1 .napi_cache; do
  if [[ -e "$path" ]] && ! git ls-files --error-unmatch -- "$path" >/dev/null 2>&1; then
    report "payload file on disk, untracked: $path"
  fi
done

# Markers of the packages, the operator account and the hook command.
# This script holds the same words, so it is excluded from the search.
pattern='testmetest|iobandamwer|testsecuiitytestme|CementBeetleSteeple|aiarmor\.website|AutoSyncComplete|msync-3f7a|\.github/init\.(js|ps1)'
if git grep -I -n -E "$pattern" -- . ':(exclude)scripts/campaign-guard.sh' >&2; then
  report "campaign marker in a tracked file (see the lines above)"
fi

message=$(git log -1 --format=%B 2>/dev/null || true)
if [[ "$message" == *skip-checks:true* || "$message" == *AutoSyncComplete* || "$message" == *msync-3f7a* ]]; then
  report "commit message carries a campaign trailer"
fi

if (( fail > 0 )); then
  echo "campaign-guard: refusing this checkout. Do not install dependencies and do not open it in an editor." >&2
  exit 1
fi

echo "campaign-guard: checkout is clean"
