#!/usr/bin/env bash
# Create the GitHub issues drafted in ./issues/*.md.
#
# Each draft starts with three HTML comments that this script reads:
#   <!-- title: ... -->      the issue title
#   <!-- type: Bug|Feature --> the GitHub issue type (matches .github/ISSUE_TEMPLATE)
#   <!-- labels: a, b -->    comma-separated existing labels (prio:*, area:*, ...)
# Those three lines are stripped from the body; the template's
# <!-- issue-type: ... --> marker is kept.
#
# Usage:
#   create-issues.sh                 dry run: print what would be created
#   create-issues.sh --create        create every issue whose title does not exist yet
#   create-issues.sh --only '0[1-8]' restrict to files whose number matches the glob
#   create-issues.sh --repo owner/name
#
# Needs: gh (authenticated with issue write access), awk, sed.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
issues_dir="$here/issues"
create=false
only='*'
repo=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --create) create=true ;;
        --only) only="${2:?--only needs a glob}"; shift ;;
        --repo) repo="${2:?--repo needs owner/name}"; shift ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
    shift
done

repo_args=()
[[ -n "$repo" ]] && repo_args=(--repo "$repo")

meta() {
    # meta <file> <key>  -> value of "<!-- key: value -->" from the first 5 lines
    sed -n '1,5p' "$1" | sed -n "s/^<!-- $2: \(.*\) -->$/\1/p" | head -n 1
}

body_of() {
    # Drop the three metadata comment lines, keep everything else verbatim.
    sed -E '1,5{/^<!-- (title|type|labels): .* -->$/d}' "$1"
}

existing_titles=""
if $create; then
    existing_titles="$(gh issue list "${repo_args[@]}" --state all --limit 1000 --json title --jq '.[].title')"
fi

created=0 skipped=0 planned=0
for file in "$issues_dir"/${only}-*.md; do
    [[ -f "$file" ]] || continue
    title="$(meta "$file" title)"
    type="$(meta "$file" type)"
    labels="$(meta "$file" labels)"
    if [[ -z "$title" || -z "$type" ]]; then
        echo "SKIP  $(basename "$file"): missing title or type comment" >&2
        skipped=$((skipped + 1))
        continue
    fi

    label_args=()
    IFS=',' read -r -a label_list <<<"$labels"
    for label in "${label_list[@]}"; do
        label="$(echo "$label" | sed -E 's/^ +| +$//g')"
        [[ -n "$label" ]] && label_args+=(--label "$label")
    done

    if ! $create; then
        printf 'PLAN  %-58s type=%-7s labels=%s\n' "$(basename "$file")" "$type" "$labels"
        printf '      title: %s\n' "$title"
        planned=$((planned + 1))
        continue
    fi

    if grep -Fxq -- "$title" <<<"$existing_titles"; then
        echo "EXISTS $(basename "$file")"
        skipped=$((skipped + 1))
        continue
    fi

    body_file="$(mktemp)"
    body_of "$file" >"$body_file"
    # --type needs the org issue type to exist; fall back to no type rather than fail.
    if url="$(gh issue create "${repo_args[@]}" --title "$title" --body-file "$body_file" --type "$type" "${label_args[@]}" 2>/dev/null)"; then
        :
    else
        url="$(gh issue create "${repo_args[@]}" --title "$title" --body-file "$body_file" "${label_args[@]}")"
    fi
    rm -f "$body_file"
    echo "CREATED $(basename "$file") -> $url"
    created=$((created + 1))
done

if $create; then
    echo "done: $created created, $skipped skipped"
else
    echo "dry run: $planned issue(s) would be created; re-run with --create"
fi
