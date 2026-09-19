# expected-result: succeeded
# reason:
# note: Run with egress.allow=[example.com:443]; every bypass below must fail while the run itself succeeds (host tcpdump + proxy audit in CI)
"""egress_proxy_abuse.py — try to leave the pin map; any success fails the control."""
import os
import socket
import sys
import urllib.request

failures = []

if "HTTPS_PROXY" not in os.environ:
    print("harness must run this script with an egress allow-list", file=sys.stderr)
    sys.exit(2)


def check(name, fn):
    try:
        fn()
    except Exception as exc:  # noqa: BLE001 - any refusal shape is fine
        print(f"ok: {name} refused ({exc})")
        return
    failures.append(name)


def direct_connect():
    s = socket.create_connection(("93.184.216.34", 443), timeout=3)
    s.close()


def disallowed_host_via_proxy():
    proxy = os.environ["HTTPS_PROXY"]
    req = urllib.request.Request("https://evil.example:443/", method="GET")
    req.set_proxy(proxy, "https")
    urllib.request.urlopen(req, timeout=5)


def ip_literal_via_proxy():
    proxy = os.environ["HTTPS_PROXY"]
    req = urllib.request.Request("https://93.184.216.34/", method="CONNECT")
    req.set_proxy(proxy, "https")
    urllib.request.urlopen(req, timeout=5)


def dns_lookup():
    socket.setdefaulttimeout(2)
    socket.getaddrinfo("example.com", 443)


check("direct TCP to pinned IP (no route expected)", direct_connect)
check("CONNECT to disallowed host (403 expected)", disallowed_host_via_proxy)
check("CONNECT to IP literal (403 expected)", ip_literal_via_proxy)
check("DNS lookup (must fail: dead resolver)", dns_lookup)

if failures:
    print(f"EGRESS ESCAPED: {failures}", file=sys.stderr)
    sys.exit(2)
print("proxy held: all bypasses refused")
