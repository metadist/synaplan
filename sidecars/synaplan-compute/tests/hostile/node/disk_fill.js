// expected-result: failed
// reason: output_limit
// note: Stops at the fsize ulimit / outputMb cap; host disk unchanged
// disk_fill.js — write until ENOSPC, EFBIG, or the output cap.
const fs = require('fs');
const chunk = Buffer.alloc(1024 * 1024, 65);
let written = 0;
try {
  const fd = fs.openSync('/work/fill.bin', 'w');
  while (written <= 512 * 1024 * 1024) {
    fs.writeSync(fd, chunk);
    written += chunk.length;
  }
  fs.closeSync(fd);
} catch (e) {
  process.stderr.write('stopped: ' + e.message + '\n');
  process.exit(1);
}
process.stderr.write('filled without hitting a cap\n');
process.exit(1);
