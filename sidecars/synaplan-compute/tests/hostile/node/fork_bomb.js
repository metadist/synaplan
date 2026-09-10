// expected-result: failed
// reason: program_error
// note: spawn fails with EAGAIN at the pids limit and the run exits 1; host load unchanged
// fork_bomb.js — spawn processes until the cgroup pids limit refuses more.
const { spawnSync } = require('child_process');
for (let i = 0; i < 100000; i++) {
  const r = spawnSync(process.execPath, ['-e', 'setTimeout(() => {}, 3600000)'], { stdio: 'ignore', detached: true });
  if (r.error) {
    process.stderr.write('spawn refused: ' + r.error.code + '\n');
    process.exit(1);
  }
}
process.stderr.write('pids limit never hit\n');
process.exit(1);
