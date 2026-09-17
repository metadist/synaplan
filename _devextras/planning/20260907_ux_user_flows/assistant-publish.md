# Wireframe — Publish an assistant (track 2 S3)

Journey **J-AB-2**. Assumes IAM-UX Share dialog. Form-first builder;
Publish is the last section, not a separate app.

## Gallery (Manage → Assistants)

```text
┌─ Assistants ──────────────────────────────── [ Create assistant ]─┐
│ [ Mine ]  [ Shared with me ]  [ Archived ]     [ Search… ]        │
│                                                                   │
│ ┌─ Contract review ──────────────┐  ┌─ Shared: Tone of voice ───┐ │
│ │ Published · v2                 │  │ From Ada · v1             │ │
│ │ Reviews supplier contracts.    │  │                           │ │
│ │ [ Start chat ] [ Edit ]        │  │ [ Start chat ] [ Clone ]  │ │
│ └────────────────────────────────┘  └───────────────────────────┘ │
└───────────────────────────────────────────────────────────────────┘
```

Empty (Mine): "An assistant is a saved recipe for the AI — instructions,
model and files. Create one to start." + **Create assistant**.
Empty (Shared with me): "Nothing has been shared with you yet." No
Create button on that chip.

## Builder — Publish section (owner / Can edit)

```text
┌─ Publish ─────────────────────────────────────────────────────────┐
│ People always talk to the published version. Your edits stay      │
│ private until you publish.                                        │
│                                                                   │
│ Latest published: v2 · 3 Sep · “Clearer refusal when no clause.”  │
│ Draft has unpublished changes.                                    │
│                                                                   │
│ What changed?                                                     │
│ [ Added the indemnity checklist…                              ]   │
│                                                                   │
│ [ Publish v3 ]     [ Share ]                                      │
│                                                                   │
│ Shared with: Legal (Can use)                                      │
│ Who uses this (counts only): 12 people · 840 messages this month  │
└───────────────────────────────────────────────────────────────────┘
```

**Publish** confirms via `useDialog()`: "Legal will get this on their
next message. Your test chat is unchanged." **Share** opens Share
dialog v2 (assistant kind). Usage never lists names.

## Chat — talking to an assistant

Composer pill: **Talking to Contract review** (dismiss = leave the
assistant, do not delete the chat). Archived: pill + badge
"This assistant is archived — you can finish this chat."
Start chat on an archived card is disabled.

## Test panel (S2, still visible after publish)

Side panel titled **Try a draft**. Helper: "Only you see this. It is
not saved in History." No Share control here.
