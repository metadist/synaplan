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

```
 New kitchen                          [ Share ]  [ ⋯ ]
 [ Chats ]  Sources   Instructions   Memory   Members
 ───────────────────────────────────────────────────────
 [ New chat ]
 Induction hobs                 Ana        today
 Offers compared                Ana, Ben   yesterday   shared
 ───────────────────────────────────────────────────────
 Sources                                  [ Add source ▾ ]
   floorplan.png        picture     Ready            ⋯
   offer-mueller.pdf    12 pages    Ready            ⋯
   ikea.com/…           link        Reading…
   Note: measurements   text        Ready            ⋯
```

"Add source" opens: File · Link · Text. One dialog, one field each.

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
compaction, MCP, relay, wrap-up. Use: working, step, sources, memory,
amount, budget, summarized.

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
| Notifications (settings) | — | — | "Notifications are off for Synaplan on this phone" + how to turn on | section absent; in the browser always absent |

## 6. Exit bullets

Every `ota-candidate` step answers these in its PR (UX contract §6):

1. **Found:** where a person finds it without being told.
2. **One way:** it is the only place that does this.
3. **Undo:** how it is undone, in the same place.
4. **Both themes, phone width, keyboard:** walked.
5. **Five locales, no banned word:** checked against §4.
