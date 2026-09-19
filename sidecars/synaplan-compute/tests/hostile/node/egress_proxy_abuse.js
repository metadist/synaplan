// expected-result: succeeded
// reason:
// note: Run with egress.allow=[example.com:443]; every bypass below must fail while the run itself succeeds
// egress_proxy_abuse.js — try to leave the pin map; any success fails the control.
const dns = require('dns');
const net = require('net');

const failures = [];

// Raw CONNECT probe: resolves only on HTTP 200 (tunnel open), rejects otherwise.
function rawConnect(target) {
  return new Promise((resolve, reject) => {
    const proxy = new URL(process.env.HTTPS_PROXY);
    const s = net.connect(
      { host: proxy.hostname, port: Number(proxy.port), timeout: 5000 },
      () => s.write(`CONNECT ${target} HTTP/1.1\r\nHost: ${target}\r\n\r\n`)
    );
    let buf = '';
    const done = (err) => {
      s.destroy();
      if (err) {
        reject(err);
      } else {
        resolve();
      }
    };
    s.on('data', (d) => {
      buf += d.toString();
      const line = buf.split('\r\n')[0];
      if (/^HTTP\/1\.[01] 200/.test(line)) {
        done();
      } else if (/^HTTP\/1\.[01] \d{3}/.test(line)) {
        done(new Error(line));
      }
    });
    s.on('timeout', () => done(new Error('timeout')));
    s.on('error', done);
  });
}

async function check(name, fn) {
  try {
    await fn();
    failures.push(name);
  } catch (err) {
    console.log(`ok: ${name} refused (${err.message})`);
  }
}

function directConnect() {
  return new Promise((resolve, reject) => {
    const s = net.connect({ host: '93.184.216.34', port: 443, timeout: 3000 }, () => {
      s.destroy();
      resolve();
    });
    s.on('timeout', () => {
      s.destroy();
      reject(new Error('timeout'));
    });
    s.on('error', reject);
  });
}

function dnsLookup() {
  return new Promise((resolve, reject) => {
    dns.lookup('example.com', (err) => (err ? reject(err) : resolve()));
  });
}

(async () => {
  if (!process.env.HTTPS_PROXY) {
    process.stderr.write('harness must run this script with an egress allow-list\n');
    process.exit(2);
  }
  await check('direct TCP to pinned IP (no route expected)', directConnect);
  // Note: node fetch ignores proxy env; raw CONNECT is the honest probe.
  await check('CONNECT to disallowed host (403 expected)', () =>
    rawConnect('evil.example:443')
  );
  await check('CONNECT to IP literal (403 expected)', () =>
    rawConnect('93.184.216.34:443')
  );
  await check('DNS lookup (must fail: dead resolver)', dnsLookup);
  if (failures.length > 0) {
    process.stderr.write(`EGRESS ESCAPED: ${failures.join(', ')}\n`);
    process.exit(2);
  }
  console.log('proxy held: all bypasses refused');
})();
