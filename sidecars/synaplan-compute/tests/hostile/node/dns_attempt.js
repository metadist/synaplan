# expected-result: succeeded
# reason:
# note: Name resolution fails; no packet leaves
"""dns_attempt.js — resolve example.com; control success is DNS failure."""
const dns = require('dns');
dns.setServers([]);
dns.lookup('example.com', { timeout: 2000 }, (err) => {
  if (err) {
    process.exit(0);
  }
  process.stderr.write('dns unexpectedly succeeded\n');
  process.exit(2);
});
