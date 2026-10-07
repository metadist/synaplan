<!-- title: Custom tools: Try it shows only the status and "fields: []", never the response; OpenAPI import drops the description and classes a read-only POST as "Changes something" -->
<!-- type: Bug -->
<!-- labels: prio:2, area:admin -->
<!-- issue-type: Bug -->

## Problem
The Try it panel shows only the HTTP status, "Request finished" and `fields: []`, never the response body; when early versions of a tool got HTTP 400 and 502 there was no way to see why, and when a call worked the admin could not see what the model received. Import from OpenAPI classed a read-only POST as "Changes something" (so every call would need approval) and kept only the short `summary`, dropping the `description` the model uses to decide when to call the tool.

---

## Expected
Try it shows the resolved request (method, URL, headers with the credential masked, body) and the full response (status, headers, body — pretty-printed JSON, size-capped with "show more"). The OpenAPI import keeps `description` (falls back to `summary`), shows the guessed risk class per operation and lets the admin change it before importing.

## Actual
1. Try it output: status, "Request finished", `fields: []`.
2. 400 / 502 from the API: no body, no reason.
3. Import: POST → "Changes something"; description lost.

---

## Steps to reproduce
1. Create a tool pointing at any JSON API; Try it.
2. Point it at a URL that returns 400; Try it.
3. Import an OpenAPI 3 document with a read-only POST (e.g. a search endpoint) and a long `description`.

---

## Notes
- Findings: F43 (4) and (5) — community test round on 5.2.0. The chat-step label and the per-step input / output view are in the task-step detail issue.
- Verified in code: `frontend/src/components/config/CustomToolTryPanel.vue` prints `JSON.stringify(result)` of the `customToolsApi.try()` response, which carries only the mapped `fields` — the raw response is not returned by the backend. `backend/src/Service/Tool/Custom/OpenApiImporter.php`: `'summary' => $operation['summary'] ?? $operationId` (no `description`), `guessClass()` maps GET / HEAD → `read`, everything else → `write`.

Fix direction: the try endpoint returns `{request: {method, url, headers(masked), body}, response: {status, headers, body(capped)}, fields}`; the panel renders request and response in two collapsible blocks with Copy; errors from the API show status + body; the importer carries `description` and `summary`, the wizard shows a risk-class select per operation prefilled with the guess (and a hint that `x-synaplan-side-effect` or the OpenAPI `x-safe` style extensions, if present, override the guess); OpenAPI annotations updated → `make -C frontend generate-schemas`.

Journey (U10): Try it on a 400 → body shows the API's error text → fix the template → Try it → 200 and JSON body → import an OpenAPI doc → set the search POST to "Reads only" → in chat the call runs without approval.

Verification:
1. Try it shows request and response for 200, 400 and 502.
2. Imported tool has the full description; a read-only POST imported as `read` runs without an approval card.
3. Vitest for the wizard risk-class select; PHPUnit for the importer carrying `description`.

---

## Screenshots/Logs
Try it output (5.2.0): `status`, "Request finished", `fields: []`.
