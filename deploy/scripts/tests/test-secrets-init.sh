#!/usr/bin/env bash
# Lifecycle of the one-shot that a plain `docker compose up` runs before MariaDB.
# The script under test is deploy/scripts/secrets-init.sh. deploy/compose.yaml
# inlines the same bytes (every "$" written as "$$") so the two-file install
# does not need this path.
set -euo pipefail

root="$(cd "$(dirname "$0")/../../.." && pwd)"
script="$root/deploy/scripts/secrets-init.sh"
compose="$root/deploy/compose.yaml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

keys=(
    APP_SECRET
    TOKEN_SECRET
    MARIADB_PASSWORD
    MARIADB_ROOT_PASSWORD
    REALTIME_API_KEY
    REALTIME_TOKEN_SECRET
    REALTIME_ADMIN_PASSWORD
    REALTIME_ADMIN_SECRET
)

fail() {
    printf 'secrets-init: %s\n' "$1" >&2
    exit 1
}

assert_same_script() {
    extracted="$work/extracted.sh"
    awk '
        /SECRETS_INIT_SCRIPT_START/ { capture = 1; next }
        /SECRETS_INIT_SCRIPT_END/ { capture = 0 }
        capture { print }
    ' "$compose" | sed 's/^        //; s/\$\$/$/g' > "$extracted"
    # The standalone file starts with a shebang the container entrypoint does not need.
    tail -n +2 "$script" > "$work/standalone.sh"
    diff -u "$work/standalone.sh" "$extracted" >/dev/null ||
        fail "deploy/compose.yaml no longer inlines deploy/scripts/secrets-init.sh"
}

run_init() {
    local data="$1"
    local out="$2"
    shift 2
    mkdir -p "$data" "$(dirname "$out")"
    env -u APP_SECRET -u TOKEN_SECRET -u MARIADB_PASSWORD -u MARIADB_ROOT_PASSWORD \
        -u REALTIME_API_KEY -u REALTIME_TOKEN_SECRET -u REALTIME_ADMIN_PASSWORD \
        -u REALTIME_ADMIN_SECRET \
        SECRETS_INIT_DATA="$data" SECRETS_INIT_OUT="$out" \
        "$@" /bin/sh "$script"
}

value_of() {
    local file="$1" key="$2" line
    line="$(grep -E "^${key}=" "$file")"
    printf '%s' "${line#"${key}"=}"
}

assert_same_script

grep -Fq 'export DB_PASSWORD="$$MARIADB_PASSWORD"' "$compose" ||
    fail "the application command does not map MARIADB_PASSWORD onto DB_PASSWORD"

# Generate on an empty directory. Every value is 64 hex characters and they differ.
gen="$work/generate"
mkdir -p "$gen"
set +e
run_init "$gen/data" "$gen/out/secrets.env" >"$gen/stdout" 2>"$gen/stderr"
code=$?
set -e
[[ "$code" -eq 0 ]] || fail "an empty directory was refused ($(cat "$gen/stderr"))"
[[ -f "$gen/data/secrets.env" ]] || fail "generation did not write data/secrets.env"
mode="$(stat -c '%a' "$gen/data/secrets.env" 2>/dev/null || stat -f '%Lp' "$gen/data/secrets.env")"
[[ "$mode" == "600" ]] || fail "data/secrets.env mode is $mode, expected 600"
seen=""
for key in "${keys[@]}"; do
    value="$(value_of "$gen/data/secrets.env" "$key")"
    [[ "$value" =~ ^[0-9a-f]{64}$ ]] || fail "$key was not generated as 64 hex characters"
    case " $seen " in
        *" $value "*) fail "two secrets share one generated value" ;;
    esac
    seen="$seen $value"
    [[ "$(value_of "$gen/out/secrets.env" "$key")" == "$value" ]] ||
        fail "the volume copy does not match data/secrets.env for $key"
done
out_mode="$(stat -c '%a' "$gen/out/secrets.env" 2>/dev/null || stat -f '%Lp' "$gen/out/secrets.env")"
[[ "$out_mode" == "644" ]] || fail "the container copy mode is $out_mode, expected 644"

# A second start keeps the file byte for byte, even if the environment offers new values.
before="$(cksum "$gen/data/secrets.env")"
run_init "$gen/data" "$gen/out/secrets.env" APP_SECRET=should-not-replace >/dev/null
after="$(cksum "$gen/data/secrets.env")"
[[ "$before" == "$after" ]] || fail "a second start rewrote data/secrets.env"

# Adopt a configured value and generate the rest.
adopt="$work/adopt"
run_init "$adopt/data" "$adopt/out/secrets.env" APP_SECRET=adopted-from-env TOKEN_SECRET=also-adopted >/dev/null
[[ "$(value_of "$adopt/data/secrets.env" "APP_SECRET")" == "adopted-from-env" ]] ||
    fail "a configured APP_SECRET was not adopted"
[[ "$(value_of "$adopt/data/secrets.env" "TOKEN_SECRET")" == "also-adopted" ]] ||
    fail "a configured TOKEN_SECRET was not adopted"
[[ "$(value_of "$adopt/data/secrets.env" "MARIADB_PASSWORD")" =~ ^[0-9a-f]{64}$ ]] ||
    fail "an unset secret was not generated beside an adopted one"

# Example placeholders are refused and nothing is written.
place="$work/placeholder"
mkdir -p "$place"
set +e
run_init "$place/data" "$place/out/secrets.env" \
    APP_SECRET=replace-with-a-stable-random-value \
    TOKEN_SECRET=replace-with-a-different-stable-random-value >"$place/stdout" 2>"$place/stderr"
code=$?
set -e
[[ "$code" -ne 0 ]] || fail "example placeholder values were accepted"
[[ ! -f "$place/data/secrets.env" ]] || fail "a refused start still wrote data/secrets.env"
[[ ! -e "$place/out/secrets.env" ]] || fail "a refused start still published secrets"
grep -Fq 'Synaplan did not start and created nothing' "$place/stderr" ||
    fail "the refusal does not say that nothing was created"
grep -Fq 'APP_SECRET' "$place/stderr" || fail "the refusal does not name APP_SECRET"
grep -Fq 'TOKEN_SECRET' "$place/stderr" || fail "the refusal does not name TOKEN_SECRET"
grep -Fq 'replace-with-' "$place/stderr" && fail "the refusal prints the example value"
grep -Fq 'openssl rand -hex 32' "$place/stderr" || fail "the refusal does not say how to set a value"

# A database that already exists cannot receive new secrets.
initd="$work/initialised"
mkdir -p "$initd/data/mariadb"
printf 'x' > "$initd/data/mariadb/ibdata1"
set +e
run_init "$initd/data" "$initd/out/secrets.env" >"$initd/stdout" 2>"$initd/stderr"
code=$?
set -e
[[ "$code" -ne 0 ]] || fail "an initialised database without secrets was accepted"
[[ ! -f "$initd/data/secrets.env" ]] || fail "the refusal created data/secrets.env beside an existing database"
grep -Fq 'already has a database' "$initd/stderr" ||
    fail "the refusal does not say the database already exists"

# An empty data/mariadb directory is what prepare.sh creates before MariaDB runs.
# That is not an initialised database.
empty="$work/empty-mariadb"
mkdir -p "$empty/data/mariadb"
run_init "$empty/data" "$empty/out/secrets.env" >/dev/null
[[ -f "$empty/data/secrets.env" ]] || fail "an empty data/mariadb directory blocked generation"

# A value Compose would rewrite is not adopted.
bad="$work/unadoptable"
mkdir -p "$bad"
set +e
run_init "$bad/data" "$bad/out/secrets.env" APP_SECRET=' abc' >"$bad/stdout" 2>"$bad/stderr"
code=$?
set -e
[[ "$code" -ne 0 ]] || fail "a value with surrounding space was adopted"
[[ ! -f "$bad/data/secrets.env" ]] || fail "an unadoptable value still wrote data/secrets.env"

printf 'secrets-init: ok\n'
