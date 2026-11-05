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
import hmac
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
TOKEN = os.environ.get("C2PA_SIGNER_TOKEN", "").strip()
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
    # Kubernetes injects the key as environment variables. Do not create or
    # chmod a file in that case: the root filesystem is read-only and the
    # key must not be written to disk a second time.
    if os.environ.get("C2PA_PRIVATE_KEY") and os.environ.get("C2PA_SIGN_CERT"):
        return
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
        try:
            os.chmod(KEY, 0o600)
        except OSError:
            return


def tool_path() -> str | None:
    found = shutil.which("c2patool")
    if found:
        return found
    bundled = "/usr/local/bin/c2patool"
    if os.access(bundled, os.X_OK):
        return bundled
    return None


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
                        {
                            "action": "c2pa.created",
                            "softwareAgent": model or "model",
                            # c2patool 0.28 rejects c2pa.created without this field
                            # and then does not return a signed file.
                            "digitalSourceType": "http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia",
                        },
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


def file_names(raw: object) -> tuple[str, str, str]:
    """Return fixed names. The client filename never becomes a path."""
    leaf = str(raw or "").lower().replace("\\", "/").rsplit("/", 1)[-1]
    names = {
        ".png": ("source.png", "signed.png", "parent.png"),
        ".jpg": ("source.jpg", "signed.jpg", "parent.jpg"),
        ".jpeg": ("source.jpeg", "signed.jpeg", "parent.jpeg"),
        ".gif": ("source.gif", "signed.gif", "parent.gif"),
        ".webp": ("source.webp", "signed.webp", "parent.webp"),
        ".mp3": ("source.mp3", "signed.mp3", "parent.mp3"),
        ".wav": ("source.wav", "signed.wav", "parent.wav"),
        ".ogg": ("source.ogg", "signed.ogg", "parent.ogg"),
        ".m4a": ("source.m4a", "signed.m4a", "parent.m4a"),
        ".mp4": ("source.mp4", "signed.mp4", "parent.mp4"),
        ".webm": ("source.webm", "signed.webm", "parent.webm"),
        ".mov": ("source.mov", "signed.mov", "parent.mov"),
    }
    for suffix, chosen in names.items():
        if leaf.endswith(suffix):
            return chosen
    return ("source.bin", "signed.bin", "parent.bin")


def decode_bytes(payload: dict) -> bytes | None:
    try:
        raw = base64.b64decode(payload.get("bytes") or "", validate=True)
    except (binascii.Error, ValueError):
        return None
    if not raw or len(raw) > MAX_BODY:
        return None
    return raw


def log_tool_error(stderr: bytes | None) -> None:
    detail = (stderr or b"").decode("utf-8", "replace").strip().splitlines()
    if detail:
        print(f"c2pa: sign failed: {detail[-1][:300]}", flush=True)


def manifest_is_valid(stdout: bytes | None) -> bool:
    if not stdout:
        return False
    try:
        report = json.loads(stdout.decode("utf-8", "replace"))
    except json.JSONDecodeError:
        return False
    return isinstance(report, dict) and report.get("validation_state") != "Invalid"


def sign(payload: dict) -> bytes | None:
    tool = tool_path()
    env = signing_env()
    raw = decode_bytes(payload)
    if tool is None or env is None or raw is None:
        return None
    source_name, signed_name, parent_name = file_names(payload.get("filename"))
    with tempfile.TemporaryDirectory() as tmp:
        source = os.path.join(tmp, source_name)
        target = os.path.join(tmp, signed_name)
        manifest_path = os.path.join(tmp, "manifest.json")
        with open(source, "wb") as handle:
            handle.write(raw)
        parent_path = None
        if payload.get("parent") is True:
            parent_path = os.path.join(tmp, parent_name)
            shutil.copyfile(source, parent_path)
        with open(manifest_path, "w", encoding="utf-8") as handle:
            json.dump(manifest(str(payload.get("model") or "model"), parent_path), handle)
        try:
            result = subprocess.run(
                [tool, source, "-m", manifest_path, "-o", target, "-f"],
                capture_output=True,
                env=env,
                timeout=30,
            )
        except subprocess.TimeoutExpired:
            print("c2pa: sign failed: timed out", flush=True)
            return None
        if not os.path.isfile(target):
            log_tool_error(result.stderr)
            return None
        signed = Path(target).read_bytes()
        # c2patool writes the manifest, then returns an error when the
        # certificate is not in its public trust store. The bytes are still
        # the marked file. An empty, unmarked, or invalid manifest is a real failure.
        if b"c2pa" not in signed or (result.returncode != 0 and not manifest_is_valid(result.stdout)):
            log_tool_error(result.stderr)
            return None
        return signed


def looks_signed(raw: bytes) -> bool:
    tool = tool_path()
    if tool is None or not raw:
        return b"c2pa" in raw or b"trainedAlgorithmicMedia" in raw
    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, "media.bin")
        Path(path).write_bytes(raw)
        try:
            result = subprocess.run([tool, path], capture_output=True, timeout=15)
        except subprocess.TimeoutExpired:
            return False
        return result.returncode == 0 and manifest_is_valid(result.stdout)


def is_ready() -> bool:
    return bool(TOKEN) and tool_path() is not None and signing_env() is not None


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802
        if self.path.split("?", 1)[0] != "/health":
            self.send_error(404)
            return
        if not is_ready():
            self.respond(503, {"status": "unavailable"})
            return
        self.respond(200, {"status": "ok"})

    def do_POST(self) -> None:  # noqa: N802
        if not self.authorized():
            self.respond(401, {"error": "unauthorized"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0") or "0")
        except ValueError:
            self.send_error(400)
            return
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

    def authorized(self) -> bool:
        if not TOKEN:
            return False
        header = self.headers.get("Authorization") or ""
        if not header.startswith("Bearer "):
            return False
        provided = header[7:].strip()
        return bool(provided) and hmac.compare_digest(provided, TOKEN)

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
