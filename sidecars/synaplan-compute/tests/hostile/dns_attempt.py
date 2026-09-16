# expected-result: succeeded
# reason:
# note: Name resolution fails; no packet leaves (host tcpdump in CI)
"""dns_attempt.py — try to resolve an external name; success of the control is failure of DNS."""
import socket
import sys

try:
    socket.setdefaulttimeout(2)
    socket.getaddrinfo("example.com", 443)
except OSError:
    sys.exit(0)
print("dns unexpectedly succeeded", file=sys.stderr)
sys.exit(2)
