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

## Generated images in vision chat (off by default)

"Draw a cat" → "what breed is it?" needs the model to *see* its own output. Only
user turns contribute image content by default, so this is behind a flag:

```sql
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
  VALUES (0, 'FILE_CONTEXT', 'VISION_INCLUDE_GENERATED', '1');
```

When enabled **and** the selected chat model is vision-capable, the newest
generated images of the conversation (at most
`GeneratedImageVisionFlag::MAX_GENERATED_IMAGES`) are attached to the assistant
turns that produced them. It defaults to off because each image rides along as a
base64 payload on every following request of the conversation.
