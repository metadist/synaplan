# Journeys and UI

For [`00_master_plan.md`](./00_master_plan.md). Binding:
[`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md). A journey is done when
it has been walked in the browser on the running stack, both themes, phone
width included.

## 1. Personas

| Name | Who | Wants |
| ---- | --- | ----- |
| **Ana** | Plans a new kitchen. Not technical. Owner of the project and the chat. | One place for everything about the kitchen; answers she can act on; no settings. |
| **Ben** | Ana's partner. Member. Has his own Synaplan account, his own Notion. | To write in Ana's chat without setting anything up; his notes stay his. |
| **Cem** | Runs Synaplan on his own server for a small office. Uses the store app. | The same app behaviour as on the cloud, with his data on his server. |

Whether phase C is built for Ana first (a household: GMX or Gmail, phone
first) or for a team (Microsoft 365, Nextcloud, desktop first) is open
(master plan §0 #111). The journeys below use Ana; J-PJ-15 and J-PJ-19 are
written so that both work.

## 2. Journeys

Every journey names where the thing is **found**, how it is **undone**, and
what it **must survive**.

### J-PJ-1 — Create a project and fill it

1. Chats panel → "Projects" → "New project". Ana types "New kitchen". The
   project page opens. No other field is required.
2. She drops the floor plan (PNG), two offers (PDF), pastes three links and
   one note. Each row shows "Reading…", then "Ready". A link that cannot be
   fetched shows "Could not be read" with the reason and "Try again".
3. "New chat" in the project. The composer says which project it is in.
4. "Which offer fits the floor plan?" The answer cites the two PDFs and the
   plan by name.

- **Found:** Chats panel, section "Projects"; project page `/projects/:id`.
- **Undo:** delete a source (confirm); delete the project (confirm; its
  chats move to plain chats or are deleted with it — the dialog asks).
- **Must survive:** reload during "Reading…"; a 60 MB PDF; a link behind a
  login (fails with a reason); phone width; flag off (no section, the route
  answers 404).

### J-PJ-2 — A question that takes 25 minutes

1. Ana: "Compare induction hobs that fit an 80 cm cut-out, with test
   results and current prices."
2. The answer bubble becomes a run card: a short plan (four lines), the
   current step, a clock that counts up, "Stop" and "Answer now".
3. She closes the tab. The chat row in the panel shows a small "working"
   mark.
4. She comes back. The card shows the steps that happened. When it ends,
   the card folds to one line ("Worked 24 min · 31 sources · 6 files read")
   and the answer follows with sources.

- **Found:** in the chat, where the answer will be.
- **Undo:** Stop (keeps what is written); delete the answer like any
  message.
- **Must survive:** tab closed, laptop asleep, worker restart, two tabs
  open, the same chat on the phone.

### J-PJ-3 — The chat asks first

1. Before it starts searching, the card shows a question card: "Question 1
   of 2 — What is your budget?" A · B (recommended) · C, a text field
   "Something else…", "You decide".
2. Ana taps B. Question 2: "How wide is the cut-out?" She types "78 cm".
3. The card folds to her two answers in one line each. The work goes on.

- **Found:** in the run card. The chat row shows "Needs you".
- **Undo:** none after sending; a new message corrects it and steers the
  run.
- **Must survive:** no answer for a day (goes on with its own choice and
  says so); reload between question 1 and 2; keyboard only (A/B/C are
  radio buttons, Enter sends).

### J-PJ-4 — The cost limit ends a run with a result

1. A long run reaches its limit. The last step is "Writing the result".
2. The answer starts with one line: "Cost limit reached. Here is the result
   so far." At the end, a short list "Still open".
3. Below the answer: "Keep going". Pressing it starts a new run that reads
   the notes of the old one.

- **Found:** in the answer.
- **Undo:** not pressing it. Nothing is charged for an unpressed button.
- **Must survive:** the payer's monthly budget is smaller than the run
  limit (the smaller one counts); the limit is hit during a tool call (the
  call finishes, then the wrap-up).

### J-PJ-5 — Message 140

1. Far up in the chat is a generated picture of the kitchen. Since then:
   139 messages. A divider further up says "Conversation summarized".
2. Ana: "Make the cabinets in the first picture darker."
3. The chat lists the files of the conversation, picks the first picture,
   edits it. The answer shows which picture it used.

- **Found:** nothing to find; it works.
- **Undo:** the old picture is still in the chat.
- **Must survive:** three pictures that look alike (it asks which one, with
  thumbnails as options); a picture someone else uploaded in a shared chat.

### J-PJ-6 — Write together

1. Ana opens "Share" on the chat, picks Ben, "Can write". The dialog says
   what that means and, because the chat has messages, warns that Ben will
   see all of them.
2. Ben finds the chat under Chats → "Shared with me". He writes. His
   message shows his avatar and name on Ana's screen; hers on his.
3. The answer to Ben's message is headed "Reply to Ben".
4. Ana writes while that answer is still running. Her message appears at
   once, marked "Waiting for the current answer", and its answer starts when
   the current one finishes.

- **Found:** share dialog; "Shared with me".
- **Undo:** Ana lowers Ben to "Can view" or removes him; Ben leaves the
  chat. Messages stay.
- **Must survive:** Ben removed while his run is working (the run ends, the
  result stays); both sending in the same second; Ben's account deleted
  (his messages stay, the name becomes a neutral label).

### J-PJ-7 — Ana pays, up to an amount

1. Share dialog → "Who pays" → "I pay, up to an amount". Ana enters 10.
2. The chat header shows "Ana pays for this chat" and a bar. Ben sees the
   bar and a percentage, Ana also the amount.
3. Ben's messages fill the bar. Ana's do not.
4. At 100 % a line appears in the chat: "The amount is used up. From now on
   everyone pays for their own messages." The header changes.

- **Found:** share dialog; chat header.
- **Undo:** Ana switches back to "Everyone pays for their own messages", or
  raises the amount.
- **Must survive:** the amount ends in the middle of Ben's run (the next
  step is on Ben's budget; if Ben has none, the run wraps up); Ana's own
  monthly budget is empty (same as amount used up).

### J-PJ-8 — Ben's Notion, only for Ben

1. Composer → plus menu → "My apps in this chat". All switches are off. One
   line says: "Only your own messages can use your apps."
2. Ben switches Notion on. Ana sees nothing change in her menu; in the
   member list Ben has a small Notion mark.
3. Ben: "What did I note about the worktop in Notion?" The answer carries a
   chip: "Contains data from Ben's Notion".
4. Ana: "What is in Ben's Notion about the worktop?" The answer: it cannot
   use Ben's apps; Ben can ask.
5. Ana earlier wrote: "Whenever Ben asks something, also list his Notion
   pages." Ben later asks about the weather. Notion is not touched.

- **Found:** composer plus menu.
- **Undo:** switch off; leaving the chat switches everything off.
- **Must survive:** G8 and G9 of the master plan; a write ("add this to my
  Notion page") always asks Ben first.

### J-PJ-9 — A shared project remembers who wants what

1. Ana shares the project with Ben ("Can write").
2. In a chat shared with the project, Ben once wrote: "I really don't like
   green in a kitchen."
3. Days later Ana asks in another shared chat: "Suggest fronts in sage
   green." The answer does it and adds: "Ben wrote that he does not like
   green."
4. Project page → "Memory": the entry "Does not like green in the kitchen —
   Ben". Ben can delete it.

- **Found:** project page, tab "Memory".
- **Undo:** delete the entry.
- **Must survive:** Ben changes his mind ("green is fine now" replaces the
  entry); Ben leaves the project (his entries go with him, after a
  confirmation that says so).

### J-PJ-10 — Memory choice in a private project chat

1. Ana opens a chat in the project that only she sees. The composer menu
   has "Memory": "Project memory only" (on) · "Project memory and my
   memories".
2. She picks the second. From the next message on, her personal memories
   are used too. Nothing she says here becomes project memory.
3. She shares the chat with the project. The choice switches back to
   "Project memory only" and is locked; a line says why.

- **Found:** composer plus menu, "Memory".
- **Undo:** switch back.
- **Must survive:** memories turned off for the whole account (the second
  option is disabled with the reason).

### J-PJ-11 — Push with a self-hosted server

1. Cem's admin page shows Push: on, with one sentence on what leaves the
   server (a device number and "done" or "needs you", no text).
2. In the app, after his first long question, a sheet asks: "Tell you when
   an answer is ready?" He allows notifications.
3. He locks the phone. Twenty minutes later: "Your answer is ready." A tap
   opens that chat on his server.
4. Settings → Notifications has two switches.

- **Found:** asked in context after the first long run; Settings →
  Notifications.
- **Undo:** the two switches; the phone's own settings; sign-out removes
  the device.
- **Must survive:** the server is not reachable from the internet (the
  push arrives; the chat opens once the phone can reach the server); a new
  phone; two accounts on one phone; relay down (the answer is in the chat
  anyway).

### J-PJ-12 — A new project gets a plan

1. Chats panel → "New project". Ana types "DIY kitchen". The project page
   opens with the chat only, full width, the project name above it.
2. Ana: "I want a new kitchen fitted." The chat asks two things in one
   question card: the rough budget (four options) and by when (three
   options), with "You decide".
3. She taps "8,000 to 15,000 €" and "in 3 months". The chat writes eleven
   steps, three dates it already knows and the budget. Under the answer:
   "Added: 11 steps, budget 12,000 € · Undo".
4. The board slides in from the right. The overview starts with two
   sentences on where the project stands.

- **Found:** the first message in a new project.
- **Undo:** "Undo" under the answer removes all of it; a single item is
  deleted on the board.
- **Must survive:** "You decide" on both questions; a project name that
  says nothing ("Project 1"); the phone (the board tab appears with the
  first item); flag off (today's project page).

### J-PJ-13 — The AI enters what someone writes

1. Ben writes in the project chat: "I'm home on Monday and will deal with
   the measurer."
2. A few seconds after the answer, a line under it: "Added: Ben takes
   care of the measuring · Undo". On the board the task "Have the kitchen
   measured" gets Ben and Monday; it belongs to the date "Measuring at
   home", which gets "Ben is home". This works whatever Ben's Chat model
   is (§0 #96).
3. Ana sees it in the status on top the next time it is written.

- **Found:** the line under the message; the board.
- **Undo:** "Undo" in that line, for as long as the item was not changed
  since; afterwards the item's own menu.
- **Must survive:** a message that only mentions a date ("the shop is
  closed on Monday" writes nothing); a fact ("the niche is 56 cm") goes to
  "What I remember", not to the board; a time without a time zone (the
  project's zone, said back in the line); two members writing at once; a
  private chat of Ben's (the item is seen only by Ben until he shares it).

### J-PJ-14 — A forwarded mail and a date that does not fit

1. Ana forwards the mail of Steinwerk Nord to the project's address
   ("People and settings → Forward mails to this project", with "Copy").
2. A minute later the overview shows "To confirm · 2", source "from the
   mail of Steinwerk Nord, today 09:14": delivery of the worktop on Monday
   14 Dec between 8 and 12, and a 30 % deposit by 7 Dec. The mail is under
   Files with what was read from it.
3. Deposit: "Add". Delivery: "Doesn't work". A mail preview opens: to
   Steinwerk Nord, subject "Re: Delivery of the worktop", variants
   "Decline" and "Other times", text editable.
4. She picks "Other times", changes a date, presses "Send". The row says
   "Mail sent, 14:06"; the item moves to "Waiting on others".

- **Found:** "To confirm" on top of the overview and on the Dates tab.
- **Undo:** an added item like any item; "Ignore" (✕) drops a proposal
  without a mail; a sent mail cannot be undone, which is why it is shown
  first.
- **Must survive:** a mail from someone who is not a member (refused, the
  sender gets nothing back); a mail with no date (filed, nothing proposed);
  no connected mailbox (J-PJ-15, step 4).

### J-PJ-15 — Follow up

1. "More → Waiting on others": "Elektro Brandt: callback about the power
   line, asked on 22 Nov, no answer for 4 days". The status on top says the
   same in one sentence, with "follow up" as a link.
2. "Follow up" opens the preview: to Elektro Brandt, subject "Re: Request
   about a power line for the kitchen", a short polite text that names the
   date of the first mail and the deadline.
3. She changes one word and presses "Open in mail app". Her own mail app
   opens with recipient, subject and text. Back in Synaplan the preview
   asks "Did you send it?"; "Yes" makes the row say "Mail sent, 14:06" and
   gives the waiting task a new date to look again.
4. With a connected Microsoft 365 mailbox the preview also has "Send": the
   mail goes out from her address and lies in her sent mails (§0 #104).
5. For Ben, who is a member, the button is "Remind": he gets a mail, and a
   push once the app has push (phase G).

- **Found:** "Waiting on others"; the link in the status.
- **Undo:** "Cancel" in the preview; nothing is sent before "Send".
- **Must survive:** the recipient's address is unknown (the preview asks
  for it first); "Send" pressed twice (one mail); the mailbox connection
  expired (the preview says so and offers the copy path).

### J-PJ-16 — Reminders and the week

1. Sunday 19:00: Ben gets "Tomorrow 10:00: measuring at home" by mail
   (by push from phase G). A tap opens the project on the Dates tab.
2. Monday 08:00: Ana gets "This week in DIY kitchen": two dates, one
   overdue task, two items waiting for her. By e-mail; by push once the
   app has it.
3. "People and settings" has three switches: before dates, weekly
   overview, overdue.

- **Found:** the phone; "People and settings".
- **Undo:** the switches; a reminder is not sent for a date that was
  deleted or moved meanwhile.
- **Must survive:** a date moved after the reminder was planned; the
  person's time zone differs from the project's; a private item (only its
  author is reminded); push off on the phone (e-mail instead).

### J-PJ-17 — Watching for you

1. In "PHP upgrade" Ben writes: "Tell me when PHP 8.5.2 is out." Under the
   answer: "I'm watching: PHP 8.5.2 · Undo". "Watching for you" on the
   overview: "8.5.1 is the newest, last checked 14:02, php.net".
2. In "DIY kitchen" Ana: "Tell me when the opening hours page of the
   recycling centre changes." The same frame shows "unchanged since 3 Nov".
3. 8.5.2 comes out. A line in the project chat and a mail: "PHP 8.5.2 is
   out, changelog" with "Make a task of it".
4. Ana: "Tell me when the oven is under 450 €." The answer says that price
   watches are not there yet and offers to watch the shop's product page
   for changes instead (§0 #106).

- **Found:** "Watching for you" on the overview.
- **Undo:** "Undo" under the answer; pause and stop on the card.
- **Must survive:** a source without a feed (the chat suggests a page
  watch); a page that blocks the check ("Could not check" with the reason,
  never an invented value); a value that does not change for weeks (no
  model call, no cost); the creator leaves the project (the watch stops).

### J-PJ-18 — Chat and board side by side

1. On a laptop the project opens with the chat on the left and the board
   on the right. Ana drags the divider to give the board more room.
2. For a long answer she hides the board (button in the chat header); the
   chat takes the full width with a readable column. The same button brings
   the board back.
3. From the board's header she hides the chat; a small chat button on the
   left edge brings it back.
4. Next day the page opens as she left it. On the phone the project opens
   in the chat; the board is the second tab.

- **Found:** the divider; the two buttons in the headers.
- **Undo:** double-click on the divider resets the width; the same buttons
  show the hidden side.
- **Must survive:** a window narrower than both minimums (the chat keeps
  420 px, the board takes the rest or the phone layout starts at 820 px);
  keyboard only (the divider is a focusable separator with arrow keys).

### J-PJ-19 — The project in my calendar

1. Dates tab → "Add to my calendar". A dialog shows the private address
   with "Copy" and "Open in calendar app" (`webcal://`), and one line: "Your
   calendar app picks up new and moved dates by itself. That can take up
   to a day." (Google refreshes a subscribed feed every 12 to 24 hours and
   has no refresh button.)
2. Ana opens it on her phone; her calendar subscribes. "Measuring at home"
   is there on Monday at 10.
3. Ben moves the measuring to Tuesday in the chat. Ana's calendar shows
   Tuesday after its next refresh.

- **Found:** Dates tab; the date card's menu.
- **Undo:** remove the subscription in the calendar app; "Renew address"
  in the same dialog makes the old one stop working at once.
- **Must survive:** a proposed date (not in the calendar until added); a
  private item of Ben's (not in Ana's feed); a repeating date; Google's
  slow refresh (the dialog says it).

### J-PJ-20 — A PDF and a model without the loop

1. Ana's Chat model is Gemini, which does not run on the loop. She drops
   "Offer B.pdf" into the project chat: "Is this cheaper than A?"
2. The answer comes as today. A few seconds later "To confirm · 2" shows
   the offer's price as a cost and its delivery date, from the file.
3. She adds both.

- **Found:** "To confirm" on the overview.
- **Undo:** "Ignore" on a proposal; an added item like any item.
- **Must survive:** a scanned PDF without text (nothing proposed, no
  error); a local Ollama model as Chat model; the loop switched off.

## 3. Wireframes

### 3.1 Chats panel

```
 Chats                          [+]
 ─────────────────────────────────
 Projects                        [+]
   ▸ New kitchen               3
   ▸ Tax 2026                  1
 ─────────────────────────────────
 Shared with me
   Kitchen offers      ● Ana
 ─────────────────────────────────
 Today
   Induction hobs      ◐ working
   Worktop material    ! needs you
```

### 3.2 Project page

The agreed look is [`mockups/project-page.html`](./mockups/project-page.html).
The wireframes below are its skeleton.

**After two weeks** (laptop):

```
 rail │ Chats panel │ Main chat ▾          (A)(B) [+] [▣]┃ DIY kitchen            (A)(B) [Share][▣][⋯]
      │             │                                   ┃ New kitchen by the end of January
      │ Projects    │ (B) I'm home on Monday and will   ┃ [Overview] Tasks 7  Dates  Files  More ▾
      │  DIY kitchen│     deal with the measurer.       ┃
      │  Flat       │  ✓ Added: Ben takes care of the   ┃ On Monday at 10 Mr Vogt comes to measure,
      │             │    measuring · Undo               ┃ Ben is home. Elektro Brandt has not called
      │             │                                   ┃ back for four days (follow up) …
      │             │ (A) Does the Blub X7 fit …?       ┃ As of today, 14:05. 4 of 11 steps done.
      │             │  ✓ Read the floor plan, compared  ┃
      │             │    5 shops · 48 s                 ┃ ┌ To confirm · 2 ─ from the mail of … ┐
      │             │  Yes, it fits. 479 € today …      ┃ │ Delivery of the worktop  [Add][Doesn't work] ✕
      │             │  👁 I'm watching: product page ·  ┃ │ Deposit 30 %             [Add][Doesn't work] ✕
      │             │    Undo                           ┃ └─────────────────────────────────────┘
      │             │ ┌───────────────────────────────┐ ┃ ┌ Tasks ──────────┐ ┌ Dates ──────────┐
      │             │ │ Write in the project …        │ ┃ │ ☐ Measure  Ben  │ │ Mon 30 Measuring│
      │             │ └───────────────────────────────┘ ┃ └─────────────────┘ └─────────────────┘
                     chat ~40 %, min 420 px         divider            board, the rest
```

- `[▣]` in the chat header hides the board; in the board header it hides
  the chat. A hidden chat leaves a chat button on the board's left edge.
- "More" opens: Chats · What I remember · People and settings (§0 #102).
- Costs, Decisions, Waiting on others and Watching for you are cards on
  the overview; "All" on a card opens its full list inside the board.
  "Show more" under the overview cards opens Decisions and Waiting on
  others.
- Tasks and Dates have a quick-add line on top: "Add a task …", "Add a
  date …" (§0 #100).

**New project** (laptop): the chat only, full width, a readable column, the
project name and "Just created" above it, the question card as the first
answer, one line under it: "As soon as there are tasks or dates, your
project board appears next to the chat. Drag offers, photos or plans into
the chat."

**Phone:** a bar at the bottom with "Chat" (first, selected) and "Board".
A new project has no bar.

**Sources** stay where PJ4 put them, now under the "Files" tab: "Add
source" opens File · Link · Text. One dialog, one field each.

### 3.3 Run card (working)

```
 ┌───────────────────────────────────────────────┐
 │ ◐ Working · 12 min                            │
 │  ✓ Read the two offers                        │
 │  ✓ Search for test results                    │
 │  ▸ Compare prices (7 shops so far)            │
 │  ○ Write the comparison                       │
 │                                               │
 │  Reading: testberichte.de/…                   │
 │  ── Conversation summarized ──                │
 │                    [ Answer now ]  [ Stop ]   │
 └───────────────────────────────────────────────┘
```

Folded when done: `Worked 24 min · 31 sources · 6 files read  ▾`.

### 3.4 Question card

```
 ┌───────────────────────────────────────────────┐
 │ Question 1 of 2                               │
 │ What is your budget for the hob?              │
 │  (A) up to 500                                │
 │  (B) 500 – 1,000            Recommended       │
 │  (C) above 1,000                              │
 │  [ Something else…                         ]  │
 │                    [ You decide ]  [ Next ]   │
 └───────────────────────────────────────────────┘
```

### 3.5 Shared chat

```
 Kitchen offers     Ana pays for this chat  ▓▓▓▓▓░░░ 62 %    (A)(B)
 ──────────────────────────────────────────────────────────────────
 (B) Ben
     Which worktop did we note in Notion?

     Reply to Ben
     You noted "oak, 4 cm, oiled" …
     [ Contains data from Ben's Notion ]

                                    And what does it cost per metre?  ← own
     Reply to Ana
     …
```

### 3.6 Share dialog, conversation

```
 Share "Kitchen offers"
 [ Ben Okafor            ]  [ Can write ▾ ]
     Can view · Can continue as a copy · Can write

 Ben can write in this chat. He will see everything written so far.
 Your memories and apps are not used here.

 Who pays
  (•) Everyone pays for their own messages
  ( ) I pay, up to an amount   [ 10.00 ]
      Only the others' messages count. When it is used up,
      everyone pays for their own messages again.
                                              [ Cancel ] [ Share ]
```

### 3.7 My apps in this chat

```
 My apps in this chat
 Only your own messages can use your apps.
  Notion           ( ●)   Only these pages ▸
  Nextcloud        (○ )
  Microsoft 365    (○ )
```

### 3.8a Mail preview

```
 ┌ Delivery date doesn't work ─────────────────────────────┐
 │ From the mail of Steinwerk Nord: Mon 14 Dec, 8–12.     │
 │ ( Decline ) ( Other times )                             │
 │ From     ana.keller@…, your connected mailbox           │
 │ To       Steinwerk Nord <service@…>                     │
 │ Subject  Re: Delivery of the worktop                    │
 │ ┌─────────────────────────────────────────────────────┐ │
 │ │ Good morning,                                        │ │
 │ │ the delivery on Monday, 14 Dec does not work for us. │ │
 │ │ Could you deliver on one of these days instead? …    │ │
 │ └─────────────────────────────────────────────────────┘ │
 │ You can still change the text. The mail goes out from   │
 │ your address and lies in your sent mails afterwards.    │
 │                                   [ Send ]  [ Cancel ]  │
 └─────────────────────────────────────────────────────────┘
```

Every preview has "Copy text" and "Open in mail app"; after either one it
asks "Did you send it?" (§0 #104). "Send" is there only with a connected
Microsoft 365 mailbox; without one the "From" line reads "your mail app".

### 3.8b Watching for you

```
 Watching for you
  👁 PHP 8.5.2                         8.5.1  last checked 14:02 · php.net
  👁 Recycling centre opening hours    unchanged since 3 Nov  last checked 13:40
                                                            [⋯ change · pause · stop]
```

### 3.8 Project memory

```
 Memory
  Does not like green in the kitchen        Ben    3 Nov   [Delete]
  Cut-out for the hob is 78 cm              Ana    5 Nov   [Delete]
  Decided: no handle-less fronts            Ana    9 Nov   [Delete]
```

## 4. Copy

Keys land in the namespace that owns their siblings (`chat`, `knowledge`,
`settings`, `admin` for the share dialog). Existing keys are reused where
the words match (`Answer now`, `Stop`, `Recommended`). German is informal
("du"), French formal ("vous"), Spanish and Turkish informal. Placeholders
are identical in every locale.

| en | de | es | fr | tr |
| -- | -- | -- | -- | -- |
| Projects | Projekte | Proyectos | Projets | Projeler |
| New project | Neues Projekt | Nuevo proyecto | Nouveau projet | Yeni proje |
| Sources | Quellen | Fuentes | Sources | Kaynaklar |
| Add source | Quelle hinzufügen | Añadir fuente | Ajouter une source | Kaynak ekle |
| File · Link · Text | Datei · Link · Text | Archivo · Enlace · Texto | Fichier · Lien · Texte | Dosya · Bağlantı · Metin |
| Instructions | Anweisungen | Instrucciones | Instructions | Talimatlar |
| Reading… | Wird gelesen … | Leyendo… | Lecture… | Okunuyor… |
| Ready | Bereit | Listo | Prêt | Hazır |
| Could not be read | Konnte nicht gelesen werden | No se pudo leer | Lecture impossible | Okunamadı |
| Working · {minutes} min | Arbeitet · {minutes} Min. | Trabajando · {minutes} min | En cours · {minutes} min | Çalışıyor · {minutes} dk |
| Answer now | Jetzt antworten | Responder ahora | Répondre maintenant | Şimdi yanıtla |
| Stop | Stopp | Detener | Arrêter | Durdur |
| Keep going | Weitermachen | Continuar | Continuer | Devam et |
| Conversation summarized | Unterhaltung zusammengefasst | Conversación resumida | Conversation résumée | Sohbet özetlendi |
| Cost limit reached. Here is the result so far. | Kostenlimit erreicht. Hier ist das Ergebnis bis hierhin. | Límite de coste alcanzado. Este es el resultado hasta ahora. | Limite de coût atteinte. Voici le résultat à ce stade. | Maliyet sınırına ulaşıldı. Şu ana kadarki sonuç burada. |
| The same steps kept repeating. Here is the result so far. | Die gleichen Schritte haben sich wiederholt. Hier ist das Ergebnis bis hierhin. | Los mismos pasos se repetían. Este es el resultado hasta ahora. | Les mêmes étapes se répétaient. Voici le résultat à ce stade. | Aynı adımlar tekrarlanıyordu. Şu ana kadarki sonuç burada. |
| Still open | Noch offen | Pendiente | Reste ouvert | Hâlâ açık |
| Worked {minutes} min · {sources} sources · {files} files read | {minutes} Min. gearbeitet · {sources} Quellen · {files} Dateien gelesen | Trabajó {minutes} min · {sources} fuentes · {files} archivos leídos | {minutes} min de travail · {sources} sources · {files} fichiers lus | {minutes} dk çalıştı · {sources} kaynak · {files} dosya okundu |
| Question {current} of {total} | Frage {current} von {total} | Pregunta {current} de {total} | Question {current} sur {total} | Soru {current} / {total} |
| Something else… | Etwas anderes … | Otra cosa… | Autre chose… | Başka bir şey… |
| You decide | Entscheide du | Decide tú | Décide pour moi | Sen karar ver |
| Next | Weiter | Siguiente | Suivant | İleri |
| Only {name} can answer this. | Nur {name} kann das beantworten. | Solo {name} puede responder. | Seul le compte de {name} peut répondre. | Bunu yalnızca {name} yanıtlayabilir. |
| Needs you | Wartet auf dich | Te necesita | Attend votre réponse | Seni bekliyor |
| Can write | Darf schreiben | Puede escribir | Peut écrire | Yazabilir |
| {name} can write in this chat. Your memories and apps are not used here. | {name} kann in diesem Chat schreiben. Deine Erinnerungen und Apps werden hier nicht verwendet. | {name} puede escribir en este chat. Tus recuerdos y apps no se usan aquí. | {name} peut écrire dans ce chat. Vos mémoires et vos apps ne sont pas utilisées ici. | {name} bu sohbette yazabilir. Anıların ve uygulamaların burada kullanılmaz. |
| They will see everything written in this chat so far. | Alles, was bisher in diesem Chat steht, wird für sie sichtbar. | Verán todo lo escrito en este chat hasta ahora. | Ces personnes verront tout ce qui a été écrit dans ce chat jusqu'ici. | Bu sohbette şimdiye kadar yazılan her şeyi görecekler. |
| Reply to {name} | Antwort an {name} | Respuesta a {name} | Réponse à {name} | {name} için yanıt |
| Waiting for the current answer | Wartet auf die laufende Antwort | Esperando la respuesta en curso | En attente de la réponse en cours | Süren yanıt bekleniyor |
| Who pays | Wer zahlt | Quién paga | Qui paie | Kim öder |
| Everyone pays for their own messages | Alle zahlen ihre eigenen Nachrichten | Cada persona paga sus mensajes | Chacun paie ses propres messages | Herkes kendi mesajını öder |
| I pay, up to an amount | Ich zahle, bis zu einem Betrag | Pago yo, hasta un importe | Je paie, jusqu'à un montant | Ben öderim, bir tutara kadar |
| {name} pays for this chat | {name} zahlt für diesen Chat | {name} paga este chat | {name} paie pour ce chat | Bu sohbeti {name} ödüyor |
| {percent} % used | {percent} % verbraucht | {percent} % usado | {percent} % utilisé | %{percent} kullanıldı |
| The amount is used up. From now on everyone pays for their own messages. | Der Betrag ist aufgebraucht. Ab jetzt zahlen alle ihre eigenen Nachrichten. | El importe se ha agotado. A partir de ahora cada persona paga sus mensajes. | Le montant est épuisé. Désormais, chacun paie ses propres messages. | Tutar tükendi. Bundan sonra herkes kendi mesajını öder. |
| My apps in this chat | Meine Apps in diesem Chat | Mis apps en este chat | Mes apps dans ce chat | Bu sohbetteki uygulamalarım |
| Only your own messages can use your apps. | Nur deine eigenen Nachrichten können deine Apps nutzen. | Solo tus mensajes pueden usar tus apps. | Seuls vos messages peuvent utiliser vos apps. | Uygulamalarını yalnızca kendi mesajların kullanabilir. |
| Only these pages | Nur diese Seiten | Solo estas páginas | Uniquement ces pages | Yalnızca bu sayfalar |
| Contains data from {name}'s {app} | Enthält Daten aus {app} von {name} | Contiene datos de {app} de {name} | Contient des données de {app} de {name} | {name} adlı kişinin {app} verilerini içerir |
| Memory | Erinnerungen | Recuerdos | Mémoires | Anılar |
| Project memory only | Nur Projekt-Erinnerungen | Solo recuerdos del proyecto | Mémoires du projet uniquement | Yalnızca proje anıları |
| Project memory and my memories | Projekt-Erinnerungen und meine Erinnerungen | Recuerdos del proyecto y mis recuerdos | Mémoires du projet et mes mémoires | Proje anıları ve kendi anılarım |
| Share with the project | Mit dem Projekt teilen | Compartir con el proyecto | Partager avec le projet | Projeyle paylaş |
| Notifications | Benachrichtigungen | Notificaciones | Notifications | Bildirimler |
| Overview | Übersicht | Resumen | Aperçu | Genel bakış |
| Tasks | Aufgaben | Tareas | Tâches | Görevler |
| Dates | Termine | Citas | Rendez-vous | Randevular |
| Files | Dateien | Archivos | Fichiers | Dosyalar |
| More | Mehr | Más | Plus | Daha fazla |
| Show more | Mehr anzeigen | Mostrar más | Afficher plus | Daha fazla göster |
| Costs | Kosten | Costes | Coûts | Maliyetler |
| Watching for you | Hält für dich Ausschau | Vigilando por ti | Je surveille pour vous | Senin için takip ediyor |
| Decisions | Entscheidungen | Decisiones | Décisions | Kararlar |
| Waiting on others | Wartet auf andere | Esperando a otros | En attente d'autres personnes | Başkalarını bekliyor |
| What I remember | Was ich mir gemerkt habe | Lo que recuerdo | Ce dont je me souviens | Hatırladıklarım |
| People and settings | Personen und Einstellungen | Personas y ajustes | Personnes et paramètres | Kişiler ve ayarlar |
| To confirm | Zum Bestätigen | Por confirmar | À confirmer | Onay bekleyenler |
| Add | Eintragen | Añadir | Ajouter | Ekle |
| Doesn't work | Passt nicht | No me va bien | Ne convient pas | Uymuyor |
| Ignore | Ignorieren | Ignorar | Ignorer | Yok say |
| Move | Verschieben | Cambiar fecha | Déplacer | Ertele |
| Follow up | Nachhaken | Hacer seguimiento | Relancer | Hatırlatma gönder |
| Remind | Erinnern | Recordar | Rappeler | Hatırlat |
| Send | Senden | Enviar | Envoyer | Gönder |
| Copy text | Text kopieren | Copiar texto | Copier le texte | Metni kopyala |
| Open in mail app | Im Mail-Programm öffnen | Abrir en la app de correo | Ouvrir dans l'application de messagerie | E-posta uygulamasında aç |
| Mail sent, {time} | Mail gesendet, {time} | Correo enviado, {time} | E-mail envoyé, {time} | E-posta gönderildi, {time} |
| Did you send it? | Hast du sie gesendet? | ¿La has enviado? | L'avez-vous envoyé ? | Gönderdin mi? |
| Add a task … | Aufgabe hinzufügen … | Añadir una tarea… | Ajouter une tâche… | Görev ekle… |
| Add a date … | Termin hinzufügen … | Añadir una cita… | Ajouter un rendez-vous… | Randevu ekle… |
| Add to my calendar | In meinen Kalender | Añadir a mi calendario | Ajouter à mon agenda | Takvimime ekle |
| Open in calendar app | In der Kalender-App öffnen | Abrir en la app de calendario | Ouvrir dans l'application d'agenda | Takvim uygulamasında aç |
| Renew address | Adresse erneuern | Renovar dirección | Renouveler l'adresse | Adresi yenile |
| Your calendar app picks up new and moved dates by itself. That can take up to a day. | Deine Kalender-App holt neue und verschobene Termine selbst ab. Das kann bis zu einem Tag dauern. | Tu app de calendario recoge sola las citas nuevas y cambiadas. Puede tardar hasta un día. | Votre application d'agenda récupère seule les rendez-vous nouveaux ou déplacés. Cela peut prendre jusqu'à un jour. | Takvim uygulaman yeni ve taşınan randevuları kendisi alır. Bu bir güne kadar sürebilir. |
| Show to everyone | Für alle sichtbar machen | Mostrar a todos | Rendre visible à tous | Herkese göster |
| Only you see this | Nur du siehst das | Solo tú ves esto | Vous seul le voyez | Bunu yalnızca sen görüyorsun |
| Added: {what} | Eingetragen: {what} | Añadido: {what} | Ajouté : {what} | Eklendi: {what} |
| Undo | Rückgängig | Deshacer | Annuler | Geri al |
| As of today, {time}. {done} of {total} steps done. | Stand heute, {time} Uhr. {done} von {total} Schritten sind erledigt. | Hoy a las {time}. {done} de {total} pasos hechos. | Aujourd'hui, {time}. {done} étapes sur {total} terminées. | Bugün {time} itibarıyla. {total} adımdan {done} tamamlandı. |
| Last checked {time} | Zuletzt geprüft {time} | Última comprobación {time} | Dernière vérification {time} | Son kontrol {time} |
| Could not check | Konnte nicht prüfen | No se pudo comprobar | Vérification impossible | Kontrol edilemedi |
| Forward mails to this project | Mails an dieses Projekt weiterleiten | Reenviar correos a este proyecto | Transférer des e-mails à ce projet | Bu projeye e-posta ilet |
| Hide board · Show board | Board ausblenden · Board einblenden | Ocultar panel · Mostrar panel | Masquer le tableau · Afficher le tableau | Panoyu gizle · Panoyu göster |
| Hide chat · Show chat | Chat ausblenden · Chat einblenden | Ocultar chat · Mostrar chat | Masquer le chat · Afficher le chat | Sohbeti gizle · Sohbeti göster |
| This week in {project} | Diese Woche in {project} | Esta semana en {project} | Cette semaine dans {project} | {project} projesinde bu hafta |
| Since you were here | Seit du zuletzt hier warst | Desde tu última visita | Depuis votre dernière visite | Son ziyaretinden beri |
| When an answer is ready | Wenn eine Antwort fertig ist | Cuando una respuesta esté lista | Quand une réponse est prête | Bir yanıt hazır olduğunda |
| When a chat needs you | Wenn ein Chat auf dich wartet | Cuando un chat te necesite | Quand un chat attend votre réponse | Bir sohbet seni beklediğinde |
| Tell you when an answer is ready? | Bescheid geben, wenn eine Antwort fertig ist? | ¿Avisarte cuando una respuesta esté lista? | Vous prévenir quand une réponse est prête ? | Bir yanıt hazır olduğunda haber verelim mi? |
| Your answer is ready (push) | Deine Antwort ist fertig | Tu respuesta está lista | Votre réponse est prête | Yanıtın hazır |
| Synaplan is waiting for you (push) | Synaplan wartet auf dich | Synaplan te está esperando | Synaplan attend votre réponse | Synaplan seni bekliyor |

A native speaker reads the es, fr and tr rows before the first PR that
paints them. The existing read-only line of the question card
(`taskPlan.askReadOnly`) is replaced by "Only {name} can answer this."

**Sentences the model writes** (not UI copy, part of the prompt rules in
`03` §4): the opening line of a wrap-up, the "Still open" list, the refusal
"I can only use the apps of the person who is asking", and a conflict hint
that names the member.

**Words that do not appear on screen:** loop, agentic, run profile,
iteration, token, RAG, vector, index, embedding, grant, payer, quota,
compaction, MCP, relay, wrap-up, agent (on the board), watcher, item,
collection, metric, dashboard. Use: working, step, sources, memory, amount,
budget, summarized, watching for you, what I remember, to confirm.

## 5. States

| Surface | Empty | Loading | Error | Flag off |
| ------- | ----- | ------- | ----- | -------- |
| Projects section | "No projects yet" + "New project" | skeleton rows | "Projects could not be loaded" + retry | section absent |
| Sources | "Add files, links or text. Every chat in this project can use them." | row "Reading…" | row "Could not be read" + reason + "Try again" | — |
| Run card | — | "Working" from the first second | "Something went wrong" + what was done + "Try again" | today's bubble |
| Question card | — | — | "Your answer could not be sent" + retry; card stays | — |
| Shared chat | "Shared with me" hidden when empty | — | posting refused: "You can view this chat" | "Can write" not offered |
| Allowance bar | hidden when "everyone pays" | — | bar hidden, mode line stays | — |
| My apps in this chat | "You have no connected apps" + link to Apps | — | switch reverts with a toast | menu entry absent |
| Project memory | "Nothing remembered yet" | skeleton | retry | tab absent |
| Project page, board | the chat only, full width; the board appears with the first item | skeleton cards beside the chat | "The board could not be loaded" + retry; the chat still works | today's project page |
| To confirm | card absent | — | "Could not add" + retry; the proposal stays | absent |
| Mail preview | — | "Writing the mail…" | "Not sent" + reason (mailbox expired, address missing) + the copy path | buttons absent |
| Watching for you | card absent | — | the row says "Could not check" + reason | absent |
| Quick-add line | placeholder "Add a task …" / "Add a date …" | the line shows "Adding…" | the text stays in the line + "Could not add" + retry | absent |
| Calendar subscription | — | — | "Could not create the address" + retry | "Add to my calendar" absent |
| "Added · Undo" line | absent when nothing was added | — (arrives after the answer) | nothing shown; the board is unchanged | absent |
| Notifications (settings) | — | — | "Notifications are off for Synaplan on this phone" + how to turn on | section absent; in the browser always absent |

## 6. Exit bullets

Every `ota-candidate` step answers these in its PR (UX contract §6):

1. **Found:** where a person finds it without being told.
2. **One way:** it is the only place that does this.
3. **Undo:** how it is undone, in the same place.
4. **Both themes, phone width, keyboard:** walked.
5. **Five locales, no banned word:** checked against §4.
