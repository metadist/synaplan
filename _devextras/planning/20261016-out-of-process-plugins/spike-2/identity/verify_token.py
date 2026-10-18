# Review spike (2026-10-18) — not product code. Verifies the 02_contracts.md §3
# call-token vector the way a Python service would, with PyJWT[crypto].
#
#   python verify_token.py vector.json
#
# A real service checks the clock with time.time(); the vector pins "now" so the
# published token keeps verifying.
import base64
import hashlib
import json
import sys

import jwt

TOKEN_TYPE = "synaplan-call+jwt"
NOW = 1792130030
LEEWAY = 30


def b64url(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()


def verify(jwks: dict, token: str, audience: str, method: str, url: str, body: bytes, now: float) -> dict:
    """Return the claims of a valid Synaplan call token, else raise."""
    header = jwt.get_unverified_header(token)
    if header.get("typ") != TOKEN_TYPE:
        raise ValueError("wrong typ")
    key = next(jwt.PyJWK(k) for k in jwks["keys"] if k["kid"] == header.get("kid"))
    claims = jwt.decode(
        token,
        key,
        algorithms=["ES256"],
        audience=audience,
        options={"require": ["exp", "iat", "sub", "jti", "htm", "htu", "bh"], "verify_exp": False, "verify_iat": False},
    )
    if now > claims["exp"] + LEEWAY or claims["iat"] > now + LEEWAY:
        raise ValueError("expired or not yet valid")
    if claims["htm"] != method or claims["htu"] != url:
        raise ValueError("wrong method or URL")
    if claims["bh"] != b64url(hashlib.sha256(body).digest()):
        raise ValueError("body hash mismatch")
    return claims


def main() -> int:
    vector = json.load(open(sys.argv[1]))
    req = vector["request"]
    token = vector["token"]
    h, p, s = token.split(".")
    payload = base64.urlsafe_b64decode(p + "=" * (-len(p) % 4)).decode()
    forged = h + "." + b64url(payload.replace(vector["claims"]["sub"], "u_" + "0" * 32).encode()) + "." + s
    body = req["body"].encode()
    cases = [
        ("valid", token, req["url"], req["method"], body, NOW, True),
        ("changed body", token, req["url"], req["method"], body.replace(b"hello", b"HELLO"), NOW, False),
        ("other audience", token, "http://other-tool:8000/mcp", req["method"], body, NOW, False),
        ("other method", token, req["url"], "DELETE", body, NOW, False),
        ("forged subject", forged, req["url"], req["method"], body, NOW, False),
        ("expired (now + 170 s)", token, req["url"], req["method"], body, NOW + 170, False),
    ]
    failed = 0
    for label, t, audience, method, b, now, expect_ok in cases:
        try:
            verify(vector["jwks"], t, audience, method, req["url"], b, now)
            reason = None
        except Exception as e:  # noqa: BLE001 — the reason is the point of the output
            reason = str(e)
        ok = (reason is None) == expect_ok
        failed += 0 if ok else 1
        print(f"python {label + ':':<24} {'as expected' if ok else 'UNEXPECTED'}" + (" (accepted)" if reason is None else f" (refused: {reason})"))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
