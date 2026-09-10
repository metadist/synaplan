// expected-result: succeeded
// reason:
// note: setuid(0) is refused; no-new-privileges holds
// setuid.js — attempt to become root via process.setuid.
try {
  if (typeof process.setuid === 'function') {
    process.setuid(0);
    process.stderr.write('setuid(0) did not raise\n');
    process.exit(2);
  }
  process.exit(0);
} catch (e) {
  process.exit(0);
}
