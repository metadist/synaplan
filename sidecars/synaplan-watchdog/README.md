# synaplan-watchdog

One process per web server. Every 5 seconds it reads FrankenPHP's admin
API, the cgroup, and `/proc`, and writes this server's capacity to Redis.
It listens on `:8098` for liveness, readiness, and Prometheus metrics.
It does not relay client connections and it does not read chat.

```bash
make test
```

`SYNAPLAN_WATCHDOG=0` keeps the web role from starting it. Redis comes from
`REDIS_URL`, or from `REDIS_DSN` when that is unset. The server id is
`SYNAPLAN_SERVER_ID`, or the hostname.
