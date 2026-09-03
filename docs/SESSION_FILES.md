# Session Files & Follow-up Edits

How Synaplan keeps the files of a conversation "in hand" so a follow-up like
*"make the car blue"* edits the picture that is already there instead of drawing
a new one.

## The problem this solves

A generated image is linked to its originating message through `BFILES.BMESSAGEID`
only — it never hangs off the message relation the way an upload does. Anything
that looked at the current message alone therefore saw no file at all, so a
follow-up request was treated as a brand new generation. Documents had the same
gap in a milder form.

## The single resolver

`App\Service\File\ConversationFileCatalog` is the one place that answers *"which
files does this conversation have?"*. It walks all three storage channels once:

| Channel | What it holds |
| ------- | ------------- |
| Message attachments | what the user attached to the message being answered |
| `BFILES` rows by message id | everything generated or uploaded earlier in the thread |
| `BFILEPATH` on a message | legacy generated media from installs that predate the file registrar |

Every entry is validated against the upload root, deduplicated, categorised
(image / document / audio / video), and handed out as a `ConversationFile` with
a stable reference (`file:123`, `attached:1`, `path:…`). Budgets are **per
category**, so a burst of generated documents can never push the picture the
user is talking about out of the catalog.

**Add no fourth resolver.** `DocumentImageCatalog` (document embedding) and
`MediaGenerationHandler` (image edits) both delegate here; new consumers should
too.

## How a follow-up edit is routed

1. `MessageSorter` annotates history turns that carry a file
   (`[Generated image file: car.png]`), so the AI sorter can tell an edit from a
   new request and votes `BINPUTMODE=reference_images`.
2. `MessageClassifier` passes that vote through as `input_mode` and defers the
   fast-path heuristic whenever the previous turn generated media.
3. `MediaGenerationHandler` resolves the newest image of the conversation from
   the catalog when the turn carries no attachment, attaches it as the reference
   image, and switches to the `PIC2PIC` model.
4. The `mediamaker` prompt is told it is editing an existing picture, so it
   writes only the requested change instead of re-describing a whole scene.

The UI shows an "Editing <filename>" status while this runs.

## Generated images in vision chat

"Draw a cat" → "what is in it?" needs the model to *see* its own output
(#1596). When the selected chat model is vision-capable, the newest generated
image of the conversation (at most
`GeneratedImageVisionFlag::MAX_GENERATED_IMAGES`, currently 1) is attached to
the current user turn — Anthropic rejects image blocks on assistant turns. A
chat model without vision is not swapped for a vision model just because
history contains a generated picture; only a user attachment on the current
turn triggers that fallback.

The switch defaults to **on**. Each included image rides along as a base64
payload on later requests of the conversation, so an operator can turn it off
(the upsert also creates the row if it is missing):

```sql
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, 'FILE_CONTEXT', 'VISION_INCLUDE_GENERATED', '0')
ON DUPLICATE KEY UPDATE BVALUE = '0';
```
