<!-- title: Models: "Failed to list Ollama models: URI must include a scheme and host" is logged repeatedly on installs without an Ollama base URL -->
<!-- type: Bug -->
<!-- labels: prio:3, area:models -->
<!-- status: shipped -->
<!-- issue-type: Bug -->

## Problem
On an instance that does not use Ollama (no base URL configured), the backend log repeats `Failed to list Ollama models: URI must include a scheme and host` as an error. It is noise that hides real problems — the testers found it while diagnosing the large-upload crash.

---

## Expected
With no Ollama base URL, nothing tries to list Ollama models; the unused provider is simply reported as not configured. No error-level log line.

## Actual
`OllamaProvider::getAvailableModels()` is called even when `baseUrl` is empty; the client constructor accepts the empty URL and the request fails on every call, logged at `error`.

---

## Steps to reproduce
1. Deploy without `OLLAMA_BASE_URL`.
2. Open the model pages or run the model discovery / capability inventory.
3. Read the backend log.

---

## Notes
- Findings: F46 (unrelated noise paragraph) — community test round on 5.2.0.
- Verified in code: `backend/src/AI/Provider/OllamaProvider.php` — `getAvailableModels()` (~line 490) catches the exception and logs `error`; `isAvailable()` (~line 106) already returns false for an empty `baseUrl` with the comment that an empty `OLLAMA_BASE_URL` "is the normal state of a stock install, not a failure".

Fix direction: in `getAvailableModels()` return `[]` early when `!$this->isAvailable()` (no log, or `debug`); audit callers that list models for all providers so they skip unavailable ones.

Verification:
1. No `Failed to list Ollama models` lines in a stock-install log.
2. With a wrong base URL the error is still logged once per call (that is a real misconfiguration).

---

## Screenshots/Logs
`Failed to list Ollama models: URI must include a scheme and host` (repeated).
