# Wireframe — Compute run card (track 5 B1)

Journey **J-CP-1**. Lives in the chat thread. No new page.

Banned in primary copy: sandbox, container, gVisor, stdout, sidecar.

## Running

```text
┌─ Working with your file ──────────────────────────────────────────┐
│ Drawing a bar chart from revenue.csv…                             │
│ ░░░░░░░░░░░░░░░░░░░░  8s                                          │
│ ▸ Details                                                         │
└───────────────────────────────────────────────────────────────────┘
```

## Done

```text
┌─ Result files ────────────────────────────────────────────────────┐
│ revenue-by-region.png                          [ Preview ]        │
│                                                                   │
│ ▸ Details · 12s                                                   │
│ [ Re-run with changes ]                                           │
└───────────────────────────────────────────────────────────────────┘
```

Preview opens the existing file viewer. **Re-run with changes**
expands a textarea with the last script (helper: "The AI will try
again with your notes") — never labelled "source" or "main.py" in
primary copy.

## Quota / refusal

```text
┌─ Could not finish ────────────────────────────────────────────────┐
│ You have used this week's file-work limit. Try again later,       │
│ or ask an administrator. Nothing new was saved.                   │
└───────────────────────────────────────────────────────────────────┘
```

Flag off / URL unset: this card is never offered; the assistant
does not mention file-work as a capability.

## Workspace (B3 only)

A secondary **Open folder** on the done card, visible when
`COMPUTE.WORKSPACES_ENABLED` is on. Opens Files on that folder.
Empty: "Files the AI creates for you will show up here."
