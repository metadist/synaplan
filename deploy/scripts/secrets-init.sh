#!/bin/sh
# Resolve the eight deployment secrets once, then publish them for the other
# containers. data/secrets.env is authoritative and is never rewritten.
# A replace-with-* example value is refused. Nothing is written in that case.
#
# The same script is inlined in deploy/compose.yaml (every "$" doubled) so a
# two-file install does not need this path. deploy/scripts/tests/test-secrets-init.sh
# fails when the two copies differ.
set -eu
DATA="${SECRETS_INIT_DATA:-/data}"
SECRETS="$DATA/secrets.env"
OUT="${SECRETS_INIT_OUT:-/run/synaplan-secrets/secrets.env}"
KEYS="APP_SECRET TOKEN_SECRET MARIADB_PASSWORD MARIADB_ROOT_PASSWORD REALTIME_API_KEY REALTIME_TOKEN_SECRET REALTIME_ADMIN_PASSWORD REALTIME_ADMIN_SECRET"

read_key() {
    file=$1
    key=$2
    val=""
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|\#*) continue ;;
        esac
        case "$line" in
            "$key"=*) val=${line#*=} ;;
        esac
    done < "$file"
    printf '%s' "$val"
}

stack_initialised() {
    [ -d "$DATA/mariadb" ] || return 1
    find "$DATA/mariadb" -mindepth 1 -print -quit 2>/dev/null | grep -q .
}

is_placeholder() {
    case "$1" in
        replace-with-*) return 0 ;;
    esac
    return 1
}

is_adoptable() {
    case "$1" in
        *'$'*|*' #'*) return 1 ;;
        \"*\"|\'*\') return 1 ;;
        [[:space:]]*|*[[:space:]]) return 1 ;;
    esac
    return 0
}

generate_secret() {
    val=$(hexdump -vn 32 -e '/1 "%02x"' /dev/urandom)
    case "$val" in
        *[!0-9a-f]*) val="" ;;
    esac
    if [ "${#val}" -ne 64 ]; then
        echo "Synaplan did not start and created nothing: a random secret could not be generated." >&2
        exit 1
    fi
    printf '%s' "$val"
}

refuse() {
    echo "Synaplan did not start and created nothing: $1" >&2
    exit 1
}

list_keys() {
    printf '%s' "$1" | sed 's/^ //; s/ /, /g'
}

file_existed=false
if [ -f "$SECRETS" ]; then
    file_existed=true
fi
initialised=false
if stack_initialised; then
    initialised=true
fi

placeholders=""
file_placeholders=""
unadoptable=""
unresolved=""
assignments=""

for key in $KEYS; do
    val=""
    if [ "$file_existed" = true ]; then
        val=$(read_key "$SECRETS" "$key")
    fi
    if [ -n "$val" ] && is_placeholder "$val"; then
        file_placeholders="$file_placeholders $key"
        continue
    fi
    if [ -z "$val" ]; then
        envval=$(printenv "$key" || true)
        if [ -n "$envval" ]; then
            if is_placeholder "$envval"; then
                placeholders="$placeholders $key"
                continue
            fi
            if ! is_adoptable "$envval"; then
                unadoptable="$unadoptable $key"
                continue
            fi
            val=$envval
        fi
    fi
    if [ -z "$val" ]; then
        if [ "$file_existed" = true ] || [ "$initialised" = true ]; then
            unresolved="$unresolved $key"
            continue
        fi
        val=$(generate_secret)
    fi
    assignments="${assignments}${key}=${val}
"
done

if [ -n "$placeholders" ]; then
    refuse "$(list_keys "$placeholders") still has the example value. Delete that line so Synaplan generates one, or set it to the output of \"openssl rand -hex 32\"."
fi

if [ -n "$file_placeholders" ]; then
    refuse "$(list_keys "$file_placeholders") in data/secrets.env still has the example value. Replace it with the output of \"openssl rand -hex 32\". The file was not rewritten."
fi

if [ -n "$unadoptable" ]; then
    refuse "$(list_keys "$unadoptable") cannot be read unchanged. Use only letters, digits, '-' and '_', or delete the line so Synaplan generates one."
fi

if [ -n "$unresolved" ]; then
    if [ "$file_existed" = true ]; then
        refuse "$(list_keys "$unresolved") has no value in data/secrets.env. That file is authoritative and was not rewritten. Put the original value back, or restore the file from a backup."
    fi
    refuse "$(list_keys "$unresolved") has no value, and this install already has a database. Put the original value back. A new password would not open the existing database."
fi

if [ "$file_existed" = false ]; then
    mkdir -p "$DATA"
    chmod 0700 "$DATA"
    umask 077
    tmp="$DATA/.secrets.env.$$"
    {
        printf '%s\n' '# Synaplan deployment secrets. Generated once. Back this file up with the database. Do not commit it.'
        printf '%s' "$assignments"
    } > "$tmp"
    for key in $KEYS; do
        got=$(read_key "$tmp" "$key")
        if ! printf '%s\n' "$assignments" | grep -Fxq "${key}=${got}"; then
            rm -f "$tmp"
            refuse "the generated secrets file could not be read back. Nothing was kept."
        fi
    done
    mv "$tmp" "$SECRETS"
    chmod 600 "$SECRETS"
fi

umask 077
out_dir=$(dirname "$OUT")
mkdir -p "$out_dir"
# The host copy stays mode 0600. This copy is read by containers that do not
# run as root (Centrifugo, the application). It never leaves the Docker volume.
chmod 755 "$out_dir"
umask 022
tmpout="$out_dir/.secrets.env.$$"
{
    printf '%s\n' '# Copy for this start. Do not edit.'
    for key in $KEYS; do
        printf '%s=%s\n' "$key" "$(read_key "$SECRETS" "$key")"
    done
} > "$tmpout"
mv "$tmpout" "$OUT"
chmod 644 "$OUT"
