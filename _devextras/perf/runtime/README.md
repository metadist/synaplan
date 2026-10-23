# Runtime benchmarks

`make perf-runtime` runs this directory against the dev stack. The scripts
here were the 2026-10-21 spike (`spike/runtime/`); this is the copy the
target maintains.

The run refuses to start unless `synaplan-backend` runs `APP_ENV=dev` and no
other run is active. It warms a prod cache, starts a second FrankenPHP in
worker mode on `:8090`, creates a `perf-runtime` API key for the seeded admin,
measures, then always stops that process, deletes the `var/cache/prod` it
warmed, revokes the key, and logs the admin session out. It does not call a paid provider unless `make perf-runtime ARGS=--with-providers`.

`test-model` has to be an active chat model. If it is not, the gateway would
fall through to the account default, so the target refuses to send a chat
request. `app:perf:chat-stream` runs only when that account's chat model is
the test provider, for the same reason.

The dev stack serves whatever checkout it has mounted. The delay class has
to be in that tree or the worker cannot stretch `test-model`.

## Database connections held by streams

`db_connections.sh [streams]` (default 8) starts the same worker-mode
FrankenPHP with 8 workers, streams that many slow in-app chat turns as the
demo user through `stream_turns.py`, and counts the MariaDB connections from
the backend's address before, during and three seconds after the streams
(`information_schema.PROCESSLIST`, once a second). It refuses to run unless
the demo user's chat model is the test provider, so no paid provider is
called, and it cleans up like `make perf-runtime`: the second FrankenPHP is
stopped and the `var/cache/prod` it warmed is removed. The chats it opens are
deleted at the end.
