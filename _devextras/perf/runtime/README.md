# Runtime benchmarks

`make perf-runtime` runs this directory against the dev stack. The scripts
here were the 2026-10-21 spike (`spike/runtime/`); this is the copy the
target maintains.

The run warms a prod cache, starts a second FrankenPHP in worker mode on
`:8090`, creates a `perf-runtime` API key for the seeded admin, measures,
then always stops that process, deletes `var/cache/prod`, and revokes the
key. It does not call a paid provider unless `make perf-runtime ARGS=--with-providers`.

`test-model` has to be an active chat model. If it is not, the gateway would
fall through to the account default, so the target refuses to send a chat
request. `app:perf:chat-stream` runs only when that account's chat model is
the test provider, for the same reason.

The dev stack serves whatever checkout it has mounted. The delay class has
to be in that tree or the worker cannot stretch `test-model`.
