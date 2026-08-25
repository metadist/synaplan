#!/usr/bin/env bash
#
# Synaplan one-line installer.
#
#   curl -fsSL https://raw.githubusercontent.com/metadist/synaplan/main/install.sh | bash
#
# Two modes:
#   try    (default) - local try-out: clones the repo and starts the Docker dev
#                      stack. Open http://localhost:5173 and a live status page
#                      walks you through the first start.
#   server           - production self-hosting on a Linux box using the
#                      deploy/ contract (published images, generated secrets,
#                      backup/restore lifecycle). A reverse proxy for HTTPS in
#                      front of 127.0.0.1:8000 remains your job.
#
# Flags (all optional; without a terminal the defaults apply):
#   --mode try|server      installation mode                  [try]
#   --dir PATH             install directory                  [./synaplan]
#   --minimal              try mode: cloud-AI-only dev stack (smaller download)
#   --domain URL           server mode: public URL, e.g. https://ai.example.com
#   --admin-email ADDR     server mode: first administrator login
#   --admin-password PASS  server mode: its password (omit to auto-generate)
#   --version X.Y.Z        server mode: release pin            [latest release]
#   --branch NAME          git branch to clone                 [main]
#   --yes                  never prompt, accept all defaults
#
# The script is idempotent where it matters: it refuses to overwrite an
# existing deploy/.env and re-uses an existing checkout.
set -euo pipefail

REPO_URL="https://github.com/metadist/synaplan.git"
RELEASES_API="https://api.github.com/repos/metadist/synaplan/releases/latest"

MODE="try"
INSTALL_DIR=""
MINIMAL="false"
DOMAIN=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
PIN_VERSION=""
BRANCH="main"
ASSUME_YES="false"

say()  { printf '%s\n' "$*"; }
step() { printf '\n==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# When piped through `curl | bash`, stdin is the script itself. Prompts must
# read from the terminal, if there is one; otherwise defaults apply silently.
have_tty() { [ "$ASSUME_YES" != "true" ] && [ -r /dev/tty ]; }
ask() {
    # $1 prompt  $2 default -> echoes the answer
    local answer=""
    if have_tty; then
        printf '%s ' "$1" > /dev/tty
        read -r answer < /dev/tty || true
    fi
    printf '%s' "${answer:-$2}"
}

need_arg() {
    # $1 flag  $2 next token — refuse a missing value or another flag.
    case "${2:-}" in
        ''|-*) die "$1 requires a value (see --help)" ;;
    esac
}

while [ $# -gt 0 ]; do
    case "$1" in
        --mode)           need_arg "$1" "${2:-}"; MODE="$2"; shift 2 ;;
        --dir)            need_arg "$1" "${2:-}"; INSTALL_DIR="$2"; shift 2 ;;
        --minimal)        MINIMAL="true"; shift ;;
        --domain)         need_arg "$1" "${2:-}"; DOMAIN="$2"; shift 2 ;;
        --admin-email)    need_arg "$1" "${2:-}"; ADMIN_EMAIL="$2"; shift 2 ;;
        --admin-password) need_arg "$1" "${2:-}"; ADMIN_PASSWORD="$2"; shift 2 ;;
        --version)        need_arg "$1" "${2:-}"; PIN_VERSION="$2"; shift 2 ;;
        --branch)         need_arg "$1" "${2:-}"; BRANCH="$2"; shift 2 ;;
        --yes|-y)         ASSUME_YES="true"; shift ;;
        -h|--help)        sed -n '2,30p' "$0" 2>/dev/null || true; exit 0 ;;
        *)                die "Unknown option: $1 (see --help)" ;;
    esac
done

case "$MODE" in try|server) ;; *) die "--mode must be 'try' or 'server'" ;; esac
INSTALL_DIR="${INSTALL_DIR:-$PWD/synaplan}"

say ""
say "  Synaplan installer"
say "  =================="
say ""

# ---------------------------------------------------------------------------
# Preflight checks - fail early with a fixable message, not later mid-install.
# ---------------------------------------------------------------------------
step "Checking prerequisites"

command -v docker >/dev/null 2>&1 \
    || die "Docker is not installed. Get it from https://docs.docker.com/engine/install/ and re-run this script."
docker info >/dev/null 2>&1 \
    || die "Docker is installed but the daemon is not reachable. Start Docker (or add your user to the 'docker' group) and re-run."
docker compose version >/dev/null 2>&1 \
    || die "Docker Compose v2 is missing ('docker compose' does not work). Install the compose plugin and re-run."

if command -v git >/dev/null 2>&1; then
    FETCH_TOOL="git"
elif command -v curl >/dev/null 2>&1 && command -v tar >/dev/null 2>&1; then
    FETCH_TOOL="tarball"
else
    die "Neither git nor curl+tar found - install git and re-run."
fi
# Server mode looks up the latest GitHub release with curl even when git
# cloned the repo, so require it up front instead of failing mid-run.
if [ "$MODE" = "server" ] && ! command -v curl >/dev/null 2>&1; then
    die "Server mode needs curl (GitHub release lookup). Install curl and re-run."
fi
say "Docker, Compose v2 and ${FETCH_TOOL} are available."

if [ "$MODE" = "try" ]; then
    for port in 5173 8000; do
        if command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | grep -q ":${port} "; then
            warn "Port ${port} is already in use - the dev stack needs it. Stop the other service or expect a failure."
        fi
    done
fi

# ---------------------------------------------------------------------------
# Fetch the repository.
# ---------------------------------------------------------------------------
step "Fetching Synaplan into ${INSTALL_DIR}"

if [ -d "${INSTALL_DIR}/.git" ]; then
    say "Existing checkout found - leaving your working tree untouched."
elif [ -e "$INSTALL_DIR" ] && [ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]; then
    die "${INSTALL_DIR} exists and is not a Synaplan checkout. Choose another --dir."
elif [ "$FETCH_TOOL" = "git" ]; then
    git clone --branch "$BRANCH" --depth 1 "$REPO_URL" "$INSTALL_DIR"
else
    mkdir -p "$INSTALL_DIR"
    curl -fsSL "https://github.com/metadist/synaplan/archive/refs/heads/${BRANCH}.tar.gz" \
        | tar -xz --strip-components=1 -C "$INSTALL_DIR"
fi
cd "$INSTALL_DIR"

# ---------------------------------------------------------------------------
# Mode: try - the local development / evaluation stack.
# ---------------------------------------------------------------------------
if [ "$MODE" = "try" ]; then
    COMPOSE_FILE="docker-compose.yml"
    if [ "$MINIMAL" = "true" ]; then
        COMPOSE_FILE="docker-compose-minimal.yml"
        step "Starting the minimal stack (cloud AI only, ~5 GB)"
    else
        step "Starting the standard stack (~9 GB on first run)"
    fi

    docker compose -f "$COMPOSE_FILE" up -d

    say ""
    say "============================================================"
    say " Synaplan is starting."
    say ""
    say " OPEN  http://localhost:5173  NOW - a live status screen"
    say " shows every boot step and switches to the app when ready."
    say " The first start takes 5-15 minutes; later starts seconds."
    say ""
    say " Login:  admin@synaplan.com / admin123"
    say " Chat needs one AI provider key - the app guides you"
    say " through it after login (free key: console.groq.com)."
    say "============================================================"
    exit 0
fi

# ---------------------------------------------------------------------------
# Mode: server - production contract in deploy/.
#
# The heavy lifting (secret generation, release validation, health checks)
# already lives in deploy/scripts/. This section only prepares deploy/.env -
# the exact step people skip when they don't read the docs - and then runs
# the documented lifecycle in order.
# ---------------------------------------------------------------------------
ENV_FILE="deploy/.env"
[ -f deploy/selfhost.env.example ] || die "deploy/selfhost.env.example missing - checkout incomplete?"

if [ -f "$ENV_FILE" ]; then
    say "deploy/.env already exists - keeping it (delete it to reconfigure)."
else
    step "Creating ${ENV_FILE}"

    DOMAIN="${DOMAIN:-$(ask 'Public URL of this install (e.g. https://ai.example.com):' '')}"
    [ -n "$DOMAIN" ] || die "A public URL is required in server mode (use --domain https://...)."
    case "$DOMAIN" in
        https://*) ;;
        http://*)  warn "Plain http URL configured - use https in front of real users." ;;
        *)         die "The URL must start with https:// (got: ${DOMAIN})" ;;
    esac

    ADMIN_EMAIL="${ADMIN_EMAIL:-$(ask 'Administrator email [admin@'"${DOMAIN#*://}"']:' "admin@${DOMAIN#*://}")}"

    GENERATED_PASSWORD="false"
    if [ -z "$ADMIN_PASSWORD" ]; then
        ADMIN_PASSWORD="$(ask 'Administrator password (leave empty to generate one):' '')"
        if [ -z "$ADMIN_PASSWORD" ]; then
            ADMIN_PASSWORD="$(openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
            GENERATED_PASSWORD="true"
        fi
    fi
    # Bootstrap rules (validated again by validate-release.sh): 8-64 chars;
    # below 16 also an uppercase letter, a lowercase letter and a number.
    pwlen=${#ADMIN_PASSWORD}
    if [ "$pwlen" -lt 8 ] || [ "$pwlen" -gt 64 ]; then
        die "Administrator password must be 8-64 characters."
    fi
    if [ "$pwlen" -lt 16 ]; then
        case "$ADMIN_PASSWORD" in *[A-Z]*) ;; *) die "Passwords under 16 characters need an uppercase letter." ;; esac
        case "$ADMIN_PASSWORD" in *[a-z]*) ;; *) die "Passwords under 16 characters need a lowercase letter." ;; esac
        case "$ADMIN_PASSWORD" in *[0-9]*) ;; *) die "Passwords under 16 characters need a number." ;; esac
    fi

    if [ -z "$PIN_VERSION" ]; then
        # Latest published release; fall back to the example's tested pin.
        PIN_VERSION="$(curl -fsSL "$RELEASES_API" 2>/dev/null \
            | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"v\{0,1\}\([^"]*\)".*/\1/p' | head -1 || true)"
    fi

    # Build deploy/.env from the example:
    #  - blank the eight secret placeholders so the lifecycle scripts generate
    #    real, independent values into deploy/data/secrets.env (their design),
    #  - set URL, version pin, and the first administrator.
    sed -e 's|^APP_SECRET=.*|APP_SECRET=|' \
        -e 's|^TOKEN_SECRET=.*|TOKEN_SECRET=|' \
        -e 's|^MARIADB_PASSWORD=.*|MARIADB_PASSWORD=|' \
        -e 's|^MARIADB_ROOT_PASSWORD=.*|MARIADB_ROOT_PASSWORD=|' \
        -e 's|^REALTIME_API_KEY=.*|REALTIME_API_KEY=|' \
        -e 's|^REALTIME_TOKEN_SECRET=.*|REALTIME_TOKEN_SECRET=|' \
        -e 's|^REALTIME_ADMIN_PASSWORD=.*|REALTIME_ADMIN_PASSWORD=|' \
        -e 's|^REALTIME_ADMIN_SECRET=.*|REALTIME_ADMIN_SECRET=|' \
        -e "s|^APP_URL=.*|APP_URL=${DOMAIN}|" \
        -e "s|^FRONTEND_URL=.*|FRONTEND_URL=${DOMAIN}|" \
        -e "s|^BOOTSTRAP_ADMIN_EMAIL=.*|BOOTSTRAP_ADMIN_EMAIL=${ADMIN_EMAIL}|" \
        -e "s|^BOOTSTRAP_ADMIN_PASSWORD=.*|BOOTSTRAP_ADMIN_PASSWORD=${ADMIN_PASSWORD}|" \
        deploy/selfhost.env.example > "$ENV_FILE"
    chmod 600 "$ENV_FILE"

    if [ -n "$PIN_VERSION" ]; then
        sed -i.bak "s|^SYNAPLAN_VERSION=.*|SYNAPLAN_VERSION=${PIN_VERSION}|" "$ENV_FILE" && rm -f "${ENV_FILE}.bak"
    fi
    if [ "$GENERATED_PASSWORD" = "true" ]; then
        # A machine-generated credential must be rotated on first login.
        printf '\nBOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE=true\n' >> "$ENV_FILE"
    fi
    say "Wrote ${ENV_FILE} (mode 600). Remaining secrets are generated on first start into deploy/data/secrets.env."
fi

step "Running the deployment lifecycle (prepare, pull, validate, start, smoke-test)"
deploy/scripts/prepare.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml pull
deploy/scripts/validate-release.sh
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
deploy/scripts/smoke-test.sh

say ""
say "============================================================"
say " Synaplan is installed."
say ""
say " App URL:   $(grep '^APP_URL=' "$ENV_FILE" | cut -d= -f2-)"
say " Local:     http://127.0.0.1:8000 (put your HTTPS proxy in front)"
say " Admin:     $(grep '^BOOTSTRAP_ADMIN_EMAIL=' "$ENV_FILE" | cut -d= -f2-)"
if grep -q '^BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE=true' "$ENV_FILE" 2>/dev/null; then
    say " Password:  $(grep '^BOOTSTRAP_ADMIN_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
    say "            (generated - you must change it on first login)"
fi
say ""
say " BACK UP deploy/data/ INCLUDING deploy/data/secrets.env -"
say " a database restored without that file cannot be opened."
say " Docs: https://docs.synaplan.com - deploy/README.md"
say "============================================================"
