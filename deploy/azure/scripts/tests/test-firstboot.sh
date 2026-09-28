#!/usr/bin/env bash
#
# firstboot.sh, executed for real, without Azure and without a virtual machine.
#
# This is the script a VM runs once, before anything else, and every defect in it
# is a customer whose deployment never comes up — with no shell, no logs they
# know how to reach, and a Marketplace certification that has already happened.
# It is also the script that is hardest to try out, because it wants an instance
# metadata service, a managed identity with a Key Vault behind it, and a blank
# data disk.
#
# So all three are stubbed, and the parts that only exist on a VM — the block
# device handling — are steered past rather than faked: the mount check at the
# top of prepare_data_disk() answers "already mounted", which is exactly what the
# second and every later boot sees. What remains is the whole configuration path,
# run as written.
#
# Run it directly, or in the same job as deploy/scripts/tests/test-lifecycle.sh:
#
#   bash deploy/azure/scripts/tests/test-firstboot.sh

set -Eeuo pipefail

AZURE_SCRIPTS_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DEPLOY_ROOT="$(cd "$AZURE_SCRIPTS_DIR/../.." && pwd)"
FIRSTBOOT="$AZURE_SCRIPTS_DIR/firstboot.sh"

# shellcheck source=../../../scripts/lib.sh
source "$DEPLOY_ROOT/scripts/lib.sh"

temporary_dir="$(mktemp -d)"
trap 'rm -rf "$temporary_dir"' EXIT

fail() {
    printf '%s\n' "$*" >&2
    exit 1
}

# firstboot.sh reads the metadata document with jq, and stubbing jq as well would
# mean testing nothing but the stub.
command -v jq >/dev/null 2>&1 || fail 'jq is required to run this suite; the image installs it in provision.sh'

# --------------------------------------------------------------------------
# The VM, as far as firstboot.sh can tell
# --------------------------------------------------------------------------

# Only the two absolute paths change, and the substitution is asserted: if a
# constant is renamed, this suite has to fail rather than silently test a script
# that still writes to /var/lib/synaplan.
install_firstboot() {
    local tree="$1"
    local script="$tree/firstboot.sh"

    sed \
        -e "s#^APP_DIR=/opt/synaplan\$#APP_DIR=$tree/opt#" \
        -e "s#^DATA_MOUNT=/var/lib/synaplan\$#DATA_MOUNT=$tree/data#" \
        "$FIRSTBOOT" > "$script"

    grep -Fq "APP_DIR=$tree/opt" "$script" ||
        fail 'firstboot.sh no longer defines APP_DIR=/opt/synaplan on its own line; update this test'
    grep -Fq "DATA_MOUNT=$tree/data" "$script" ||
        fail 'firstboot.sh no longer defines DATA_MOUNT=/var/lib/synaplan on its own line; update this test'

    chmod 0755 "$script"
}

write_stub() {
    local path="$1"
    shift
    mkdir -p "$(dirname "$path")"
    {
        printf '#!/usr/bin/env bash\n'
        printf '%s\n' "$@"
    } > "$path"
    chmod 0755 "$path"
}

# The instance metadata service, as one JSON document per case. Tags are a list
# of name/value objects exactly as IMDS returns them, and user data is base64,
# because the script has to decode it.
write_metadata() {
    local tag_json="[]" user_data=""

    [[ -s "$tags" ]] && tag_json="$(jq -Rs 'split("\n") | map(select(length > 0) | split("\t") | {name: .[0], value: .[1]})' < "$tags")"
    [[ -s "$user_data_source" ]] && user_data="$(base64 < "$user_data_source" | tr -d '\n')"

    jq -n \
        --argjson tags "$tag_json" \
        --arg userData "$user_data" \
        --arg publicIp "$public_ip" \
        '{
            compute: {
                name: "synaplan",
                vmId: "11111111-2222-3333-4444-555555555555",
                resourceGroupName: "synaplan-rg",
                subscriptionId: "00000000-0000-0000-0000-000000000000",
                location: "westeurope",
                userData: $userData,
                tagsList: $tags
            },
            network: {
                interface: [
                    {ipv4: {ipAddress: [{privateIpAddress: "10.20.1.4", publicIpAddress: $publicIp}]}}
                ]
            }
        }' > "$metadata"
}

# curl, answering the three requests firstboot.sh can make. Written into the tree
# only when a case wants a metadata service — a stub that always fails is the
# bare "deploy the image and click Create" case.
enable_metadata_service() {
    write_metadata
    cat > "$bin/curl" <<STUB
#!/usr/bin/env bash
url=""
for argument in "\$@"; do
    [[ "\$argument" == http* ]] && url="\$argument"
done

case "\$url" in
    *"/metadata/instance"*)
        cat "$metadata"
        ;;
    *"/metadata/identity/oauth2/token"*)
        [[ -e "$tree/no-managed-identity" ]] && exit 22
        printf '{"access_token":"stub-token","expires_in":"3599"}\n'
        ;;
    *"/secrets/"*)
        # The Key Vault PUT. The body arrives on stdin, which is the point: a
        # password passed as an argument would be visible in the process list.
        printf '%s\n' "\$url" >> "$vault_writes"
        cat >> "$vault_writes"
        printf '\n' >> "$vault_writes"
        [[ ! -e "$tree/key-vault-write-fails" ]]
        ;;
    *)
        exit 1
        ;;
esac
STUB
    chmod 0755 "$bin/curl"
}

new_instance() {
    tree="$temporary_dir/instance-$((++instance_index))"
    bin="$tree/bin"
    tags="$tree/tags"
    user_data_source="$tree/user-data"
    metadata="$tree/metadata.json"
    vault_writes="$tree/vault-writes"
    env_file="$tree/data/.env"
    password_file="$tree/data/initial-admin-password"
    public_ip=""

    mkdir -p "$tree/opt/deploy/scripts" "$tree/opt/deploy/host" "$tree/data" "$bin"
    : > "$tags"
    : > "$user_data_source"
    : > "$vault_writes"

    printf 'SYNAPLAN_VERSION=9.9.9\n' > "$tree/opt/image-release"
    write_stub "$tree/opt/deploy/host/configure-tls.sh" 'exit 0'
    write_stub "$tree/opt/deploy/scripts/prepare.sh" 'exit 0'

    # prepare_data_disk() asks this first, and an affirmative answer is the
    # honest one for every boot after the disk exists.
    write_stub "$bin/findmnt" 'exit 0'
    # No instance metadata service. This is not only the test environment: it is
    # also what a deployment with the metadata endpoint blocked looks like, and
    # the script has to produce a working installation anyway.
    write_stub "$bin/curl" 'exit 1'
    # The image runs Ubuntu, where `date --utc` exists. A maintainer's macOS has
    # BSD date, which does not, so this suite would fail on the laptop it is most
    # likely to be run from.
    write_stub "$bin/date" 'printf "2026-01-01T00:00:00Z\n"'

    install_firstboot "$tree"
}

set_tag() { printf '%s\t%s\n' "synaplan:$1" "$2" >> "$tags"; }
set_user_data() { printf '%s=%s\n' "$1" "$2" >> "$user_data_source"; }

boot() {
    local status=0
    PATH="$bin:$PATH" "$tree/firstboot.sh" > "$tree/boot.log" 2>&1 || status=$?
    return "$status"
}

env_value() { env_file_raw_value "$env_file" "$1"; }

file_mode() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"; }

assert_env() {
    local key="$1" expected="$2" actual
    actual="$(env_value "$key")" || actual="<unset>"
    [[ "$actual" == "$expected" ]] ||
        fail "Expected $key to be [$expected], got [$actual]"
}

instance_index=0
tree=""; bin=""; tags=""; user_data_source=""; metadata=""; vault_writes=""
env_file=""; password_file=""; public_ip=""

# --------------------------------------------------------------------------
# A bare Marketplace deployment: no template, no tags, no metadata
#
# The one-click "Create" out of the Marketplace listing, and the hardest case,
# because everything the script would like to read is absent. It still has to
# produce a complete, startable configuration — a VM that comes up unconfigured
# is a support ticket; one that does not come up at all is a refund and a bad
# review.
# --------------------------------------------------------------------------

new_instance
boot || fail "A bare deployment with no metadata failed:$(printf '\n%s' "$(<"$tree/boot.log")")"

[[ -f "$env_file" ]] || fail 'A bare deployment did not write a configuration file'
[[ "$(file_mode "$env_file")" == 600 ]] ||
    fail 'The generated configuration file is readable by more than its owner'

# The image bakes exactly one release, and compose.yaml pulls whatever this says.
assert_env SYNAPLAN_VERSION 9.9.9
assert_env SYNAPLAN_PULL_POLICY missing
# Points the in-app update notice at docs/UPDATE_AZURE.md rather than the generic
# self-host instructions, which do not apply here.
assert_env SYNAPLAN_PLATFORM azure

# The security posture the Marketplace listing promises. Each of these is a
# one-line regression away from a VM that is open to the internet.
assert_env SYNAPLAN_HTTP_BIND 127.0.0.1
assert_env REGISTRATION_ENABLED false
assert_env AUTH_COOKIE_SECURE true
assert_env BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE true

# No domain and no public IP: the private address is the last fallback, and all
# three URLs have to agree or the widget origin check and every absolute link
# point somewhere the browser is not.
assert_env APP_URL https://127.0.0.1
assert_env FRONTEND_URL https://127.0.0.1
assert_env REALTIME_ALLOWED_ORIGINS https://127.0.0.1

# Billing off. The Marketplace product must ship unrestricted; an operator adds
# their OWN Stripe account afterwards.
for key in STRIPE_SECRET_KEY STRIPE_WEBHOOK_SECRET STRIPE_PRICE_PRO STRIPE_PRICE_TEAM STRIPE_PRICE_BUSINESS; do
    assert_env "$key" ''
done

assert_env AI_DEFAULT_PROVIDER groq
assert_env GROQ_API_KEY ''
assert_env MAILER_DSN 'null://null'
assert_env BOOTSTRAP_ADMIN_EMAIL admin@example.com

# THE contract with the rest of the deployment: prepare.sh runs
# validate_bootstrap_admin_config on this password a few lines later, and a
# generated value that fails it turns every deployment of the published image
# into a VM that refuses to start. Both the shape and the shared rule are pinned,
# because the shape is what makes the value survive Compose's .env parser.
admin_password="$(env_value BOOTSTRAP_ADMIN_PASSWORD)"
[[ "$admin_password" =~ ^[0-9a-f]{24}$ ]] ||
    fail "The generated administrator password is not 24 hexadecimal characters: ${#admin_password} bytes"
validate_bootstrap_admin_values "$(env_value BOOTSTRAP_ADMIN_EMAIL)" "$admin_password" >/dev/null ||
    fail 'The generated administrator credentials do not pass the preflight that prepare.sh runs'

# `synaplan-admin-password` and the MOTD both point at this file, and without a
# Key Vault it is the only way into the installation.
[[ -f "$password_file" ]] || fail 'No initial administrator password was written for the operator to read'
[[ "$(file_mode "$password_file")" == 600 ]] ||
    fail 'The initial administrator password file is readable by more than its owner'
[[ "$(<"$password_file")" == "$admin_password" ]] ||
    fail 'The password file and the configuration disagree about the administrator password'

grep -Fq 'synaplan-admin-password' "$tree/boot.log" ||
    fail 'A deployment without a Key Vault does not say how to read the password'
grep -Fq "$admin_password" "$tree/boot.log" &&
    fail 'The boot log prints the generated administrator password'

# --------------------------------------------------------------------------
# Idempotence
#
# A reboot, a deallocate and start, and a VM recreated onto the same data disk
# all re-run this script. Regenerating the password there would lock the operator
# out of a running installation, with the database still holding the old hash.
# --------------------------------------------------------------------------

before="$(<"$env_file")"
boot || fail "The second boot failed:$(printf '\n%s' "$(<"$tree/boot.log")")"
[[ "$(<"$env_file")" == "$before" ]] ||
    fail 'A second boot rewrote the configuration of a running installation'
grep -Fq 'already initialised' "$tree/boot.log" ||
    fail 'A second boot does not report that it left the installation untouched'

# A disk restored from a backup carries the configuration but not the marker
# file. Adopting it is the difference between a restore and a fresh, empty
# installation whose database credentials no longer match its data.
rm -f "$tree/data/.firstboot-complete"
boot || fail "A restored disk failed to boot:$(printf '\n%s' "$(<"$tree/boot.log")")"
[[ "$(<"$env_file")" == "$before" ]] ||
    fail 'A restored data disk was reconfigured instead of adopted'
grep -Fq 'adopting it unchanged' "$tree/boot.log" ||
    fail 'A restored disk is not reported as adopted'

# --------------------------------------------------------------------------
# An ARM deployment: tags, a public address, a Key Vault
# --------------------------------------------------------------------------

new_instance
public_ip=203.0.113.10
set_tag domain synaplan.example.com
set_tag admin-email operator@example.com
set_tag ai-provider anthropic
set_tag key-vault synaplan-vault
enable_metadata_service
boot || fail "An ARM deployment failed:$(printf '\n%s' "$(<"$tree/boot.log")")"

assert_env APP_URL https://synaplan.example.com
assert_env FRONTEND_URL https://synaplan.example.com
assert_env REALTIME_ALLOWED_ORIGINS https://synaplan.example.com
assert_env BOOTSTRAP_ADMIN_EMAIL operator@example.com
assert_env AI_DEFAULT_PROVIDER anthropic

# The template outputs an `az keyvault secret show` command for exactly this
# secret, and for most buyers it is the first thing they run.
grep -Fq 'https://synaplan-vault.vault.azure.net/secrets/synaplan-admin-password' "$vault_writes" ||
    fail 'The administrator password was not written to the Key Vault named by the tag'
grep -Fq "$(env_value BOOTSTRAP_ADMIN_PASSWORD)" "$vault_writes" ||
    fail 'The Key Vault write does not carry the generated password'

# --------------------------------------------------------------------------
# Without a domain, the public address is the one the browser can reach
# --------------------------------------------------------------------------

new_instance
public_ip=203.0.113.11
enable_metadata_service
boot || fail "A deployment without a domain failed:$(printf '\n%s' "$(<"$tree/boot.log")")"
assert_env APP_URL https://203.0.113.11

# --------------------------------------------------------------------------
# Where each optional setting comes from
#
# Two sources, because the two ways of deploying the image can each supply only
# one: the ARM template writes a user data document, an operator deploying the
# bare image sets tags. API keys belong in the user data — a tag is shown in
# every resource list and is indexed by Resource Graph.
# --------------------------------------------------------------------------

new_instance
set_user_data anthropic-api-key sk-ant-from-the-user-data
set_user_data mailer-dsn 'smtp://user:pass@mail.example.com:587'
set_user_data registration-enabled true
set_user_data domain userdata.example.com
set_tag domain tag.example.com
enable_metadata_service
boot || fail "A deployment reading user data failed:$(printf '\n%s' "$(<"$tree/boot.log")")"

assert_env ANTHROPIC_API_KEY sk-ant-from-the-user-data
# The value runs to the end of the line, so a DSN carrying its own `=` or `:`
# survives the parser intact.
assert_env MAILER_DSN 'smtp://user:pass@mail.example.com:587'
assert_env REGISTRATION_ENABLED true
# The user data wins: it describes the deployment the operator asked for, and a
# tag edited afterwards must not quietly override it.
assert_env APP_URL https://userdata.example.com

# --------------------------------------------------------------------------
# A failure to publish to Key Vault must not fail the boot
#
# The VM may have no managed identity, or the identity may deliberately not be
# allowed near the vault. An installation that works but needs a shell to reveal
# its password beats an installation that refuses to start.
# --------------------------------------------------------------------------

new_instance
set_tag key-vault synaplan-vault
enable_metadata_service
touch "$tree/key-vault-write-fails"
boot || fail "A deployment whose Key Vault write fails did not boot:$(printf '\n%s' "$(<"$tree/boot.log")")"
[[ -f "$env_file" ]] || fail 'A deployment that could not publish the password wrote no configuration'
[[ -f "$password_file" ]] || fail 'A deployment that could not publish the password kept no local copy'
grep -Fq 'synaplan-admin-password' "$tree/boot.log" ||
    fail 'A deployment that could not publish the password does not say how to reach it instead'

new_instance
set_tag key-vault synaplan-vault
enable_metadata_service
touch "$tree/no-managed-identity"
boot || fail "A deployment with no managed identity did not boot:$(printf '\n%s' "$(<"$tree/boot.log")")"
[[ -f "$password_file" ]] || fail 'A deployment with no managed identity kept no local copy of the password'
[[ -s "$vault_writes" ]] &&
    fail 'A deployment with no managed-identity token still attempted a Key Vault write'

echo "Azure first-boot tests passed."
