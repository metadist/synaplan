# Review spike (2026-10-18) — not product code. Python standard library only.
#
# The 2026-10-16 draft of 02_contracts.md §3 derived X-Synaplan-User from the
# signing secret, which the service holds. This script plays the service: with
# only the draft's published secret and pseudonym it recovers the Synaplan user
# id by counting up, because user ids are small sequential integers.
#
#   python3 pseudonym_reversal.py
import hashlib
import hmac
import time

DRAFT_SECRET = "3f9a1c0e7b2d4a6f8e1c3b5a7d9f0e2c4b6a8d0f1e3c5b7a9d1f3e5c7b9a0d2f"
DRAFT_PSEUDONYM = "u_b541fdf0b220867c3e06d933ca59d3be"
SEARCH_UP_TO = 1_000_000

start = time.perf_counter()
for user_id in range(1, SEARCH_UP_TO + 1):
    candidate = "u_" + hmac.new(DRAFT_SECRET.encode(), f"user:{user_id}".encode(), hashlib.sha256).hexdigest()[:32]
    if candidate == DRAFT_PSEUDONYM:
        print(f"{DRAFT_PSEUDONYM} is Synaplan user id {user_id} (found after {user_id} tries, {time.perf_counter() - start:.4f} s)")
        break
else:
    print(f"not found up to {SEARCH_UP_TO}")

start = time.perf_counter()
for user_id in range(1, SEARCH_UP_TO + 1):
    hmac.new(DRAFT_SECRET.encode(), f"user:{user_id}".encode(), hashlib.sha256).hexdigest()
print(f"a full table of {SEARCH_UP_TO:,} ids takes {time.perf_counter() - start:.2f} s on one core")
