# Sprint 2 — Routing Awareness

Branch: `feat/file-context-routing`
Answers: *"send them WITH related requests"* — before the handler can reuse a
file, the request must **route** as a file-related request. Today a short
"make it blue" can be fast-pathed to `general`, and even when the sorter runs,
it has no way to know the assistant just produced an image.

Three surgical changes, all mirroring mechanisms that already exist for
documents (#1042):

## 1. Fast-path guard for generated media

`MessageClassifier::canFastPathClassify()`
(`backend/src/Service/Message/MessageClassifier.php` ~603) defers to the AI
sorter when the last assistant turn generated a *document* — keyed on the
`__FILE_GENERATED__:` text marker (~798–827). Generated images/videos never
carry that marker, so the guard misses them.

Add the media mirror of the two document cases:

- **(a)** `lastAssistantGeneratedMedia(array $conversationHistory): bool` —
  the most recent OUT turn carries a media file: `getFilePath()` with an
  image/video extension, or an attached `BFILES` row with `source=generated`.
  The very next turn is almost certainly about it → defer to the sorter,
  regardless of wording (exactly the document rationale).
- **(b)** `threadHasGeneratedMedia() && mentionsMediaReference($trimmed)` —
  media generated earlier in the thread and the current message references an
  image or its properties. `mentionsMediaReference()` is the media sibling of
  `mentionsDocumentReference()` (~837): nouns/attributes across the UI
  languages — `bild|image|picture|photo|foto|imagen|grafik|logo|hintergrund|
  background|farbe|color|colour|stil|style|resolution|auflösung` etc. False
  positive cost: one extra sorter call (the documented, accepted trade-off).

Implementation note: prefer resolving "last OUT turn has media" from the
`Message` entities already in `$conversationHistory` (`getFilePath()`,
`getFiles()`) — no extra query. The S1 catalog is NOT needed here; the guard
must stay allocation-cheap because it runs on every message.

## 2. Sorter sees a file inventory

`MessageSorter::classify()` history assembly (~192–212) renders OUT turns as
200-char text. Add one clipped annotation line per turn that carries files,
sourced from the message entities (uploads) and — for generated media — the
legacy path/type columns:

```
[assistant] Generated image file: car-sunset.png (message 12345)
[user] Uploaded file: contract.pdf
```

Cap: total inventory contribution ≤ ~600 chars (it rides inside the existing
history window, not on top).

### `tools:sort` prompt rule update (`PromptCatalog`)

Rule 9 today (~412): `BINPUTMODE=reference_images` only "if the user attached
image(s)". Extend:

> `reference_images` — if the user attached image(s), **or** the user asks to
> modify / edit / restyle / recolor an image that was generated or uploaded
> earlier in this conversation (see the "Generated image file" / "Uploaded
> file" history annotations). A follow-up like "make the car blue" directly
> after an image generation is an edit, not a new image.

Seeding: `PromptCatalog` change covers new installs; existing installs get the
updated prompt row via the established prompt-seeder update path (verify how
`tools:sort` rows are versioned/refreshed for operators before shipping; if
seeded-once, ship the explicit UPDATE migration per the `BCONFIG`-style rule in
`AGENTS.md`).

## 3. Propagate `input_mode` through the classifier

`MessageSorter::parseResponse()` already validates `BINPUTMODE` (~436–443) and
returns `input_mode`; `MessageClassifier::classify()` drops it (passthrough
ends at `resolution`, ~311–317). Add the identical passthrough:

```php
$inputMode = $result['input_mode'] ?? null;
if (is_string($inputMode) && '' !== $inputMode) {
    $classification['input_mode'] = $inputMode;
}
```

`TestProvider::detectMediaIntent()` (`backend/src/AI/Provider/TestProvider.php`
~452) already emits `input_mode` for edit phrasing — the mock is ahead of
production; tests can build on it directly.

## Steps

1. `lastAssistantGeneratedMedia()` + `threadHasGeneratedMedia()` +
   `mentionsMediaReference()` in `MessageClassifier`; wire into
   `canFastPathClassify()` next to the document guards.
2. File-inventory annotations in `MessageSorter` history assembly (clipped,
   capped).
3. `tools:sort` rule 9 extension in `PromptCatalog` + rollout path for
   existing installs.
4. `input_mode` passthrough in `MessageClassifier::classify()`.
5. Re-record routing characterization snapshots; review every changed line:

   ```bash
   docker compose exec -T -e UPDATE_ROUTING_SNAPSHOTS=1 backend \
     ./vendor/bin/phpunit tests/Characterization/RoutingCharacterizationTest.php
   git diff backend/tests/Characterization/__snapshots__/
   ```

## Fast-path reachability check (pre-commit trap from `AGENTS.md`)

`CLASSIFIER.FAST_PATH_ENABLED` defaults OFF (`isFastPathEnabled()`, ~559–573).
The guard change is still required (operators do enable it), but the sorter
prompt + `input_mode` passthrough are the production-effective part of this
sprint. State this explicitly in the PR description so the fix is not
"verified" only on a disabled path.

## Tests (sprint gate)

- `MessageClassifierTest` — fast-path: declines directly after an OUT turn
  with a generated image; declines on "ändere die farbe im bild" with media
  earlier in thread; still accepts plain smalltalk in a thread without files;
  document guards unchanged.
- `MessageSorterTest` — history assembly contains the generated-image
  annotation, clipped and capped; `input_mode` surfaced in the result array.
- `MessageClassifierTest` — classification array contains `input_mode` when
  the sorter provides it; absent otherwise (null-safety).
- Characterization snapshots re-recorded and reviewed.
- Full unfiltered gate.

## Explicitly out of scope (Sprint 3)

Consuming `input_mode`/the catalog in `MediaGenerationHandler` — after this
sprint the request routes correctly and carries the edit signal, but the
handler still generates fresh. That is intentional sequencing, not a bug: S2
alone must not change generation output (except via sorter topic choice).
