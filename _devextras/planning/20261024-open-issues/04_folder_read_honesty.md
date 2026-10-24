# 04 — Do not claim a folder was read

**Issue:** [#2667](https://github.com/metadist/synaplan/issues/2667).
**Commit:** `fix(chat): say when a connected folder was not read`
**Class:** `ota-candidate` if the sentence is a new UI string;
`backend-only` if it is only the model instruction and the plan.
Confirm with `mobile-impact.mjs` before pushing.
**Branch:** `fix/20261024-folder-read-honesty`

## Problem

A request that names a connected folder and asks for the files' content
often gets a plan with one `chat` step. No `folder_fetch` runs. The chat
model then answers as if it had read the files: invented vendors, dates
and totals, or "I'm fetching the three invoice files … now" and nothing
follows. The names came from memories and the conversation catalogue.

Two failures, one issue:

1. The planner does not emit `folder_fetch` although the folder is in
   its channel list (`PlannerChannelCatalog` adds the capability).
2. A chat answer may present file content as read when no step read a
   file (UX contract U8).

The real rows in the report: Nordwind GmbH 2026-10-02 1,240.00 EUR;
Ostsee Logistik 2026-10-09 386.50 EUR; Nordwind GmbH 2026-10-17
2,015.75 EUR. The invented rows were Acme Supplies, Beta Logistics,
Gamma Tech.

## Goal

A request to work on files in a connected folder either reads that
folder or says, in one sentence, that it did not and why. A chat answer
does not present invented file content as read from a file.

## User-flow

Pointer: the chat the person is already in, plus the plan card.
Journey: **J-OI-4**.

1. Account `demo@synaplan.com`, a connected folder that has files (the
   dev Nextcloud folder `Invoices` if it is up).
2. Ask for one table of a field that is only inside those files.
3. Either the plan has a `folder_fetch` and the table matches the
   files, or the answer's first sentence says the folder was not read
   and the table is absent.
4. A chat with no connected folder is unchanged.

Exit (UX contract §6):

1. J-OI-4 walked on the dev stack when the folder is connected. If it
   is not, STATUS says so and the unit test is the proof for this pull
   request. Do not invent the walk.
2. The plan card is where the person sees that a read happened. The
   refusal sentence is in the answer itself.
3. One sentence, five locales if the sentence is a fixed string. If the
   model writes it, the instruction that forces it is the change, and
   the test locks the instruction, not a translation.
4. No folder, no files, and a failed read each end in a sentence. No
   silent skip. No flag.
5. The plan card already exists. Check the refusal sentence in light
   and dark.

## Change (keep it to this)

- Planner: when the user text names a connected folder that is in the
  channel list and asks for file content, the plan includes
  `folder_fetch` for that folder. Look at the planner prompt and
  `Capability::FolderFetch` before adding a new heuristic. Prefer the
  prompt if a fixture already shows the model can emit the step.
- Answer: the chat step's instructions include the list of files this
  turn actually received. When that list is empty, the instruction
  forbids claiming a read and requires one sentence that no file was
  read. Do not add a second model call to police the answer.
- Do not hide memories. The bug is the claim, not the memory.

A second bug found while doing this (for example a wrong folder id) is
recorded in STATUS and left alone.

## Test

A planner fixture: the channel list contains the folder, the user text
asks for the files' contents, the plan must include `folder_fetch`.
Red on current `main` if the fixture's plan is a lone `chat` step.

A second test on the chat-step instruction builder: with no file body
attached, the instruction contains the refusal requirement; with a file
body attached, it does not.

Name the classes in the test file after reading them. Do not add a
retry or a second plan pass.

## Gate

`make ci-local` and `make test-e2e` (the chat surface). Re-record a
routing snapshot only if this sprint changes the classifier contract,
and review the diff.
