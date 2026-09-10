// expected-result: succeeded
// reason:
// note: Symlink from /out to /etc/passwd is not followed on artefact pull
// symlink_out.js — plant a symlink in /out that must not be pulled.
const fs = require('fs');
try {
  fs.symlinkSync('/etc/passwd', '/out/passwd');
} catch (e) {
  // The link may already exist from a previous attempt; that is fine.
}
fs.writeFileSync('/out/ok.txt', 'ok\n');
