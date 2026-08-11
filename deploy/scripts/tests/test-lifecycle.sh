#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=../lib.sh
source "$SCRIPT_DIR/lib.sh"

temporary_dir="$(mktemp -d)"
trap 'rm -rf "$temporary_dir"' EXIT
STATE_DIR="$temporary_dir/state"
BACKUP_DIR="$temporary_dir/backups"
# Used indirectly by prepare_data_directories() from the sourced library.
# shellcheck disable=SC2034
DATA_DIR="$temporary_dir/data"
PAUSED_SERVICES_FILE="$STATE_DIR/paused-services"
mkdir -p "$BACKUP_DIR"

COMPOSE_FILE="$SCRIPT_DIR/../compose.yaml"

# A password is expanded by the CONTAINER's shell in a healthcheck, so an
# unquoted expansion splits a value containing whitespace: mariadb-admin never
# authenticates, db never turns healthy, and every service that waits on
# `condition: service_healthy` waits forever. The quoting is asserted on the file
# itself, because nothing else in this Docker-free suite can observe it.
grep -Fq -- 'mariadb-admin ping -h 127.0.0.1 -uroot -p\"$$MARIADB_ROOT_PASSWORD\" --silent' "$COMPOSE_FILE" || {
    echo "The db healthcheck no longer quotes MARIADB_ROOT_PASSWORD" >&2
    exit 1
}
if grep -qE -- '-p\$\$[A-Za-z_]' "$COMPOSE_FILE"; then
    echo "compose.yaml expands a password into a shell command without quoting it" >&2
    exit 1
fi

# The same class of defect one step further: a value Compose interpolates into a
# command STRING becomes part of the program, so whitespace splits arguments and
# metacharacters run as code. Operator-configurable values must reach the shell
# through the environment, quoted.
if grep -q 'ggml-${WHISPER_DEFAULT_MODEL' "$COMPOSE_FILE"; then
    echo "compose.yaml interpolates WHISPER_DEFAULT_MODEL into the whisper-models command text" >&2
    exit 1
fi

# Where the deployment reads its configuration from, for each layout that has to
# work. A managed platform may materialise the configured environment as a .env
# at the CHECKOUT ROOT, which Compose ignores for `-f deploy/compose.yaml`,
# because deploy/ is the project directory.
assert_resolved_env_file() {
    local description="$1"
    local deploy_dir="$2"
    local expected="$3"
    local actual
    actual="$(resolve_compose_env_file "$deploy_dir")"
    [[ "$actual" == "$expected" ]] || {
        printf 'Env file case "%s": expected [%s], got [%s]\n' "$description" "$expected" "$actual" >&2
        exit 1
    }
}

env_tree="$temporary_dir/tree"
mkdir -p "$env_tree/deploy"

# Today's reality for every self-hoster: no file of either kind, so the whole
# fallback is a no-op and the configuration comes from the host environment.
assert_resolved_env_file "no file at all" "$env_tree/deploy" ""
printf 'SYNAPLAN_VERSION=1.2.3\n' > "$env_tree/.env"
assert_resolved_env_file "checkout-root file only" "$env_tree/deploy" "$env_tree/.env"
printf 'SYNAPLAN_VERSION=1.2.3\n' > "$env_tree/deploy/.env"
assert_resolved_env_file "both files present, the documented one wins" \
    "$env_tree/deploy" "$env_tree/deploy/.env"
rm -f "$env_tree/.env"
assert_resolved_env_file "documented file only" "$env_tree/deploy" "$env_tree/deploy/.env"

# The resolved file must actually reach Compose. `docker` is stubbed instead of
# `compose`, so this exercises the real argument construction, inside a subshell
# so neither the stub nor the moved DEPLOY_DIR leaks into the cases below.
assert_compose_passes_env_file() {
    local description="$1"
    local deploy_dir="$2"
    local expected="$3"
    local actual
    actual="$(
        DEPLOY_DIR="$deploy_dir"
        docker() { printf '%s\n' "$*"; }
        compose ps -q backend
    )"
    [[ "$actual" == *"$expected"* ]] || {
        printf 'Compose argument case "%s": expected to contain [%s], got [%s]\n' \
            "$description" "$expected" "$actual" >&2
        exit 1
    }
}

assert_compose_passes_env_file "documented file" "$env_tree/deploy" \
    "--env-file $env_tree/deploy/.env"
rm -f "$env_tree/deploy/.env"
printf 'SYNAPLAN_VERSION=1.2.3\n' > "$env_tree/.env"
assert_compose_passes_env_file "checkout-root file" "$env_tree/deploy" \
    "--env-file $env_tree/.env"
rm -f "$env_tree/.env"
assert_compose_passes_env_file "no file" "$env_tree/deploy" \
    "compose -f $env_tree/deploy/compose.yaml ps -q backend"
(
    DEPLOY_DIR="$env_tree/deploy"
    docker() { printf '%s\n' "$*"; }
    [[ "$(compose ps)" != *--env-file* ]]
) || {
    echo "compose passes --env-file although no configuration file exists" >&2
    exit 1
}

# Whether the file uses Windows line endings, which decides one line of the
# report below. A carriage return that is not a line ending must not count.
assert_crlf_detection() {
    local expected_status="$1"
    local description="$2"
    local body="$3"
    local file="$temporary_dir/crlf-probe.env"
    local status=0

    printf '%s' "$body" > "$file"
    env_file_has_crlf "$file" || status=$?
    [[ "$status" == "$expected_status" ]] || {
        printf 'CRLF detection case "%s": expected status %s, got %s\n' \
            "$description" "$expected_status" "$status" >&2
        exit 1
    }
}

assert_crlf_detection 0 "a Windows file" $'A=1\r\nB=2\r\n'
assert_crlf_detection 0 "a Windows file without a final newline" $'A=1\r'
assert_crlf_detection 0 "only the last line is CRLF" $'A=1\nB=2\r\n'
assert_crlf_detection 1 "a Unix file" $'A=1\nB=2\n'
assert_crlf_detection 1 "an empty file" ''
assert_crlf_detection 1 "a carriage return inside a value is not a line ending" $'A=a\rb\n'

# What the operator is TOLD about the resolution above. The hazard is both
# candidates existing: Compose is handed exactly one --env-file and does not
# merge the other, so every key set only in the ignored file falls back to
# compose.yaml's default. `${VAR:?}` keys fail loudly; keys with a `:-` default
# do not — SYNAPLAN_HTTP_BIND returns to 127.0.0.1 and a managed platform's
# HTTPS proxy can no longer reach the container, and BOOTSTRAP_ADMIN_* go empty
# so no administrator is created. Nothing else in the deployment says a word.
assert_env_file_report() {
    local description="$1"
    local deploy_dir="$2"
    local expected="$3"
    local unexpected="${4:-}"
    local output

    output="$(report_compose_env_file "$deploy_dir" 2>&1)"
    [[ "$output" == *"$expected"* ]] || {
        printf 'Env file report case "%s": expected [%s] in:\n%s\n' \
            "$description" "$expected" "$output" >&2
        exit 1
    }
    [[ -z "$unexpected" || "$output" != *"$unexpected"* ]] || {
        printf 'Env file report case "%s": did not expect [%s] in:\n%s\n' \
            "$description" "$unexpected" "$output" >&2
        exit 1
    }
}

assert_env_file_report "no file at all" "$env_tree/deploy" \
    "using the host environment only" IGNORED
printf 'SYNAPLAN_VERSION=1.2.3\n' > "$env_tree/deploy/.env"
assert_env_file_report "the documented file alone" "$env_tree/deploy" \
    "Reading deployment configuration from $env_tree/deploy/.env" IGNORED
printf 'SYNAPLAN_VERSION=1.2.3\n' > "$env_tree/.env"
assert_env_file_report "both files, the used one is named" "$env_tree/deploy" \
    "Reading deployment configuration from $env_tree/deploy/.env"
assert_env_file_report "both files, the ignored one is named" "$env_tree/deploy" \
    "$env_tree/.env also exists and is IGNORED"
assert_env_file_report "both files, and that they are not merged" "$env_tree/deploy" \
    "does not merge"
rm -f "$env_tree/deploy/.env"
assert_env_file_report "the checkout-root file alone" "$env_tree/deploy" \
    "Reading deployment configuration from $env_tree/.env" IGNORED

# A Windows configuration file is supported, so it is reported as a note and
# never as a failure — Compose discards the carriage returns and
# env_file_raw_value() does the same.
printf 'SYNAPLAN_VERSION=1.2.3\r\n' > "$env_tree/.env"
assert_env_file_report "a Windows file is named as such" "$env_tree/deploy" \
    "Windows (CRLF) line endings"
report_compose_env_file "$env_tree/deploy" >/dev/null 2>&1 || {
    echo "The configuration report fails on a Windows configuration file" >&2
    exit 1
}
printf 'SYNAPLAN_VERSION=1.2.3\n' > "$env_tree/.env"
assert_env_file_report "a Unix file is not" "$env_tree/deploy" \
    "Reading deployment configuration" CRLF
rm -f "$env_tree/.env"

# Every Elestio lifecycle entry point reaches Compose through lib.sh's compose(),
# so the fallback belongs there and nowhere else. A wrapper that called
# `docker compose` itself would bypass it.
#
# Comment lines are exempt, in shell and in YAML alike: these files explain why
# the command must not be called directly, and naming it in prose is not calling
# it.
calls_docker_compose_directly() {
    awk '
        { line = $0; sub(/^[[:space:]]*/, "", line) }
        index(line, "#") == 1 { next }
        index($0, "docker compose") > 0 { found = 1 }
        END { exit !found }
    ' "$1"
}

assert_no_direct_docker_compose() {
    calls_docker_compose_directly "$1" || return 0
    printf '%s calls docker compose directly instead of going through lib.sh\n' \
        "$(basename "$1")" >&2
    exit 1
}

for wrapper in "$SCRIPT_DIR/../elestio"/*.sh; do
    assert_no_direct_docker_compose "$wrapper"
done

# The AWS adapter is the same kind of wrapper, one layer further out: an EC2
# instance runs these from systemd and from `synaplan-update` / `synaplan-snapshot`,
# with no platform in between to resolve anything for them.
AWS_DIR="$SCRIPT_DIR/../aws"
for wrapper in "$AWS_DIR/scripts"/*.sh; do
    assert_no_direct_docker_compose "$wrapper"
done

# The systemd units are the AWS equivalent of elestio.yml: the platform — here,
# the instance itself — runs them outside every lifecycle hook, so a unit that
# spelled out `docker compose` would be the one step of the deployment that
# skipped the whole library.
for unit in "$AWS_DIR/systemd"/*.service; do
    assert_no_direct_docker_compose "$unit"
done

assert_systemd_runs() {
    local unit="$AWS_DIR/systemd/$1"
    local field="$2"
    local script="$3"
    grep -Eq "^$field=/opt/synaplan/$script( |\$)" "$unit" || {
        printf '%s %s does not run /opt/synaplan/%s\n' "$1" "$field" "$script" >&2
        exit 1
    }
}

# Starting the stack is the portable script's job on AWS too, so an instance
# comes up exactly the way a self-hosted install does.
assert_systemd_runs synaplan.service ExecStart deploy/scripts/run.sh
assert_systemd_runs synaplan-firstboot.service ExecStart deploy/aws/scripts/firstboot.sh

# The update path owns no logic of its own: it is the portable backup gate, a
# version bump, and the portable start. Dropping either half would turn
# `synaplan-update` into an unguarded restart on a new image.
for portable in pre-update.sh post-update.sh; do
    grep -Fq "\$DEPLOY_DIR/scripts/$portable" "$AWS_DIR/scripts/update.sh" || {
        printf 'deploy/aws/scripts/update.sh no longer calls deploy/scripts/%s\n' "$portable" >&2
        exit 1
    }
done

# systemd, the symlinks in /usr/local/bin and the SSM document all invoke these
# by path. A file that lost its executable bit fails at boot, not at build.
for script in "$AWS_DIR/scripts"/*.sh; do
    [[ -x "$script" ]] || {
        printf 'deploy/aws/scripts/%s is not executable, so the instance cannot invoke it\n' \
            "$(basename "$script")" >&2
        exit 1
    }
done

# The same rule for the scripts, where lib.sh owns the single `docker compose`
# invocation. Every other script has to reach Compose through compose().
for script in "$SCRIPT_DIR"/*.sh; do
    [[ "$script" == */lib.sh ]] && continue
    assert_no_direct_docker_compose "$script"
done

# elestio.yml is the remaining entry point: the platform runs its build and run
# commands itself, outside every lifecycle hook. They used to call
# `docker compose` directly, which made them the only steps of the deployment that
# resolved their configuration WITHOUT the fallback above — so a checkout-root
# .env would have started every hook correctly and failed exactly there.
ELESTIO_MANIFEST="$(cd "$SCRIPT_DIR/../.." && pwd)/elestio.yml"
assert_no_direct_docker_compose "$ELESTIO_MANIFEST"

assert_manifest_command() {
    local field="$1"
    local script="$2"
    grep -Eq "^  $field: \"[^\"]*deploy/scripts/$script\.sh" "$ELESTIO_MANIFEST" || {
        printf 'elestio.yml %s does not run deploy/scripts/%s.sh\n' "$field" "$script" >&2
        exit 1
    }
}

assert_manifest_command buildCommand build
assert_manifest_command runCommand run

# The two platform commands themselves, executed for real. `docker` and the two
# sibling scripts are stubbed inside a copied tree, so nothing here needs Docker
# or a configuration. What it pins: the order of the steps, that the resolved
# --env-file reaches the pull and the up, that a failing step aborts the whole
# command non-zero instead of starting the stack anyway, and that both work from
# the repository root — how the platform invokes them — as well as from deploy/.
platform_tree="$temporary_dir/platform"
platform_log="$platform_tree/steps.log"
mkdir -p "$platform_tree/deploy/scripts" "$platform_tree/bin"
cp "$SCRIPT_DIR/lib.sh" "$SCRIPT_DIR/build.sh" "$SCRIPT_DIR/run.sh" \
    "$platform_tree/deploy/scripts/"
cp "$COMPOSE_FILE" "$platform_tree/deploy/compose.yaml"
chmod 0755 "$platform_tree/deploy/scripts/build.sh" "$platform_tree/deploy/scripts/run.sh"
platform_compose="docker compose -f $platform_tree/deploy/compose.yaml"

# The platform invokes ./deploy/scripts/<name>.sh, so the checked-in file has to
# be executable too, not only the copy above.
for script in "$SCRIPT_DIR/build.sh" "$SCRIPT_DIR/run.sh"; do
    [[ -x "$script" ]] || {
        printf '%s is not executable, so the platform cannot invoke it\n' \
            "$(basename "$script")" >&2
        exit 1
    }
done

# Records that it ran, and fails when it is the step under test — the sibling
# scripts do the real work elsewhere, and this suite is about the command around
# them.
write_platform_stub() {
    cat > "$platform_tree/deploy/scripts/$1.sh" <<STUB
#!/usr/bin/env bash
printf '%s\n' '$1' >> "\$PLATFORM_LOG"
[[ "\$PLATFORM_FAILING_STEP" != '$1' ]]
STUB
    chmod 0755 "$platform_tree/deploy/scripts/$1.sh"
}

write_platform_stub prepare
write_platform_stub validate-release

cat > "$platform_tree/bin/docker" <<'STUB'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "$PLATFORM_LOG"
STUB
chmod 0755 "$platform_tree/bin/docker"

expected_platform_steps() {
    printf '%s\n' "$@"
}

assert_platform_command() {
    local description="$1"
    local directory="$2"
    local command="$3"
    local failing_step="$4"
    local expected_status="$5"
    local expected_steps="$6"
    local status=0 actual

    : > "$platform_log"
    (
        cd "$directory"
        export PATH="$platform_tree/bin:$PATH"
        export PLATFORM_LOG="$platform_log"
        export PLATFORM_FAILING_STEP="$failing_step"
        "$command"
    ) >/dev/null 2>&1 || status=$?
    [[ "$status" == "$expected_status" ]] || {
        printf 'Platform command case "%s": expected status %s, got %s\n' \
            "$description" "$expected_status" "$status" >&2
        exit 1
    }
    actual="$(<"$platform_log")"
    [[ "$actual" == "$expected_steps" ]] || {
        printf 'Platform command case "%s": expected steps\n[%s]\ngot\n[%s]\n' \
            "$description" "$expected_steps" "$actual" >&2
        exit 1
    }
}

# Host environment only: no file exists, so no --env-file is passed and Compose
# uses the exported configuration, exactly as today.
assert_platform_command "build from the repository root, host environment only" \
    "$platform_tree" ./deploy/scripts/build.sh "" 0 \
    "$(expected_platform_steps prepare "$platform_compose pull" validate-release)"

# The managed-platform layout, and the whole reason these scripts exist.
printf 'SYNAPLAN_VERSION=1.2.3\n' > "$platform_tree/.env"
assert_platform_command "build from deploy/, checkout-root configuration file" \
    "$platform_tree/deploy" scripts/build.sh "" 0 \
    "$(expected_platform_steps prepare \
        "$platform_compose --env-file $platform_tree/.env pull" validate-release)"
assert_platform_command "run from the repository root, checkout-root configuration file" \
    "$platform_tree" ./deploy/scripts/run.sh "" 0 \
    "$(expected_platform_steps validate-release \
        "$platform_compose --env-file $platform_tree/.env up -d")"

# The documented self-host file still wins, on the platform commands too.
printf 'SYNAPLAN_VERSION=1.2.3\n' > "$platform_tree/deploy/.env"
assert_platform_command "run from deploy/, documented configuration file" \
    "$platform_tree/deploy" scripts/run.sh "" 0 \
    "$(expected_platform_steps validate-release \
        "$platform_compose --env-file $platform_tree/deploy/.env up -d")"
rm -f "$platform_tree/.env" "$platform_tree/deploy/.env"

# A failing step must abort the command, so the platform reports a failed
# deployment instead of a started one. The `&&` chain these scripts replaced did
# this, and losing it would start the stack on a rejected configuration.
assert_platform_command "the first build step fails" \
    "$platform_tree" ./deploy/scripts/build.sh prepare 1 \
    "$(expected_platform_steps prepare)"
assert_platform_command "validation after the pull fails" \
    "$platform_tree" ./deploy/scripts/build.sh validate-release 1 \
    "$(expected_platform_steps prepare "$platform_compose pull" validate-release)"
assert_platform_command "validation before the start fails" \
    "$platform_tree" ./deploy/scripts/run.sh validate-release 1 \
    "$(expected_platform_steps validate-release)"

running=$'backend\ndb\nollama'
compose_log="$temporary_dir/compose.log"

running_service() {
    grep -Fxq "$1" <<< "$running"
}

compose() {
    local command="$1"
    shift
    printf '%s %s\n' "$command" "$*" >> "$compose_log"

    if [[ "$command" == "stop" ]]; then
        [[ "${1:-}" == "--timeout" ]] && shift 2
        running="$(grep -Fxv "$1" <<< "$running" || true)"
    elif [[ "$command" == "start" ]]; then
        local service
        for service in "$@"; do
            running+=$'\n'"$service"
        done
    fi
}

begin_service_pause
pause_services backend
pause_services ollama qdrant db

expected=$'backend\nollama\ndb'
actual="$(<"$PAUSED_SERVICES_FILE")"
[[ "$actual" == "$expected" ]] || {
    printf 'Unexpected paused-service record:\n%s\n' "$actual" >&2
    exit 1
}
[[ "$running" != *backend* && "$running" != *db* && "$running" != *ollama* ]]

resume_paused_services
grep -Fxq "start backend ollama db" "$compose_log"
[[ ! -e "$PAUSED_SERVICES_FILE" ]]

# The guarantee that a redeploy never silently upgrades: compose.yaml sets
# `pull_policy: always`, so every start re-pulls the configured tag. On an
# IMMUTABLE tag that returns the identical digest and the operator gets the
# release they pinned; on a MUTABLE one the same restart — a reboot, a platform
# redeploy, an unrelated `docker compose up` — quietly installs a different
# application. validate_release_pin is the only thing standing between the two,
# so every mutable form the registry actually publishes is pinned here, not just
# `latest`: metadata-action also moves `4` and `4.0` on every release, which is
# the realistic floating-tag mistake.
assert_release_pin() {
    local expected_status="$1"
    local description="$2"
    local image="$3"
    local output status=0

    resolved_app_image() { printf '%s\n' "$image"; }
    output="$(validate_release_pin 2>&1)" || status=$?
    [[ "$status" == "$expected_status" ]] || {
        printf 'Release pin case "%s": expected status %s, got %s:\n%s\n' \
            "$description" "$expected_status" "$status" "$output" >&2
        exit 1
    }
}

assert_release_pin 0 "the immutable release tag every pin must be" \
    "ghcr.io/metadist/synaplan:4.0.13"
assert_release_pin 0 "a pre-release, which is immutable too" \
    "ghcr.io/metadist/synaplan:5.0.0-rc.1"
# A registry port contains the only other colon an image reference can carry, so
# a rule anchored on the wrong one would reject a mirrored deployment.
assert_release_pin 0 "the same pin behind a private registry mirror" \
    "registry.example.com:5000/metadist/synaplan:4.0.13"
assert_release_pin 1 "latest, which moves on every release" \
    "ghcr.io/metadist/synaplan:latest"
assert_release_pin 1 "a branch tag, which moves on every merge" \
    "ghcr.io/metadist/synaplan:main"
assert_release_pin 1 "a channel name that is not published at all" \
    "ghcr.io/metadist/synaplan:stable"
assert_release_pin 1 "the floating major alias" "ghcr.io/metadist/synaplan:4"
assert_release_pin 1 "the floating major.minor alias" "ghcr.io/metadist/synaplan:4.0"
# The git tag is `v4.0.13`, the published image tag is `4.0.13`. Copying the
# former must fail the preflight rather than the image pull.
assert_release_pin 1 "the v-prefixed git tag" "ghcr.io/metadist/synaplan:v4.0.13"
assert_release_pin 1 "an empty version" "ghcr.io/metadist/synaplan:"
assert_release_pin 1 "no tag at all, which docker reads as latest" \
    "ghcr.io/metadist/synaplan"

# Unset, compose.yaml's `${SYNAPLAN_VERSION:?}` makes `compose config` itself
# fail, so validate_release_pin never sees an image reference. That has to be a
# rejection as well, instead of an empty value falling through the pattern.
resolved_app_image() {
    return 1
}
if validate_release_pin 2>/dev/null; then
    echo "A deployment whose image cannot be resolved unexpectedly passed validation" >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR/20260804T120000Z"
ln -s 20260804T120000Z "$BACKUP_DIR/latest"
expected_backup="$(cd "$BACKUP_DIR/20260804T120000Z" && pwd -P)"
[[ "$(latest_backup_path)" == "$expected_backup" ]]
ln -sfn /tmp "$BACKUP_DIR/latest"
if latest_backup_path >/dev/null 2>&1; then
    echo "Escaping backup symlink unexpectedly passed validation" >&2
    exit 1
fi

assert_bootstrap_values() {
    local expected_status="$1"
    local description="$2"
    local email="$3"
    local password="$4"
    local expected_message="${5:-}"
    local output status=0

    output="$(validate_bootstrap_admin_values "$email" "$password" 2>&1)" || status=$?
    [[ "$status" == "$expected_status" ]] || {
        printf 'Bootstrap case "%s": expected status %s, got %s:\n%s\n' \
            "$description" "$expected_status" "$status" "$output" >&2
        exit 1
    }
    [[ "$output" == *"$expected_message"* ]] || {
        printf 'Bootstrap case "%s": expected message %s, got:\n%s\n' \
            "$description" "$expected_message" "$output" >&2
        exit 1
    }
}

# Boundary lengths are built, never hand-counted: a literal with one character
# too many or too few would silently move the boundary under test.
repeat_character() {
    local padding
    padding="$(printf '%*s' "$2" '')"
    printf '%s' "${padding// /$1}"
}

# Assigns a password of exactly $1 bytes to $generated_password, starting with
# the character classes in $2 so composition can be varied independently of
# length. The length is asserted here rather than trusted at the call site.
generated_password=""
build_password_of_length() {
    local length="$1"
    local prefix="$2"
    generated_password="$prefix$(repeat_character b "$((length - ${#prefix}))")"
    (( ${#generated_password} == length )) || {
        printf 'Test helper built a %s byte password, expected %s\n' \
            "${#generated_password}" "$length" >&2
        exit 1
    }
}

assert_bootstrap_values 0 "both empty" "" "" "bootstrap will be skipped"
assert_bootstrap_values 1 "email only" "admin@example.com" "" "BOOTSTRAP_ADMIN_PASSWORD is empty"
assert_bootstrap_values 1 "password only" "" "Str0ngPass" "BOOTSTRAP_ADMIN_EMAIL is empty"
assert_bootstrap_values 0 "short password with full composition" "admin@example.com" "Str0ngPass"
assert_bootstrap_values 1 "short password without uppercase" "admin@example.com" "str0ngpass" "one uppercase letter"
# The motivating Elestio case: a generated high-entropy password without a digit.
assert_bootstrap_values 0 "long password without a digit" "admin@example.com" "QWZFaxYB-gtYh-AXqFbcde"
assert_bootstrap_values 1 "password below the minimum" "admin@example.com" "Short1a" "at least 8 characters"
build_password_of_length 65 Aa1
assert_bootstrap_values 1 "password above the maximum" "admin@example.com" "$generated_password" "at most 64 characters"
assert_bootstrap_values 1 "malformed email" "not-an-email" "Str0ngPass" "valid email address"
assert_bootstrap_values 1 "email above the maximum" "$(printf '%0122d' 0)@example.com" "Str0ngPass" "at most 128 characters"
assert_bootstrap_values 0 "email with surrounding whitespace" "  admin@example.com  " "Str0ngPass"

# The composition rule is waived from 16 bytes upwards, so both sides of that
# boundary are pinned: a `<=` instead of `<` in lib.sh would otherwise stay green.
build_password_of_length 15 Aa
assert_bootstrap_values 1 "one byte below the composition waiver, no digit" \
    "admin@example.com" "$generated_password" "one number"
build_password_of_length 16 Aa
assert_bootstrap_values 0 "exactly at the composition waiver, no digit" \
    "admin@example.com" "$generated_password"
build_password_of_length 8 Aa1
assert_bootstrap_values 0 "exactly the minimum length with full composition" \
    "admin@example.com" "$generated_password"
build_password_of_length 64 Aa1
assert_bootstrap_values 0 "exactly the maximum length" \
    "admin@example.com" "$generated_password"

# Addresses that the earlier host-side pattern accepted and filter_var rejects.
# Each one used to pass the preflight and then crash-loop the backend, because
# BootstrapAdminService threw on a value this script had already approved.
assert_bootstrap_values 1 "consecutive dots in the local part" \
    "a..b@example.com" "Str0ngPass" "valid email address"
assert_bootstrap_values 1 "leading dot in the local part" \
    ".admin@example.com" "Str0ngPass" "valid email address"
assert_bootstrap_values 1 "trailing dot in the local part" \
    "admin.@example.com" "Str0ngPass" "valid email address"
assert_bootstrap_values 1 "domain label starting with a hyphen" \
    "admin@-example.com" "Str0ngPass" "valid email address"
assert_bootstrap_values 1 "domain label ending with a hyphen" \
    "admin@example-.com" "Str0ngPass" "valid email address"
assert_bootstrap_values 1 "local part above 64 bytes" \
    "$(repeat_character a 65)@example.com" "Str0ngPass" "valid email address"
assert_bootstrap_values 1 "domain label above 63 bytes" \
    "admin@$(repeat_character a 64).com" "Str0ngPass" "valid email address"

# The same limits one byte lower stay valid, so the rules above cannot be
# "fixed" by rejecting every long address.
assert_bootstrap_values 0 "local part of exactly 64 bytes" \
    "$(repeat_character a 64)@example.com" "Str0ngPass"
assert_bootstrap_values 0 "domain label of exactly 63 bytes" \
    "admin@$(repeat_character a 63).com" "Str0ngPass"
assert_bootstrap_values 0 "tagged local part" "admin+tag@example.com" "Str0ngPass"
assert_bootstrap_values 0 "dotted local part" "a.b.c@example.com" "Str0ngPass"
assert_bootstrap_values 0 "multi-level domain" "admin@sub.example.co.uk" "Str0ngPass"
assert_bootstrap_values 0 "hyphens inside a domain label" "admin@ex--ample.com" "Str0ngPass"
assert_bootstrap_values 0 "hyphens at the edge of the local part" "-admin-@example.com" "Str0ngPass"

compose() {
    [[ "$*" == "config --environment" ]] || return 1
    printf '%s\n' "PATH=/usr/bin" ${bootstrap_environment[@]+"${bootstrap_environment[@]}"}
}

bootstrap_environment=()
validate_bootstrap_admin_config >/dev/null
bootstrap_environment=(BOOTSTRAP_ADMIN_EMAIL= BOOTSTRAP_ADMIN_PASSWORD=)
validate_bootstrap_admin_config >/dev/null
bootstrap_environment=(BOOTSTRAP_ADMIN_EMAIL=admin@example.com "BOOTSTRAP_ADMIN_PASSWORD=Str0ng Pass=word")
validate_bootstrap_admin_config
bootstrap_environment=(BOOTSTRAP_ADMIN_EMAIL=admin@example.com BOOTSTRAP_ADMIN_PASSWORD=short)
if validate_bootstrap_admin_config 2>/dev/null; then
    echo "Invalid resolved bootstrap password unexpectedly passed validation" >&2
    exit 1
fi

# Every path that starts the stack must run the host-side preflight before its
# first `compose up`; validate-release.sh only guards install-time deploys.
assert_preflight_precedes_compose_up() {
    local script="$SCRIPT_DIR/$1"
    local preflight_line compose_line
    preflight_line="$(awk '/^validate_bootstrap_admin_config$/ { print NR; exit }' "$script")"
    compose_line="$(awk '/^compose up/ { print NR; exit }' "$script")"

    [[ -n "$preflight_line" ]] || {
        printf '%s does not call validate_bootstrap_admin_config\n' "$1" >&2
        exit 1
    }
    [[ -n "$compose_line" ]] || {
        printf '%s no longer starts services with a "compose up" line; update this test\n' "$1" >&2
        exit 1
    }
    (( preflight_line < compose_line )) || {
        printf '%s calls validate_bootstrap_admin_config at line %s, after "compose up" at line %s\n' \
            "$1" "$preflight_line" "$compose_line" >&2
        exit 1
    }
}

assert_preflight_precedes_compose_up post-update.sh
assert_preflight_precedes_compose_up post-restore.sh

# Tier 2's exit-code contract, without Docker: the probe itself runs inside the
# application image, so `compose` is stubbed to replay the statuses and the
# stdout that probe produces. The command line it receives is recorded to a file
# — a variable would be lost, because the caller reads it in a subshell.
probe_command_file="$temporary_dir/probe-command"
probe_status=0
probe_output=""
compose() {
    [[ "$1" == run ]] || return 1
    printf '%s\n' "$*" > "$probe_command_file"
    if [[ -n "$probe_output" ]]; then
        printf '%s\n' "$probe_output"
    fi
    return "$probe_status"
}

assert_image_contract() {
    local expected_status="$1"
    local description="$2"
    local expected_message="${3:-}"
    local output status=0

    output="$(validate_app_image_contract 2>&1)" || status=$?
    [[ "$status" == "$expected_status" ]] || {
        printf 'Image contract case "%s": expected status %s, got %s:\n%s\n' \
            "$description" "$expected_status" "$status" "$output" >&2
        exit 1
    }
    [[ "$output" == *"$expected_message"* ]] || {
        printf 'Image contract case "%s": expected message %s, got:\n%s\n' \
            "$description" "$expected_message" "$output" >&2
        exit 1
    }
}

assert_image_contract 0 "contract satisfied and configuration accepted"

# The reserved status alone is not enough, and neither is the marker alone: only
# both together mean "the authority decided", so a future probe command that
# exits 3 for its own reasons cannot be reported as a rejected credential.
probe_status=3
probe_output="$BOOTSTRAP_ADMIN_REJECTION_MARKER BOOTSTRAP_ADMIN_PASSWORD must be at least 8 characters."
assert_image_contract 1 "authority rejected the configuration" "must be at least 8 characters"
probe_output="a future probe step that happens to exit 3"
assert_image_contract 3 "reserved status without the rejection marker" \
    "Could not verify the application image contract"
probe_status=0
probe_output="$BOOTSTRAP_ADMIN_REJECTION_MARKER BOOTSTRAP_ADMIN_PASSWORD must be at least 8 characters."
assert_image_contract 1 "rejection marker without the reserved status" \
    "Could not verify the application image contract"
probe_status=64
probe_output=""
assert_image_contract 64 "image is missing the entrypoint" \
    "Could not verify the application image contract"
probe_status=125
assert_image_contract 125 "image was never pulled" \
    "run 'docker compose -f deploy/compose.yaml pull' first"
probe_status=0

# HARD CONSTRAINT: the probe must reference the password by NAME only. A value on
# the command line would be visible in the host process list, so a switch to
# `-e BOOTSTRAP_ADMIN_PASSWORD=$value` has to fail this suite.
probe_command="$(<"$probe_command_file")"
[[ "$probe_command" == *'getenv("BOOTSTRAP_ADMIN_PASSWORD")'* ]] || {
    printf 'The image contract probe no longer reads BOOTSTRAP_ADMIN_PASSWORD from the environment:\n%s\n' \
        "$probe_command" >&2
    exit 1
}
[[ "$probe_command" != *"BOOTSTRAP_ADMIN_PASSWORD="* ]] || {
    printf 'The image contract probe puts BOOTSTRAP_ADMIN_PASSWORD on the command line, where the host process list exposes it:\n%s\n' \
        "$probe_command" >&2
    exit 1
}

# The value as the FILE spells it, which is the half `compose config
# --environment` cannot show: it returns the value AFTER Compose's .env parsing.
assert_raw_env_value() {
    local expected_status="$1"
    local description="$2"
    local line="$3"
    local expected="$4"
    local file="$temporary_dir/raw.env"
    local actual status=0

    printf '%s' "$line" > "$file"
    actual="$(env_file_raw_value "$file" PROBE_KEY)" || status=$?
    [[ "$status" == "$expected_status" ]] || {
        printf 'Raw value case "%s": expected status %s, got %s\n' \
            "$description" "$expected_status" "$status" >&2
        exit 1
    }
    [[ "$actual" == "$expected" ]] || {
        printf 'Raw value case "%s": expected [%s], got [%s]\n' "$description" "$expected" "$actual" >&2
        exit 1
    }
}

assert_raw_env_value 1 "key not assigned at all" $'OTHER_KEY=x\n' ""
assert_raw_env_value 0 "plain value" $'PROBE_KEY=plainABC123\n' "plainABC123"
assert_raw_env_value 0 "no trailing newline" 'PROBE_KEY=plainABC123' "plainABC123"
assert_raw_env_value 0 "quotes are kept" $'PROBE_KEY="abc"\n' '"abc"'
assert_raw_env_value 0 "surrounding whitespace is kept" $'PROBE_KEY=  abc  \n' "  abc  "
assert_raw_env_value 0 "an inline comment is kept" $'PROBE_KEY=abc #x\n' "abc #x"
assert_raw_env_value 0 "only the first = separates" $'PROBE_KEY=a=b=c\n' "a=b=c"
assert_raw_env_value 0 "empty value" $'PROBE_KEY=\n' ""
# Forms Compose itself accepts on the KEY side. Reading them as "no assignment"
# would silently skip the key; reading the indent as part of the value would
# report a value Compose never altered.
assert_raw_env_value 0 "an export prefix is normalised away" $'export PROBE_KEY=abc\n' "abc"
assert_raw_env_value 0 "an indented key is normalised away" $'   PROBE_KEY=abc\n' "abc"
assert_raw_env_value 0 "the last assignment wins" $'PROBE_KEY=first\nPROBE_KEY=second\n' "second"
assert_raw_env_value 1 "a longer key is not a prefix match" $'PROBE_KEY_EXTRA=x\n' ""

# Windows line endings. Compose discards the trailing carriage return for every
# value shape, quoted ones included, so a CRLF file is a VALID configuration —
# and keeping the carriage return here reported every covered key of such a file
# as altered at once, with advice naming only causes that did not apply. A
# Windows checkout (core.autocrlf), Notepad or WinSCP produces exactly this.
assert_raw_env_value 0 "a CRLF line ending is not part of the value" $'PROBE_KEY=abc\r\n' "abc"
assert_raw_env_value 0 "a CRLF line ending on a quoted value" $'PROBE_KEY="abc"\r\n' '"abc"'
assert_raw_env_value 0 "a CRLF line ending without a final newline" $'PROBE_KEY=abc\r' "abc"
assert_raw_env_value 0 "a CRLF line ending on a later line" \
    $'OTHER_KEY=x\r\nPROBE_KEY=abc\r\n' "abc"
assert_raw_env_value 0 "an indented export with a CRLF line ending" \
    $'  export PROBE_KEY=abc\r\n' "abc"
# Only the carriage return is discarded. Trimming trailing whitespace as well
# would hide the alteration the guard exists for: Compose turns `abc  ` into
# `abc`, and that has to keep reporting as altered.
assert_raw_env_value 0 "trailing whitespace before a CRLF is kept" $'PROBE_KEY=abc  \r\n' "abc  "
assert_raw_env_value 0 "a carriage return inside the value is kept" \
    $'PROBE_KEY=ab\rcd\n' $'ab\rcd'

# Whitespace around the `=`. Compose accepts it and assigns the key; reading the
# line as "no assignment" skipped the key entirely, so the guard compared
# nothing and passed a value Compose had truncated to `ab`.
assert_raw_env_value 0 "whitespace before the = belongs to the key side" $'PROBE_KEY =abc\n' "abc"
assert_raw_env_value 0 "whitespace after the = stays in the value" $'PROBE_KEY = abc\n' " abc"
assert_raw_env_value 0 "a tab around the = is accepted as Compose accepts it" \
    $'PROBE_KEY\t=\tabc\n' $'\tabc'
assert_raw_env_value 1 "whitespace does not turn a longer key into a match" \
    $'PROBE_KEY_EXTRA = x\n' ""
assert_raw_env_value 1 "a key without an = is not an assignment" $'PROBE_KEY\n' ""

# A UTF-8 BOM, which Windows tooling writes and Compose strips before the first
# key. Keeping it made the first key of the file unrecognisable, so exactly the
# key an operator writes first was the one never compared.
assert_raw_env_value 0 "a UTF-8 BOM before the first key is discarded" \
    $'\xef\xbb\xbfPROBE_KEY=abc\n' "abc"
assert_raw_env_value 0 "a UTF-8 BOM together with CRLF line endings" \
    $'\xef\xbb\xbfPROBE_KEY=abc\r\n' "abc"
assert_raw_env_value 0 "a BOM only affects the first line" \
    $'\xef\xbb\xbfOTHER_KEY=x\nPROBE_KEY=abc\n' "abc"
assert_raw_env_value 1 "a BOM does not turn a longer key into a match" \
    $'\xef\xbb\xbfPROBE_KEY_EXTRA=x\n' ""
# The BOM is stripped as three bytes, never as "any leading non-ASCII": a value
# that is not valid UTF-8 has to survive the read byte for byte, or the
# comparison reports an alteration Compose never made.
assert_raw_env_value 0 "a non-UTF-8 byte in the value survives" \
    $'PROBE_KEY=ab\xffcd\n' $'ab\xffcd'
assert_raw_env_value 0 "a BOM inside the value is not stripped" \
    $'PROBE_KEY=\xef\xbb\xbfabc\n' $'\xef\xbb\xbfabc'

# The preflight itself. Every "resolved" value below is what Docker Compose
# v5.3.1 actually returns for the raw value next to it.
#
# validate_secret_roundtrip() takes no file argument: it resolves the file it
# compares with the same resolver compose() uses, so both halves of the
# comparison are guaranteed to describe the file Compose actually reads. The
# fixtures therefore live where resolve_compose_env_file() finds them, and
# DEPLOY_DIR points at that tree for the rest of this section.
roundtrip_tree="$temporary_dir/roundtrip"
mkdir -p "$roundtrip_tree/deploy"
roundtrip_env_file="$roundtrip_tree/deploy/.env"
DEPLOY_DIR="$roundtrip_tree/deploy"
roundtrip_resolved=()
# The cases below decide on the FILE. A covered key that happens to be exported
# in the caller's shell is skipped by design, which would silently weaken this
# suite depending on who runs it.
for roundtrip_key in "${SYNAPLAN_ROUNDTRIP_KEYS[@]}"; do
    unset "$roundtrip_key"
done
compose() {
    [[ "$*" == "config --environment" ]] || return 1
    printf '%s\n' "PATH=/usr/bin" ${roundtrip_resolved[@]+"${roundtrip_resolved[@]}"}
}

assert_secret_roundtrip() {
    local expected_status="$1"
    local description="$2"
    local key="$3"
    local raw="$4"
    local resolved="$5"
    local output status=0

    printf '%s=%s\n' "$key" "$raw" > "$roundtrip_env_file"
    roundtrip_resolved=("$key=$resolved")
    output="$(validate_secret_roundtrip 2>&1)" || status=$?
    [[ "$status" == "$expected_status" ]] || {
        printf 'Roundtrip case "%s": expected status %s, got %s:\n%s\n' \
            "$description" "$expected_status" "$status" "$output" >&2
        exit 1
    }
    if (( expected_status != 0 )); then
        [[ "$output" == *"$key"* ]] || {
            printf 'Roundtrip case "%s" does not name the affected key:\n%s\n' "$description" "$output" >&2
            exit 1
        }
    fi
    # HARD CONSTRAINT: these keys are secrets, so neither the configured nor the
    # resolved value may appear anywhere in the output. Only values long enough
    # for a match to mean something are asserted: the message legitimately
    # contains English words, and a two-byte fixture matches "variable" by
    # accident. The dedicated case below carries a distinctive value.
    local minimum_distinctive_length=8
    (( ${#raw} < minimum_distinctive_length )) || [[ "$output" != *"$raw"* ]] || {
        printf 'Roundtrip case "%s" prints the configured value:\n%s\n' "$description" "$output" >&2
        exit 1
    }
    (( ${#resolved} < minimum_distinctive_length )) || [[ "$output" != *"$resolved"* ]] || {
        printf 'Roundtrip case "%s" prints the resolved value:\n%s\n' "$description" "$output" >&2
        exit 1
    }
}

# The five ways Compose's .env parser alters a value. Each one silently
# reconfigures the deployment today; for BOOTSTRAP_ADMIN_PASSWORD it locks the
# operator out of their own instance with no recovery path.
assert_secret_roundtrip 1 "an unescaped dollar sign starts an interpolation" \
    BOOTSTRAP_ADMIN_PASSWORD 'ab$cd' 'ab'
assert_secret_roundtrip 1 "a doubled dollar sign collapses" \
    BOOTSTRAP_ADMIN_PASSWORD 'ab$$cd' 'ab$cd'
assert_secret_roundtrip 1 "surrounding double quotes are stripped" \
    APP_SECRET '"abc"' 'abc'
assert_secret_roundtrip 1 "surrounding single quotes are stripped" \
    APP_SECRET "'abc'" 'abc'
assert_secret_roundtrip 1 "surrounding whitespace is trimmed" \
    TOKEN_SECRET '  abc  ' 'abc'
assert_secret_roundtrip 1 "an inline comment is dropped" \
    MARIADB_ROOT_PASSWORD 'abc #x' 'abc'
assert_secret_roundtrip 1 "a backslash escape becomes a real newline" \
    MAILER_DSN '"ab\ncd"' $'ab\ncd'
# A generated administrator address that loses a segment is the same silent
# lockout as a mangled password, so the email is covered too.
assert_secret_roundtrip 1 "an altered email is caught as well" \
    BOOTSTRAP_ADMIN_EMAIL 'admin@ex$ample.com' 'admin@ex.com'
# Distinctive enough that finding it in the output can only mean the guard leaked
# it. Both halves of the comparison are asserted, before and after Compose.
assert_secret_roundtrip 1 "neither the configured nor the resolved value is printed" \
    BOOTSTRAP_ADMIN_PASSWORD 'Zq7NeverPrintThis$suffix' 'Zq7NeverPrintThis'

# Values Compose returns unchanged must not be rejected: a preflight that fails
# on a legitimate secret is its own outage.
assert_secret_roundtrip 0 "a generated hexadecimal secret" \
    APP_SECRET '2f6b8c1d4e9a0b7c' '2f6b8c1d4e9a0b7c'
assert_secret_roundtrip 0 "a password containing a space" \
    MARIADB_ROOT_PASSWORD 'has space' 'has space'
assert_secret_roundtrip 0 "a hash that does not start a comment" \
    MARIADB_PASSWORD 'a#b' 'a#b'
assert_secret_roundtrip 0 "an unbalanced trailing quote" \
    XAI_API_KEY "abc'" "abc'"
assert_secret_roundtrip 0 "an empty optional key" \
    GROQ_API_KEY '' ''
# A Windows configuration file. The helper terminates every line with a newline,
# so a value ending in a carriage return IS a CRLF line. Compose discards it, so
# nothing was altered and the deployment must proceed — this used to fail every
# covered key of such a file at once, with advice naming only causes that did
# not apply, and no way for the operator to act on it.
assert_secret_roundtrip 0 "a Windows (CRLF) configuration file is read back unchanged" \
    APP_SECRET $'2f6b8c1d4e9a0b7c\r' '2f6b8c1d4e9a0b7c'
assert_secret_roundtrip 0 "a CRLF line ending on a value containing a space" \
    MARIADB_ROOT_PASSWORD $'has space\r' 'has space'
# The alteration is still caught in a CRLF file: only the line ending is
# discarded, never the trailing whitespace Compose trims.
assert_secret_roundtrip 1 "trailing whitespace is still caught in a CRLF file" \
    TOKEN_SECRET $'abcdefgh  \r' 'abcdefgh'

# Nothing is printed for an accepted configuration, so the guard cannot leak a
# secret on the happy path either.
printf 'APP_SECRET=%s\n' 2f6b8c1d4e9a0b7c > "$roundtrip_env_file"
roundtrip_resolved=(APP_SECRET=2f6b8c1d4e9a0b7c)
[[ -z "$(validate_secret_roundtrip 2>&1)" ]] || {
    echo "The roundtrip guard prints output for an unaltered configuration" >&2
    exit 1
}

# Every altered key is named in one message: an operator who has to run the
# deployment once per broken value gives up on the third one.
{
    printf 'APP_SECRET="a"\n'
    printf 'TOKEN_SECRET=  b  \n'
    printf 'GROQ_API_KEY=cd\n'
} > "$roundtrip_env_file"
roundtrip_resolved=(APP_SECRET=a "TOKEN_SECRET=b" GROQ_API_KEY=cd)
roundtrip_status=0
roundtrip_output="$(validate_secret_roundtrip 2>&1)" || roundtrip_status=$?
(( roundtrip_status != 0 )) || {
    echo "Two altered values unexpectedly passed the roundtrip guard" >&2
    exit 1
}
[[ "$roundtrip_output" == *APP_SECRET* && "$roundtrip_output" == *TOKEN_SECRET* ]] || {
    printf 'The roundtrip guard does not name every altered key:\n%s\n' "$roundtrip_output" >&2
    exit 1
}
[[ "$roundtrip_output" != *GROQ_API_KEY* ]] || {
    printf 'The roundtrip guard names an unaltered key:\n%s\n' "$roundtrip_output" >&2
    exit 1
}

# Assignment spellings Compose accepts and the raw reader used to read as "this
# file does not assign the key". That skipped the comparison entirely, so the
# guard exited 0 while Compose delivered a truncated `ab` to the containers —
# the exact silent mangling it exists to prevent. Written out instead of using
# the helper, which spells every assignment as `KEY=value`.
assert_altered_value_is_caught() {
    local description="$1"
    local status=0

    roundtrip_resolved=(APP_SECRET=ab)
    validate_secret_roundtrip >/dev/null 2>&1 || status=$?
    (( status != 0 )) || {
        printf 'Assignment case "%s": the guard passed a value Compose truncated\n' \
            "$description" >&2
        exit 1
    }
}

printf 'APP_SECRET = ab$cdefgh\n' > "$roundtrip_env_file"
assert_altered_value_is_caught "whitespace around the ="
printf 'APP_SECRET\t=\tab$cdefgh\n' > "$roundtrip_env_file"
assert_altered_value_is_caught "a tab around the ="
printf '%s' $'\xef\xbb\xbf' > "$roundtrip_env_file"
printf 'APP_SECRET=ab$cdefgh\n' >> "$roundtrip_env_file"
assert_altered_value_is_caught "a UTF-8 BOM before the first key"

# The guard resolves the configuration file itself, with the same resolver
# compose() uses, so both halves of the comparison always describe the file
# Compose actually reads. With both candidates present that is deploy/.env: an
# altered value in the ignored checkout-root file must not fail a deployment
# that never reads it, and the file that IS in play must still be compared.
printf 'APP_SECRET=2f6b8c1d4e9a0b7c\n' > "$roundtrip_env_file"
printf 'APP_SECRET="ignored-entirely"\n' > "$roundtrip_tree/.env"
roundtrip_resolved=(APP_SECRET=2f6b8c1d4e9a0b7c)
validate_secret_roundtrip || {
    echo "The roundtrip guard compared a configuration file Compose does not read" >&2
    exit 1
}
printf 'APP_SECRET="2f6b8c1d4e9a0b7c"\n' > "$roundtrip_env_file"
if validate_secret_roundtrip >/dev/null 2>&1; then
    echo "The roundtrip guard did not compare the configuration file Compose reads" >&2
    exit 1
fi
rm -f "$roundtrip_tree/.env"

# An exported host variable wins over every file entry, so Compose resolves it
# from the environment and the file entry is not the value in play. Comparing it
# would fail a deployment that is configured correctly.
printf 'APP_SECRET="abc"\n' > "$roundtrip_env_file"
roundtrip_resolved=(APP_SECRET=something-else-entirely)
(
    export APP_SECRET=something-else-entirely
    validate_secret_roundtrip
) || {
    echo "A host-provided value was compared against a file entry it overrides" >&2
    exit 1
}

# Keys the file does not mention at all come from the host environment or from
# compose.yaml's own default, so there is nothing to compare.
printf 'UNRELATED_KEY=x\n' > "$roundtrip_env_file"
roundtrip_resolved=(APP_SECRET=whatever)
validate_secret_roundtrip || {
    echo "A key that the configuration file does not assign was compared anyway" >&2
    exit 1
}

# A host-environment-only deployment has no file, so Compose parses nothing and
# the guard must not even resolve an environment: it has to stay a silent no-op.
# This is the case for every self-hoster who has no configuration file today.
compose() {
    echo "the roundtrip guard resolved an environment although no file is in play" >&2
    return 1
}
rm -f "$roundtrip_env_file"
validate_secret_roundtrip || {
    echo "The roundtrip guard is not a no-op when no configuration file exists" >&2
    exit 1
}
(
    # Read indirectly, by resolve_compose_env_file() from the sourced library.
    # shellcheck disable=SC2034
    DEPLOY_DIR="$env_tree/deploy"
    validate_secret_roundtrip
) || {
    echo "The roundtrip guard is not a no-op when neither candidate file exists" >&2
    exit 1
}

# The guard has to run on the paths that configure a deployment, before any rule
# that inspects an already-resolved value.
assert_roundtrip_precedes_value_rules() {
    local script="$SCRIPT_DIR/$1"
    local roundtrip_line value_rule_line
    roundtrip_line="$(awk '/^validate_secret_roundtrip/ { print NR; exit }' "$script")"
    value_rule_line="$(awk '/^(validate_bootstrap_admin_config|validate_app_image_contract)$/ { print NR; exit }' "$script")"

    [[ -n "$roundtrip_line" ]] || {
        printf '%s does not call validate_secret_roundtrip\n' "$1" >&2
        exit 1
    }
    [[ -z "$value_rule_line" ]] || (( roundtrip_line < value_rule_line )) || {
        printf '%s calls validate_secret_roundtrip at line %s, after a value rule at line %s\n' \
            "$1" "$roundtrip_line" "$value_rule_line" >&2
        exit 1
    }
}

assert_roundtrip_precedes_value_rules prepare.sh
assert_roundtrip_precedes_value_rules validate-release.sh

# Both platform commands must name the configuration source, before they act on
# it. run.sh used to start the stack without saying which file configured it, so
# a checkout-root .env silently ignored in favour of a leftover deploy/.env left
# no trace anywhere in the deployment log.
assert_reports_configuration_source() {
    local script="$SCRIPT_DIR/$1"
    local report_line action_line
    report_line="$(awk '/^report_compose_env_file$/ { print NR; exit }' "$script")"
    action_line="$(awk '/^(compose |"\$DEPLOY_DIR)/ { print NR; exit }' "$script")"

    [[ -n "$report_line" ]] || {
        printf '%s does not report which configuration file it uses\n' "$1" >&2
        exit 1
    }
    [[ -z "$action_line" ]] || (( report_line < action_line )) || {
        printf '%s reports the configuration source at line %s, after acting on it at line %s\n' \
            "$1" "$report_line" "$action_line" >&2
        exit 1
    }
}

assert_reports_configuration_source prepare.sh
assert_reports_configuration_source run.sh
# Every path that starts the stack, too: post-update.sh is the install, deploy and
# update hook, and validate-release.sh does not run before the first two.
assert_roundtrip_precedes_value_rules post-update.sh
assert_roundtrip_precedes_value_rules post-restore.sh

# One independent value per secret, recorded where a redeploy cannot erase it.
#
# The defect these cases exist for: a managed platform's password generator is ONE
# draw per deployment. Elestio substitutes its `random_password` placeholder into
# every variable that names it, so a real installation came up with APP_SECRET,
# TOKEN_SECRET, both MariaDB passwords and all four REALTIME_* values set to the
# same string — the database password was also the secret signing every
# application token. Nothing in the stack noticed, because every side used the
# same wrong value consistently.
#
# Every case runs in a subshell. ensure_deployment_secrets EXPORTS its result, and
# a leaked export would silently decide the next case — and would make the
# roundtrip section above skip the very keys it covers.
secrets_tree="$temporary_dir/secrets"
secrets_case_index=0
secrets_deploy_dir=""
secrets_data_dir=""
secrets_file=""
secrets_messages=""

# A deployment tree per case: resolve_compose_env_file() looks for deploy/.env and
# <checkout>/.env, and the secrets file lives in deploy/data, so no two cases may
# share a root.
new_secrets_case() {
    secrets_case_index=$((secrets_case_index + 1))
    secrets_deploy_dir="$secrets_tree/case-$secrets_case_index/deploy"
    secrets_data_dir="$secrets_deploy_dir/data"
    secrets_file="$secrets_data_dir/secrets.env"
    secrets_messages="$secrets_tree/case-$secrets_case_index/messages.log"
    mkdir -p "$secrets_deploy_dir"
}

# Runs ensure_deployment_secrets for the current case and prints the RESOLVED
# environment it exported, one `KEY=value` line per managed secret, so a case can
# assert on values that only exist inside that subshell. Arguments are `KEY=value`
# assignments exported before the call, which is how the "already configured"
# cases are set up. The function's own messages go to a file instead of stdout, so
# they can be asserted separately — several cases require that no value appears in
# them.
run_ensure_secrets() {
    local status=0
    (
        # Both are read indirectly, by the sourced library.
        # shellcheck disable=SC2030,SC2034
        DEPLOY_DIR="$secrets_deploy_dir"
        # shellcheck disable=SC2030,SC2034
        DATA_DIR="$secrets_data_dir"
        if (($# > 0)); then
            export "$@"
        fi
        ensure_deployment_secrets > "$secrets_messages" 2>&1 || exit $?
        for secrets_key in "${SYNAPLAN_MANAGED_SECRET_KEYS[@]}"; do
            printf '%s=%s\n' "$secrets_key" "${!secrets_key-}"
        done
    ) || status=$?
    return "$status"
}

# The permission bits, spelled differently by GNU and BSD stat. The mode is a
# guarantee of this file, not a detail: it holds every credential of the
# deployment.
#
# GNU is asked first, and its output is captured rather than tested in place:
# `stat -f` means --file-system there, so it reads "%Lp" as a FILE NAME, prints a
# filesystem report for the real argument on STDOUT and exits non-zero. A
# BSD-first probe would therefore pass that report on as the mode on every Linux
# host, CI included. BSD rejects `-c` as an illegal option with nothing on stdout,
# so this order is unambiguous on both.
file_mode() {
    local mode
    mode="$(stat -c '%a' "$1" 2>/dev/null)" || mode="$(stat -f '%Lp' "$1")"
    printf '%s\n' "$mode"
}

assert_secret_value() {
    local description="$1"
    local resolved="$2"
    local key="$3"
    local expected="$4"
    local actual
    actual="$(compose_environment_value "$resolved" "$key")"
    [[ "$actual" == "$expected" ]] || {
        printf 'Secret case "%s": %s resolved to %s bytes, expected the configured %s bytes\n' \
            "$description" "$key" "${#actual}" "${#expected}" >&2
        exit 1
    }
}

# A fresh deployment: nothing configured, nothing initialised. Every secret must
# get its OWN 32-byte draw — the whole point — and the generated shape must be one
# that no later parser can rewrite.
new_secrets_case
secrets_resolved="$(run_ensure_secrets)"
for secrets_key in "${SYNAPLAN_MANAGED_SECRET_KEYS[@]}"; do
    secrets_generated="$(compose_environment_value "$secrets_resolved" "$secrets_key")"
    [[ "$secrets_generated" =~ ^[0-9a-f]{64}$ ]] || {
        printf 'A fresh deployment did not generate 64 hexadecimal characters for %s; it produced %s bytes\n' \
            "$secrets_key" "${#secrets_generated}" >&2
        exit 1
    }
done

# THE defect. Eight identical values is exactly what the platform produced, and a
# suite that only checked the shape above would have passed on it.
secrets_distinct="$(printf '%s\n' "$secrets_resolved" | awk -F= '{ print $2 }' | sort -u | wc -l | tr -d ' ')"
[[ "$secrets_distinct" == "${#SYNAPLAN_MANAGED_SECRET_KEYS[@]}" ]] || {
    printf 'A fresh deployment produced %s distinct values for %s secrets; each one must be an independent draw\n' \
        "$secrets_distinct" "${#SYNAPLAN_MANAGED_SECRET_KEYS[@]}" >&2
    exit 1
}

# HARD CONSTRAINT: a deployment log is not a place for credentials, and these
# lines are printed on every start.
while IFS= read -r secrets_line; do
    secrets_generated="${secrets_line#*=}"
    [[ "$(<"$secrets_messages")" != *"$secrets_generated"* ]] || {
        printf 'ensure_deployment_secrets prints the value it generated for %s\n' \
            "${secrets_line%%=*}" >&2
        exit 1
    }
done <<< "$secrets_resolved"

# The file the values live in. deploy/data is the only part of the deployment that
# survives an Elestio redeploy, because the platform rewrites the deployment's
# .env from its own configuration every time.
[[ -f "$secrets_file" ]] || {
    printf 'A fresh deployment did not record its secrets in %s\n' "$secrets_file" >&2
    exit 1
}
[[ "$(file_mode "$secrets_file")" == "600" ]] || {
    printf 'The deployment secrets file has mode %s, expected 600\n' \
        "$(file_mode "$secrets_file")" >&2
    exit 1
}
secrets_assignment_count="$(LC_ALL=C awk '/^[A-Z]/ { count++ } END { print count + 0 }' "$secrets_file")"
[[ "$secrets_assignment_count" == "${#SYNAPLAN_MANAGED_SECRET_KEYS[@]}" ]] || {
    printf 'The deployment secrets file assigns %s keys, expected %s\n' \
        "$secrets_assignment_count" "${#SYNAPLAN_MANAGED_SECRET_KEYS[@]}" >&2
    exit 1
}
# The file itself has to say that losing it loses the database: whoever finds it
# during a disaster recovery has nothing else to go by.
grep -Fq 'backup' "$secrets_file" || {
    printf '%s does not tell the reader that it belongs in a backup\n' "$secrets_file" >&2
    exit 1
}

# A host without openssl. The portable /dev/urandom fallback has to produce the
# same shape, because nothing downstream would notice a short or empty draw — it
# would simply become the deployment's permanent database password.
(
    command() { return 1; }
    secrets_fallback="$(generate_deployment_secret)"
    [[ "$secrets_fallback" =~ ^[0-9a-f]{64}$ ]]
) || {
    echo "The /dev/urandom fallback does not produce 64 hexadecimal characters on a host without openssl" >&2
    exit 1
}

# An installation that already runs must not change at all. Its credentials come
# from the host environment or from the configuration file Compose reads, and both
# are adopted BYTE FOR BYTE — a "normalised" MariaDB password would be a database
# the application can no longer open.
new_secrets_case
printf 'APP_SECRET=configured-in-the-deployment-file\n' > "$secrets_deploy_dir/.env"
printf 'UNRELATED_KEY=x\n' > "$(dirname "$secrets_deploy_dir")/.env"
secrets_adopted_password='already in use #7'
secrets_resolved="$(run_ensure_secrets "MARIADB_PASSWORD=$secrets_adopted_password")"
assert_secret_value "adoption" "$secrets_resolved" MARIADB_PASSWORD "$secrets_adopted_password"
assert_secret_value "adoption" "$secrets_resolved" APP_SECRET configured-in-the-deployment-file
[[ "$(compose_environment_value "$secrets_resolved" TOKEN_SECRET)" =~ ^[0-9a-f]{64}$ ]] || {
    echo "A secret that nothing configured was not generated alongside the adopted ones" >&2
    exit 1
}
[[ "$(<"$secrets_messages")" != *"$secrets_adopted_password"* ]] || {
    echo "ensure_deployment_secrets prints an adopted secret" >&2
    exit 1
}
# Adopted values are recorded too, so the next start no longer depends on a
# platform that may not deliver them again.
[[ "$(env_file_raw_value "$secrets_file" MARIADB_PASSWORD)" == "$secrets_adopted_password" ]] || {
    echo "The adopted MariaDB password was not recorded verbatim in the secrets file" >&2
    exit 1
}

# Idempotence, and the reason the file exists: once a value is in use it is fixed.
# A second start must resolve the recorded set even when the environment now says
# something else — a platform that rotates its generated value on every deploy is
# exactly the situation this deployment is protecting itself from.
secrets_recorded="$(<"$secrets_file")"
secrets_resolved_again="$(run_ensure_secrets MARIADB_PASSWORD=a-rotated-value APP_SECRET=another-rotated-value)"
[[ "$(<"$secrets_file")" == "$secrets_recorded" ]] || {
    echo "A second run rewrote the deployment secrets file" >&2
    exit 1
}
[[ "$secrets_resolved_again" == "$secrets_resolved" ]] || {
    echo "A second run resolved different secrets than the recorded ones" >&2
    exit 1
}
[[ "$(<"$secrets_messages")" != *a-rotated-value* ]] || {
    echo "ensure_deployment_secrets prints a value from the environment" >&2
    exit 1
}

# The abort. An initialised stack whose credential is unknown must NOT be given a
# new one: MariaDB created its user with the old password, so a generated
# MARIADB_PASSWORD locks the application out of its own data permanently, with no
# way back. The message has to name the variable, because that is the only thing
# the operator can act on.
new_secrets_case
mkdir -p "$secrets_data_dir/mariadb"
# What "initialised" means here: a POPULATED data directory. The directory itself
# exists on every deployment from the first start onwards, because
# prepare_data_directories() creates it empty — treating that as initialised would
# abort every fresh installation with advice about a credential that never was.
printf 'x' > "$secrets_data_dir/mariadb/ibdata1"
secrets_status=0
run_ensure_secrets \
    APP_SECRET=known TOKEN_SECRET=known MARIADB_ROOT_PASSWORD=known \
    REALTIME_API_KEY=known REALTIME_TOKEN_SECRET=known \
    REALTIME_ADMIN_PASSWORD=known REALTIME_ADMIN_SECRET=known \
    > /dev/null || secrets_status=$?
((secrets_status != 0)) || {
    echo "An initialised deployment with an unknown MariaDB password was allowed to generate a new one" >&2
    exit 1
}
grep -Fq MARIADB_PASSWORD "$secrets_messages" || {
    printf 'The refusal does not name the missing variable:\n%s\n' "$(<"$secrets_messages")" >&2
    exit 1
}
[[ ! -f "$secrets_file" ]] || {
    echo "A refused deployment left a secrets file behind" >&2
    exit 1
}

# And every missing variable in ONE message: an operator who has to run the
# deployment once per missing value gives up on the third one.
new_secrets_case
mkdir -p "$secrets_data_dir/mariadb"
printf 'x' > "$secrets_data_dir/mariadb/ibdata1"
secrets_status=0
run_ensure_secrets \
    APP_SECRET=known TOKEN_SECRET=known MARIADB_ROOT_PASSWORD=known \
    REALTIME_TOKEN_SECRET=known REALTIME_ADMIN_PASSWORD=known \
    REALTIME_ADMIN_SECRET=known \
    > /dev/null || secrets_status=$?
((secrets_status != 0)) || {
    echo "An initialised deployment with two unknown secrets was allowed to generate them" >&2
    exit 1
}
grep -Fq MARIADB_PASSWORD "$secrets_messages" && grep -Fq REALTIME_API_KEY "$secrets_messages" || {
    printf 'The refusal does not name every missing variable:\n%s\n' "$(<"$secrets_messages")" >&2
    exit 1
}

# The same directory, empty, is a fresh deployment and must generate. This is the
# state prepare_data_directories() leaves behind on every single start.
new_secrets_case
mkdir -p "$secrets_data_dir/mariadb"
secrets_resolved="$(run_ensure_secrets)"
[[ "$(compose_environment_value "$secrets_resolved" MARIADB_PASSWORD)" =~ ^[0-9a-f]{64}$ ]] || {
    echo "An empty data/mariadb directory was mistaken for an initialised deployment" >&2
    exit 1
}

# A spelling Compose does not read back unchanged must not be adopted from the
# configuration FILE. The adopted value is exported afterwards, and Compose never
# rewrites a host environment variable — so `MARIADB_PASSWORD="abc"`, which
# reaches the database as `abc` today, would reach it as `"abc"` from then on and
# break exactly the installation this rule protects. validate_secret_roundtrip()
# rejects the same spellings, but it cannot run this early: it resolves its
# comparison through `compose config --environment`, which fails while these
# `${VAR:?}` keys have no value at all.
assert_secret_not_adoptable() {
    local description="$1"
    local raw="$2"
    local status=0

    new_secrets_case
    printf 'APP_SECRET=%s\n' "$raw" > "$secrets_deploy_dir/.env"
    run_ensure_secrets > /dev/null || status=$?
    ((status != 0)) || {
        printf 'Adoption case "%s": a value Compose rewrites was adopted and exported as written\n' \
            "$description" >&2
        exit 1
    }
    grep -Fq APP_SECRET "$secrets_messages" || {
        printf 'Adoption case "%s" does not name the affected key:\n%s\n' \
            "$description" "$(<"$secrets_messages")" >&2
        exit 1
    }
}

assert_secret_not_adoptable "surrounding double quotes" '"abcdefgh"'
assert_secret_not_adoptable "surrounding single quotes" "'abcdefgh'"
assert_secret_not_adoptable "an unescaped dollar sign" 'ab$cdefgh'
assert_secret_not_adoptable "surrounding whitespace" '  abcdefgh  '
assert_secret_not_adoptable "an inline comment" 'abcdefgh #x'

# Spellings Compose returns unchanged stay adoptable: refusing them would fail a
# deployment that is configured perfectly well.
assert_secret_adoptable() {
    local description="$1"
    local raw="$2"

    new_secrets_case
    printf 'APP_SECRET=%s\n' "$raw" > "$secrets_deploy_dir/.env"
    assert_secret_value "$description" "$(run_ensure_secrets)" APP_SECRET "$raw"
}

assert_secret_adoptable "a generated hexadecimal secret" 2f6b8c1d4e9a0b7c
assert_secret_adoptable "an interior space" 'has space'
assert_secret_adoptable "a hash that does not start a comment" 'a#b'
assert_secret_adoptable "an unbalanced trailing quote" "abcdefgh'"

# CONTRACT with the roundtrip guard: a generated value must be one Docker Compose
# reads back byte for byte, or the deployment would reject its own secrets on the
# next start.
#
# The property is asserted directly — none of the five spellings Compose's .env
# parser rewrites can occur in 64 hexadecimal characters — and then fed through
# validate_secret_roundtrip() itself. Its `compose config --environment` is
# stubbed to return the value unchanged, which is what Compose does for a value
# with no dollar sign, no quotes, no surrounding whitespace and no ` #`; the shape
# assertion above is what makes that stub legitimate in a suite that must run
# without Docker.
new_secrets_case
secrets_resolved="$(run_ensure_secrets)"
(
    roundtrip_tree="$secrets_tree/roundtrip"
    mkdir -p "$roundtrip_tree/deploy"
    # Read indirectly, by resolve_compose_env_file() from the sourced library.
    # shellcheck disable=SC2030,SC2034
    DEPLOY_DIR="$roundtrip_tree/deploy"
    : > "$roundtrip_tree/deploy/.env"
    while IFS= read -r secrets_line; do
        secrets_key="${secrets_line%%=*}"
        secrets_generated="${secrets_line#*=}"
        [[ "$secrets_generated" != *'$'* && "$secrets_generated" != *'"'* &&
            "$secrets_generated" != *"'"* && "$secrets_generated" != *' '* &&
            "$secrets_generated" != *'#'* ]] || {
            printf 'The value generated for %s contains a character Compose rewrites in a .env file\n' \
                "$secrets_key" >&2
            exit 1
        }
        printf '%s\n' "$secrets_line" >> "$roundtrip_tree/deploy/.env"
    done <<< "$secrets_resolved"

    compose() {
        [[ "$*" == "config --environment" ]] || return 1
        printf '%s\n' "PATH=/usr/bin" "$secrets_resolved"
    }

    validate_secret_roundtrip
) || {
    echo "Generated secrets do not survive the roundtrip guard" >&2
    exit 1
}

# Every lifecycle script has to resolve the secrets before it touches the stack.
# compose.yaml declares them as `${VAR:?}`, so a script that reaches Compose
# without them aborts on the first interpolation — and one that reaches it with
# only SOME of them configured would start a container against the wrong
# credentials. build.sh and pre-update.sh are the subtle ones: they call sibling
# scripts as CHILD processes, whose exports never come back to them, and then run
# a `compose pull` of their own.
#
# Pass "only-when-it-does" for a tree where reaching the stack is the exception
# rather than the rule: the AWS adapter also contains image-build and first-boot
# steps that run before a stack exists, and demanding the call there would only
# teach the next author to add it as a ritual.
assert_resolves_secrets_before_compose() {
    local script="$1"
    local requirement="${2:-always}"
    local name ensure_line stack_line
    name="$(basename "$script")"
    ensure_line="$(awk '/^ensure_deployment_secrets$/ { print NR; exit }' "$script")"
    stack_line="$(awk '/^(compose |app_tool |begin_service_pause|pause_services|resume_paused_services|wait_for_service_health|validate_|"\$DEPLOY_DIR)/ { print NR; exit }' "$script")"

    [[ "$requirement" == only-when-it-does && -z "$stack_line" ]] && return 0

    [[ -n "$ensure_line" ]] || {
        printf '%s reaches the stack without resolving the deployment secrets first\n' "$name" >&2
        exit 1
    }
    [[ -z "$stack_line" ]] || ((ensure_line < stack_line)) || {
        printf '%s calls ensure_deployment_secrets at line %s, after reaching the stack at line %s\n' \
            "$name" "$ensure_line" "$stack_line" >&2
        exit 1
    }
}

for script in "$SCRIPT_DIR"/*.sh; do
    [[ "$script" == */lib.sh ]] && continue
    assert_resolves_secrets_before_compose "$script"
done

for script in "$AWS_DIR/scripts"/*.sh; do
    assert_resolves_secrets_before_compose "$script" only-when-it-does
done

# The platform manifest must no longer declare the managed secrets. Elestio would
# generate ONE value for all of them and display it, which is the defect; leaving
# a single key in place would silently reintroduce a shared credential for it.
for secrets_key in "${SYNAPLAN_MANAGED_SECRET_KEYS[@]}"; do
    grep -Eq "^  - key: \"$secrets_key\"" "$ELESTIO_MANIFEST" && {
        printf 'elestio.yml still declares %s, so the platform generates one shared value for it again\n' \
            "$secrets_key" >&2
        exit 1
    }
done
# BOOTSTRAP_ADMIN_PASSWORD is deliberately NOT managed: the platform displays it as
# the login for the web UI shortcut, so it has to stay exactly what the platform
# generated.
grep -Eq '^  - key: "BOOTSTRAP_ADMIN_PASSWORD"' "$ELESTIO_MANIFEST" || {
    echo "elestio.yml no longer declares BOOTSTRAP_ADMIN_PASSWORD, which the platform displays as the administrator login" >&2
    exit 1
}
[[ " ${SYNAPLAN_MANAGED_SECRET_KEYS[*]} " != *" BOOTSTRAP_ADMIN_PASSWORD "* ]] || {
    echo "BOOTSTRAP_ADMIN_PASSWORD must not be a managed secret; the platform displays it as the administrator login" >&2
    exit 1
}

# CONTRACT: tier 1 (the pure-shell rules above) must agree with the authority,
# App\Service\Admin\BootstrapAdminConfiguration, on every case below — the class
# tier 2 calls inside the application image. The absence of this test is what let
# the host-side pattern drift looser than the authority.
#
# It needs a PHP runtime, which means a container, and this suite runs in CI's
# Docker-free lint job — so it is opt-in and skipped by default:
#
#   SYNAPLAN_CONTRACT_PHP_IMAGE=php:8.3-cli bash deploy/scripts/tests/test-lifecycle.sh
#
# Any image providing a `php` binary works: the authority has no dependencies, so
# its class file is mounted in and reached through the same SYNAPLAN_AUTOLOAD_PATH
# knob the image uses for vendor/autoload.php. That keeps this case pinned to the
# rules in THIS checkout instead of the copy baked into some image. Use the
# release image (ghcr.io/metadist/synaplan:<tag>) to pin the exact runtime
# production runs.
AUTHORITY_FILE="$(cd "$SCRIPT_DIR/../.." && pwd)/backend/src/Service/Admin/BootstrapAdminConfiguration.php"

contract_valid_email="admin@example.com"
contract_valid_password="Str0ngPass"
contract_emails=(
    "admin@example.com"
    "a..b@example.com"
    ".admin@example.com"
    "admin.@example.com"
    "admin@-example.com"
    "admin@example-.com"
    "$(repeat_character a 65)@example.com"
    "$(repeat_character a 64)@example.com"
    "admin@$(repeat_character a 64).com"
    "admin@$(repeat_character a 63).com"
    "admin+tag@example.com"
    "a.b.c@example.com"
    "admin@sub.example.co.uk"
    "admin@ex--ample.com"
    "-admin-@example.com"
    "admin@1example.com"
    "admin@1.example.com"
    "admin@example.123"
    "admin@example.1a"
    "admin@example.a1"
    "admin@123.45.67.89"
    "admin@1.2"
    "admin@example.c"
    "a@b.c"
    "admin@example..com"
    "admin@.example.com"
    "admin@example.com."
    "admin@example_x.com"
    "admin@localhost"
    "admin@@example.com"
    "admin @example.com"
    "ad min@example.com"
    "admin"
    "@example.com"
    "admin@"
    "!#\$%&'*+-/=?^_\`{|}~@example.com"
)

# Forms filter_var accepts that the host-side rule rejects on purpose: none of
# them is a usable administrator mailbox, and rejecting them costs one clear
# preflight error instead of a crash loop. Listed separately so the intentional
# asymmetry is visible, and so the strict-agreement list above stays strict.
contract_stricter_emails=(
    "admin@[127.0.0.1]"
    "admin@[IPv6:2001:db8::1]"
    "\"admin\"@example.com"
)

# Tier 2 decides the password with the same call, so the password rules belong in
# the same agreement check. Boundary lengths are built, never hand-counted.
build_password_of_length 8 Aa1
contract_password_minimum="$generated_password"
build_password_of_length 15 Aa
contract_password_below_waiver="$generated_password"
build_password_of_length 16 Aa
contract_password_at_waiver="$generated_password"
build_password_of_length 64 Aa1
contract_password_maximum="$generated_password"
build_password_of_length 65 Aa1
contract_password_above_maximum="$generated_password"
contract_passwords=(
    "$contract_valid_password"
    "str0ngpass"
    "STR0NGPASS"
    "StrongPass"
    "Short1a"
    "Str0ng Pass=word"
    "QWZFaxYB-gtYh-AXqFbcde"
    "$contract_password_minimum"
    "$contract_password_below_waiver"
    "$contract_password_at_waiver"
    "$contract_password_maximum"
    "$contract_password_above_maximum"
)

# One case per line, email and password separated by a tab: no fixture contains
# one, and any other separator appears inside a realistic address or password.
contract_tab=$'\t'
contract_cases=()
for contract_value in "${contract_emails[@]}"; do
    contract_cases+=("${contract_value}${contract_tab}${contract_valid_password}")
done
for contract_value in "${contract_passwords[@]}"; do
    contract_cases+=("${contract_valid_email}${contract_tab}${contract_value}")
done
contract_stricter_cases=()
for contract_value in "${contract_stricter_emails[@]}"; do
    contract_stricter_cases+=("${contract_value}${contract_tab}${contract_valid_password}")
done

# One container decides every case, and it runs the exact command lib.sh runs
# inside the application image — so this test can never end up agreeing with a
# copy of the rules. The cases arrive on stdin and reach PHP through the
# container's environment, never through a command line.
authority_statuses() {
    local image="$1"
    shift
    local script

    script="$(
        printf 'authority_verdict() {\n'
        bootstrap_admin_authority_command
        cat <<'LOOP'
}

tab="$(printf '\t')"
while IFS="$tab" read -r BOOTSTRAP_ADMIN_EMAIL BOOTSTRAP_ADMIN_PASSWORD; do
    export BOOTSTRAP_ADMIN_EMAIL BOOTSTRAP_ADMIN_PASSWORD
    status=0
    authority_verdict >/dev/null 2>&1 </dev/null || status=$?
    printf '%s\n' "$status"
done
LOOP
    )"

    printf '%s\n' "$@" |
        docker run --rm -i \
            --entrypoint sh \
            -e SYNAPLAN_AUTOLOAD_PATH=/synaplan-authority.php \
            -v "$AUTHORITY_FILE:/synaplan-authority.php:ro" \
            "$image" -ec "$script"
}

# 0 accepted, 1 rejected — tier 1's own contract. Passwords are never printed by
# either tier, so a mismatch is reported by case index and length instead.
host_status() {
    local status=0
    validate_bootstrap_admin_values "$1" "$2" >/dev/null 2>&1 || status=$?
    printf '%s' "$status"
}

# The authority answers 0 accepted, 3 rejected. Anything else means it could not
# run at all, which must fail loudly instead of counting as "rejected".
expected_host_status() {
    case "$1" in
        0) printf '0' ;;
        3) printf '1' ;;
        *) printf 'undecided' ;;
    esac
}

assert_bootstrap_contract() {
    local image="$1"
    local statuses status expected email password
    local index=0

    statuses="$(authority_statuses "$image" "${contract_cases[@]}")"
    while IFS= read -r status; do
        email="${contract_cases[$index]%%$contract_tab*}"
        password="${contract_cases[$index]#*$contract_tab}"
        expected="$(expected_host_status "$status")"
        [[ "$expected" != undecided ]] || {
            printf 'The authority could not decide case %s (email "%s", %s byte password): it exited %s\n' \
                "$index" "$email" "${#password}" "$status" >&2
            exit 1
        }
        [[ "$(host_status "$email" "$password")" == "$expected" ]] || {
            printf 'Bootstrap contract drift in case %s (email "%s", %s byte password): the authority says %s, the host says %s\n' \
                "$index" "$email" "${#password}" \
                "$(expected_host_status "$status")" "$(host_status "$email" "$password")" >&2
            exit 1
        }
        index=$((index + 1))
    done <<< "$statuses"

    (( index == ${#contract_cases[@]} )) || {
        printf 'Expected %s authority verdicts, got %s\n' "${#contract_cases[@]}" "$index" >&2
        exit 1
    }

    local stricter_index=0
    statuses="$(authority_statuses "$image" "${contract_stricter_cases[@]}")"
    while IFS= read -r status; do
        email="${contract_stricter_emails[$stricter_index]}"
        [[ "$status" == 0 && "$(host_status "$email" "$contract_valid_password")" == 1 ]] || {
            printf 'Expected "%s" to be accepted by the authority and deliberately host-rejected; the authority exited %s, the host says %s\n' \
                "$email" "$status" "$(host_status "$email" "$contract_valid_password")" >&2
            exit 1
        }
        stricter_index=$((stricter_index + 1))
    done <<< "$statuses"

    (( stricter_index == ${#contract_stricter_cases[@]} )) || {
        printf 'Expected %s authority verdicts for the stricter cases, got %s\n' \
            "${#contract_stricter_cases[@]}" "$stricter_index" >&2
        exit 1
    }

    printf 'Bootstrap email contract verified against %s (%s emails + %s passwords matching the authority, %s deliberately stricter).\n' \
        "$image" "${#contract_emails[@]}" "${#contract_passwords[@]}" "$stricter_index"
}

if [[ -n "${SYNAPLAN_CONTRACT_PHP_IMAGE:-}" ]]; then
    assert_bootstrap_contract "$SYNAPLAN_CONTRACT_PHP_IMAGE"
else
    echo "Bootstrap email contract test skipped; set SYNAPLAN_CONTRACT_PHP_IMAGE to a PHP-capable image to run it."
fi

echo "Lifecycle contract tests passed."
