# expected-result: failed
# reason: pids_limit
# note: Killed by pids-limit; host load unchanged
"""fork_bomb.js — spawn processes until the cgroup pids limit kills the run."""
const { fork } = require('child_process');
function boom() {
  while (true) {
    fork(__filename);
  }
}
boom();
