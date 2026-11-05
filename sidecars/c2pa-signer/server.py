#!/usr/bin/env python3
"""Content-credentials signer.

POST /sign  {"bytes", "filename", "model", "parent"} -> {"bytes"}
POST /verify {"bytes"} -> {"signed": bool, "watermark": null}
GET /health

The private key is read from the process environment or from /certs.
It is never written into the manifest and never returned.
TrustMark is not implemented. /verify always reports watermark null.
"""

from __future__ import annotations

import base64
import binascii
import json
import os
import shutil
import subprocess
import tempfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ADDR = os.environ.get("C2PA_SIGNER_ADDR", ":8787")
KEY = os.environ.get("C2PA_KEY", "/certs/key.pem")
CERT = os.environ.get("C2PA_CERT", "/certs/cert.pem")
MAX_BODY = 64 * 1024 * 1024
ALLOWED_ALG = {"es256", "ps256", "ps384", "ps512", "ed25519"}


DEV_CERT_CONFIG = """
[req]
distinguished_name = dn
prompt = no
[dn]
CN = Synaplan Dev CA
[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
[v3_leaf]
basicConstraints = CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
"""


def ensure_dev_cert() -> None:
    parent = os.path.dirname(KEY) or "/certs"
    os.makedirs(parent, exist_ok=True)
    if not (os.path.isfile(KEY) and os.path.isfile(CERT)):
        # C2PA rejects a self-signed CA. Issue an end-entity certificate from a
        # temporary CA, then delete the CA key. This is not a public trust anchor.
        with tempfile.TemporaryDirectory() as tmp:
            config = os.path.join(tmp, "openssl.cnf")
            ca_key = os.path.join(tmp, "ca.key")
            ca_cert = os.path.join(tmp, "ca.pem")
            csr = os.path.join(tmp, "leaf.csr")
            Path(config).write_text(DEV_CERT_CONFIG, encoding="utf-8")
            subprocess.run(
                [
                    "openssl", "req", "-x509", "-newkey", "rsa:2048",
                    "-keyout", ca_key, "-out", ca_cert, "-days", "365", "-nodes",
                    "-config", config, "-extensions", "v3_ca",
                ],
                check=False,
                capture_output=True,
            )
            subprocess.run(
                [
                    "openssl", "req", "-newkey", "rsa:2048",
                    "-keyout", KEY, "-out", csr, "-nodes",
                    "-subj", "/CN=Synaplan Dev Signer",
                ],
                check=False,
                capture_output=True,
            )
            subprocess.run(
                [
                    "openssl", "x509", "-req", "-in", csr,
                    "-CA", ca_cert, "-CAkey", ca_key, "-CAcreateserial",
                    "-out", CERT, "-days", "365",
                    "-extfile", config, "-extensions", "v3_leaf",
                ],
                check=False,
                capture_output=True,
            )
    if os.path.isfile(KEY):
        os.chmod(KEY, 0o600)


def algorithm() -> str:
    alg = os.environ.get("C2PA_ALG", "ps256").strip().lower()
    return alg if alg in ALLOWED_ALG else "ps256"


def signing_env() -> dict[str, str] | None:
    env = os.environ.copy()
    if not env.get("C2PA_PRIVATE_KEY") and os.path.isfile(KEY):
        env["C2PA_PRIVATE_KEY"] = Path(KEY).read_text(encoding="utf-8")
    if not env.get("C2PA_SIGN_CERT") and os.path.isfile(CERT):
        env["C2PA_SIGN_CERT"] = Path(CERT).read_text(encoding="utf-8")
    if not env.get("C2PA_PRIVATE_KEY") or not env.get("C2PA_SIGN_CERT"):
        return None
    return env


def manifest(model: str, parent_path: str | None) -> dict:
    body: dict = {
        "claim_generator": "Synaplan/1.0",
        "claim_generator_info": [{"name": "Synaplan", "version": "1.0"}],
        "title": model or "model",
        "alg": algorithm(),
        "assertions": [
            {
                "label": "c2pa.actions",
                "data": {
                    "actions": [
                        {"action": "c2pa.created", "softwareAgent": model or "model"},
                    ],
                },
            },
            {
                "label": "stds.iptc",
                "data": {
                    "digitalSourceType": "http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia",
                },
            },
        ],
    }
    if parent_path:
        body["ingredients"] = [
            {
                "title": "provider original",
                "relationship": "parentOf",
                "path": parent_path,
            },
        ]
    return body


def safe_name(raw: object) -> str:
    name = os.path.basename(str(raw or "media.bin"))
    if name in ("", ".", ".."):
        return "media.bin"
    return name


def decode_bytes(payload: dict) -> bytes | None:
    try:
        raw = base64.b64decode(payload.get("bytes") or "", validate=True)
    except (binascii.Error, ValueError):
        return None
    if not raw or len(raw) > MAX_BODY:
        return None
    return raw


def sign(payload: dict) -> bytes | None:
    tool = shutil.which("c2patool")
    env = signing_env()
    raw = decode_bytes(payload)
    if tool is None or env is None or raw is None:
        return None
    filename = safe_name(payload.get("filename"))
    with tempfile.TemporaryDirectory() as tmp:
        source = os.path.join(tmp, filename)
        target = os.path.join(tmp, "signed-" + filename)
        manifest_path = os.path.join(tmp, "manifest.json")
        with open(source, "wb") as handle:
            handle.write(raw)
        parent_path = None
        if payload.get("parent") is True:
            parent_path = os.path.join(tmp, "parent-" + filename)
            shutil.copyfile(source, parent_path)
        with open(manifest_path, "w", encoding="utf-8") as handle:
            json.dump(manifest(str(payload.get("model") or "model"), parent_path), handle)
        result = subprocess.run(
            [tool, source, "-m", manifest_path, "-o", target, "-f"],
            capture_output=True,
            env=env,
        )
        if not os.path.isfile(target):
            return None
        signed = Path(target).read_bytes()
        # c2patool writes the manifest, then returns an error when the
        # certificate is not in its public trust store. The bytes are still
        # the marked file. An empty or unmarked file is a real failure.
        if b"c2pa" not in signed:
            return None
        return signed


def looks_signed(raw: bytes) -> bool:
    tool = shutil.which("c2patool")
    if tool is None or not raw:
        return b"c2pa" in raw or b"trainedAlgorithmicMedia" in raw
    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, "media.bin")
        Path(path).write_bytes(raw)
        result = subprocess.run([tool, path], capture_output=True)
        return result.returncode == 0


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802
        if self.path.split("?", 1)[0] != "/health":
            self.send_error(404)
            return
        body = b'{"status":"ok"}'
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("Content-Length", "0") or "0")
        if length < 0 or length > MAX_BODY:
            self.send_error(413)
            return
        try:
            payload = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            self.send_error(400)
            return
        if not isinstance(payload, dict):
            self.send_error(400)
            return
        path = self.path.split("?", 1)[0]
        if path == "/verify":
            raw = decode_bytes(payload) or b""
            self.respond(200, {"signed": looks_signed(raw), "watermark": None})
            return
        if path != "/sign":
            self.send_error(404)
            return
        signed = sign(payload)
        if signed is None:
            self.respond(503, {"error": "signer unavailable"})
            return
        self.respond(200, {"bytes": base64.b64encode(signed).decode("ascii")})

    def respond(self, status: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt: str, *args) -> None:
        return


def main() -> None:
    ensure_dev_cert()
    host, _, port = ADDR.rpartition(":")
    server = ThreadingHTTPServer((host or "0.0.0.0", int(port or "8787")), Handler)
    server.serve_forever()


if __name__ == "__main__":
    main()
