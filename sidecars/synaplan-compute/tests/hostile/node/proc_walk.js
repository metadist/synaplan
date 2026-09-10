# expected-result: succeeded
# reason:
# note: /proc shows only the run's own processes; no host PIDs
"""proc_walk.js — walk /proc; exit 2 if the host leaked in."""
const fs = require('fs');
const pids = fs.readdirSync('/proc').filter((n) => /^\d+$/.test(n));
if (pids.length > 64) {
  process.stderr.write('too many pids: ' + pids.length + '\n');
  process.exit(2);
}
try {
  fs.readFileSync('/proc/1/environ');
} catch (e) {
  process.exit(0);
}
process.exit(0);
