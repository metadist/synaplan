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

self_test() {
  # Not local: the EXIT trap runs after this function returns.
  campaign_guard_tmp_dirs=()
  cleanup() {
    local dir
    for dir in "${campaign_guard_tmp_dirs[@]}"; do
      rm -rf "$dir"
    done
  }
  trap cleanup EXIT

  new_repo() {
    local dir
    dir=$(mktemp -d)
    campaign_guard_tmp_dirs+=("$dir")
    git -C "$dir" init -q -b main
    git -C "$dir" config user.email "guard@example.com"
    git -C "$dir" config user.name "guard"
    printf '%s\n' "$dir"
  }

  refuse() {
    if bash "$0" --root "$1"; then
      echo "campaign-guard: self-test expected a refusal ($2)" >&2
      exit 1
    fi
  }

  allow() {
    if ! bash "$0" --root "$1"; then
      echo "campaign-guard: self-test expected a clean checkout ($2)" >&2
      exit 1
    fi
  }

  local dir base head trailer real_git wrapper output status

  dir=$(new_repo)
  mkdir -p "$dir/.github"
  printf 'payload\n' > "$dir/.github/init.js"
  git -C "$dir" add .github/init.js
  git -C "$dir" commit -q -m "fixture"
  refuse "$dir" "tracked payload"

  dir=$(new_repo)
  printf 'ok\n' > "$dir/readme"
  git -C "$dir" add readme
  git -C "$dir" commit -q -m "readme"
  allow "$dir" "ordinary tree"

  dir=$(new_repo)
  printf 'see testmetest\n' > "$dir/notes.txt"
  git -C "$dir" add notes.txt
  git -C "$dir" commit -q -m "notes"
  refuse "$dir" "marker in a tracked file"

  for trailer in 'skip-checks:true' 'AutoSyncComplete' 'msync-3f7a'; do
    dir=$(new_repo)
    git -C "$dir" commit -q --allow-empty -m "land ${trailer}"
    refuse "$dir" "commit message ${trailer}"
  done

  # A pull_request runner checks out GitHub's merge commit. The scan has to
  # read the commits the branch introduced, not that synthetic message.
  dir=$(new_repo)
  printf 'base\n' > "$dir/readme"
  git -C "$dir" add readme
  git -C "$dir" commit -q -m "base"
  base=$(git -C "$dir" rev-parse HEAD)
  git -C "$dir" checkout -q -b topic
  git -C "$dir" commit -q --allow-empty -m "operator AutoSyncComplete"
  head=$(git -C "$dir" rev-parse HEAD)
  git -C "$dir" checkout -q main
  git -C "$dir" merge -q --no-ff topic -m "Merge topic into main"
  if PR_HEAD_SHA="$head" PR_BASE_SHA="$base" bash "$0" --root "$dir"; then
    echo "campaign-guard: self-test missed the pull request head message" >&2
    exit 1
  fi

  dir=$(new_repo)
  printf 'base\n' > "$dir/readme"
  git -C "$dir" add readme
  git -C "$dir" commit -q -m "base"
  base=$(git -C "$dir" rev-parse HEAD)
  git -C "$dir" checkout -q -b topic
  git -C "$dir" commit -q --allow-empty -m "topic work"
  head=$(git -C "$dir" rev-parse HEAD)
  git -C "$dir" checkout -q main
  git -C "$dir" merge -q --no-ff topic -m "Merge AutoSyncComplete"
  if ! PR_HEAD_SHA="$head" PR_BASE_SHA="$base" bash "$0" --root "$dir"; then
    echo "campaign-guard: self-test flagged the synthetic merge commit" >&2
    exit 1
  fi
  refuse "$dir" "local HEAD is the merge commit"

  # git grep exits 1 for no match and 2+ for a real error. Only 1 is clean.
  dir=$(new_repo)
  printf 'ok\n' > "$dir/readme"
  git -C "$dir" add readme
  git -C "$dir" commit -q -m "readme"
  wrapper=$(mktemp -d)
  campaign_guard_tmp_dirs+=("$wrapper")
  real_git=$(command -v git)
  cat > "$wrapper/git" << EOF
#!/bin/bash
if [[ "\${1:-}" == grep ]]; then
  echo "simulated git grep failure" >&2
  exit 2
fi
exec $(printf '%q' "$real_git") "\$@"
EOF
  chmod +x "$wrapper/git"
  set +e
  output=$(PATH="$wrapper:$PATH" bash "$0" --root "$dir" 2>&1)
  status=$?
  set -e
  if (( status == 0 )) || [[ "$output" != *"git grep failed with status 2"* ]]; then
    echo "campaign-guard: self-test treated a git grep error as a clean checkout" >&2
    printf '%s\n' "$output" >&2
    exit 1
  fi

  echo "campaign-guard: self-test passed"
}

if [[ "${1:-}" == "--self-test" ]]; then
  self_test
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
# Exit 1 means no match. Any other non-zero status is a broken scan, not a
# clean tree: set -e does not fire for a command used as a condition.
pattern='testmetest|iobandamwer|testsecuiitytestme|CementBeetleSteeple|aiarmor\.website|AutoSyncComplete|msync-3f7a|\.github/init\.(js|ps1)'
grep_status=0
git grep -I -n -E "$pattern" -- . ':(exclude)scripts/campaign-guard.sh' >&2 || grep_status=$?
if (( grep_status == 0 )); then
  report "campaign marker in a tracked file (see the lines above)"
elif (( grep_status != 1 )); then
  echo "campaign-guard: git grep failed with status ${grep_status}" >&2
  exit 1
fi

# pull_request checkouts are GitHub's merge commit. Read the commits the
# pull request introduced. A local run has no PR_HEAD_SHA and reads HEAD.
read_commit_messages() {
  local depth
  if [[ -z "${PR_HEAD_SHA:-}" ]]; then
    git log -1 --format=%B
    return
  fi
  if ! git cat-file -e "${PR_HEAD_SHA}^{commit}" 2>/dev/null; then
    depth=1
    if [[ "${PR_COMMIT_COUNT:-}" =~ ^[0-9]+$ ]] && (( PR_COMMIT_COUNT > 0 )); then
      depth=$(( PR_COMMIT_COUNT + 1 ))
    fi
    if [[ -n "${PR_NUMBER:-}" ]]; then
      if ! git fetch --no-tags --depth="$depth" origin "+refs/pull/${PR_NUMBER}/head:refs/campaign-guard/head"; then
        echo "campaign-guard: could not fetch pull/${PR_NUMBER}/head" >&2
        return 1
      fi
    elif ! git fetch --no-tags --depth="$depth" origin "$PR_HEAD_SHA"; then
      echo "campaign-guard: could not fetch pull request head ${PR_HEAD_SHA}" >&2
      return 1
    fi
  fi
  if [[ -n "${PR_BASE_SHA:-}" ]]; then
    if ! git merge-base --is-ancestor "$PR_BASE_SHA" "$PR_HEAD_SHA" 2>/dev/null; then
      if [[ -n "${PR_NUMBER:-}" ]]; then
        git fetch --no-tags --deepen=200 origin "+refs/pull/${PR_NUMBER}/head:refs/campaign-guard/head" || true
      else
        git fetch --no-tags --deepen=200 origin "$PR_HEAD_SHA" || true
      fi
    fi
    if git merge-base --is-ancestor "$PR_BASE_SHA" "$PR_HEAD_SHA" 2>/dev/null; then
      git log --format=%B "${PR_BASE_SHA}..${PR_HEAD_SHA}"
      return
    fi
    echo "campaign-guard: pull request base is not in the fetched history; scanning the head commit only" >&2
  fi
  git log -1 --format=%B "$PR_HEAD_SHA"
}

message=$(read_commit_messages) || exit 1
if [[ "$message" == *skip-checks:true* || "$message" == *AutoSyncComplete* || "$message" == *msync-3f7a* ]]; then
  report "commit message carries a campaign trailer"
fi

if (( fail > 0 )); then
  echo "campaign-guard: refusing this checkout. Do not install dependencies and do not open it in an editor." >&2
  exit 1
fi

echo "campaign-guard: checkout is clean"
