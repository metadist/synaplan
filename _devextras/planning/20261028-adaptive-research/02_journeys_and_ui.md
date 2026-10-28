# Journeys, surfaces and copy — 2026-10-28

Part of [`00_master_plan.md`](./00_master_plan.md). Steps AR3 (web chat),
AR4 (admin), AR6 (Desktop) implement this file. Binding rules:
`docs/UX_CONTRACT.md` U1–U12 and the design rules of row 21
`05_journeys_and_ui.md` §1 (one new noun — *research*; one sentence per
state; the person is never asked a question the system can answer).

---

## 1. Design rules for this track

1. **No mode, no setting, no picker.** Nothing on the composer says
   research. The chat decides; the person sees the decision as one sentence
   and can overrule it in the moment.
2. **The sentence before the wait.** Whenever the chat chooses the long
   path, the first thing on screen is why and how long. Never a spinner
   with no words.
3. **Two escapes, both one click, both with their consequence stated**
   (U3): **Answer now** while it runs, **Look deeper** when it did not run.
4. **Honest at a limit** (U8): when the chat wanted to research and could
   not, the answer starts with the reason and the way out. Never a quiet
   short answer.
5. **The report is findable in ten seconds** (U1, U6): it arrives as a new
   message in the same chat with the chat's unread badge; the card stays
   above it with Open report; on Desktop it is in `out/` and on the Out
   board as today.
6. **Flag off ⇒ absent** (U11): with `RESEARCH.AUTO_ENABLED = 0` or the
   module off there is no sentence, no card, no Look deeper, no Details line
   — the chat is exactly today's chat.

People: Ada (new, non-technical), Ben (works with his own documents), Chris
(returns to a report), Dana (Desktop), Admin (`web.synaplan.com` and a
self-host). Same people as row 21.

---

## 2. Journeys

### J-AR-1 — Simple stays fast (Ada, AR3)

1. Ada types "What time is it in Tokyo right now?" and presses Enter.
2. Status line "Sorting…" as today, then the answer streams within
   seconds. No sentence about research, no card.
3. Under the answer: the usual actions. **Look deeper** is **not** shown —
   the sorter rated `answer`; the button appears only under `lookup`
   answers and under answers a limit held back (§2.4).
4. Ada types "Thanks!" → "You're welcome." No search, no button.

Exit: the walk shows no new element on either turn; the routing snapshot for
both messages carries `effort: answer`.

### J-AR-2 — Hard becomes research (Ada, AR3; live in AR7)

1. Ada types: "Was brauche ich, um eine Chinesin in China zu heiraten —
   welche Behörden, welche Dokumente, in welcher Reihenfolge?"
2. "Sorting…" for a moment, then a **notice block** in the assistant's
   place (wireframe §3.1):

   > Ich sehe das für dich in mehreren Quellen nach — etwa 6 Minuten. Die
   > Frage hat mehrere Teile.

   Directly under it the **research card** (today's `ResearchCard.vue`)
   with the plan as it forms ("I will look into: 1 … 2 … 3 …"), the
   progress line, **Stop** and **Answer now**.
3. Ada can keep chatting; the card follows the chat (it is anchored to the
   message, not to the viewport). She can leave the page: on return the
   card is restored from the run (`synaplan.researchRun.{chatId}` and
   `GET /research?active=1`), as today.
4. A few minutes later the report arrives as a new assistant message:
   title, summary, the points with sources, the fact-check sentence, Not
   confirmed when there is one, the file `research-{id}.md`. The card
   collapses to its result state with **Open report**, **Go deeper** and
   the "Ask about this report…" box (J-RQ-9).
5. The Details log of the card says in its first line: "5 points — a
   standard look" and names the reason ("the question has several parts").

Exit: the five bullets of §7; the live walk in AR7 has the report's
fact-check sentence in STATUS.

### J-AR-3 — Look deeper (Ben, AR3)

1. Ben asks "Which EU countries recognise a Chinese marriage certificate
   without an apostille?" The sorter rates `lookup`: one search, three pages
   read, an answer with a Sources list, as today.
2. Under the answer, beside the usual actions: **Look deeper** with the
   hover / long-press text "Reads more sources and checks every statement.
   About 6 minutes."
3. Ben clicks. The button turns into the notice sentence ("I am looking
   through more sources for this — about 6 minutes. You asked for more.")
   and the card appears under the answer, seeded with the three pages
   already cited.
4. The report arrives as in J-AR-2. The first answer stays in the chat
   above it, untouched.

Exit: the seeded sources appear in the report's Sources with their original
numbers; the button is gone from that answer after the run started (one
run per answer; a second deeper look is **Go deeper** on the report).

### J-AR-4 — Follow-up without a new run (Chris, AR3)

1. Under the J-AR-2 report Chris types "Und in Österreich?"
2. The sorter sees the report in the thread and rates `lookup`,
   `follow_up`. The chat does **not** start a run: the message is answered
   through today's follow-up path (`POST /research/{id}/followups`,
   `question`), which answers from the notes and searches at most once.
3. The answer is short, cited, in the same chat; under it **Look deeper**
   is available if Chris wants the Austrian case as a full report.

Exit: no `research_started` event; one follow-up run with the parent's id;
the answer arrives in under a minute on the dev stack.

### J-AR-5 — At a limit (Ada, Admin, AR3)

1. Web search is off for the install and the chat has no folder. Ada asks
   the marriage question.
2. The answer begins with: "I would normally look this up in several
   sources, but web search is off for this installation. Here is what I
   know — check the current requirements with the authorities." Then the
   answer from general knowledge. No card. **Look deeper** is shown
   disabled with the same sentence on hover.
3. Admin, on the same install, sees under AI Infrastructure → Research the
   line "Web search is off; automatic research answers from the chat's
   folders only." with a link to the web search setting.
4. Variant: five runs already going on the install → "Too many researches
   are already going. Here is a first answer; click Look deeper in a few
   minutes for the full one." The button is enabled and retries.

Exit: each of the five reasons of `01` §2.4 has its own sentence in five
locales, verified by a unit test over the locale files and one Playwright
case per reason using the test doubles.

### J-AR-6 — Desktop (Dana, AR6)

1. In Synaplan Desktop, in a project with a pinned folder, Dana asks the
   marriage question. No chip is on the composer any more.
2. The thread shows the notice sentence as the assistant turn and the
   research card attached under it (the same card RD3 built), with Stop and
   Answer now.
3. The report lands in the thread, in `out/` and on the Out board, as
   today; follow-ups work as today.
4. Dana asks "what time is it in Tokyo" → answered in seconds, nothing
   else on screen.

Exit: walked on Windows and one other OS; `protocol: 1` unchanged; the
`usecases --quick` set green.

### J-AR-7 — The administrator's switch (Admin, AR4)

1. Admin opens AI Infrastructure → Research. Under the existing Research
   switch there is a second row **Automatic research** — "The chat starts a
   research on its own when a question needs several sources. People can
   always click Answer now." — switch on.
2. Admin turns it off. Consequence line appears at once: "Off: the chat
   answers every question directly. Look deeper disappears from answers.
   Runs already going finish." Audit entry `research.settings.update`.
3. J-AR-2 now answers as today: no sentence, no card, no button.
4. Admin turns it on again; J-AR-2 behaves as in J-AR-2.

Exit: the switch round-trips through `ResearchAdminService`; the
capabilities answer carries `automatic`; the Playwright flag-off case shows
the absence of every element.

---

## 3. Wireframes (web chat; Desktop mirrors them)

### 3.1 The notice block and the card

```text
┌───────────────────────────────────────────────────────────────────────┐
│ Ada                                                                   │
│ Was brauche ich, um eine Chinesin in China zu heiraten — welche       │
│ Behörden, welche Dokumente, in welcher Reihenfolge?                   │
└───────────────────────────────────────────────────────────────────────┘
┌───────────────────────────────────────────────────────────────────────┐
│ ◌  Ich sehe das für dich in mehreren Quellen nach — etwa 6 Minuten.   │
│    Die Frage hat mehrere Teile.                                       │
├───────────────────────────────────────────────────────────────────────┤
│ Recherche                                                   Details ▸ │
│ Ich prüfe:                                                            │
│  1. Voraussetzungen und zuständige Stellen in China         ● läuft   │
│  2. Dokumente aus Deutschland, Beglaubigung und Übersetzung ○         │
│  3. Reihenfolge und Fristen                                 ○         │
│  4. Anerkennung in Deutschland danach                       ○         │
│  5. Kosten und typische Stolpersteine                       ○         │
│ 2 von 10 Suchen · 6 Quellen gelesen · 1 Min.                          │
│                                                                       │
│ [ Jetzt antworten ]  [ Stopp ]                                        │
│ Jetzt antworten schreibt den Bericht aus dem, was schon gelesen ist.  │
└───────────────────────────────────────────────────────────────────────┘
```

- The notice block is a distinct surface (`AutoResearchNotice.vue`), not
  a plain assistant bubble: a leading status glyph, the two sentences, the
  card under it in the same frame. It is persisted as the turn's reply
  with `providerIndex = RESEARCH_NOTICE`, so a reload renders it the same
  way, and `SharedChatView` renders it as plain text.
- **Answer now** is the primary action (`btn-primary px-4 py-2.5
  text-sm`), **Stop** secondary. The consequence sentence under them changes
  with the phase: before the first source "Jetzt antworten beantwortet die
  Frage ohne die zusätzliche Suche."; during writing both buttons are
  disabled with "Der Bericht ist gleich fertig."
- Width: the card is `max-w` of the message column; at 320 px the two
  buttons stack (`flex-wrap`), the plan list keeps its numbers.

### 3.2 Look deeper under an answer

```text
┌───────────────────────────────────────────────────────────────────────┐
│ Which EU countries recognise a Chinese marriage certificate …          │
│ … answer text …                                                        │
│ Sources  [1] auswaertiges-amt.de  [2] europa.eu  [3] justiz.gv.at      │
│                                                                        │
│ ⧉ Copy   ↻ Again   👍 👎   ◎ Look deeper                               │
│                                 └ Reads more sources and checks every  │
│                                   statement. About 6 minutes.          │
└───────────────────────────────────────────────────────────────────────┘
```

- `LookDeeperAction.vue`, mounted by the message actions row beside Again
  for assistant messages whose turn carried `effort: lookup` or an
  `effort_notice`. One glyph for the action on every surface:
  `MagnifyingGlassPlusIcon` (Heroicons outline) — added to the icon map in
  `docs/FRONTEND_CONVENTIONS.md` in AR3.
- Click → the action is replaced in place by the notice sentence ("I am
  looking through more sources for this — about 6 minutes. You asked for
  more.") and the card appears under this message (§3.1 without the first
  frame line).
- Disabled state (limit): same glyph, `opacity-50`, hover text = the limit
  sentence of §4.

### 3.3 The report's arrival

```text
┌───────────────────────────────────────────────────────────────────────┐
│ ✓ Fertig · 5 Punkte · 14 Quellen · 5 Min.          [ Bericht öffnen ]  │
│ Faktencheck: 31 von 33 Aussagen sind belegt. 2 wurden entfernt.        │
│ [ Tiefer recherchieren ]   Frage zu diesem Bericht…                    │
└───────────────────────────────────────────────────────────────────────┘
┌───────────────────────────────────────────────────────────────────────┐
│ Heirat mit einer chinesischen Staatsbürgerin in China                  │
│ Zusammenfassung …                                                      │
│ 1. Voraussetzungen und zuständige Stellen in China  [1][2]             │
│ …                                                                      │
│ Nicht bestätigt: …                                                     │
│ 📄 research-rr_….md                                                    │
└───────────────────────────────────────────────────────────────────────┘
```

Today's result card and report message (RW1); nothing new except the
Details first line (§2, J-AR-2 step 5).

### 3.4 The administrator's row

```text
AI Infrastructure → Research

  Research                                                      [ on ]
  Lets people get a report with sources. Off hides it everywhere.

  Automatic research                                            [ on ]
  The chat starts a research on its own when a question needs several
  sources. People can always click Answer now.
  Off: the chat answers every question directly. Look deeper disappears
  from answers. Runs already going finish.

  ⓘ Web search is off; automatic research answers from the chat's
    folders only.  Open web search settings ▸          (only when true)

  ⓘ In the last 24 hours the sorting model gave no rating on 7 % of
    messages; those were answered directly.            (only when > 2 %)
```

Same component (`ResearchAdminTab.vue`), one new row and two conditional
info lines. The second switch is disabled with "Turn on Research first."
when the first is off.

### 3.5 Desktop

The thread shows the notice sentence as the assistant message and the
existing research card under it; **Answer now** joins Stop on the card;
**Look deeper** sits in the message actions as on the web. The chip and its
depth sheet are removed from the composer; the "Research" entry in the
command palette (if any) is removed. Everything else (Out board, `out/`,
follow-ups, Word export) stays.

---

## 4. Copy (five locales)

Web app German uses *du* (as the existing chat strings), French *vous*,
Spanish *tú*, Turkish *siz*. Keys live in `chat.json` under `research.*`
(the namespace that already owns the card) and `admin.json` under
`research.*`. The limit sentences also exist server-side (the answer begins
with them) in the backend's locale files beside the outcome texts.

| Key | en | de | es | fr | tr |
| --- | -- | -- | -- | -- | -- |
| `research.auto.notice` | I am looking through several sources for this — about {minutes} minutes. | Ich sehe das für dich in mehreren Quellen nach — etwa {minutes} Minuten. | Estoy consultando varias fuentes para esto; unos {minutes} minutos. | Je consulte plusieurs sources pour cela — environ {minutes} minutes. | Bunun için birkaç kaynağa bakıyorum; yaklaşık {minutes} dakika. |
| `research.auto.why.several_parts` | The question has several parts. | Die Frage hat mehrere Teile. | La pregunta tiene varias partes. | La question comporte plusieurs parties. | Soru birkaç bölümden oluşuyor. |
| `research.auto.why.comparison` | It is a comparison. | Es ist ein Vergleich. | Es una comparación. | C'est une comparaison. | Bu bir karşılaştırma. |
| `research.auto.why.procedure` | It is a procedure with several steps and authorities. | Es ist ein Ablauf mit mehreren Schritten und Stellen. | Es un trámite con varios pasos y organismos. | C'est une démarche en plusieurs étapes auprès de plusieurs services. | Birkaç adım ve kurum içeren bir süreç. |
| `research.auto.why.current_fact` | It needs current sources. | Es braucht aktuelle Quellen. | Necesita fuentes actuales. | Il faut des sources à jour. | Güncel kaynaklar gerekiyor. |
| `research.auto.why.asked_thorough` | You asked for a thorough answer. | Du hast um eine gründliche Antwort gebeten. | Pediste una respuesta a fondo. | Vous avez demandé une réponse approfondie. | Ayrıntılı bir yanıt istediniz. |
| `research.auto.why.deeper` | You asked for more. | Du wolltest mehr wissen. | Pediste más. | Vous avez demandé plus. | Daha fazlasını istediniz. |
| `research.answerNow` | Answer now | Jetzt antworten | Responder ahora | Répondre maintenant | Şimdi yanıtla |
| `research.answerNow.consequence.reading` | Answer now writes the report from what was already read. | Jetzt antworten schreibt den Bericht aus dem, was schon gelesen ist. | Responder ahora redacta el informe con lo ya leído. | Répondre maintenant rédige le rapport à partir de ce qui a déjà été lu. | Şimdi yanıtla, okunanlardan raporu yazar. |
| `research.answerNow.consequence.early` | Answer now answers the question without the extra look. | Jetzt antworten beantwortet die Frage ohne die zusätzliche Suche. | Responder ahora contesta sin la búsqueda adicional. | Répondre maintenant répond sans la recherche supplémentaire. | Şimdi yanıtla, ek arama olmadan yanıtlar. |
| `research.answerNow.consequence.writing` | The report is almost done. | Der Bericht ist gleich fertig. | El informe está casi listo. | Le rapport est presque prêt. | Rapor neredeyse hazır. |
| `research.answeredWithout` | Answered without the extra look. | Ohne die zusätzliche Suche beantwortet. | Respondido sin la búsqueda adicional. | Répondu sans la recherche supplémentaire. | Ek arama olmadan yanıtlandı. |
| `research.lookDeeper` | Look deeper | Gründlicher nachsehen | Investigar a fondo | Approfondir la recherche | Daha derin bak |
| `research.lookDeeper.hint` | Reads more sources and checks every statement. About {minutes} minutes. | Liest mehr Quellen und prüft jede Aussage. Etwa {minutes} Minuten. | Lee más fuentes y comprueba cada afirmación. Unos {minutes} minutos. | Lit plus de sources et vérifie chaque affirmation. Environ {minutes} minutes. | Daha fazla kaynak okur ve her ifadeyi kontrol eder. Yaklaşık {minutes} dakika. |
| `research.details.ceiling.quick` | {points} points — a short look | {points} Punkte — ein kurzer Blick | {points} puntos: una consulta breve | {points} points — un coup d'œil rapide | {points} madde — kısa bir bakış |
| `research.details.ceiling.standard` | {points} points — a standard look | {points} Punkte — eine normale Recherche | {points} puntos: una investigación estándar | {points} points — une recherche standard | {points} madde — standart bir araştırma |
| `research.limit.no_sources` | I would normally look this up in several sources, but web search is off here and this chat has no folder. Here is what I know — check current requirements with the authorities. | Normalerweise würde ich das in mehreren Quellen nachsehen, aber die Websuche ist hier aus und dieser Chat hat keinen Ordner. Das weiß ich dazu — prüfe aktuelle Vorgaben bei den Behörden. | Normalmente lo consultaría en varias fuentes, pero la búsqueda web está desactivada aquí y este chat no tiene carpeta. Esto es lo que sé; comprueba los requisitos actuales con las autoridades. | Je consulterais normalement plusieurs sources, mais la recherche web est désactivée ici et cette conversation n'a pas de dossier. Voici ce que je sais — vérifiez les exigences actuelles auprès des autorités. | Normalde bunu birkaç kaynakta araştırırdım, ancak burada web araması kapalı ve bu sohbetin klasörü yok. Bildiklerim şunlar; güncel gereklilikleri yetkili kurumlarla doğrulayın. |
| `research.limit.too_many` | Too many researches are already going. Here is a first answer — click Look deeper in a few minutes for the full one. | Es laufen schon zu viele Recherchen. Hier ist eine erste Antwort — klicke in ein paar Minuten auf Gründlicher nachsehen für die vollständige. | Ya hay demasiadas investigaciones en curso. Aquí tienes una primera respuesta; pulsa Investigar a fondo en unos minutos para la completa. | Trop de recherches sont déjà en cours. Voici une première réponse — cliquez sur Approfondir la recherche dans quelques minutes pour la version complète. | Zaten çok fazla araştırma sürüyor. İlk yanıt şu; tam yanıt için birkaç dakika sonra Daha derin bak'a tıklayın. |
| `research.limit.budget` | Your usage budget for this period is used up, so I answer directly. An administrator can raise it. | Dein Nutzungsbudget für diesen Zeitraum ist aufgebraucht, deshalb antworte ich direkt. Ein Administrator kann es erhöhen. | Tu presupuesto de uso para este periodo se ha agotado, así que respondo directamente. Un administrador puede ampliarlo. | Votre budget d'utilisation pour cette période est épuisé, je réponds donc directement. Un administrateur peut l'augmenter. | Bu dönem için kullanım bütçeniz doldu, bu yüzden doğrudan yanıtlıyorum. Bir yönetici artırabilir. |
| `research.limit.plan_limit` | Your plan's researches for today are used up, so I answer directly. Tomorrow it works again. | Die Recherchen deines Tarifs für heute sind aufgebraucht, deshalb antworte ich direkt. Morgen geht es wieder. | Las investigaciones de tu plan para hoy se han agotado, así que respondo directamente. Mañana vuelve a funcionar. | Les recherches de votre offre pour aujourd'hui sont épuisées, je réponds donc directement. Cela fonctionnera à nouveau demain. | Planınızın bugünkü araştırmaları doldu, bu yüzden doğrudan yanıtlıyorum. Yarın yeniden çalışır. |
| `research.limit.unavailable` | Research is not available right now, so I answer directly. Try Look deeper in a few minutes. | Die Recherche ist gerade nicht verfügbar, deshalb antworte ich direkt. Versuche es in ein paar Minuten mit Gründlicher nachsehen. | La investigación no está disponible ahora, así que respondo directamente. Prueba Investigar a fondo en unos minutos. | La recherche n'est pas disponible pour le moment, je réponds donc directement. Réessayez avec Approfondir la recherche dans quelques minutes. | Araştırma şu anda kullanılamıyor, bu yüzden doğrudan yanıtlıyorum. Birkaç dakika sonra Daha derin bak'ı deneyin. |
| `admin.research.auto.title` | Automatic research | Automatische Recherche | Investigación automática | Recherche automatique | Otomatik araştırma |
| `admin.research.auto.help` | The chat starts a research on its own when a question needs several sources. People can always click Answer now. | Der Chat startet selbst eine Recherche, wenn eine Frage mehrere Quellen braucht. Jeder kann jederzeit Jetzt antworten klicken. | El chat inicia una investigación por sí mismo cuando una pregunta necesita varias fuentes. Siempre se puede pulsar Responder ahora. | Le chat lance une recherche de lui-même quand une question nécessite plusieurs sources. Chacun peut toujours cliquer sur Répondre maintenant. | Bir soru birkaç kaynak gerektirdiğinde sohbet kendi başına araştırma başlatır. Herkes her zaman Şimdi yanıtla'ya tıklayabilir. |
| `admin.research.auto.offConsequence` | Off: the chat answers every question directly. Look deeper disappears from answers. Runs already going finish. | Aus: Der Chat beantwortet jede Frage direkt. Gründlicher nachsehen verschwindet aus den Antworten. Laufende Recherchen werden beendet. | Desactivado: el chat responde todas las preguntas directamente. Investigar a fondo desaparece de las respuestas. Las investigaciones en curso terminan. | Désactivé : le chat répond directement à chaque question. Approfondir la recherche disparaît des réponses. Les recherches en cours se terminent. | Kapalı: sohbet her soruyu doğrudan yanıtlar. Daha derin bak yanıtlardan kaybolur. Süren araştırmalar tamamlanır. |
| `admin.research.auto.needsResearch` | Turn on Research first. | Schalte zuerst Recherche ein. | Activa primero Investigación. | Activez d'abord Recherche. | Önce Araştırma'yı açın. |
| `admin.research.auto.noWeb` | Web search is off; automatic research answers from the chat's folders only. | Die Websuche ist aus; die automatische Recherche nutzt nur die Ordner des Chats. | La búsqueda web está desactivada; la investigación automática solo usa las carpetas del chat. | La recherche web est désactivée ; la recherche automatique n'utilise que les dossiers de la conversation. | Web araması kapalı; otomatik araştırma yalnızca sohbetin klasörlerini kullanır. |
| `admin.research.auto.noVote` | In the last 24 hours the sorting model gave no rating on {percent} % of messages; those were answered directly. | In den letzten 24 Stunden hat das Sortiermodell bei {percent} % der Nachrichten keine Einstufung geliefert; diese wurden direkt beantwortet. | En las últimas 24 horas el modelo de clasificación no dio valoración en el {percent} % de los mensajes; esos se respondieron directamente. | Au cours des dernières 24 heures, le modèle de tri n'a donné aucune évaluation pour {percent} % des messages ; ceux-ci ont reçu une réponse directe. | Son 24 saatte sıralama modeli mesajların %{percent} kadarına derecelendirme vermedi; bunlar doğrudan yanıtlandı. |

Removed keys (AR3): `research.menu`, `research.menuHint`, and every key
`ResearchComposer.vue` owned alone (depth question, Standard / Thorough
labels on the composer, Start research). Removing a key from all five
locales keeps the parity test green; a ledger entry for a removed key is
deleted in the same change.

"Sorting model" in `admin.research.auto.noVote` is the one place the admin
tab names the SORT role; the chat never does. The forbidden-word list of
row 21 `05` §6 applies unchanged (no *sorter*, *lead*, *prompt*, *token*).

Plurals: `{minutes}` and `{points}` use plural branches where the locale
needs them; placeholder names are identical in all five files.

---

## 5. The five questions on each surface (U7)

| Surface | Who owns this | Who else | What it touches / can do | How do I stop it | Where did it come from |
| ------- | ------------- | -------- | ------------------------ | ---------------- | ---------------------- |
| Notice + card | The person who asked (the chat's owner) | Chat members as today for shared chats | "Reads the web and this chat's folders; writes a report into this chat. Nothing is sent or changed elsewhere." — one line in Details | Stop / Answer now on the card | The first line of Details: "Started by the chat because the question has several parts" / "because you clicked Look deeper" |
| Look deeper | The person reading the answer | — | Hover text names the sources and the minutes | It has not started; the card has Stop | Beside the answer it belongs to |
| Report message | As the chat | As the chat | Sources, fact-check, Not confirmed on the message | — (finished) | Card above it; Details names the run id and the ceiling |
| Admin row | The administrator | Everyone on the install | Help text and the off-consequence | The switch itself | Audit entry `research.settings.update` with old and new value |
| Desktop thread | Dana | — | As the web; the report is also in `out/` | Stop / Answer now | The thread and the Out board entry |

---

## 6. Empty, error and flag-off states (U5, U8, U11)

| State | What the person sees |
| ----- | -------------------- |
| Module off, or `AUTO_ENABLED = 0` | Nothing of this track exists: no notice, no card, no Look deeper, no admin row (admin: the Research switch stays; the second row is absent when the module is off, disabled when only Research is off). |
| Sorter gave no rating (`voted = false`) | Today's answer. Nothing else. The admin tab shows the 24-hour no-vote line when it is over 2 %. |
| Rated `research`, limit (§2.4 reasons 4–6) | The answer begins with the limit sentence; Look deeper disabled (reasons 4, 5-budget, 5-plan) or enabled with retry (5-too_many, 6). |
| Run started, worker queue full | The card says "Waiting for a free slot…" as today (`queued`); Answer now before the first source cancels and answers directly. |
| Run fails (`failed_*`) | Today's failed card with its one sentence and **Try again**; plus one line "You can also ask the question again for a direct answer." |
| Answer now before any source | Card: "Answered without the extra look."; the direct answer arrives as the next assistant message. |
| Page reload mid-run | Card restored from the run, as today. The notice block is in the message list (persisted reply). |
| Shared chat view | The notice renders as plain text, the card is not rendered, the report message renders as today. |
| Follow-up under a report | No new run; today's follow-up answer. |
| Incognito chat | No rating is acted on; the chat behaves as today, without any sentence. |

---

## 7. Exit bullets for every UI step (UX contract §6)

Each of AR3, AR4 and AR6 ends only when:

- **Journey walked in the browser** (or Desktop): J-AR-1…5 for AR3, J-AR-7
  for AR4, J-AR-6 for AR6; entered as a dated STATUS line with what was
  clicked, typed, found and undone.
- **Findability in ten seconds:** the report is the next message in the
  same chat with the unread badge; the card's Open report; Desktop `out/`
  and the Out board.
- **Consequence copy in five locales:** Answer now (three phases), Stop,
  Look deeper hint, the six limit sentences, the admin off-consequence —
  all in `en/de/es/fr/tr`, parity test green, no ledger additions.
- **Empty / error / flag-off states** of §6 walked, including
  `AUTO_ENABLED = 0` → nothing of this track on screen.
- **Dark, V2 glass and 320 px:** the notice block, the card with two
  buttons, Look deeper in the actions row and the admin row checked in
  light, dark and `.design-v2` at 320 px (`test-e2e-layout` case added).
