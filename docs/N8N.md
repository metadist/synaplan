# n8n and Saved Tasks

Synaplan does not embed n8n. Another system starts a Saved Task, or a Saved Task
sends a result out. Both use ordinary HTTPS.

## Another system starts a Saved Task

1. On the Saved Task card, choose **Let another system start this**.
2. Copy the address. Optional: turn on **Require a signature**.
3. In n8n, add an **HTTP Request** node that `POST`s JSON to that address.

When a shared secret is set, send:

`X-Synaplan-Signature: sha256=<hex>`

over the raw request body (HMAC-SHA256). Unknown or disabled tasks return the
same 404. The body becomes the run’s starting event (`from: trigger`).

## A Saved Task sends a result to n8n

Add a **Send to another system** step. The POST is HTTPS only, no redirects,
no retries. Body shape:

```json
{ "task": 1, "run": 2, "step": "step_2", "result": { } }
```

When the step has a secret, the same `X-Synaplan-Signature` header is sent.
n8n can receive that with a Webhook node, do its work, then call back to the
Saved Task inbound address if another run should start.
