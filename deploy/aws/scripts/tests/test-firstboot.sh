#!/usr/bin/env bash
#
# firstboot.sh, executed for real, without AWS and without a virtual machine.
#
# This is the script an EC2 instance runs once, before anything else, and every
# defect in it is a customer whose instance never comes up — with no shell, no
# logs they know how to reach, and a Marketplace review that has already
# happened. It is also the script that is hardest to try out, because it wants
# an instance metadata service, an AWS CLI with an instance role, and a blank
# EBS volume.
#
# So all three are stubbed, and the parts that only exist on an instance — the
# block-device handling — are steered past rather than faked: the mount check at
# the top of prepare_data_volume() answers "already mounted", which is exactly
# what the second and every later boot sees. What remains is the whole
# configuration path, run as written.
#
# Run it directly, or in the same job as deploy/scripts/tests/test-lifecycle.sh:
#
#   bash deploy/aws/scripts/tests/test-firstboot.sh

set -Eeuo pipefail

AWS_SCRIPTS_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DEPLOY_ROOT="$(cd "$AWS_SCRIPTS_DIR/../.." && pwd)"
FIRSTBOOT="$AWS_SCRIPTS_DIR/firstboot.sh"

# shellcheck source=../../../scripts/lib.sh
source "$DEPLOY_ROOT/scripts/lib.sh"

temporary_dir="$(mktemp -d)"
trap 'rm -rf "$temporary_dir"' EXIT

fail() {
    printf '%s\n' "$*" >&2
    exit 1
}

# --------------------------------------------------------------------------
# The AMI metadata the build hands to EC2
# --------------------------------------------------------------------------

# Not firstboot, but the same build, and there is nowhere cheaper to catch it.
# EC2 refuses a non-ASCII AMI description with "Character sets beyond ASCII are
# not supported", and it refuses it in ModifyImageAttribute — the last call of a
# ten-minute Packer run, once the image and its snapshots already exist. An em
# dash in that one line burned exactly that, for both architectures at once.
non_ascii_ami_metadata="$(
    grep -E '^[[:space:]]*ami_(description|name)[[:space:]]*=' \
        "$DEPLOY_ROOT/aws/packer/synaplan.pkr.hcl" |
        LC_ALL=C grep '[^ -~]' || true
)"
if [[ -n "$non_ascii_ami_metadata" ]]; then
    fail "The AMI name or description carries a character EC2 will reject: $non_ascii_ami_metadata"
fi

# Marketplace must copy the source AMI into its ingestion account before it can
# scan and publish it. An encrypted EBS snapshot cannot be shared, even when it
# uses the seller account's default KMS key. The 4.2.4 submission reached the
# portal with exactly that combination and failed with Image access exception.
packer_file="$DEPLOY_ROOT/aws/packer/synaplan.pkr.hcl"
launch_mapping="$(
    awk '
        /launch_block_device_mappings[[:space:]]*\{/ { capture = 1 }
        capture { print }
        capture && /^[[:space:]]*\}/ { exit }
    ' "$packer_file"
)"
grep -Eq 'encrypted[[:space:]]*=[[:space:]]*false' <<<"$launch_mapping" ||
    fail "The Packer launch root is not explicitly unencrypted; AWS Marketplace cannot ingest or share the resulting AMI"

repo_root="$(cd "$DEPLOY_ROOT/.." && pwd)"
ami_workflow="$repo_root/.github/workflows/aws-ami.yml"
grep -Fq 'get-ebs-encryption-by-default' "$ami_workflow" ||
    fail "the AMI workflow does not reject account-level default EBS encryption before paying for a Marketplace-incompatible build"
grep -Fq 'describe-snapshots' "$ami_workflow" ||
    fail "the AMI workflow does not verify that every built snapshot is unencrypted"
grep -Fq '679593333241' "$ami_workflow" ||
    fail "the AMI workflow does not name the AWS Marketplace ingestion account"
grep -Fq 'modify-image-attribute' "$ami_workflow" ||
    fail "the AMI workflow does not share the built AMI with the AWS Marketplace ingestion account"

# --------------------------------------------------------------------------
# The boot order the units ask of systemd
# --------------------------------------------------------------------------

# A unit that is WantedBy=multi-user.target must not order itself after
# cloud-init.target or cloud-final.service: both sit after multi-user.target,
# which closes an ordering cycle that systemd resolves by deleting the unit's
# start job. The 4.2.4 verification instance booted exactly like that — no
# firstboot, no stack, no Caddy, an idle machine for fifty minutes.
for unit in "$DEPLOY_ROOT/aws/systemd/"*.service; do
    grep -Eq '^WantedBy=.*multi-user\.target' "$unit" || continue
    if grep -Eq '^(After|Wants|Requires)=.*cloud-(init\.target|final\.service)' "$unit"; then
        fail "$(basename "$unit") is WantedBy=multi-user.target and orders itself after cloud-init — that is an ordering cycle, and systemd answers it by never starting the unit"
    fi
done

# --------------------------------------------------------------------------
# The operator commands provision.sh publishes
# --------------------------------------------------------------------------

# Wrappers, never symlinks: every published script locates lib.sh relative to
# its own path, and through a symlink that path is /usr/local/bin. Each command
# then fails with "lib.sh: No such file or directory" — first observed when
# synaplan-smoke-test did exactly that during a verification post-mortem.
if grep -E '^[[:space:]]*ln\b' "$AWS_SCRIPTS_DIR/provision.sh" | grep -q '/usr/local/bin/'; then
    fail "provision.sh symlinks a command into /usr/local/bin; publish an exec wrapper instead, or the command cannot find lib.sh next to its real path"
fi

# --------------------------------------------------------------------------
# Caddy must be able to write the access log
# --------------------------------------------------------------------------

# The packaged unit runs as user caddy, often with ProtectSystem=strict. A
# chown of /var/log/caddy on the host is not enough: the namespace still has
# /var read-only unless LogsDirectory=caddy is set. The 4.2.4 verification
# brought the whole stack up and then died on
# "open /var/log/caddy/synaplan.log: permission denied".
grep -Fq 'LogsDirectory=caddy' "$AWS_SCRIPTS_DIR/provision.sh" ||
    fail "provision.sh does not set LogsDirectory=caddy; Caddy cannot write /var/log/caddy/synaplan.log under ProtectSystem=strict"
grep -Fq 'LogsDirectory=caddy' "$AWS_SCRIPTS_DIR/configure-tls.sh" ||
    fail "configure-tls.sh does not set LogsDirectory=caddy; a later synaplan-tls would drop the writable log directory"

# LogsDirectory alone is not enough. `caddy validate` PROVISIONS the config,
# and provisioning a `log { output file }` block opens the file — as root,
# because both scripts run as root. The file then belongs to root, mode 0600,
# and caddy.service (User=caddy) dies on "permission denied" opening its own
# log while the whole stack behind it is healthy. Proven with the packaged
# caddy binary: validate as root leaves -rw------- root root synaplan.log.
# So after the LAST validate in each script, ownership must be handed back.
for script in "$AWS_SCRIPTS_DIR/provision.sh" "$AWS_SCRIPTS_DIR/configure-tls.sh"; do
    awk '
        { line = $0; sub(/^[[:space:]]*/, "", line) }
        index(line, "#") == 1 { next }
        /caddy validate/ { validate = NR }
        /chown -R caddy:caddy \/var\/log\/caddy/ { restored = NR }
        END { exit !(validate && restored > validate) }
    ' "$script" || fail "$(basename "$script") runs caddy validate (as root, which creates a root-owned synaplan.log) without a chown -R caddy:caddy /var/log/caddy afterwards — caddy.service cannot open its own log"
done

# The wait used to probe only firstboot and synaplan. Both were active
# (oneshot RemainAfterExit) while Caddy was failed, so the probe sat out
# fifty minutes next to a stack that had been healthy for 45 of them.
repo_root="$(cd "$DEPLOY_ROOT/.." && pwd)"
grep -Fq 'systemctl is-failed caddy.service' "$repo_root/.github/workflows/aws-ami.yml" ||
    fail "the AMI verification wait does not probe caddy.service; a failed TLS terminator would again cost fifty minutes next to a healthy stack"

# --------------------------------------------------------------------------
# The self-signed certificate an instance without a domain serves
# --------------------------------------------------------------------------

# `tls internal` cannot answer a client that dials the bare IP — which is how
# every launch is reached until a domain is attached. The catch-all site has no
# host name, the ClientHello carries no SNI, so the internal issuer has no name
# to mint a certificate for and aborts every handshake with "tlsv1 alert
# internal error". A verification run watched a fully healthy stack refuse
# connections for fifty minutes behind exactly that. The Caddyfile must serve
# the pair configure-tls.sh mints instead.
selfsigned_caddyfile="$DEPLOY_ROOT/aws/caddy/Caddyfile.selfsigned"
if grep -Eq '^[[:space:]]*tls[[:space:]]+internal([[:space:]]|$)' "$selfsigned_caddyfile"; then
    fail "Caddyfile.selfsigned uses tls internal, which cannot answer a client connecting by bare IP (no SNI, no name to issue for) — every handshake dies with a tlsv1 internal error"
fi
grep -Fq '/etc/caddy/selfsigned/cert.pem' "$selfsigned_caddyfile" ||
    fail "Caddyfile.selfsigned does not serve the minted pair under /etc/caddy/selfsigned/; without it Caddy has no certificate at all"
grep -Fq 'openssl req' "$AWS_SCRIPTS_DIR/configure-tls.sh" ||
    fail "configure-tls.sh does not mint the self-signed certificate that Caddyfile.selfsigned serves"

# The pair is minted on the instance, never shipped in the image: an AMI that
# carries a private key hands the SAME key to every customer. provision.sh may
# create a throwaway pair so `caddy validate` has files to load, but it must
# delete BOTH files before the image is snapshotted — the key is the secret,
# and a leftover certificate would mask the missing mint at first boot.
# Comment lines are skipped, so a commented-out rm does not count as one.
awk '
    { line = $0; sub(/^[[:space:]]*/, "", line) }
    index(line, "#") == 1 { next }
    /openssl req/ { minted = NR }
    /rm .*\/etc\/caddy\/selfsigned\/key\.pem/ { removed_key = NR }
    /rm .*\/etc\/caddy\/selfsigned\/cert\.pem/ { removed_cert = NR }
    END { exit !(!minted || (removed_key > minted && removed_cert > minted)) }
' "$AWS_SCRIPTS_DIR/provision.sh" ||
    fail "provision.sh mints a certificate pair without deleting both files afterwards — the AMI would ship one private key to every customer"

# --------------------------------------------------------------------------
# The architecture-specific templates submitted to AWS Marketplace
# --------------------------------------------------------------------------

# A Marketplace AMI product has one architecture. The reusable source templates
# deliberately support both x86_64 and arm64 for direct deployments, but
# submitting them unchanged lets a buyer pair an x86 AMI with a Graviton
# instance (or vice versa), which EC2 rejects only after the stack starts.
# Generated listing templates expose only compatible instance types. Keep them
# byte-for-byte reproducible so a source-template fix cannot miss the copies
# already prepared for S3.
node "$repo_root/scripts/generate-aws-marketplace-templates.mjs" --check ||
    fail "AWS Marketplace CloudFormation templates are stale; regenerate them before publishing"

# Marketplace requires one current architecture diagram per CloudFormation
# delivery option, and the self-service portal currently enforces 1560 x 878.
# The SVGs are reproducible from the official AWS icons; the PNG header check
# catches an export tool that pads, crops or resizes them.
node "$repo_root/scripts/generate-aws-marketplace-diagrams.mjs" --check ||
    fail "AWS Marketplace architecture diagram SVGs are stale; regenerate them before publishing"
python3 - "$DEPLOY_ROOT/aws/marketplace/diagrams" <<'PY' ||
import pathlib
import struct
import sys

diagram_dir = pathlib.Path(sys.argv[1])
for name in ("synaplan-new-vpc.png", "synaplan-existing-vpc.png"):
    path = diagram_dir / name
    data = path.read_bytes()[:24]
    if data[:8] != b"\x89PNG\r\n\x1a\n":
        raise SystemExit(f"{path} is not a PNG")
    width, height = struct.unpack(">II", data[16:24])
    if (width, height) != (1560, 878):
        raise SystemExit(
            f"{path} is {width} x {height}; AWS Marketplace requires 1560 x 878"
        )
PY
    fail "AWS Marketplace architecture diagram PNGs are missing or have invalid dimensions"

# --------------------------------------------------------------------------
# The XFS label firstboot writes on a blank data volume
# --------------------------------------------------------------------------

# mkfs.xfs -L rejects anything longer than 12 characters. The verification of
# 4.2.4 died seven seconds into firstboot on DATA_LABEL=synaplan-data (13).
data_label="$(sed -n 's/^DATA_LABEL=//p' "$FIRSTBOOT" | head -n1)"
[[ -n "$data_label" ]] || fail "firstboot.sh no longer defines DATA_LABEL on its own line; update this test"
if (( ${#data_label} > 12 )); then
    fail "DATA_LABEL is ${#data_label} characters ($data_label); XFS labels are at most 12, and mkfs.xfs -L will refuse to format the data volume"
fi

# --------------------------------------------------------------------------
# Bind mounts the AMI has to ship, because compose.yaml asks for them
# --------------------------------------------------------------------------

# deploy/compose.yaml is the stack the instance runs. A bind mount whose source
# is not ./data (created at first boot) is a file the AMI must contain, or
# Docker creates a directory at the mount point and the service never becomes
# healthy. Centrifugo's config is ../_docker/centrifugo/config.json relative to
# deploy/ — without it, compose up waits until synaplan.service's 30-minute
# TimeoutStartSec.
repo_root="$(cd "$DEPLOY_ROOT/.." && pwd)"
packer_file="$DEPLOY_ROOT/aws/packer/synaplan.pkr.hcl"
# Bind specs look like `- ./data/uploads:...` or `- ../_docker/centrifugo/config.json:...`.
# A YAML parser would be nicer, but this suite has to run in the lint job, which
# has bash and a checkout and nothing else.
while IFS= read -r spec; do
    [[ -n "$spec" ]] || continue
    source_path="${spec%%:*}"
    source_path="${source_path#- }"
    source_path="${source_path#"${source_path%%[![:space:]]*}"}"
    case "$source_path" in
        ./data/*) continue ;;
        /*) continue ;;
        -*) continue ;;
    esac
    [[ "$source_path" == .* ]] || continue
    resolved="$(cd "$DEPLOY_ROOT" && python3 -c 'import os,sys; print(os.path.realpath(sys.argv[1]))' "$source_path")"
    [[ -e "$resolved" ]] || fail "compose.yaml bind-mounts $source_path, which does not exist in the repository"
    case "$resolved" in
        "$repo_root/deploy"/*|"$repo_root/_docker"/*) ;;
        *)
            fail "compose.yaml bind-mounts $source_path ($resolved), which the AMI does not copy — only deploy/ and _docker/ ship"
            ;;
    esac
    case "$resolved" in
        "$repo_root/_docker"/*)
            grep -Fq '_docker/centrifugo' "$packer_file" ||
                fail "compose.yaml bind-mounts $source_path from _docker/, but the Packer template does not copy _docker/centrifugo into the image"
            grep -Fq '_docker/' "$AWS_SCRIPTS_DIR/provision.sh" ||
                fail "compose.yaml bind-mounts $source_path from _docker/, but provision.sh does not install it under /opt/synaplan/_docker"
            ;;
    esac
done < <(grep -E '^[[:space:]]+-[[:space:]]+\.\./' "$DEPLOY_ROOT/compose.yaml" | sed 's/^[[:space:]]*-[[:space:]]*//')

# After mkfs the label is not yet in udev; mounting by LABEL= is how a successful
# format still fails firstboot. The device we just formatted has to be the mount
# source, and LABEL= belongs only in fstab for later boots.
if ! awk '
    /mkfs\.xfs/ { seen = 1 }
    seen && /mount "\$device"/ { found = 1 }
    END { exit !found }
' "$FIRSTBOOT"; then
    fail "firstboot.sh formats the data volume but does not mount the device it formatted — mounting by LABEL= races udev and fails the boot"
fi
if awk '
    /mkfs\.xfs/ { seen = 1 }
    seen && /mount "\$DATA_MOUNT"/ { found = 1 }
    /Mounted the data volume/ { seen = 0 }
    END { exit !found }
' "$FIRSTBOOT"; then
    fail "firstboot.sh still mounts the data volume by mountpoint right after mkfs; that is a LABEL= lookup and udev has not learned the label yet"
fi

# NVMe partitions are named nvme0n1p1, not nvme0n11. Stripping trailing digits
# does not yield the parent disk, so the root disk would look like a blank data
# volume. PKNAME is the parent lsblk already reports.
grep -Fq PKNAME "$FIRSTBOOT" ||
    fail "firstboot.sh no longer uses lsblk PKNAME to find the root disk; NVMe partition names would make the root disk look like a data volume"

# --------------------------------------------------------------------------
# The instance, as far as firstboot.sh can tell
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

# A tree that looks enough like a launched instance for the script to run.
# `tags` and `parameters` are the two configuration sources it reads, written as
# `key<tab>value` lines that the stubbed AWS CLI answers from.
new_instance() {
    tree="$temporary_dir/instance-$((++instance_index))"
    bin="$tree/bin"
    tags="$tree/tags"
    parameters="$tree/parameters"
    put_parameters="$tree/put-parameters"
    env_file="$tree/data/.env"

    mkdir -p "$tree/opt/deploy/scripts" "$tree/opt/deploy/aws/scripts" "$tree/data" "$bin"
    : > "$tags"
    : > "$parameters"
    : > "$put_parameters"

    printf 'SYNAPLAN_VERSION=9.9.9\n' > "$tree/opt/ami-release"
    write_stub "$tree/opt/deploy/aws/scripts/configure-tls.sh" 'exit 0'
    write_stub "$tree/opt/deploy/scripts/prepare.sh" 'exit 0'

    # prepare_data_volume() asks this first, and an affirmative answer is the
    # honest one for every boot after the volume exists.
    write_stub "$bin/findmnt" 'exit 0'
    # No instance metadata service. This is not only the test environment: it is
    # also what a launch inside a VPC without metadata access looks like, and
    # the script has to produce a working installation anyway.
    write_stub "$bin/curl" 'exit 1'
    # The AMI runs Amazon Linux, where `date --utc` exists. A maintainer's macOS
    # has BSD date, which does not, so this suite would fail on the laptop it is
    # most likely to be run from.
    write_stub "$bin/date" 'printf "2026-01-01T00:00:00Z\n"'

    install_firstboot "$tree"
}

# The AWS CLI as firstboot.sh uses it: three subcommands, answered from the
# files above. Written into the tree only when a case wants an instance role —
# `command -v aws` failing is the "Launch from Website" case.
enable_aws_cli() {
    cat > "$bin/aws" <<STUB
#!/usr/bin/env bash
case "\$1 \$2" in
    "ec2 describe-tags")
        key=""
        for argument in "\$@"; do
            [[ "\$argument" == Name=key,Values=* ]] && key="\${argument#Name=key,Values=}"
        done
        value="\$(awk -F'\t' -v k="\$key" '\$1 == k { print \$2; exit }' "$tags")"
        printf '%s\n' "\${value:-None}"
        ;;
    "ssm get-parameter")
        name=""
        for argument in "\$@"; do
            [[ "\$previous" == --name ]] && name="\$argument"
            previous="\$argument"
        done
        value="\$(awk -F'\t' -v k="\$name" '\$1 == k { print \$2; exit }' "$parameters")"
        [[ -n "\$value" ]] || exit 1
        printf '%s\n' "\$value"
        ;;
    "ssm put-parameter")
        printf '%s\n' "\$*" >> "$put_parameters"
        [[ ! -e "$tree/ssm-write-fails" ]]
        ;;
    *)
        exit 1
        ;;
esac
STUB
    chmod 0755 "$bin/aws"
}

set_tag() { printf '%s\t%s\n' "synaplan:$1" "$2" >> "$tags"; }
set_parameter() { printf '%s\t%s\n' "/synaplan/$1/config/$2" "$3" >> "$parameters"; }

boot() {
    local status=0
    PATH="$bin:$PATH" "$tree/firstboot.sh" > "$tree/boot.log" 2>&1 || status=$?
    return "$status"
}

env_value() { env_file_raw_value "$env_file" "$1"; }

assert_env() {
    local key="$1" expected="$2" actual
    actual="$(env_value "$key")" || actual="<unset>"
    [[ "$actual" == "$expected" ]] ||
        fail "Expected $key to be [$expected], got [$actual]"
}

instance_index=0
tree=""; bin=""; tags=""; parameters=""; put_parameters=""; env_file=""

# --------------------------------------------------------------------------
# "Launch from Website": no stack, no tags, no instance role, no metadata
#
# The Marketplace one-click launch, and the hardest case, because everything
# the script would like to read is absent. It still has to produce a complete,
# startable configuration — an instance that comes up unconfigured is a support
# ticket; one that does not come up at all is a refund and a bad review.
# --------------------------------------------------------------------------

new_instance
boot || fail "A bare launch with no metadata and no AWS CLI failed:$(printf '\n%s' "$(<"$tree/boot.log")")"

[[ -f "$env_file" ]] || fail 'A bare launch did not write a configuration file'
[[ "$(stat -c '%a' "$env_file" 2>/dev/null || stat -f '%Lp' "$env_file")" == 600 ]] ||
    fail 'The generated configuration file is readable by more than its owner'

# The AMI bakes exactly one release, and compose.yaml pulls whatever this says.
assert_env SYNAPLAN_VERSION 9.9.9
assert_env SYNAPLAN_PULL_POLICY missing
# Points the in-app update notice at docs/UPDATE_AWS.md rather than the generic
# self-host instructions, which do not apply here.
assert_env SYNAPLAN_PLATFORM aws

# The security posture the Marketplace listing promises. Each of these is a
# one-line regression away from an instance that is open to the internet.
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
# generated value that fails it turns every launch of the published AMI into an
# instance that refuses to start. Both the shape and the shared rule are pinned,
# because the shape is what makes the value survive Compose's .env parser.
admin_password="$(env_value BOOTSTRAP_ADMIN_PASSWORD)"
[[ "$admin_password" =~ ^[0-9a-f]{24}$ ]] ||
    fail "The generated administrator password is not 24 hexadecimal characters: ${#admin_password} bytes"
validate_bootstrap_admin_values "$(env_value BOOTSTRAP_ADMIN_EMAIL)" "$admin_password" >/dev/null ||
    fail 'The generated administrator credentials do not pass the preflight that prepare.sh runs'

# Without an instance role the password exists only in this file, and the
# operator has to be told so — it is the only way into their own installation.
grep -Fq "$env_file" "$tree/boot.log" ||
    fail 'A launch that could not publish the password does not say where to read it'
grep -Fq "$admin_password" "$tree/boot.log" &&
    fail 'The boot log prints the generated administrator password'

# --------------------------------------------------------------------------
# Idempotence
#
# A reboot, a stop/start and an instance replaced onto the same data volume all
# re-run this script. Regenerating the password there would lock the operator
# out of a running installation, with the database still holding the old hash.
# --------------------------------------------------------------------------

before="$(<"$env_file")"
boot || fail "The second boot failed:$(printf '\n%s' "$(<"$tree/boot.log")")"
[[ "$(<"$env_file")" == "$before" ]] ||
    fail 'A second boot rewrote the configuration of a running installation'
grep -Fq 'already initialised' "$tree/boot.log" ||
    fail 'A second boot does not report that it left the installation untouched'

# A volume restored from a snapshot carries the configuration but not the
# marker file. Adopting it is the difference between a restore and a fresh,
# empty installation whose database credentials no longer match its data.
rm -f "$tree/data/.firstboot-complete"
boot || fail "A restored volume failed to boot:$(printf '\n%s' "$(<"$tree/boot.log")")"
[[ "$(<"$env_file")" == "$before" ]] ||
    fail 'A restored data volume was reconfigured instead of adopted'
grep -Fq 'adopting it unchanged' "$tree/boot.log" ||
    fail 'A restored volume is not reported as adopted'

# --------------------------------------------------------------------------
# A CloudFormation launch: tags, an instance role, a domain
# --------------------------------------------------------------------------

new_instance
enable_aws_cli
set_tag domain synaplan.example.com
set_tag admin-email operator@example.com
set_tag ai-provider anthropic
boot || fail "A stack launch failed:$(printf '\n%s' "$(<"$tree/boot.log")")"

assert_env APP_URL https://synaplan.example.com
assert_env FRONTEND_URL https://synaplan.example.com
assert_env REALTIME_ALLOWED_ORIGINS https://synaplan.example.com
assert_env BOOTSTRAP_ADMIN_EMAIL operator@example.com
assert_env AI_DEFAULT_PROVIDER anthropic

# The stack outputs an `aws ssm get-parameter` command for exactly this
# parameter, and it is the only way a buyer reaches their instance.
grep -Fq -- '--type SecureString' "$put_parameters" ||
    fail 'The administrator password was not published as a SecureString'
grep -Fq -- '--name /synaplan/unknown/admin-password' "$put_parameters" ||
    fail 'The administrator password was not published under the documented parameter name'

# --------------------------------------------------------------------------
# Where each optional setting comes from
#
# Two sources, because the two ways of launching the AMI can each supply only
# one: a stack sets tags at launch, an operator writes a parameter afterwards.
# API keys are only ever accepted from the parameter store — a tag is readable
# by anyone who can describe the instance.
# --------------------------------------------------------------------------

new_instance
enable_aws_cli
set_parameter unknown anthropic-api-key sk-ant-from-the-parameter-store
set_parameter unknown mailer-dsn 'smtp://mail.example.com:587'
set_parameter unknown registration-enabled true
set_parameter unknown domain parameter.example.com
set_tag domain tag.example.com
boot || fail "A launch reading the parameter store failed:$(printf '\n%s' "$(<"$tree/boot.log")")"

assert_env ANTHROPIC_API_KEY sk-ant-from-the-parameter-store
assert_env MAILER_DSN 'smtp://mail.example.com:587'
assert_env REGISTRATION_ENABLED true
# The tag wins: it describes the launch the operator asked for, and a stale
# parameter from a previous instance must not quietly override it.
assert_env APP_URL https://tag.example.com

# --------------------------------------------------------------------------
# A failure to publish the password must not fail the boot
#
# The instance profile may deliberately not allow the write. An installation
# that works but needs a shell to reveal its password beats an installation
# that refuses to start.
# --------------------------------------------------------------------------

new_instance
enable_aws_cli
touch "$tree/ssm-write-fails"
boot || fail "A launch whose instance role cannot write to SSM failed:$(printf '\n%s' "$(<"$tree/boot.log")")"
[[ -f "$env_file" ]] || fail 'A launch that could not publish the password wrote no configuration'
grep -Fq 'Session Manager' "$tree/boot.log" ||
    fail 'A launch that could not publish the password does not say how to reach it instead'

echo "AWS first-boot tests passed."
