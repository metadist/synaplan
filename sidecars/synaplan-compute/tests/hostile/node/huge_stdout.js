# expected-result: succeeded
# reason:
# truncated-stdout: true
# note: Stream truncated server-side at the log cap; run finishes
"""huge_stdout.js — emit more than COMPUTE_LOG_CAP_BYTES of stdout."""
const MARKER = 'SYNAPLAN_HUGE_STDOUT_MARKER';
const chunk = (MARKER + '\n').repeat(64);
for (let i = 0; i < 4096; i++) {
  process.stdout.write(chunk);
}
