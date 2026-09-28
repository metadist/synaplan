#!/usr/bin/env bash
#
# Second-to-last provisioner in the Packer build, immediately before
# `waagent -deprovision+user`. Everything here maps to a rule Azure Marketplace
# certification enforces on a Linux VM image, and a violation is a rejected
# submission — so this runs after every other step, which is the only way to be
# sure nothing it removes was put back.

set -Eeuo pipefail

log() { printf '==> %s\n' "$*"; }

log "Disabling password authentication and root login over SSH"
# Marketplace policy: no password-based remote access, and no root shell. Access
# is an SSH key the customer supplies at deployment time, or Azure Bastion / Run
# Command.
install -d -m 0755 /etc/ssh/sshd_config.d
cat > /etc/ssh/sshd_config.d/50-synaplan-hardening.conf <<'CONF'
PasswordAuthentication no
PermitRootLogin no
PermitEmptyPasswords no
ChallengeResponseAuthentication no
KbdInteractiveAuthentication no
CONF
chmod 0600 /etc/ssh/sshd_config.d/50-synaplan-hardening.conf

# Ubuntu's cloud image ships a drop-in that turns password authentication back
# on, and sshd takes the LAST matching directive from the included files in
# lexical order — so leaving it in place would silently undo the file above.
rm -f /etc/ssh/sshd_config.d/50-cloud-init.conf

log "Locking every account password"
# An account with a usable password hash is a password-authenticated account
# even when sshd refuses it — the serial console still accepts it.
while IFS=: read -r account _; do
    passwd --lock "$account" >/dev/null 2>&1 || true
done < /etc/passwd

log "Removing authorized_keys left behind by the build"
# Packer's own build key lives here. Shipping it would hand every customer's VM
# to whoever holds that key. `waagent -deprovision+user` deletes the build user
# outright a moment later; this covers every other account it does not touch.
rm -f /root/.ssh/authorized_keys
find /home -maxdepth 3 -name authorized_keys -type f -delete

log "Removing host keys so every VM generates its own"
# Identical host keys across all VMs make the SSH host identity meaningless and
# let anyone with the image impersonate any instance. cloud-init regenerates
# them on first boot.
rm -f /etc/ssh/ssh_host_*

log "Clearing build history and caches"
rm -rf /tmp/synaplan
rm -f /root/.bash_history /home/*/.bash_history
rm -rf /var/lib/cloud/instances /var/lib/cloud/instance
apt-get clean
rm -rf /var/lib/apt/lists/*

log "Truncating logs"
find /var/log -type f -exec truncate --size=0 {} + 2>/dev/null || true

log "Verifying no secret material survived"
# Cheap, and it turns a mistake in an earlier provisioner into a failed build
# instead of a published image.
leftovers=()
for path in \
    /opt/synaplan/deploy/.env \
    /opt/synaplan/deploy/data/secrets.env \
    /var/lib/synaplan/.env \
    /var/lib/synaplan/initial-admin-password \
    /var/lib/synaplan/data/secrets.env \
    /root/.ssh/authorized_keys \
    /root/.azure; do
    [[ -e "$path" ]] && leftovers+=("$path")
done

if ((${#leftovers[@]} > 0)); then
    printf 'Refusing to publish: the image still contains %s\n' "${leftovers[*]}" >&2
    exit 1
fi

log "Hardening complete"
