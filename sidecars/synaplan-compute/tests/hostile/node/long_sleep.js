// expected-result: failed
// reason: timeout
// note: Killed at timeoutSec; no zombie container
// long_sleep.js — sleep longer than any T1 timeout.
setTimeout(() => {}, 3600 * 1000);
