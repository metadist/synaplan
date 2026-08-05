#!/usr/bin/env bash

set -Eeuo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA_DIR="$DEPLOY_DIR/data"
STATE_DIR="$DEPLOY_DIR/.lifecycle"
BACKUP_DIR="$DATA_DIR/backups"
PAUSED_SERVICES_FILE="$STATE_DIR/paused-services"

# The configuration file Compose must read, or nothing at all when the
# configuration arrives as host environment variables only.
#
# Two layouts have to work, and only one of them is Compose's own default:
#
#   deploy/.env       the documented self-host location. `-f deploy/compose.yaml`
#                     makes deploy/ the project directory, so this is also the
#                     file Compose would read on its own; passing it explicitly
#                     changes nothing.
#   <checkout>/.env   where a managed platform materialises the environment it
#                     was configured with. Elestio's own examples
#                     (elestio-examples/glpi, elestio-examples/ws-screenshot)
#                     open their lifecycle hooks with
#                     `set -o allexport; source .env; set +o allexport`, so the
#                     file lands at the checkout root — where Compose IGNORES it,
#                     because the project directory is deploy/. Without this
#                     fallback every required `${VAR:?}` would abort the very
#                     first lifecycle script and the deployment would never
#                     start.
#
# Precedence, highest first:
#   1. a real host environment variable. Compose always prefers it over any
#      --env-file entry, so exported configuration keeps winning, unchanged.
#   2. deploy/.env. An operator who created the documented file meant it, and
#      their behaviour must not change. A checkout can additionally carry a
#      root .env for the DEVELOPMENT stack (it is gitignored at the repository
#      root), which must never override the production file.
#   3. <checkout>/.env, the managed-platform fallback.
#
# Exactly one file is ever passed: Compose does not merge env files, so two files
# with disagreeing values would resolve differently per invocation.
#
# The file is handed to Compose instead of being sourced. Sourcing it, as the
# Elestio examples do, would execute whatever the file contains in the lifecycle
# shell, and would add a second .env parser that disagrees with the one the
# containers are configured by. Compose stays the single parser.
#
# The candidate files themselves live in one place, so the resolver that PICKS
# one and the report that names the others as ignored can never disagree about
# what the candidates are.
compose_env_file_candidates() {
    local deploy_dir="${1:-$DEPLOY_DIR}"
    printf '%s\n' "$deploy_dir/.env" "$(dirname "$deploy_dir")/.env"
}

resolve_compose_env_file() {
    local candidate
    while IFS= read -r candidate; do
        if [[ -f "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done < <(compose_env_file_candidates "${1:-$DEPLOY_DIR}")
}

# Name the file this deployment is configured from, and the two things about it
# that are silent everywhere else. Called once per platform command, so it stays
# at one line per fact.
#
# BOTH CANDIDATES PRESENT is the dangerous one. Exactly one --env-file is passed
# and Compose does not merge the other, so every key that only the ignored file
# assigns falls back to compose.yaml's default. A `${VAR:?}` key fails loudly; a
# key with a `:-` default degrades in silence — SYNAPLAN_HTTP_BIND returns to
# 127.0.0.1 and a managed platform's HTTPS proxy can no longer reach the
# container, BOOTSTRAP_ADMIN_EMAIL and BOOTSTRAP_ADMIN_PASSWORD go empty and no
# administrator is ever created, provider API keys disappear. The instance is
# simply offline or half-configured, with nothing in the log to say why. It
# happens in both directions: a deploy/.env left behind on a managed platform
# hides the platform's own checkout-root file, and a checkout-root .env written
# for the DEVELOPMENT stack becomes the production configuration when no
# deploy/.env exists.
#
# CRLF LINE ENDINGS are a note, not a warning: Compose discards the trailing
# carriage return for every value shape, quoted ones included, and
# env_file_raw_value() does the same, so a Windows checkout is a valid
# configuration and must not fail here. It is still named, because
# core.autocrlf turns the shipped example into a CRLF file silently and an
# operator who does not know the carriage returns are there cannot reason about
# the file.
report_compose_env_file() {
    local deploy_dir="${1:-$DEPLOY_DIR}"
    local env_file candidate

    env_file="$(resolve_compose_env_file "$deploy_dir")"
    if [[ -z "$env_file" ]]; then
        echo "No deployment configuration file found; using the host environment only."
        return 0
    fi

    printf 'Reading deployment configuration from %s.\n' "$env_file"

    while IFS= read -r candidate; do
        [[ -f "$candidate" && "$candidate" != "$env_file" ]] || continue
        printf 'Warning: %s also exists and is IGNORED; Compose reads %s only and does not merge the two, so any key set solely in the ignored file falls back to its default.\n' \
            "$candidate" "$env_file" >&2
    done < <(compose_env_file_candidates "$deploy_dir")

    if env_file_has_crlf "$env_file"; then
        printf 'Note: %s uses Windows (CRLF) line endings. Compose discards the carriage returns, so this is supported; convert the file with dos2unix, or with your editor, if you prefer Unix line endings.\n' \
            "$env_file"
    fi
}

# Whether the configuration file uses Windows line endings. One CRLF line is
# enough: the question is which tool wrote the file, not how consistent it was.
env_file_has_crlf() {
    LC_ALL=C awk '/\r$/ { found = 1; exit } END { exit !found }' "$1"
}

compose() {
    local args=(docker compose -f "$DEPLOY_DIR/compose.yaml")
    if [[ -n "${SYNAPLAN_COMPOSE_OVERRIDE:-}" ]]; then
        args+=(-f "$SYNAPLAN_COMPOSE_OVERRIDE")
    fi
    local env_file
    env_file="$(resolve_compose_env_file)"
    if [[ -n "$env_file" ]]; then
        args+=(--env-file "$env_file")
    fi
    "${args[@]}" "$@"
}

resolved_app_image() {
    compose config |
        awk '
            /^  backend:$/ { in_backend = 1; next }
            in_backend && /^    image: / { print $2; found = 1; exit }
            in_backend && /^  [^ ]/ { exit 1 }
            END { if (!found) exit 1 }
        '
}

validate_release_pin() {
    local resolved_image
    resolved_image="$(resolved_app_image)"
    if [[ ! "$resolved_image" =~ :[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9][A-Za-z0-9.-]*)?$ ]]; then
        echo "SYNAPLAN_VERSION must resolve to a published immutable SemVer tag; got: $resolved_image" >&2
        return 1
    fi
}

# Read one interpolation variable from `compose config --environment`, which
# already merges the host environment over deploy/.env using Compose's own
# precedence — the same resolution the containers will see. The rendered
# environment is piped in, so no value ever appears in a process list.
#
# LC_ALL=C for the same reason env_file_raw_value() uses it: the two results are
# compared byte for byte, so both sides have to be read with byte semantics.
compose_environment_value() {
    printf '%s\n' "$1" | LC_ALL=C awk -v key="$2" '
        index($0, key "=") == 1 { value = substr($0, length(key) + 2) }
        END { print value }
    '
}

# Keys whose configured value must reach the containers byte for byte, ordered by
# the consequence of it not doing so:
#
#   BOOTSTRAP_ADMIN_EMAIL / BOOTSTRAP_ADMIN_PASSWORD  silent lockout. The first
#     start stores the hash of the altered password, or creates the administrator
#     under an altered address; the operator then authenticates with what the
#     configuration file says and there is no recovery path.
#   APP_SECRET / TOKEN_SECRET  silent loss of entropy. `ab$cd` becomes `ab`, and
#     a two-byte application secret signs every token without any symptom.
#   MARIADB_ROOT_PASSWORD / MARIADB_PASSWORD  used consistently on both sides
#     today, so an altered value still works — until it is typed by hand during a
#     recovery, or the file is replayed on a database that already exists.
#   REALTIME_*  internally consistent for the same reason, and equally weakened.
#   MAILER_DSN  carries SMTP credentials, and ` #` or `$` in a password is
#     realistic.
#   provider API keys  fail loudly at the first request, but the failure names
#     the provider rather than the configuration file.
#
# Deliberately not covered: APP_URL, FRONTEND_URL, REALTIME_ALLOWED_ORIGINS,
# MARIADB_DATABASE, MARIADB_USER, APP_SENDER_*, AI_DEFAULT_PROVIDER, LOG_FORMAT,
# PHP_MAX_FILE_UPLOADS, ENABLE_LOCAL_GPT_OSS and WHISPER_DEFAULT_MODEL. None is a
# secret, an altered value is visible in the running configuration, and it fails
# where it is used instead of silently succeeding. SYNAPLAN_VERSION and
# SYNAPLAN_IMAGE are decided by validate_release_pin, and the COMPOSE_* keys
# configure Compose itself.
SYNAPLAN_ROUNDTRIP_KEYS=(
    BOOTSTRAP_ADMIN_EMAIL
    BOOTSTRAP_ADMIN_PASSWORD
    APP_SECRET
    TOKEN_SECRET
    MARIADB_ROOT_PASSWORD
    MARIADB_PASSWORD
    REALTIME_API_KEY
    REALTIME_TOKEN_SECRET
    REALTIME_ADMIN_PASSWORD
    REALTIME_ADMIN_SECRET
    MAILER_DSN
    OPENAI_API_KEY
    ANTHROPIC_API_KEY
    GROQ_API_KEY
    GOOGLE_GEMINI_API_KEY
    MISTRAL_API_KEY
    XAI_API_KEY
)

# The value exactly as the FILE spells it: everything after the first "=", with
# no unquoting, no trimming, no comment stripping and no interpolation.
#
# Normalised away, and only this, is the spelling Compose itself discards before
# it ever looks at a value. Getting that set wrong breaks the guard in both
# directions: normalising too much hides a real alteration, and normalising too
# little either reports a file Compose reads perfectly (a blocked installation)
# or fails to recognise the assignment at all — which skips the key silently,
# the exact false negative this guard exists to prevent. Every form below was
# measured against Compose v5.3.1:
#
#   a UTF-8 BOM on the first line, which Compose strips before the first key;
#   a trailing carriage return, which Compose discards for every value shape,
#     quoted ones included, so a Windows checkout is a valid configuration;
#   a leading indent and an `export ` prefix;
#   whitespace between the key and the `=`, which Compose accepts
#     (`KEY = value` and `KEY<tab>=<tab>value` both assign).
#
# Whitespace AFTER the `=` is deliberately NOT normalised. Compose trims it, and
# reporting that trimming is the whole point — a password written as `  abc  `
# reaches the containers as `abc`. `KEY = value` therefore reports as altered
# too, because of the space the file puts in front of the value; that is the
# same finding, and the message says to remove whitespace around the `=`.
#
# The last assignment wins, which is Compose's rule too. Returns non-zero when
# the file does not assign the key at all.
#
# LC_ALL=C keeps every operation on bytes: the BOM is compared as the three
# bytes it is, and a value that is not valid UTF-8 survives the read unchanged.
# The BOM is built with sprintf() rather than written as an escape, because a
# `\xNN` escape is not portable between BSD awk, gawk and mawk. The key is
# interpolated into a regular expression, which is safe because every covered
# key is spelled with `[A-Z_]` only.
env_file_raw_value() {
    LC_ALL=C awk -v key="$2" '
        BEGIN { byte_order_mark = sprintf("%c%c%c", 239, 187, 191) }
        {
            line = $0
            if (NR == 1 && substr(line, 1, 3) == byte_order_mark) {
                line = substr(line, 4)
            }
            sub(/\r$/, "", line)
            sub(/^[[:space:]]*(export[[:space:]]+)?/, "", line)
        }
        match(line, "^" key "[[:space:]]*=") {
            value = substr(line, RLENGTH + 1)
            found = 1
        }
        END { if (!found) exit 1; print value }
    ' "$1"
}

# Whether the EXPORTED environment defines the key. Only exported variables reach
# Compose, and Compose prefers them over every --env-file entry, so a key defined
# here is not resolved from the file and its file entry must not be compared.
host_environment_defines() {
    env | awk -v key="$1" 'index($0, key "=") == 1 { found = 1 } END { exit !found }'
}

# Reject a configuration file whose secrets Compose does not read back unchanged.
#
# validate_bootstrap_admin_config resolves its values through
# `compose config --environment`, which returns them AFTER Compose's .env
# parsing. It therefore sees the already-altered value, approves it, and is
# structurally incapable of noticing the alteration. This check is the missing
# half: it compares what the file says with what Compose resolved.
#
# Compose's .env parser alters a value in five ways — an unescaped `$` starts an
# interpolation (`ab$cd` becomes `ab`), `$$` collapses to `$`, surrounding
# quotes are stripped, surrounding whitespace is trimmed, and ` #` starts a
# comment. A sixth form, `ab${cd`, is already a hard Compose error and fails
# `compose config` before this runs.
#
# One byte-for-byte comparison per key catches all of them, including whatever a
# future Compose release adds, without reimplementing the dotenv grammar here —
# a second parser is exactly the kind of divergence this check exists to catch.
#
# `$$` is reported as well, even though an operator may have written it as a
# deliberate escape: the two intentions ("a literal $$" and "an escaped $") are
# indistinguishable from the file, and guessing wrong on a password means the
# lockout above. Naming the key and letting the operator choose a value without
# `$`, or pass it as a host environment variable, is the recoverable outcome.
#
# No value is ever printed: these keys are secrets and a deployment log is not.
#
# The file is resolved here and cannot be passed in. Both halves of the
# comparison have to describe the SAME file, and only the internal `compose()`
# decides which one Compose actually reads; a caller-supplied path is free to
# name a different one, which would compare a file against an environment
# rendered from another and report either a phantom alteration or, worse,
# nothing at all. Nothing needs to compare a file Compose does not read.
validate_secret_roundtrip() {
    local environment key raw resolved env_file altered_keys=""

    # A host-environment-only deployment has no file, so Compose parses nothing
    # and there is nothing to compare.
    env_file="$(resolve_compose_env_file)"
    if [[ -z "$env_file" ]]; then
        return 0
    fi

    if ! environment="$(compose config --environment)"; then
        echo "Could not resolve the Compose interpolation environment (see the error above); check $env_file and make sure Docker Compose 2.28 or newer is installed." >&2
        return 1
    fi

    for key in "${SYNAPLAN_ROUNDTRIP_KEYS[@]}"; do
        host_environment_defines "$key" && continue
        raw="$(env_file_raw_value "$env_file" "$key")" || continue
        resolved="$(compose_environment_value "$environment" "$key")"
        if [[ "$raw" != "$resolved" ]]; then
            altered_keys="${altered_keys}${altered_keys:+ }$key"
        fi
    done

    if [[ -n "$altered_keys" ]]; then
        echo "Docker Compose's .env parsing does not read these values back unchanged from $env_file, so the containers would be configured with something other than what the file says: $altered_keys" >&2
        echo "Fix: write each listed value without surrounding quotes, without whitespace around the '=' or around the value, without an inline ' #' comment, and without a dollar sign; or pass it as a host environment variable, which Compose never rewrites." >&2
        echo "For a URL-shaped value such as MAILER_DSN, a dollar sign in a third-party password usually cannot simply be removed: percent-encode it inside the DSN instead, writing '\$' as '%24'." >&2
        echo "Generating the value with 'openssl rand -hex 32' always satisfies this. No value is printed here, because these keys are secrets." >&2
        return 1
    fi
}

# Reject an unusable first-admin configuration before any container starts.
#
# BootstrapAdminService stays the authoritative validator, but it only runs
# inside the backend container, and compose.yaml restarts that container
# `unless-stopped` — so a rejected value there crash-loops the deployment
# instead of reporting the operator error. This host-side preflight runs before
# `docker compose up -d` and fails the deployment once, with a fixable message.
#
# The preflight is deliberately two-tier, because a validator that is looser
# than the authority reintroduces exactly that crash loop:
#
#   Tier 1 — validate_bootstrap_admin_values(): pure shell, no dependencies,
#     unit-testable without Docker. It runs everywhere, including the update and
#     restore paths where starting a throwaway container is not appropriate, and
#     it is the only tier when no image is available. Its email rule mirrors
#     filter_var(FILTER_VALIDATE_EMAIL) for realistically configurable addresses
#     (see bootstrap_admin_email_is_valid) and must never reject an address the
#     authority accepts.
#   Tier 2 — validate_app_image_contract(): calls the authority itself,
#     App\Service\Admin\BootstrapAdminConfiguration, inside the resolved
#     application image, so neither the email nor the password rule can drift
#     from the PHP implementation. It needs that image, so it only runs where one
#     is already being probed: validate-release.sh.
validate_bootstrap_admin_config() {
    local environment
    if ! environment="$(compose config --environment)"; then
        echo "Could not resolve the Compose interpolation environment (see the error above); check deploy/.env and make sure Docker Compose 2.28 or newer is installed." >&2
        return 1
    fi
    validate_bootstrap_admin_values \
        "$(compose_environment_value "$environment" BOOTSTRAP_ADMIN_EMAIL)" \
        "$(compose_environment_value "$environment" BOOTSTRAP_ADMIN_PASSWORD)"
}

# Tier 1's email rule. Mirrors what PHP's
# filter_var($email, FILTER_VALIDATE_EMAIL) accepts, for the addresses an
# operator can realistically configure. A plain `local@domain` pattern is not
# enough: filter_var additionally rejects a leading, trailing or doubled dot in
# the local part, a domain label that starts or ends with a hyphen, a top-level
# label that does not start with a letter, a local part longer than 64 bytes and
# a label longer than 63 bytes. Accepting those here would let the deployment
# start and then crash-loop the backend.
#
# Exotic-but-valid forms that filter_var accepts are intentionally rejected:
# address literals (admin@[127.0.0.1]) and quoted local parts ("a b"@example.com).
# Neither is a usable administrator mailbox, and rejecting them costs a clear
# preflight error instead of a crash loop.
bootstrap_admin_email_is_valid() {
    # Byte semantics, matching PHP's strlen().
    local LC_ALL=C
    local email="$1"
    local maximum_local_part_length=64
    local maximum_label_length=63
    # Local part: dot-separated, non-empty atoms.
    local local_part_pattern='^[A-Za-z0-9!#$%&'\''*+/=?^_`{|}~-]+(\.[A-Za-z0-9!#$%&'\''*+/=?^_`{|}~-]+)*$'
    # Domain: at least two labels; a label may contain hyphens but not at either edge.
    local domain_pattern='^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$'
    local local_part domain label remainder

    [[ "$email" == *@* ]] || return 1
    # A second "@" stays in the local part, where the pattern rejects it.
    local_part="${email%@*}"
    domain="${email##*@}"

    (( ${#local_part} <= maximum_local_part_length )) || return 1
    [[ "$local_part" =~ $local_part_pattern ]] || return 1
    [[ "$domain" =~ $domain_pattern ]] || return 1

    remainder="$domain"
    while :; do
        label="${remainder%%.*}"
        (( ${#label} <= maximum_label_length )) || return 1
        [[ "$remainder" == *.* ]] || break
        remainder="${remainder#*.}"
    done

    # $label is now the top-level label. filter_var requires it to start with a
    # letter, so it rejects admin@example.123 and admin@1.2 while still
    # accepting digits anywhere else in the domain (admin@1.example.com).
    [[ "$label" =~ ^[A-Za-z] ]] || return 1
}

# Mirrors BootstrapAdminService::bootstrap()'s validation. Values are never
# echoed: the password is a secret and the deployment log is not.
validate_bootstrap_admin_values() {
    # Byte semantics, matching PHP's strlen().
    local LC_ALL=C
    local email="$1"
    local password="$2"
    local maximum_email_length=128
    local minimum_password_length=8
    local maximum_password_length=64
    local composition_waiver_length=16
    local present missing

    # BootstrapAdminService trims the email, and only the email, before deciding
    # whether the bootstrap is configured at all.
    email="${email#"${email%%[![:space:]]*}"}"
    email="${email%"${email##*[![:space:]]}"}"

    if [[ -z "$email" && -z "$password" ]]; then
        echo "No first administrator configured; the bootstrap will be skipped."
        return 0
    fi

    if [[ -z "$email" || -z "$password" ]]; then
        present=BOOTSTRAP_ADMIN_EMAIL
        missing=BOOTSTRAP_ADMIN_PASSWORD
        if [[ -z "$email" ]]; then
            present=BOOTSTRAP_ADMIN_PASSWORD
            missing=BOOTSTRAP_ADMIN_EMAIL
        fi
        echo "$present is set but $missing is empty; both must be set together or both left empty." >&2
        echo "Fix: set $missing, or clear $present to skip the first-admin bootstrap." >&2
        return 1
    fi

    if (( ${#email} > maximum_email_length )) || ! bootstrap_admin_email_is_valid "$email"; then
        echo "BOOTSTRAP_ADMIN_EMAIL must be a valid email address of at most $maximum_email_length characters; the configured value is ${#email} characters long." >&2
        echo "Fix: set BOOTSTRAP_ADMIN_EMAIL to the administrator's email address, for example admin@example.com." >&2
        return 1
    fi

    if (( ${#password} < minimum_password_length )); then
        echo "BOOTSTRAP_ADMIN_PASSWORD must be at least $minimum_password_length characters." >&2
        echo "Fix: set BOOTSTRAP_ADMIN_PASSWORD to a longer password." >&2
        return 1
    fi

    if (( ${#password} > maximum_password_length )); then
        echo "BOOTSTRAP_ADMIN_PASSWORD must be at most $maximum_password_length characters." >&2
        echo "Fix: set BOOTSTRAP_ADMIN_PASSWORD to a shorter password." >&2
        return 1
    fi

    # Following NIST SP 800-63B, composition rules only apply to short
    # passwords: managed platforms such as Elestio inject a high-entropy
    # generated password that can legitimately contain no digit.
    if (( ${#password} < composition_waiver_length )) &&
        { [[ ! "$password" =~ [a-z] ]] || [[ ! "$password" =~ [A-Z] ]] || [[ ! "$password" =~ [0-9] ]]; }; then
        echo "BOOTSTRAP_ADMIN_PASSWORD must contain at least one uppercase letter, one lowercase letter, and one number, or be at least $composition_waiver_length characters long." >&2
        echo "Fix: add the missing character types to BOOTSTRAP_ADMIN_PASSWORD, or use a password of at least $composition_waiver_length characters." >&2
        return 1
    fi
}

# The line the authority's program prints when it rejects a value. The host keys
# on this marker instead of on the exit status alone, so a probe command added
# later that happens to exit 3 can never be misread as a rejected configuration.
# Keep it in sync with the printf in bootstrap_admin_authority_command().
BOOTSTRAP_ADMIN_REJECTION_MARKER=SYNAPLAN_BOOTSTRAP_ADMIN_REJECTED

# The container-side command that asks the authority itself —
# App\Service\Admin\BootstrapAdminConfiguration, which the application image
# ships — for a verdict on the WHOLE first-admin configuration.
#
# No rule is reimplemented here: this is the same call, through the same
# SYNAPLAN_AUTOLOAD_PATH knob with the same default, that
# require_valid_bootstrap_admin_config makes in
# _docker/backend/lib/container-runtime.sh — a path relative to the application
# directory the image already works in. It is printed by a function so the
# contract test in deploy/scripts/tests can run this very command instead of a
# second copy that would be free to drift.
#
# Both values are read from the environment inside PHP and never passed as
# arguments, so BOOTSTRAP_ADMIN_PASSWORD never reaches a process list, and the
# only thing ever printed is the validator's own message — which by contract
# never contains a configured value.
#
# Exit status: 0 accepted (including "not configured at all"), 3 plus the marker
# above rejected; anything else means the program could not run.
bootstrap_admin_authority_command() {
    cat <<'COMMAND'
SYNAPLAN_AUTOLOAD_PATH="${SYNAPLAN_AUTOLOAD_PATH:-vendor/autoload.php}" php -r '
require getenv("SYNAPLAN_AUTOLOAD_PATH");

try {
    App\Service\Admin\BootstrapAdminConfiguration::fromConfiguration(
        (string) getenv("BOOTSTRAP_ADMIN_EMAIL"),
        (string) getenv("BOOTSTRAP_ADMIN_PASSWORD"),
    );
} catch (InvalidArgumentException $violation) {
    printf("SYNAPLAN_BOOTSTRAP_ADMIN_REJECTED %s\n", $violation->getMessage());
    exit(3);
}
'
COMMAND
}

# Tier 2 of the first-admin check, plus the image contract itself.
#
# Both checks share ONE throwaway container: starting a second one would add
# an image start to every managed deployment for no extra coverage. The
# credentials are read from the container's own environment (compose.yaml passes
# BOOTSTRAP_ADMIN_EMAIL and BOOTSTRAP_ADMIN_PASSWORD to the backend service), so
# they are resolved exactly as the real container resolves them, they never reach
# the host process list, and --rm leaves no container behind to inspect them in.
#
# The image already ships the authority, so the probe calls it and covers the
# email AND the password with it. Repeating a rule here would reintroduce the
# defect this tier exists to prevent: a copy that drifts from the PHP
# implementation, accepts a value the bootstrap later rejects, and crash-loops
# the deployment.
#
# Exit-code contract of the probe:
#   0        the image satisfies the contract and the authority accepted the
#            configuration;
#   3 + the rejection marker on stdout: the authority rejected the email or the
#            password, and its message says which;
#   64/65/66 an image contract check failed — one explicit code each, so no
#            other step of the probe can ever produce 3;
#   anything else, a missing image included: the contract is unverified.
#
# If the image cannot be started (for example it was never pulled, because
# `--pull never` keeps this probe offline), this returns non-zero and the
# caller fails: the image contract is unverified, so the deployment must not
# proceed. Validation is never silently skipped — tier 1 has already run by
# then, so a value that shell can decide on has still been rejected.
validate_app_image_contract() {
    local probe output violation status=0
    probe="$(
        cat <<'PROBE'
test -x /usr/local/bin/docker-entrypoint.sh || exit 64
test -x /usr/local/bin/container-healthcheck || exit 65
grep -q SYNAPLAN_ROLE /usr/local/bin/docker-entrypoint.sh || exit 66
PROBE
        bootstrap_admin_authority_command
    )"

    output="$(compose run --rm --no-deps --pull never --entrypoint sh backend -ec "$probe")" || status=$?
    violation="$(
        printf '%s\n' "$output" |
            awk -v marker="$BOOTSTRAP_ADMIN_REJECTION_MARKER" '
                index($0, marker " ") == 1 { print substr($0, length(marker) + 2) }
            '
    )"

    if (( status == 3 )) && [[ -n "$violation" ]]; then
        echo "The first-admin configuration was rejected by the application's own validator, which is stricter than the host-side check:" >&2
        printf '   %s\n' "$violation" >&2
        echo "Fix: correct BOOTSTRAP_ADMIN_EMAIL or BOOTSTRAP_ADMIN_PASSWORD in your deployment configuration, then run the deployment again." >&2
        echo "For the email, use a plain deliverable address, for example admin@example.com; avoid leading, trailing or repeated dots, hyphens at the edge of a domain label, and local parts longer than 64 characters." >&2
        return 1
    fi

    if (( status == 0 )) && [[ -z "$violation" ]]; then
        return 0
    fi

    # Either a probe step failed, or the probe did not behave as documented (the
    # marker without status 3, or status 3 without the marker). Both mean the
    # contract is unverified, so neither may pass as a decided verdict.
    if [[ -n "$output" ]]; then
        printf '%s\n' "$output" >&2
    fi
    echo "Could not verify the application image contract (see the error above); the image must be present locally, so run 'docker compose -f deploy/compose.yaml pull' first." >&2
    (( status != 0 )) || status=1
    return "$status"
}

# The secrets this deployment gives its own, independent value to.
#
# Why the deployment has to do this at all: a managed platform's password
# generator is ONE draw per deployment. Elestio substitutes its `random_password`
# placeholder into every variable that names it, so a real installation came up
# with APP_SECRET, TOKEN_SECRET, both MariaDB passwords and all four REALTIME_*
# values set to the same string — the password of the database was also the
# secret that signs every application token, and the Centrifugo admin password.
# One leaked value is then every credential the deployment has. Elestio offers no
# second generator (their own catalog templates, elestio-examples/glpi among
# them, carry the same defect), so the split has to happen on this side.
#
# BOOTSTRAP_ADMIN_PASSWORD is deliberately NOT managed here. The platform DISPLAYS
# that value as the login for the web UI shortcut, and the bootstrap never resets
# an existing administrator, so it has to stay exactly the value the platform
# generated — rotating it would leave the operator with a password that does not
# open their own instance.
SYNAPLAN_MANAGED_SECRET_KEYS=(
    APP_SECRET
    TOKEN_SECRET
    MARIADB_PASSWORD
    MARIADB_ROOT_PASSWORD
    REALTIME_API_KEY
    REALTIME_TOKEN_SECRET
    REALTIME_ADMIN_PASSWORD
    REALTIME_ADMIN_SECRET
)

# Give every managed secret its own value, once, and export all of them.
#
# The values are recorded in $DATA_DIR/secrets.env, which is the only place they
# CAN live: Elestio rewrites the deployment's .env from the pipeline's configured
# environment on every deploy, so a value written back into that file is gone on
# the next one. The bind-mounted data directory is the one part of the deployment
# that survives a redeploy — and it is what the platform backup captures, which is
# why that file is backup-critical: a database restored without it is a database
# nobody can open again.
#
# Resolution per key, in this order:
#
#   1. secrets.env, when it exists. It is authoritative and is never rewritten,
#      because it records what the running stack was BUILT with.
#   2. the value the deployment already resolves to — an exported host
#      environment variable, or the configuration file Compose reads. Adopted
#      verbatim, which is what guarantees that an installation which already runs
#      keeps exactly the credentials it has: a self-hosted deploy/.env, or a
#      platform deployment whose one shared value is already in use everywhere.
#   3. a fresh, independent draw, but only while the stack has never been
#      initialised and no secrets.env exists yet.
#   4. otherwise the deployment is refused, see the two messages below.
#
# Exporting is not cosmetic. Compose prefers a real host environment variable
# over every --env-file entry, so exporting is the only way these values reliably
# win however the platform delivered the originals — and the only way a generated
# value reaches a `compose` call at all, because it appears in no configuration
# file the platform would pass with --env-file.
ensure_deployment_secrets() {
    local secrets_file="$DATA_DIR/secrets.env"
    local env_file secrets_file_existed=false
    local index key value
    local values=() assignments=()
    local generated_count=0 adopted_count=0
    # Collected instead of reported one at a time: an operator who has to run the
    # deployment once per missing variable gives up on the third one. This is the
    # same reason validate_secret_roundtrip() names every altered key at once.
    local unresolved_keys="" unadoptable_keys=""

    # The data root has to exist before the file can be written, and this runs on
    # paths that have not called prepare_data_directories() yet. 0700 for the same
    # reason it applies there: everything below this directory is either data or,
    # now, credentials.
    mkdir -p "$DATA_DIR"
    chmod 0700 "$DATA_DIR"

    [[ -f "$secrets_file" ]] && secrets_file_existed=true
    env_file="$(resolve_compose_env_file)"

    for ((index = 0; index < ${#SYNAPLAN_MANAGED_SECRET_KEYS[@]}; index++)); do
        key="${SYNAPLAN_MANAGED_SECRET_KEYS[$index]}"
        value=""
        values[$index]=""
        assignments[$index]="$key="

        if [[ "$secrets_file_existed" == true ]]; then
            value="$(env_file_raw_value "$secrets_file" "$key")" || value=""
        fi

        # Compose's precedence, so the value adopted here is the value the
        # containers are running with: an exported host variable beats every
        # --env-file entry, and the file only decides what the environment does
        # not. An exported variable that is EMPTY is not a value — compose.yaml's
        # `${VAR:?}` rejects it exactly like an unset one — so the file still
        # decides in that case.
        if [[ -z "$value" ]]; then
            if host_environment_defines "$key" && [[ -n "${!key-}" ]]; then
                value="${!key-}"
            elif [[ -n "$env_file" ]]; then
                value="$(env_file_raw_value "$env_file" "$key")" || value=""
                if [[ -n "$value" ]] && ! deployment_secret_is_adoptable "$value"; then
                    unadoptable_keys="${unadoptable_keys}${unadoptable_keys:+ }$key"
                    continue
                fi
            fi
            [[ -z "$value" ]] || adopted_count=$((adopted_count + 1))
        fi

        if [[ -z "$value" ]]; then
            # Nothing may be generated for a key that cannot be recorded, and
            # nothing may be generated for a stack that already exists:
            #
            #   an EXISTING secrets.env is never rewritten, so a value generated
            #     here would be a different one on every single start — APP_SECRET
            #     alone would invalidate every session on each deploy;
            #   an INITIALISED stack keeps credentials that cannot be
            #     reconstructed from its data. MariaDB created its user with the
            #     old password, so a new MARIADB_PASSWORD locks the application
            #     out of its own database permanently.
            if [[ "$secrets_file_existed" == true ]] || deployment_stack_is_initialised; then
                unresolved_keys="${unresolved_keys}${unresolved_keys:+ }$key"
                continue
            fi
            value="$(generate_deployment_secret)" || return 1
            generated_count=$((generated_count + 1))
        fi

        values[$index]="$value"
        assignments[$index]="$key=$value"
    done

    if [[ -n "$unadoptable_keys" ]]; then
        echo "Docker Compose does not read these values back unchanged from $env_file, so they cannot be adopted as the deployment's secrets: $unadoptable_keys" >&2
        echo "This deployment exports the value it adopts, and Compose never rewrites a host environment variable — so adopting one of these as written would change the credential the containers have been using until now, and a changed MARIADB_PASSWORD locks the application out of its own database." >&2
        echo "Fix: write each listed value without surrounding quotes, without whitespace around the '=' or around the value, without an inline ' #' comment and without a dollar sign; or pass it as a host environment variable, which Compose never rewrites. 'openssl rand -hex 32' always satisfies this. No value is printed here, because these keys are secrets." >&2
        return 1
    fi

    if [[ -n "$unresolved_keys" ]]; then
        if [[ "$secrets_file_existed" == true ]]; then
            echo "$secrets_file exists but does not assign these secrets, and neither the host environment nor the deployment configuration provides them: $unresolved_keys" >&2
            echo "That file is authoritative and is never rewritten, because it records the values this stack was built with; generating new ones here would produce a different set on every start." >&2
            echo "Fix: add a '<VARIABLE>=<the value this deployment was installed with>' line per listed secret to $secrets_file, or set them in the platform's environment editor, then run the deployment again." >&2
        else
            echo "These secrets have no value, and this deployment is already initialised — $DATA_DIR/mariadb holds a database that was created with the previous ones: $unresolved_keys" >&2
            echo "Refusing to generate replacements: a generated credential cannot match an initialised stack. A new MARIADB_PASSWORD locks the application out of its own database permanently, because the MariaDB user still authenticates with the old one, and no secret can be reconstructed from the stored data." >&2
            echo "Fix: restore the listed variables in the platform's environment editor or in the deployment configuration file, or write their known values into $secrets_file as '<VARIABLE>=<value>' lines, then run the deployment again." >&2
        fi
        return 1
    fi

    if [[ "$secrets_file_existed" == false ]]; then
        write_deployment_secrets_file "$secrets_file" "${assignments[@]}" || return 1
        printf 'Recorded %s deployment secrets in %s (%s generated independently, %s adopted from the existing configuration). This file is secret material and belongs in every backup.\n' \
            "${#SYNAPLAN_MANAGED_SECRET_KEYS[@]}" "$secrets_file" "$generated_count" "$adopted_count"
    else
        printf 'Using the %s deployment secrets recorded in %s.\n' \
            "${#SYNAPLAN_MANAGED_SECRET_KEYS[@]}" "$secrets_file"
    fi

    for ((index = 0; index < ${#SYNAPLAN_MANAGED_SECRET_KEYS[@]}; index++)); do
        export "${SYNAPLAN_MANAGED_SECRET_KEYS[$index]}=${values[$index]}"
    done
}

# Whether the stack has already been initialised, which decides whether a missing
# credential may be generated or has to be refused.
#
# The mere EXISTENCE of data/mariadb is not the signal: prepare_data_directories()
# creates that directory empty on the very first start, and every lifecycle script
# calls it, so an existence check would report a brand-new deployment as
# initialised and refuse it with advice about a credential that never existed.
# MariaDB populating the directory is what makes the old password unrecoverable.
deployment_stack_is_initialised() {
    [[ -d "$DATA_DIR/mariadb" ]] || return 1
    find "$DATA_DIR/mariadb" -mindepth 1 -print -quit 2>/dev/null | grep -q .
}

# Whether Compose's .env parser returns this raw spelling unchanged, and only
# relevant for a value read from the configuration FILE.
#
# The adopted value is EXPORTED afterwards, and Compose never rewrites a host
# environment variable — so adopting a spelling Compose alters would change the
# credential the containers have used until now: `MARIADB_PASSWORD="abc"` reaches
# the database as `abc` today and would reach it as `"abc"` from here on, which is
# the single outcome the adoption rule exists to prevent.
#
# validate_secret_roundtrip() rejects exactly these spellings, for these keys,
# with the same advice — but it resolves its comparison through
# `compose config --environment`, which cannot run before these variables have a
# value at all (compose.yaml declares them as `${VAR:?}`). So the alterations that
# parser performs are named here instead, and a value carrying one is refused
# rather than adopted: an unescaped `$` starts an interpolation, `$$` collapses to
# `$`, surrounding quotes are stripped (with backslash escapes inside them
# expanded), surrounding whitespace is trimmed, and ` #` starts a comment.
# Anything else is returned byte for byte, which is why every value is also read
# back from the file after it is written.
deployment_secret_is_adoptable() {
    local value="$1"

    [[ "$value" != *'$'* ]] || return 1
    [[ "$value" != *' #'* && "$value" != *$'\t#'* ]] || return 1
    [[ "$value" == "${value#[[:space:]]}" && "$value" == "${value%[[:space:]]}" ]] || return 1
    case "$value" in
        '"'*'"' | "'"*"'") return 1 ;;
    esac
}

# 32 bytes of randomness rendered as 64 hexadecimal characters.
#
# Hexadecimal only, deliberately: Compose interpolation, a .env parser and the
# container shell that expands MARIADB_ROOT_PASSWORD inside the db healthcheck
# each rewrite or split a value containing `$`, quotes, whitespace or ` #`.
# validate_secret_roundtrip() guards that class of bug for a CONFIGURED value; a
# generated one must not be able to cause it in the first place.
#
# openssl is present in every image this deployment runs, but not guaranteed on a
# minimal host, so /dev/urandom is read directly as a fallback. Either way the
# result is length-checked: a short read would silently weaken every secret it
# produced, and nothing downstream would notice.
generate_deployment_secret() {
    local value=""

    if command -v openssl >/dev/null 2>&1; then
        value="$(openssl rand -hex 32)"
    else
        value="$(LC_ALL=C od -vAn -N 32 -tx1 < /dev/urandom | LC_ALL=C tr -d ' \n')"
    fi

    [[ "$value" =~ ^[0-9a-f]{64}$ ]] || {
        echo "Could not generate a 32-byte random secret; install openssl, or make /dev/urandom readable for this deployment." >&2
        return 1
    }
    printf '%s' "$value"
}

# Create the secrets file, as a whole set, verified — and only ever create it.
#
# umask 077 rather than a chmod after the fact: between creating a world-readable
# file and tightening it there is a window in which every credential of the
# deployment is readable by any local account. The previous umask is restored,
# because this library is sourced by scripts that write other files afterwards.
#
# Every value is read back with env_file_raw_value(), the same reader
# ensure_deployment_secrets() and validate_secret_roundtrip() use, and compared
# byte for byte. A value adopted from the host environment can contain anything —
# a newline, a carriage return — and this file format cannot represent all of it.
# Refusing to install a file that does not read back is what keeps "adopted
# verbatim" true; the alternative is a deployment configured with a silently
# truncated credential.
#
# The write goes to a temporary file next to the target and is moved into place,
# so an interrupted or rejected run never leaves a half-written authoritative
# file behind. Values are passed as function arguments, which stay inside this
# shell: no external command receives them, so none appears in a process list.
write_deployment_secrets_file() {
    local secrets_file="$1"
    shift
    local assignment key value temporary_file previous_umask status=0

    previous_umask="$(umask)"
    umask 077
    temporary_file="$(mktemp "$(dirname "$secrets_file")/.secrets.env.XXXXXX")" || status=$?
    if ((status == 0)); then
        {
            printf '# Synaplan deployment secrets, generated once on the first start.\n'
            printf '#\n'
            printf '# Each value is an independent random draw, or the value this deployment was\n'
            printf '# already configured with. Together they are the credentials of the database,\n'
            printf '# of the realtime service and of the application token signing, so this file is\n'
            printf '# secret material:\n'
            printf '#\n'
            printf '#   - it MUST be part of every backup. A database restored without it cannot be\n'
            printf '#     opened again, because the MariaDB user still expects the password recorded\n'
            printf '#     here and it exists nowhere else.\n'
            printf '#   - it must never be committed, and never be readable by anyone else.\n'
            printf '#   - a value in use must not be edited. The deployment reads this file as\n'
            printf '#     authoritative and never rewrites it.\n'
            printf '\n'
            for assignment in "$@"; do
                printf '%s\n' "$assignment"
            done
        } > "$temporary_file" || status=$?
    fi
    umask "$previous_umask"
    ((status == 0)) || {
        echo "Could not write the deployment secrets next to $secrets_file; check that the deployment data directory is writable." >&2
        return 1
    }

    for assignment in "$@"; do
        key="${assignment%%=*}"
        value="${assignment#*=}"
        [[ "$(env_file_raw_value "$temporary_file" "$key")" == "$value" ]] || {
            rm -f "$temporary_file"
            echo "The value configured for $key cannot be recorded in $secrets_file unchanged, so this deployment refuses to store an altered credential for it." >&2
            echo "Fix: give $key a value without line breaks or carriage returns — 'openssl rand -hex 32' always works — or clear it and let the deployment generate one. No value is printed here, because this key is a secret." >&2
            return 1
        }
    done

    chmod 0600 "$temporary_file"
    mv "$temporary_file" "$secrets_file"
}

prepare_data_directories() {
    mkdir -p \
        "$STATE_DIR" \
        "$BACKUP_DIR" \
        "$DATA_DIR/mariadb" \
        "$DATA_DIR/redis" \
        "$DATA_DIR/qdrant" \
        "$DATA_DIR/uploads" \
        "$DATA_DIR/ollama" \
        "$DATA_DIR/whisper"
    # 0700 on the data root as well, not only on the two directories that hold
    # dumps and lifecycle state: below it sit the raw MariaDB files, the Redis
    # append-only file and every uploaded document. post-restore.sh already
    # tightens it, so a restored instance was stricter than a freshly installed
    # one — the same deployment, two different permissions.
    chmod 0700 "$DATA_DIR" "$STATE_DIR" "$BACKUP_DIR"
}

running_service() {
    local service="$1"
    compose ps --services --filter status=running | grep -Fxq "$service"
}

begin_service_pause() {
    prepare_data_directories
    : > "$PAUSED_SERVICES_FILE"
    chmod 0600 "$PAUSED_SERVICES_FILE"
}

pause_services() {
    prepare_data_directories
    touch "$PAUSED_SERVICES_FILE"
    chmod 0600 "$PAUSED_SERVICES_FILE"

    local service
    for service in "$@"; do
        if running_service "$service" && ! grep -Fxq "$service" "$PAUSED_SERVICES_FILE"; then
            printf '%s\n' "$service" >> "$PAUSED_SERVICES_FILE"
        fi
    done

    for service in "$@"; do
        if grep -Fxq "$service" "$PAUSED_SERVICES_FILE" && running_service "$service"; then
            compose stop --timeout 60 "$service"
        fi
    done
}

service_was_paused() {
    local service="$1"
    [[ -f "$PAUSED_SERVICES_FILE" ]] && grep -Fxq "$service" "$PAUSED_SERVICES_FILE"
}

resume_paused_services() {
    [[ -f "$PAUSED_SERVICES_FILE" ]] || return 0

    local services=()
    local service
    while IFS= read -r service; do
        [[ -n "$service" ]] && services+=("$service")
    done < "$PAUSED_SERVICES_FILE"

    if ((${#services[@]} > 0)); then
        compose start "${services[@]}"
    fi
    rm -f "$PAUSED_SERVICES_FILE"
}

sha256_file() {
    local file="$1"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$file" | awk '{print $1}'
    else
        shasum -a 256 "$file" | awk '{print $1}'
    fi
}

verify_sha256_file() {
    local file="$1"
    local checksum_file="$2"
    local expected
    expected="$(awk 'NR == 1 {print $1}' "$checksum_file")"
    [[ "$expected" =~ ^[a-fA-F0-9]{64}$ ]] || {
        echo "Invalid SHA-256 checksum in $checksum_file" >&2
        return 1
    }
    [[ "$(sha256_file "$file")" == "$expected" ]] || {
        echo "SHA-256 verification failed for $file" >&2
        return 1
    }
}

app_tool() {
    compose run --rm --no-deps --pull never --entrypoint bash backend -ec "$1"
}

wait_for_service_health() {
    local service="$1"
    local timeout="${2:-300}"
    local started
    started="$(date +%s)"

    while true; do
        local container_id status
        container_id="$(compose ps -q "$service")"
        if [[ -n "$container_id" ]]; then
            status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id")"
            if [[ "$status" == "healthy" || "$status" == "running" ]]; then
                return 0
            fi
            if [[ "$status" == "unhealthy" || "$status" == "exited" || "$status" == "dead" ]]; then
                echo "$service entered state: $status" >&2
                compose logs --tail 80 "$service" >&2 || true
                return 1
            fi
        fi

        if (( $(date +%s) - started >= timeout )); then
            echo "Timed out waiting for $service health" >&2
            compose logs --tail 80 "$service" >&2 || true
            return 1
        fi
        sleep 5
    done
}

latest_backup_path() {
    local link="$BACKUP_DIR/latest"
    local backup_root resolved
    [[ -L "$link" ]] || {
        echo "No completed backup is available at $link" >&2
        return 1
    }
    backup_root="$(cd "$BACKUP_DIR" && pwd -P)"
    resolved="$(cd "$backup_root" && cd "$(readlink latest)" && pwd -P)"
    [[ "$resolved" == "$backup_root/"* ]] || {
        echo "Latest backup symlink escapes $backup_root" >&2
        return 1
    }
    printf '%s\n' "$resolved"
}
