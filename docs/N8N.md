# n8n and Saved Tasks

Synaplan does not embed n8n. Another system starts a Saved Task, or a Saved Task
sends a result out. Both use ordinary HTTPS.

## Another system starts a Saved Task

1. On the Saved Task card, choose **Let another system start this**.
2. Copy the address. Optional: turn on **Require a signature** — the shared
   secret is shown **once**, right then. Copy it into the other system; turn
   the signature off and on again if you need a new one.
3. In n8n, add an **HTTP Request** node that `POST`s JSON to that address.

When a shared secret is set, send:

`X-Synaplan-Signature: sha256=<hex>`

over the raw request body (HMAC-SHA256). Unknown or disabled tasks return the
same 404. The JSON body becomes the run’s starting event: a step input
`{ "from": "trigger", "field": "order.id" }` reads a part of it, and an empty
`field` passes the whole body. Bodies are limited to 64 KiB, and each task
accepts at most 60 starts per minute.

The call returns `202` as soon as the event is accepted; the run itself is
queued and executes in the background. Anything the run produces arrives
through the task's own steps (an outbound webhook, an email, a saved file) —
not in this response.

## A Saved Task sends a result to n8n

Add a **Send to another system** step. The POST is HTTPS only, no redirects,
no retries. Body shape:

```json
{ "task": 1, "run": 2, "step": "step_2", "result": { "result": "…" } }
```

`result` holds what you mapped under **What to send** (by default the text of
the previous step). With no mapping at all it carries the text and details of
every step this one follows, keyed by step id.

When the step has a secret, the same `X-Synaplan-Signature` header is sent.
The secret is stored on the server and never shown again after you type it;
the editor only tells you that one is set.
n8n can receive that with a Webhook node, do its work, then call back to the
Saved Task inbound address if another run should start.
