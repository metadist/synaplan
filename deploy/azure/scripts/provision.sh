#!/usr/bin/env bash
#
# Packer provisioner. Turns a stock Ubuntu 24.04 LTS Azure image into the
# Synaplan marketplace image: container runtime, TLS terminator, application
# tree, pre-pulled container images, and the systemd units that bring the stack
# up on every boot.
#
# Nothing here writes configuration or secrets — the image is deployed many
# times and must be identical for every customer. See scripts/firstboot.sh for
# the per-instance part.

set -Eeuo pipefail

: "${SYNAPLAN_VERSION:?SYNAPLAN_VERSION must be passed by the Packer build}"

APP_DIR=/opt/synaplan
DATA_MOUNT=/var/lib/synaplan
STAGE_DIR=/tmp/synaplan

export DEBIAN_FRONTEND=noninteractive

# The same pinned, checksum-verified plugin the AWS image installs. Ubuntu's
# Docker repository ships a Compose plugin of its own, but pinning it here keeps
# both marketplace images on the exact same Compose version — the portable
# scripts in deploy/scripts/ are written against one behaviour, and a first boot
# is not the place to discover a difference.
DOCKER_COMPOSE_VERSION=v5.4.0
DOCKER_COMPOSE_SHA256_x86_64=837fd1d35bf6a494f41b5b5988269a7be79de337cf1a1a6ff0e45ab51bb4e9be
DOCKER_COMPOSE_SHA256_aarch64=fc5d1371f1ec7987e703da94ede49af3fbfb240b83f22991a98511de7bc4b93b

log() { printf '==> %s\n' "$*"; }

# shellcheck disable=SC1091
codename="$(. /etc/os-release && printf '%s' "$VERSION_CODENAME")"
architecture="$(dpkg --print-architecture)"

# Azure's Ubuntu images boot with cloud-init and unattended-upgrades already
# holding the dpkg lock, and Packer connects long before either finishes. Every
# apt call below would otherwise fail on a race that has nothing to do with the
# build.
wait_for_apt() {
    local deadline=$((SECONDS + 300))
    while fuser /var/lib/dpkg/lock-frontend /var/lib/apt/lists/lock >/dev/null 2>&1; do
        ((SECONDS < deadline)) || {
            printf 'Another package manager still holds the dpkg lock after five minutes\n' >&2
            return 1
        }
        sleep 5
    done
}

apt_install() {
    wait_for_apt
    apt-get install -y --no-install-recommends "$@"
}

log "Applying pending security updates"
# Stopped rather than waited out: it would otherwise take the lock again between
# every step below. The deployed instance re-enables it on its first boot,
# because the package itself is untouched.
systemctl stop unattended-upgrades.service 2>/dev/null || true
wait_for_apt
apt-get update
wait_for_apt
apt-get upgrade -y

log "Installing base packages"
apt_install \
    ca-certificates \
    curl \
    gnupg \
    jq \
    rsync \
    tar \
    gzip \
    xfsprogs \
    openssl

install -d -m 0755 /etc/apt/keyrings

log "Installing Docker from the vendor package repository"
# Ubuntu's own docker.io package trails the upstream release by a long way. The
# vendor repository also means the instance keeps receiving Docker security
# updates through apt like everything else.
curl --fail --silent --show-error --location \
    https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/docker.list <<REPO
deb [arch=$architecture signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $codename stable
REPO
wait_for_apt
apt-get update
apt_install docker-ce docker-ce-cli containerd.io

log "Installing the Docker Compose v2 CLI plugin ($DOCKER_COMPOSE_VERSION)"
install_compose_plugin() {
    local arch expected url plugin_dir tmp
    arch="$(uname -m)"
    case "$arch" in
        x86_64) expected="$DOCKER_COMPOSE_SHA256_x86_64" ;;
        aarch64) expected="$DOCKER_COMPOSE_SHA256_aarch64" ;;
        *)
            printf 'Unsupported architecture for the Compose plugin: %s\n' "$arch" >&2
            return 1
            ;;
    esac

    url="https://github.com/docker/compose/releases/download/${DOCKER_COMPOSE_VERSION}/docker-compose-linux-${arch}"
    plugin_dir=/usr/local/lib/docker/cli-plugins
    tmp="$(mktemp)"

    curl --fail --silent --show-error --location --retry 5 --retry-delay 3 -o "$tmp" "$url"
    printf '%s  %s\n' "$expected" "$tmp" | sha256sum --check --status || {
        printf 'Checksum mismatch for the Compose plugin — refusing to bake an unverified binary\n' >&2
        rm -f "$tmp"
        return 1
    }

    install -d -m 0755 "$plugin_dir"
    install -m 0755 "$tmp" "$plugin_dir/docker-compose"
    rm -f "$tmp"

    # The binary just installed, invoked directly: this proves the download runs
    # on this architecture, without going near a Compose project.
    "$plugin_dir/docker-compose" version
}
install_compose_plugin

log "Installing Caddy from the project's own package repository"
# Caddy terminates TLS on the host. Installed from the vendor repository rather
# than as a loose binary so the instance keeps receiving security updates
# through apt like everything else.
curl --fail --silent --show-error --location \
    https://dl.cloudsmith.io/public/caddy/stable/gpg.key |
    gpg --dearmor --yes -o /etc/apt/keyrings/caddy-stable.gpg
cat > /etc/apt/sources.list.d/caddy-stable.list <<REPO
deb [arch=$architecture signed-by=/etc/apt/keyrings/caddy-stable.gpg] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main
REPO
wait_for_apt
apt-get update
apt_install caddy

log "Installing the Azure CLI"
# Used by snapshot.sh to start an on-demand backup. firstboot.sh deliberately
# does NOT use it: it talks to the instance metadata service and the Key Vault
# REST API directly, so a first boot cannot fail on a CLI that is slow to start
# or missing a credential.
curl --fail --silent --show-error --location \
    https://packages.microsoft.com/keys/microsoft.asc |
    gpg --dearmor --yes -o /etc/apt/keyrings/microsoft.gpg
cat > /etc/apt/sources.list.d/azure-cli.list <<REPO
deb [arch=$architecture signed-by=/etc/apt/keyrings/microsoft.gpg] https://packages.microsoft.com/repos/azure-cli/ $codename main
REPO
wait_for_apt
apt-get update
apt_install azure-cli

log "Installing the application tree into $APP_DIR"
install -d -m 0755 "$APP_DIR"
rsync -a --delete \
    --exclude 'data/' \
    --exclude '.lifecycle/' \
    --exclude '.env' \
    "$STAGE_DIR/deploy/" "$APP_DIR/deploy/"
chmod 0755 "$APP_DIR/deploy/scripts"/*.sh "$APP_DIR/deploy/host"/*.sh "$APP_DIR/deploy/azure/scripts"/*.sh

# Persistent state lives on a separate managed disk so an instance can be
# replaced without losing anything. The application tree keeps the paths lib.sh
# expects and reaches the disk through symlinks.
install -d -m 0755 "$DATA_MOUNT"
ln -sfn "$DATA_MOUNT/data" "$APP_DIR/deploy/data"
ln -sfn "$DATA_MOUNT/.lifecycle" "$APP_DIR/deploy/.lifecycle"
ln -sfn "$DATA_MOUNT/.env" "$APP_DIR/deploy/.env"

log "Publishing the operator commands"
ln -sfn "$APP_DIR/deploy/host/update.sh" /usr/local/bin/synaplan-update
ln -sfn "$APP_DIR/deploy/host/configure-tls.sh" /usr/local/bin/synaplan-tls
ln -sfn "$APP_DIR/deploy/azure/scripts/snapshot.sh" /usr/local/bin/synaplan-snapshot
ln -sfn "$APP_DIR/deploy/azure/scripts/admin-password.sh" /usr/local/bin/synaplan-admin-password
ln -sfn "$APP_DIR/deploy/scripts/smoke-test.sh" /usr/local/bin/synaplan-smoke-test

log "Installing the Azure Backup application-consistency hooks"
# Azure Backup reads this file before it snapshots the disks and runs the two
# scripts it names, which is what turns a crash-consistent disk snapshot into an
# application-consistent one. Azure refuses to run the hooks unless the file is
# owned by root and readable by nobody else.
install -d -m 0755 /etc/azure
install -m 0600 -o root -g root \
    "$APP_DIR/deploy/azure/backup/VMSnapshotScriptPluginConfig.json" \
    /etc/azure/VMSnapshotScriptPluginConfig.json

log "Announcing the initial administrator password at login"
install -m 0755 "$APP_DIR/deploy/azure/scripts/motd.sh" /etc/update-motd.d/99-synaplan

log "Recording the baked release"
# Read by firstboot.sh to pin deploy/.env, and by support to tell instantly
# which image an instance came from.
cat > "$APP_DIR/image-release" <<EOF
SYNAPLAN_VERSION=$SYNAPLAN_VERSION
IMAGE_BUILT_AT=$(date --utc +%Y-%m-%dT%H:%M:%SZ)
EOF
chmod 0644 "$APP_DIR/image-release"

log "Installing the systemd units"
install -m 0644 "$APP_DIR/deploy/azure/systemd/synaplan-firstboot.service" /etc/systemd/system/
install -m 0644 "$APP_DIR/deploy/azure/systemd/synaplan.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable docker.service synaplan-firstboot.service synaplan.service caddy.service

log "Pre-pulling the container images for $SYNAPLAN_VERSION"
# The whole point: a first boot must not depend on ghcr.io being reachable or
# fast. A deployment into a locked-down virtual network comes up from the local
# image cache.
systemctl start docker
SYNAPLAN_VERSION="$SYNAPLAN_VERSION" "$APP_DIR/deploy/host/pull-images.sh"
systemctl stop docker

log "Provisioning complete"
