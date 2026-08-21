#!/usr/bin/env bash
#
# Packer provisioner. Turns a stock Amazon Linux 2023 instance into the Synaplan
# AMI: container runtime, TLS terminator, application tree, pre-pulled images,
# and the systemd units that bring the stack up on every boot.
#
# Nothing here writes configuration or secrets — the AMI is launched many times
# and must be identical for every customer. See scripts/firstboot.sh for the
# per-instance part.

set -Eeuo pipefail

: "${SYNAPLAN_VERSION:?SYNAPLAN_VERSION must be passed by the Packer build}"

APP_DIR=/opt/synaplan
DATA_MOUNT=/var/lib/synaplan
STAGE_DIR=/tmp/synaplan

# Pinned, checksum-verified. Amazon Linux 2023 ships Docker but not the Compose
# v2 CLI plugin, and an unpinned download would make the image non-reproducible.
DOCKER_COMPOSE_VERSION=v5.4.0
DOCKER_COMPOSE_SHA256_x86_64=837fd1d35bf6a494f41b5b5988269a7be79de337cf1a1a6ff0e45ab51bb4e9be
DOCKER_COMPOSE_SHA256_aarch64=fc5d1371f1ec7987e703da94ede49af3fbfb240b83f22991a98511de7bc4b93b

log() { printf '==> %s\n' "$*"; }

log "Applying pending security updates"
dnf --releasever=latest -y upgrade

log "Installing base packages"
dnf -y install \
    docker \
    jq \
    rsync \
    tar \
    gzip \
    xfsprogs \
    openssl \
    amazon-ssm-agent

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
    plugin_dir=/usr/libexec/docker/cli-plugins
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
# through dnf like everything else.
#
# The RPMs live in Fedora COPR, not in the Cloudsmith repository the Caddy
# install page shows first — that one carries Debian packages only, and asking
# it for an RPM yields an empty repository that fails as "Unable to find a
# match: caddy" on every architecture. The EPEL 9 build is the one that fits
# Amazon Linux 2023: it needs nothing beyond glibc 2.34 and systemd, both of
# which AL2023 has, and it exists for x86_64 and aarch64 alike.
#
# COPR does not sign repository metadata, only the packages, so gpgcheck is on
# and repo_gpgcheck stays off.
cat > /etc/yum.repos.d/caddy.repo <<'REPO'
[caddy]
name=Caddy (COPR @caddy/caddy, EPEL 9)
baseurl=https://download.copr.fedorainfracloud.org/results/@caddy/caddy/epel-9-$basearch/
gpgcheck=1
enabled=1
gpgkey=https://download.copr.fedorainfracloud.org/results/@caddy/caddy/pubkey.gpg
repo_gpgcheck=0
skip_if_unavailable=False
REPO
dnf -y install caddy

# Same reasoning as the Compose plugin above: prove the thing that was just
# installed actually runs on this architecture, while the build can still fail
# cheaply. A broken TLS terminator would otherwise only surface on a customer's
# first boot.
caddy version

log "Installing the application tree into $APP_DIR"
install -d -m 0755 "$APP_DIR"
rsync -a --delete \
    --exclude 'data/' \
    --exclude '.lifecycle/' \
    --exclude '.env' \
    "$STAGE_DIR/deploy/" "$APP_DIR/deploy/"
chmod 0755 "$APP_DIR/deploy/scripts"/*.sh "$APP_DIR/deploy/aws/scripts"/*.sh

# Persistent state lives on a separate EBS volume so an instance can be replaced
# without losing anything. The application tree keeps the paths lib.sh expects
# and reaches the volume through symlinks.
install -d -m 0755 "$DATA_MOUNT"
ln -sfn "$DATA_MOUNT/data" "$APP_DIR/deploy/data"
ln -sfn "$DATA_MOUNT/.lifecycle" "$APP_DIR/deploy/.lifecycle"
ln -sfn "$DATA_MOUNT/.env" "$APP_DIR/deploy/.env"

log "Publishing the operator commands"
ln -sfn "$APP_DIR/deploy/aws/scripts/update.sh" /usr/local/bin/synaplan-update
ln -sfn "$APP_DIR/deploy/aws/scripts/snapshot.sh" /usr/local/bin/synaplan-snapshot
ln -sfn "$APP_DIR/deploy/aws/scripts/configure-tls.sh" /usr/local/bin/synaplan-tls
ln -sfn "$APP_DIR/deploy/scripts/smoke-test.sh" /usr/local/bin/synaplan-smoke-test

log "Recording the baked release"
# Read by firstboot.sh to pin deploy/.env, and by support to tell instantly
# which AMI an instance came from.
cat > "$APP_DIR/ami-release" <<EOF
SYNAPLAN_VERSION=$SYNAPLAN_VERSION
AMI_BUILT_AT=$(date --utc +%Y-%m-%dT%H:%M:%SZ)
EOF
chmod 0644 "$APP_DIR/ami-release"

log "Installing the systemd units"
install -m 0644 "$APP_DIR/deploy/aws/systemd/synaplan-firstboot.service" /etc/systemd/system/
install -m 0644 "$APP_DIR/deploy/aws/systemd/synaplan.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable docker amazon-ssm-agent synaplan-firstboot.service synaplan.service caddy.service

log "Pre-pulling the container images for $SYNAPLAN_VERSION"
# The whole point: a first boot must not depend on ghcr.io being reachable or
# fast. A launch in a locked-down VPC comes up from the local image cache.
systemctl start docker
SYNAPLAN_VERSION="$SYNAPLAN_VERSION" "$APP_DIR/deploy/aws/scripts/pull-images.sh"
systemctl stop docker

log "Provisioning complete"
