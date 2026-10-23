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
the backend's address (`information_schema.PROCESSLIST`): once after the
chats are opened, once a second while the turns stream, and three seconds
after the last one ended, before the chats are deleted.

No paid provider is called. Before it opens a chat, the script asks the
measured FrankenPHP which chat, sorting and memory models the demo user's
turns will use (`/api/v1/config/models/defaults`, the same resolution as
generation: group policies, allow-list, fallbacks) and refuses unless all
three are the test provider and no topic prompt answers with a model of its
own (`aiModel`) outside it; that FrankenPHP runs with an empty
`QDRANT_URL`, so no memory search embeds the question; and the run fails if
the usage ledger (`BUSELOG`) shows a call to any other provider
(`calls_to_other_providers`). It cleans up like `make perf-runtime`: the
second FrankenPHP is stopped, the `var/cache/prod` it warmed is removed, and
every chat it opened is deleted, also after a run that failed half-way.
