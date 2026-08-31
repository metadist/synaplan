#!/usr/bin/env bash
# DS17 — stand-in for the real Synaplan Desktop client.
#
# Pairs a fake computer, then drives the MCP check-in loop end to end AND every
# refusal path the contract promises (11_security_and_compatibility.md). The
# same assertions are gated in PHPUnit (DesktopMcpCheckinTest,
# DesktopControllerTest); this script is the human-runnable acceptance demo and
# the reference implementation for the client team.
#
# Refusal / safety paths exercised here:
#   * hostile input.command never reaches the device payload
#   * a refused skill is a normal "failed" (errorCode), not a transport error
#   * a stale/wrong lease token is a clean tool error
#   * an oversized result is rejected
#   * a job targeted at another device is not leased by this one
#   * an unknown device id is 404
#   * with the flag OFF: enqueue 404s and the agent tools disappear
#
# Usage: ./_devextras/testing/desktop/fake-device.sh
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

require_tools

echo "== Synaplan Desktop fake client =="
echo "BASE=$BASE  EMAIL=$EMAIL"
echo

enable_flag
login
CODE="$(mint_code)"
pair_device "$CODE" "Fake device (primary)"
PRIMARY_KEY="$DESKTOP_KEY"
PRIMARY_DEVICE_ID="$DEVICE_ID"
echo "NOTE  Paired primary device #$PRIMARY_DEVICE_ID."

# --- 1. The two agent tools appear only for a paired, desktop-scoped key ------
mcp_init
mapfile -t TOOLS < <(mcp_tool_names)
tool_present() { printf '%s\n' "${TOOLS[@]}" | grep -qx "$1"; }
assert "agent_checkin tool is offered"         "tool_present agent_checkin"
assert "agent_report_result tool is offered"   "tool_present agent_report_result"
assert "base tools remain (superset, not swap)" "tool_present synaplan_chat"

# --- 2. Idle check-in returns no work and a next_call_at ----------------------
# jq extractions ALWAYS land in scalar vars first; never pass a JSON blob to
# assert (its quotes would break the eval). See lib.sh assert()/assert_eq().
IDLE="$(mcp_call agent_checkin '{"protocol":1,"capabilities":["skill.run"],"enabledSkills":["hello-files"],"status":"idle"}')"
IDLE_PROTO="$(jq -r '.result.structuredContent.protocol' <<<"$IDLE")"
IDLE_JOBS="$(jq -r '.result.structuredContent.jobs | length' <<<"$IDLE")"
IDLE_NEXT="$(jq -r '.result.structuredContent.next_call_at' <<<"$IDLE")"
assert_eq "idle check-in speaks protocol 1" "$IDLE_PROTO" "1"
assert_eq "idle check-in hands back 0 jobs" "$IDLE_JOBS" "0"
assert "idle check-in sets next_call_at"    "[[ \"$IDLE_NEXT\" =~ ^[0-9]+$ ]]"

# --- 3. Happy path: enqueue (with a hostile extra key) → lease → succeed ------
# The web caller sneaks input.command; the server must strip it so the device
# only ever sees {skill, prompt, fileIds}.
STATUS="$(enqueue_job_raw "{\"deviceId\":$PRIMARY_DEVICE_ID,\"type\":\"skill.run\",\"input\":{\"skill\":\"hello-files\",\"prompt\":\"say hi\",\"command\":\"rm -rf /\"}}")"
assert_eq "enqueue with hostile extra key still 201" "$STATUS" "201"
JOB_ID="$(jq -r '.jobId' /tmp/syn-enq.json)"

LEASE="$(mcp_call agent_checkin '{"protocol":1,"capabilities":["skill.run"],"enabledSkills":["hello-files"]}')"
LEASE_COUNT="$(jq -r '.result.structuredContent.jobs | length' <<<"$LEASE")"
LEASE_JOB_ID="$(jq -r '.result.structuredContent.jobs[0].jobId' <<<"$LEASE")"
LEASE_KEYS="$(jq -c '.result.structuredContent.jobs[0].input | keys' <<<"$LEASE")"
LEASE_HAS_CMD="$(jq -r '.result.structuredContent.jobs[0].input | has("command")' <<<"$LEASE")"
LEASE_TOKEN="$(jq -r '.result.structuredContent.jobs[0].leaseToken' <<<"$LEASE")"
assert_eq "check-in leases exactly 1 job" "$LEASE_COUNT" "1"
assert_eq "leased job is the enqueued one" "$LEASE_JOB_ID" "$JOB_ID"
assert_eq "device payload has ONLY skill/prompt/fileIds" "$LEASE_KEYS" '["fileIds","prompt","skill"]'
assert_eq "hostile input.command is stripped" "$LEASE_HAS_CMD" "false"
assert "a lease token was issued" "[[ \"$LEASE_TOKEN\" == lt_* ]]"

# --- 4. A second check-in while leased sees nothing (no double-hand-out) ------
AGAIN="$(mcp_call agent_checkin '{"protocol":1}')"
AGAIN_COUNT="$(jq -r '.result.structuredContent.jobs | length' <<<"$AGAIN")"
assert_eq "re-check-in while leased returns 0 jobs" "$AGAIN_COUNT" "0"

# --- 5. Report success → chat completion note (asserted via job status) -------
REPORT="$(mcp_call agent_report_result "{\"leaseToken\":\"$LEASE_TOKEN\",\"status\":\"succeeded\",\"result\":{\"summary\":\"done\",\"fileIds\":[]}}")"
REPORT_OK="$(jq -r '.result.structuredContent.success' <<<"$REPORT")"
assert_eq "report success returns success:true" "$REPORT_OK" "true"
get_job "$JOB_ID" >/dev/null
JOB_STATUS="$(jq -r '.job.status' /tmp/syn-job.json)"
assert_eq "job is now succeeded" "$JOB_STATUS" "succeeded"

# --- 6. Refusal: a stale/wrong lease token is a clean tool error --------------
BADTOK="$(mcp_call agent_report_result '{"leaseToken":"lt_does_not_exist","status":"succeeded"}')"
BADTOK_ERR="$(jq -r '.result.isError' <<<"$BADTOK")"
assert_eq "stale lease token → isError" "$BADTOK_ERR" "true"

# --- 7. Refusal: an unknown skill is a normal 'failed', not a transport error -
enqueue_job "$PRIMARY_DEVICE_ID" "definitely-not-installed" "try it" >/dev/null
REFUSE_JOB_ID="$(jq -r '.jobId' /tmp/syn-enq.json)"
REFUSE_LEASE="$(mcp_call agent_checkin '{"protocol":1}')"
REFUSE_TOKEN="$(jq -r '.result.structuredContent.jobs[0].leaseToken' <<<"$REFUSE_LEASE")"
REFUSE_REPORT="$(mcp_call agent_report_result "{\"leaseToken\":\"$REFUSE_TOKEN\",\"status\":\"failed\",\"errorCode\":\"unknown_skill\"}")"
REFUSE_OK="$(jq -r '.result.structuredContent.success' <<<"$REFUSE_REPORT")"
assert_eq "refusal report succeeds transport-wise" "$REFUSE_OK" "true"
get_job "$REFUSE_JOB_ID" >/dev/null
REFUSE_STATUS="$(jq -r '.job.status' /tmp/syn-job.json)"
REFUSE_CODE="$(jq -r '.job.errorCode' /tmp/syn-job.json)"
assert_eq "refused job is failed" "$REFUSE_STATUS" "failed"
assert_eq "refused job records errorCode" "$REFUSE_CODE" "unknown_skill"

# --- 8. Refusal: an oversized result is rejected ------------------------------
enqueue_job "$PRIMARY_DEVICE_ID" "hello-files" "big one" >/dev/null
BIG_LEASE="$(mcp_call agent_checkin '{"protocol":1}')"
BIG_TOKEN="$(jq -r '.result.structuredContent.jobs[0].leaseToken' <<<"$BIG_LEASE")"
BIG_BLOB="$(head -c 70000 /dev/zero | tr '\0' 'x')"
BIG_ARGS="$(jq -nc --arg t "$BIG_TOKEN" --arg b "$BIG_BLOB" '{leaseToken:$t,status:"succeeded",result:{summary:$b}}')"
BIG_REPORT="$(mcp_call agent_report_result "$BIG_ARGS")"
BIG_ERR="$(jq -r '.result.isError' <<<"$BIG_REPORT")"
assert_eq "oversized result → isError" "$BIG_ERR" "true"
# The lease survives the rejection, so the device can retry with a small result.
mcp_call agent_report_result "{\"leaseToken\":\"$BIG_TOKEN\",\"status\":\"failed\",\"errorCode\":\"local_error\"}" >/dev/null

# --- 9. A job targeted at another device is not leased by this one ------------
CODE2="$(mint_code)"
pair_device "$CODE2" "Fake device (secondary)"
SECOND_KEY="$DESKTOP_KEY"
enqueue_job "$PRIMARY_DEVICE_ID" "hello-files" "primary only" >/dev/null
TARGETED_JOB_ID="$(jq -r '.jobId' /tmp/syn-enq.json)"
# Check in AS the secondary device.
DESKTOP_KEY="$SECOND_KEY"
mcp_init
SECOND_CHECKIN="$(mcp_call agent_checkin '{"protocol":1}')"
SECOND_COUNT="$(jq -r '.result.structuredContent.jobs | length' <<<"$SECOND_CHECKIN")"
assert_eq "secondary device does not lease primary's job" "$SECOND_COUNT" "0"
# Primary reclaims and drains it so the queue is left clean.
DESKTOP_KEY="$PRIMARY_KEY"
mcp_init
PRIMARY_DRAIN="$(mcp_call agent_checkin '{"protocol":1}')"
DRAIN_JOB_ID="$(jq -r '.result.structuredContent.jobs[0].jobId' <<<"$PRIMARY_DRAIN")"
DRAIN_TOKEN="$(jq -r '.result.structuredContent.jobs[0].leaseToken' <<<"$PRIMARY_DRAIN")"
assert_eq "primary can still lease its own targeted job" "$DRAIN_JOB_ID" "$TARGETED_JOB_ID"
[[ "$DRAIN_TOKEN" == lt_* ]] && mcp_call agent_report_result "{\"leaseToken\":\"$DRAIN_TOKEN\",\"status\":\"succeeded\"}" >/dev/null

# --- 10. Enqueue for an unknown device id → 404 ------------------------------
UNKNOWN_STATUS="$(enqueue_job 999999999 "hello-files" "nowhere" || true)"
assert_eq "enqueue to an unknown device is 404" "$UNKNOWN_STATUS" "404"

# --- 11. Flag OFF: enqueue 404s and the agent tools disappear ----------------
if command -v docker >/dev/null 2>&1 && [[ "${SYNAPLAN_SKIP_FLAG:-0}" != "1" ]]; then
  docker compose exec -T db mariadb -usynaplan_user -psynaplan_password synaplan \
    -e "UPDATE BCONFIG SET BVALUE='0' WHERE BGROUP='DESKTOP_AGENT' AND BSETTING='ENABLED';" >/dev/null 2>&1 || true
  OFF_STATUS="$(enqueue_job "$PRIMARY_DEVICE_ID" "hello-files" "flag off" || true)"
  assert_eq "enqueue with flag OFF is 404" "$OFF_STATUS" "404"
  DESKTOP_KEY="$PRIMARY_KEY"
  mcp_init
  mapfile -t OFF_TOOLS < <(mcp_tool_names)
  off_tool_present() { printf '%s\n' "${OFF_TOOLS[@]}" | grep -qx "$1"; }
  assert "agent_checkin gone with flag OFF"       "! off_tool_present agent_checkin"
  assert "agent_report_result gone with flag OFF" "! off_tool_present agent_report_result"
  # Restore the flag so the harness leaves the instance usable.
  docker compose exec -T db mariadb -usynaplan_user -psynaplan_password synaplan \
    -e "UPDATE BCONFIG SET BVALUE='1' WHERE BGROUP='DESKTOP_AGENT' AND BSETTING='ENABLED';" >/dev/null 2>&1 || true
else
  echo "NOTE  Skipping flag-off checks (no docker or SYNAPLAN_SKIP_FLAG=1)."
fi

echo
summary
