# Status — assistant onboarding UX (row 44)

## 2026-11-06 — plan written from a live walk; no code yet

Walked J-AX1 ("daily open-source news") on web.synaplan.com with an
admin account, end to end: assistant created, instructions iterated
four times, schedule 07:00 Europe/Berlin saved and On, provider checked
on Operate → System configuration → Web search (Brave, key present but
"not verified" until the test query ran). Final test produced a correct
sourced digest (8 Phoronix items, dates checked, ≈ $0.02/run).

The working end state still stands on three crutches a normal user
cannot be expected to find: the literal `Websuche:` prefix in
"Ask it to", a hand-written fail-loud paragraph in the instructions,
and an unpublished draft whose schedule is nevertheless On. Findings
F1–F10 with file anchors are in `00_master_plan.md` §1.

Open: owner decisions D1–D3 (§0). Steps AX1–AX12 unstarted; AX10 may
slip per D3. This folder moves to `2027-archive/` when the last landed
step closes the acceptance journey in §3.

## 2026-11-06 — second review of the same page folded in

The owner brought an earlier review round of the assistant builder
(since 5.6.4; most of its points still open — three landed: test input
with Send, "No published versions yet", the Usage block). Its findings
are §1b (F11–F19) of the master plan, re-checked against today's
screenshots where possible (duplicate switches, Delete next to
Publish, empty test panel all confirmed). They land as new steps
AX13 (guided instructions + "Improve with AI"), AX14 (one switch list
with visible states) and AX15 (speaking empty states and names), plus
extensions of AX7 (starter chips in the empty test panel) and AX10
(single knowledge entry, jargon to Advanced, danger zone, sticky bar).
Nothing from either round is now only in a chat history.
