# Wireframe — Share dialog v2 (IAM-UX)

Replaces the S2 ASCII box. Binding for `IAM-UX1`. Same component for
every kind; only the consequence sentence and the permission set change.

Banned in primary copy: ACL, tenant, principal, grant, RBAC, claim, SCIM.

## Closed card (entry)

The **Share** action stays on the resource (chat header, folder menu,
assistant Publish section, task card, widget, custom tool). It is a
house button (`btn-secondary` or a menu item), never a raw icon without
a label on first use.

## Dialog — conversation (kind = `conversation`)

```text
┌─────────────────────────────────────────────────────────────┐
│ Share “Q3 playbook”                                      [×]│
│ Owner · Ada                                                 │
│                                                             │
│ [ Search a person or group…        ] [ Can use ▾ ] [ Share ]│
│                                                             │
│  Can use — they can continue this chat as their own copy.   │
│  They will find it under Incoming chats.                    │
│                                                             │
│ Shared with                                                 │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ ● Sales          group     [ Can use      ▾ ] Remove  │  │
│  │ ○ Everyone in this organization                       │  │
│  │                          [ Can view     ▾ ] Remove    │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                             │
│ Public link                                                 │
│  Anyone with the link can view.  [ Manage public link ]     │
└─────────────────────────────────────────────────────────────┘
```

## Dialog — assistant (kind = `assistant`, after IAM-UX)

```text
┌─────────────────────────────────────────────────────────────┐
│ Share “Contract review”                                  [×]│
│ Owner · Ada · Published v2                                  │
│                                                             │
│ [ Search a person or group…        ] [ Can use ▾ ] [ Share ]│
│                                                             │
│  Can use — they can start a chat with this assistant.       │
│  They will find it under Assistants → Shared with me.       │
│                                                             │
│ Shared with                                                 │
│  ● Legal            group     [ Can use      ▾ ] Remove     │
└─────────────────────────────────────────────────────────────┘
```

Drafts are not shareable — the Share action is disabled with
"Publish this assistant first."

## States (each needs copy in five locales + a component test)

| State | What the dialog shows |
| ----- | --------------------- |
| Empty shares | "Only you can see this." + the add row |
| Search, no matches | "No person or group matches." Never a blank list |
| Everyone hidden | When `IAM.EVERYONE_SHARES` refuses the actor, the pinned row is absent — no disabled tease |
| Load failed | i18n error + **Try again**, not an empty list pretending to be truth |
| Flag off | Dialog is not mounted |
| 320 px | Add row stacks: search, permission, Share as full-width buttons; list remains one person per row |

## Permission menu (kind-specific, U3)

The dropdown label is the short word (Can view / Can use / …). The
open menu shows the short word **and** the one-line consequence from
the §4.1 table in `202609_ux_user_flows.md`. Changing permission in
the list saves immediately (with undo via the previous value if the
request fails).

## What this is not

- Not a second public-link modal reinvented here — **Manage public
  link** opens the existing `ChatShareModal` / file share flow.
- Not an email notification in v1.
- Not a people-picker that lists every user on focus with no query —
  type-to-search, with groups and (when allowed) Everyone pinned
  above results when the query is empty.
