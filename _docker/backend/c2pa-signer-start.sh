#!/bin/bash
# Content-credentials signer inside the Synaplan container.
# Listens on 127.0.0.1 only. Starts when C2PA_SIGNER_URL points there and a
# token is set. The signing key, when present, is a file under /certs read by
# uid 65532. It is not copied into this process environment for PHP.
set -u

url="${C2PA_SIGNER_URL:-}"
case "$url" in
    http://127.0.0.1:*|http://localhost:*) ;;
    *) exit 0 ;;
esac

token="${C2PA_SIGNER_TOKEN:-}"
if [ -z "$token" ]; then
    echo "c2pa: C2PA_SIGNER_TOKEN is empty. The signer stays off."
    exit 0
fi

if [ ! -x /usr/local/bin/c2patool ]; then
    echo "c2pa: this image has no c2patool (linux amd64 only). PNG and JPEG still get the XMP note."
    exit 0
fi

if ! id c2pa >/dev/null 2>&1; then
    echo "c2pa: user 65532 is missing. The signer stays off."
    exit 0
fi

port="${url##*:}"
port="${port%%/*}"
case "$port" in
    ''|*[!0-9]*) port=8787 ;;
esac

mkdir -p /certs
if [ -w /certs ]; then
    chown 65532:65532 /certs 2>/dev/null || true
    chmod 700 /certs 2>/dev/null || true
fi

alg="${C2PA_ALG:-ps256}"
# nohup keeps the process after the entrypoint replaces itself with FrankenPHP
# or the worker. Logs stay on the container's stdout.
nohup runuser -u c2pa -- env \
    C2PA_SIGNER_ADDR="127.0.0.1:${port}" \
    C2PA_SIGNER_TOKEN="$token" \
    C2PA_ALG="$alg" \
    python3 -u /usr/local/lib/c2pa-signer/server.py >/dev/stdout 2>/dev/stderr &
disown || true

echo "c2pa: signer listening on 127.0.0.1:${port}"
exit 0
