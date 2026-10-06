#!/usr/bin/env bash
# Lifecycle of the one-shot that a plain `docker compose up` runs before MariaDB.
# The script under test is deploy/scripts/secrets-init.sh. deploy/compose.yaml
# and deploy/quickstart/compose.yaml inline the same bytes (every "$" written
# as "$$") so neither install needs this path.
set -euo pipefail

root="$(cd "$(dirname "$0")/../../.." && pwd)"
script="$root/deploy/scripts/secrets-init.sh"
compose="$root/deploy/compose.yaml"
quickstart="$root/deploy/quickstart/compose.yaml"
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
    local file="$1"
    extracted="$work/extracted.sh"
    awk '
        /SECRETS_INIT_SCRIPT_START/ { capture = 1; next }
        /SECRETS_INIT_SCRIPT_END/ { capture = 0 }
        capture { print }
    ' "$file" | sed 's/^        //; s/\$\$/$/g' > "$extracted"
    # The standalone file starts with a shebang the container entrypoint does not need.
    tail -n +2 "$script" > "$work/standalone.sh"
    diff -u "$work/standalone.sh" "$extracted" >/dev/null ||
        fail "${file#"$root"/} no longer inlines deploy/scripts/secrets-init.sh"
}

run_init() {
    local data="$1"
    local out="$2"
    shift 2
    mkdir -p "$data" "$out/app" "$out/db" "$out/realtime"
    env -u APP_SECRET -u TOKEN_SECRET -u MARIADB_PASSWORD -u MARIADB_ROOT_PASSWORD \
        -u REALTIME_API_KEY -u REALTIME_TOKEN_SECRET -u REALTIME_ADMIN_PASSWORD \
        -u REALTIME_ADMIN_SECRET \
        SECRETS_INIT_DATA="$data" \
        SECRETS_INIT_APP_OUT="$out/app/secrets.env" \
        SECRETS_INIT_DB_OUT="$out/db/secrets.env" \
        SECRETS_INIT_REALTIME_OUT="$out/realtime/secrets.env" \
        "$@" /bin/sh "$script"
}

sourced_value() {
    local file="$1" key="$2"
    (
        set -a
        # shellcheck disable=SC1090
        . "$file"
        set +a
        printenv "$key"
    )
}

assert_not_published() {
    local out="$1"
    [[ ! -e "$out/app/secrets.env" && ! -e "$out/db/secrets.env" && ! -e "$out/realtime/secrets.env" ]] ||
        fail "a refused start still published secrets"
}

value_of() {
    local file="$1" key="$2" line
    line="$(grep -E "^${key}=" "$file")"
    printf '%s' "${line#"${key}"=}"
}

assert_same_script "$compose"
assert_same_script "$quickstart"

grep -Fq 'export DB_PASSWORD="$$MARIADB_PASSWORD"' "$compose" ||
    fail "the application command does not map MARIADB_PASSWORD onto DB_PASSWORD"

# The quickstart keeps the backup copy and the database in named volumes. The
# script must see the database volume, or it would generate new secrets beside
# an existing database.
quickstart_init="$(awk '/^  secrets-init:/,/^  backend:/' "$quickstart")"
for line in '- secrets:/data' '- db:/db:ro' 'SECRETS_INIT_DB_DIR: /db' \
    '- secrets-app:/out/app' '- secrets-db:/out/db' '- secrets-realtime:/out/realtime'; do
    grep -Fq -- "$line" <<<"$quickstart_init" || fail "quickstart secrets-init lacks '$line'"
done
grep -Fq 'export DB_PASSWORD="$$MARIADB_PASSWORD"' "$quickstart" ||
    fail "the quickstart application command does not map MARIADB_PASSWORD onto DB_PASSWORD"
quickstart_backend="$(awk '/^  backend:/,/^  worker:/' "$quickstart")"
grep -Fq -- '- secrets-app:/run/synaplan-secrets:ro' <<<"$quickstart_backend" ||
    fail "the quickstart application does not mount its own secrets volume"
grep -Eq -- '- secrets(-db|-realtime)?:' <<<"$quickstart_backend" &&
    fail "the quickstart application mounts secrets that are not its own"
grep -Fq -- '- secrets-db:/run/synaplan-secrets:ro' <<<"$(awk '/^  db:/,/^  redis:/' "$quickstart")" ||
    fail "the quickstart database does not mount its own secrets volume"

awk '/^x-app-volumes:/,/^services:/' "$compose" | grep -Fq 'synaplan-secrets-db:' &&
    fail "the application mounts the database secrets"
awk '/^x-app-volumes:/,/^services:/' "$compose" | grep -Fq 'synaplan-secrets-realtime:' &&
    fail "the application mounts the realtime admin secrets"
grep -Fq 'synaplan-secrets-app:/run/synaplan-secrets:ro' "$compose" ||
    fail "the application does not mount its own secrets volume"
grep -Fq 'synaplan-secrets-db:/run/synaplan-secrets:ro' "$compose" ||
    fail "the database does not mount its own secrets volume"
grep -Fq 'synaplan-secrets-realtime:/run/synaplan-secrets:ro' "$compose" ||
    fail "the realtime service does not mount its own secrets volume"

# Generate on an empty directory. Every value is 64 hex characters and they differ.
gen="$work/generate"
mkdir -p "$gen"
set +e
run_init "$gen/data" "$gen/out" >"$gen/stdout" 2>"$gen/stderr"
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
    case "$key" in
        MARIADB_ROOT_PASSWORD) published="$gen/out/db/secrets.env" ;;
        REALTIME_ADMIN_PASSWORD|REALTIME_ADMIN_SECRET) published="$gen/out/realtime/secrets.env" ;;
        *) published="$gen/out/app/secrets.env" ;;
    esac
    [[ "$(sourced_value "$published" "$key")" == "$value" ]] ||
        fail "the volume copy does not match data/secrets.env for $key"
done
grep -Fq 'MARIADB_ROOT_PASSWORD=' "$gen/out/app/secrets.env" &&
    fail "the application file contains the database root password"
grep -Fq 'REALTIME_ADMIN_PASSWORD=' "$gen/out/app/secrets.env" &&
    fail "the application file contains the realtime admin password"
grep -Fq 'APP_SECRET=' "$gen/out/db/secrets.env" &&
    fail "the database file contains APP_SECRET"
out_mode="$(stat -c '%a' "$gen/out/app/secrets.env" 2>/dev/null || stat -f '%Lp' "$gen/out/app/secrets.env")"
[[ "$out_mode" == "644" ]] || fail "the container copy mode is $out_mode, expected 644"

# A second start keeps the file byte for byte, even if the environment offers new values.
before="$(cksum "$gen/data/secrets.env")"
run_init "$gen/data" "$gen/out" APP_SECRET=should-not-replace >/dev/null
after="$(cksum "$gen/data/secrets.env")"
[[ "$before" == "$after" ]] || fail "a second start rewrote data/secrets.env"

# Adopt a configured value and generate the rest.
adopt="$work/adopt"
run_init "$adopt/data" "$adopt/out" APP_SECRET=adopted-from-env TOKEN_SECRET=also-adopted >/dev/null
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
run_init "$place/data" "$place/out" \
    APP_SECRET=replace-with-a-stable-random-value \
    TOKEN_SECRET=replace-with-a-different-stable-random-value >"$place/stdout" 2>"$place/stderr"
code=$?
set -e
[[ "$code" -ne 0 ]] || fail "example placeholder values were accepted"
[[ ! -f "$place/data/secrets.env" ]] || fail "a refused start still wrote data/secrets.env"
assert_not_published "$place/out"
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
run_init "$initd/data" "$initd/out" >"$initd/stdout" 2>"$initd/stderr"
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
run_init "$empty/data" "$empty/out" >/dev/null
[[ -f "$empty/data/secrets.env" ]] || fail "an empty data/mariadb directory blocked generation"

# A value Compose would rewrite is not adopted.
bad="$work/unadoptable"
mkdir -p "$bad"
set +e
run_init "$bad/data" "$bad/out" APP_SECRET=' abc' >"$bad/stdout" 2>"$bad/stderr"
code=$?
set -e
[[ "$code" -ne 0 ]] || fail "a value with surrounding space was adopted"
[[ ! -f "$bad/data/secrets.env" ]] || fail "an unadoptable value still wrote data/secrets.env"

# An existing backup file that is missing a key is not filled from .env.
# Publishing that gap would start the database with an empty password.
missing="$work/missing"
mkdir -p "$missing/data"
{
    printf '%s\n' 'APP_SECRET=kept-app-secret'
    printf '%s\n' 'TOKEN_SECRET=kept-token-secret'
    printf '%s\n' 'MARIADB_ROOT_PASSWORD=kept-root'
    printf '%s\n' 'REALTIME_API_KEY=kept-api'
    printf '%s\n' 'REALTIME_TOKEN_SECRET=kept-token'
    printf '%s\n' 'REALTIME_ADMIN_PASSWORD=kept-admin'
    printf '%s\n' 'REALTIME_ADMIN_SECRET=kept-admin-secret'
} > "$missing/data/secrets.env"
before="$(cksum "$missing/data/secrets.env")"
set +e
run_init "$missing/data" "$missing/out" MARIADB_PASSWORD=from-the-env >"$missing/stdout" 2>"$missing/stderr"
code=$?
set -e
[[ "$code" -ne 0 ]] || fail "a secrets file missing one key was accepted"
[[ "$(cksum "$missing/data/secrets.env")" == "$before" ]] || fail "a missing key rewrote data/secrets.env"
assert_not_published "$missing/out"
grep -Fq 'MARIADB_PASSWORD' "$missing/stderr" || fail "the refusal does not name the missing key"
grep -Fq 'from-the-env' "$missing/stderr" && fail "the refusal prints the secret from .env"
grep -Fq 'only in .env' "$missing/stderr" || fail "the refusal does not say a .env value is not used"

# Values an existing install may already store (a space, a hash, a semicolon,
# a quote) must survive being sourced, and the semicolon must not run.
restore="$work/restore"
side="$restore/should-not-exist"
mkdir -p "$restore/data"
{
    printf '%s\n' 'APP_SECRET=kept'
    printf '%s\n' "TOKEN_SECRET=abc;touch $side"
    printf '%s\n' 'MARIADB_PASSWORD=has space'
    printf '%s\n' 'MARIADB_ROOT_PASSWORD=a#b'
    printf '%s\n' "REALTIME_API_KEY=it's"
    printf '%s\n' 'REALTIME_TOKEN_SECRET=kept-realtime'
    printf '%s\n' 'REALTIME_ADMIN_PASSWORD=kept-admin'
    printf '%s\n' 'REALTIME_ADMIN_SECRET=kept-admin-secret'
} > "$restore/data/secrets.env"
restore_before="$(cksum "$restore/data/secrets.env")"
run_init "$restore/data" "$restore/out" >/dev/null
[[ ! -e "$side" ]] || fail "sourcing the published file ran a command from a secret"
[[ "$(sourced_value "$restore/out/app/secrets.env" TOKEN_SECRET)" == "abc;touch $side" ]] ||
    fail "a semicolon in a secret was not kept as text"
[[ "$(sourced_value "$restore/out/db/secrets.env" MARIADB_PASSWORD)" == "has space" ]] ||
    fail "a space in a secret was split"
[[ "$(sourced_value "$restore/out/db/secrets.env" MARIADB_ROOT_PASSWORD)" == "a#b" ]] ||
    fail "a hash in a secret was cut off"
[[ "$(sourced_value "$restore/out/realtime/secrets.env" REALTIME_API_KEY)" == "it's" ]] ||
    fail "a quote in a secret was not kept"
[[ "$(cksum "$restore/data/secrets.env")" == "$restore_before" ]] ||
    fail "restoring an existing file rewrote it"

# Quickstart layout: the database lives in its own volume (SECRETS_INIT_DB_DIR)
# and errors name the volume instead of data/secrets.env.
qs_label='secrets.env in the secrets volume'

qs_new="$work/quickstart-new"
mkdir -p "$qs_new/db"
run_init "$qs_new/secrets" "$qs_new/out" SECRETS_INIT_DB_DIR="$qs_new/db" >/dev/null
[[ -f "$qs_new/secrets/secrets.env" ]] || fail "an empty quickstart database volume blocked generation"
for key in "${keys[@]}"; do
    [[ "$(value_of "$qs_new/secrets/secrets.env" "$key")" =~ ^[0-9a-f]{64}$ ]] ||
        fail "quickstart: $key was not generated"
done

qs_db="$work/quickstart-existing-db"
mkdir -p "$qs_db/db"
printf 'x' > "$qs_db/db/ibdata1"
set +e
run_init "$qs_db/secrets" "$qs_db/out" SECRETS_INIT_DB_DIR="$qs_db/db" >"$qs_db/stdout" 2>"$qs_db/stderr"
code=$?
set -e
[[ "$code" -ne 0 ]] || fail "quickstart: a database volume without secrets was accepted"
[[ ! -f "$qs_db/secrets/secrets.env" ]] || fail "quickstart: the refusal created secrets.env beside an existing database"
assert_not_published "$qs_db/out"
grep -Fq 'already has a database' "$qs_db/stderr" ||
    fail "quickstart: the refusal does not say the database already exists"

qs_missing="$work/quickstart-missing"
mkdir -p "$qs_missing/secrets" "$qs_missing/db"
grep -v '^MARIADB_PASSWORD=' "$qs_new/secrets/secrets.env" > "$qs_missing/secrets/secrets.env"
set +e
run_init "$qs_missing/secrets" "$qs_missing/out" SECRETS_INIT_DB_DIR="$qs_missing/db" \
    SECRETS_INIT_FILE_LABEL="$qs_label" >"$qs_missing/stdout" 2>"$qs_missing/stderr"
code=$?
set -e
[[ "$code" -ne 0 ]] || fail "quickstart: a secrets file missing one key was accepted"
assert_not_published "$qs_missing/out"
grep -Fq "has no value in $qs_label" "$qs_missing/stderr" ||
    fail "quickstart: the refusal does not name where the secrets live"
grep -Fq 'data/secrets.env' "$qs_missing/stderr" &&
    fail "quickstart: the refusal points to data/secrets.env, which does not exist there"

printf 'secrets-init: ok\n'
