# Synaplan Partners — open your Synaplan to other businesses in three clicks

| | |
| - | - |
| **Status** | Plan, 2026-09-29. **Binding entry point** for this folder. Supersedes [`03_verdict_and_orders.md`](./03_verdict_and_orders.md) where they differ (listed in §11). Keeps every safety rule from `03` §3 and the portable format from [`04_portable_sharing_migration.md`](./04_portable_sharing_migration.md). No product code yet. |
| **Branch** | `feat/synaplan-network` (one PR per step) |
| **User-facing name** | **Partners.** Code keeps `Federation` (`App\Module\Federation\FederationModule`, `App\Service\Federation\*`). The words *federation, peer, node, link, gossip, realm, mount, RAG, vector, protocol* never appear in primary UI copy. |
| **Binding contracts** | UX rules U1–U12 ([`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)), AGENTS.md "Perfect UX & Stability", `03` §3 safety findings, `04` §1 "what never travels". |

---

## 0. The pitch

Every Synaplan is an organization's private AI: its knowledge, its assistants,
its people. **Partners** lets an admin open that Synaplan to other businesses
they choose, and share exactly what they choose, in a way anyone can use.

> Monday, 9:00. Contoso makes truck tyres. Its admin opens **Operate →
> Partners**, clicks **Open to partners**, and sends an invite link to 40
> dealers. At 9:05 the first dealer accepts. Contoso shares its folder
> *Warranty terms* as the topic **Tyre warranty** and its assistant
> **Fitment advisor**. At 9:10 a dealer's service desk asks, in its own
> Synaplan: *"Is a 2019 tyre with a sidewall bulge covered?"* The answer
> quotes Contoso's warranty clause, with the source. Contoso's files never
> left Contoso. The dealer's admin can remove the topic in one click. So can
> Contoso.

**The one rule that makes it easy:**

> **Sharing with a partner works like sharing with a colleague.** It uses the
> same Share dialog, the same "Shared with" list and the same Remove button.
> A partner company is one more kind of recipient, next to a person, a group
> and everyone.

### Synaplan as an operating system for business AI

This is the mental model for the team. It is not UI copy.

| OS idea | Synaplan today | What Partners adds |
| ------- | -------------- | ------------------ |
| Accounts and groups | People, groups | **Partners**: other companies, each one row with a logo |
| File system | Knowledge folders (Sources) | **Topics**: a folder shared under a name, which the partner can ask but never download. A partner's topics appear in your Sources like a network drive. |
| Apps | Assistants | **Run it for them** (they chat, it runs on your server) or **give them a copy** (they install it on theirs) |
| Permissions | Share dialog (view / use / manage) | One dialog with partner permissions: *Can ask*, *Can chat*, *Can copy* |
| Pairing | Connect cards (Nextcloud, desktop) | **Invite link**: one link, one accept |
| App store / address book | — | **Synaplan Directory** on `web.synaplan.com`: find companies by topic, industry, language, region |
| Firewall | Feature switches | **Pause all partner activity**: one switch, both directions stop immediately |
| System log | People → Audit | Partner events in the audit log, plus a "This week" counter on each partner |
| Install / migrate | Export & import (`synaplan-bundle.v1`) | The same bundle, sent to a partner (`04`) |

---

## 1. Words on screen (all five locales in the first UI PR)

| Concept | en | de | es | fr | tr |
| ------- | -- | -- | -- | -- | -- |
| Another organization you are connected with | **Partner** | Partner | socio | partenaire | iş ortağı |
| The feature and its page | **Partners** | Partner | Socios | Partenaires | İş ortakları |
| Turn it on | **Open to partners** | Für Partner öffnen | Abrir a socios | Ouvrir aux partenaires | İş ortaklarına aç |
| A knowledge folder shared under a name | **Topic** | Thema | tema | thème | konu |
| Things partners share with you | **From partners** | Von Partnern | De socios | Des partenaires | İş ortaklarından |
| The seeding directory | **Synaplan Directory** | Synaplan-Verzeichnis | Directorio de Synaplan | Annuaire Synaplan | Synaplan Dizini |
| Partner may ask a topic | **Can ask** | Darf fragen | Puede preguntar | Peut demander | Sorabilir |
| Partner's people may chat with an assistant | **Can chat** | Darf chatten | Puede chatear | Peut discuter | Sohbet edebilir |
| Partner may take a copy | **Can copy** | Darf kopieren | Puede copiar | Peut copier | Kopyalayabilir |

These are added to the canonical term list in `AGENTS.md` in the same PR as
the first paint (U1, U9). The translations above are a proposal; M0 confirms
them.

---

## 2. The admin journey: open, connect, share

Every screen below is reachable from **Operate → Partners**. That is a child of
Operate, not a new top-level item (UX contract §8.4). A badge on it counts
connection requests and new offers from partners, following the Approvals
pattern (U6).

### 2.1 Open (one screen)

```text
Operate → Partners

  Work with other businesses.
  Share chosen knowledge and assistants with companies you trust.
  They can ask questions and chat. Your files stay on your server.
                                                        [Open to partners]
```

Clicking opens one confirm card. There is no wizard:

```text
Open to partners
  ✓ Other companies can reach your server at https://ai.contoso.com
  ✓ The connection is secure (valid certificate)
  Name  [Contoso GmbH          ]   Logo  (taken from your branding)
  Who may share with partners
    (•) Admins only   ( ) Admins and chosen groups   ( ) Everyone
  [ ] List us in the Synaplan Directory (you can do this later)
                                               [Cancel]  [Open to partners]
```

- The server creates its key pair silently. No key, fingerprint or protocol
  word is shown here; the fingerprint appears only on the partner detail for
  admins who want to check it.
- Readiness is checked, not assumed. On `localhost` or a private address the
  first line reads: *"Your server runs on a private address, so other
  companies can't reach it. Partners need a public https address."* The card
  links to the admin docs and the **Open** button stays disabled. That is an
  honest state with a named fix (U8), not a dead control.
- The first-run setup (`/admin/setup`) gets one optional card, **Work with
  other businesses → Open to partners**. That is where an admin who "starts
  Synaplan today" meets the feature.

### 2.2 Connect: invite link first, Directory second

```text
[Invite a partner]  → Copy link   https://ai.contoso.com/partners/join/Kq9…   (valid 7 days, one use)
                      or send it by email  [dealer@fleet-service.de] [Send]
[Find partners]     → Synaplan Directory (§5)
```

The partner's admin clicks the link. Their own Synaplan opens one confirm
card, the same pattern as the Nextcloud connect card (UX contract §4.6):

```text
Connect with Contoso GmbH (contoso.com ✓)?
  You can then use what Contoso shares with you, and share things with them.
  Nothing is shared yet, on either side.
                                                   [Cancel]  [Connect]
```

If the partner has no Synaplan yet, the link page says so in one sentence and
offers the choice: install Synaplan, or (later, §10) open a free workspace.

### 2.3 Share: from the thing, or from the partner

There are two entry points that open the same dialog:

- **Resource first.** Open a folder or an assistant, click **Share**, and
  search "Contoso". Partners appear as their own section in the recipient
  search.
- **Partner first.** On the partner page, click **Share something…**, pick a
  folder or an assistant, and the Share dialog opens with Contoso already
  selected.

```text
Share "Warranty terms"                                          [×]
Owner · Ada
[ Search a person, group or partner…     ] [ Can ask ▾ ] [ Share ]
    Partners
      Contoso GmbH · contoso.com
  Can ask — Contoso's people can ask questions and get short quotes with
  the source. They never get the files. They can keep asking, so share only
  what you would email them.
  Topic name  [Tyre warranty    ]  Description  [Terms, claims, exclusions] [Suggest]
Shared with
  Sales (group)            Can use ▾    Remove
  Contoso GmbH (partner)   Can ask ▾    Remove
```

**Suggest** drafts the topic description with the local chat model from the
folder's file titles. The owner edits it before sharing. The description is
what partners, and the Directory if the owner allows it, will see.

### 2.4 The partner page: five questions on one screen (U7)

```text
Operate → Partners → Contoso GmbH            contoso.com ✓   ● reachable   since 3 Oct
─────────────────────────────────────────────┬─────────────────────────────────────────
We share with Contoso                        │ Contoso shares with us
  Topic      Pricing FAQ      Can ask        │   Topic  Tyre warranty    Everyone   [Change]
  Assistant  Order helper     Can chat       │   Asst.  Fitment advisor  Sales      [Change]
  This week: 42 questions, 18 chats          │   New    Winter range     [Make available…]
[Share something…]                           │
─────────────────────────────────────────────┴─────────────────────────────────────────
Limits  500 questions/day · 200 assistant messages/day · AI costs up to €20/month  [Edit]
[View as Contoso]      [Pause]      [Disconnect]
```

| Question | Answered by |
| -------- | ----------- |
| Who is this? | Name, logo, verified domain, since when, who accepted |
| Who else can use it? | Right column: who in our company may use each offer (Everyone / a group / admins) |
| What can they do? | Left column: permission per item, in the partner permission words |
| How do I stop it? | Remove on any row, **Pause** (everything, both directions, now), **Disconnect** |
| Where did it come from? | Invite link or Directory request, date, and the admin who accepted |

**View as Contoso** renders exactly the catalog Contoso's server receives from
us, from the same code that builds it. Nothing else is simpler for "what did I
actually expose?"

Consequence copy (U3). English only here; five locales in the PR:

- **Pause:** "Paused. Contoso can't ask or chat, and we can't use their
  topics or assistants. Nothing is deleted. Resume any time."
- **Disconnect:** "Disconnected. Everything shared in both directions stops
  now. Copies Contoso already made stay with them."
- **Remove a topic share:** "Contoso can no longer ask Tyre warranty. Answers
  they already received stay in their chats."

---

## 3. Knowledge topics: share, find, remove

### 3.1 Owner side

- A **topic** is one knowledge folder shared with one or more partners under
  a name and a one-line description. It has one card per folder,
  independent of how many partners share it.
- It answers with **short quotes plus a source line**: at most 6 per question,
  about 1,200 characters each. It never returns files, file ids, download
  links or vectors. It is searched with the existing `VectorSearchService`
  over exactly that folder's `RagScope`, the same IAM path as a local
  "Can use" share.
- Only the folder **owner** can share it with a partner (`01` finding 8).
  "Who may share with partners" (§2.1) narrows this further.
- The copy is honest about exfiltration (`03` §3.3): a partner who keeps
  asking can read most of a folder over time. The "Can ask" sentence says so,
  the daily limit bounds it, and the "This week" counter shows it.

### 3.2 Receiver side: what "From partners" means

When Contoso shares a topic with us, our admin sees **New: Tyre warranty** on
the Contoso page (badge on Operate → Partners) and chooses **Make available
to: Everyone / a group / only admins**. From then on:

| Where | What the user sees |
| ----- | ------------------ |
| **Sources → From partners** (sibling of *Incoming*, `/files/partners`) | Every partner topic available to me: topic, partner, description, last used, **Remove from my list**. Admins also see **Remove for everyone**. **Browse partner topics** re-adds a removed one. |
| Knowledge folder picker in the composer | A **From partners** section under my folders |
| `@` palette | A **Partners** section listing topics available to me. Typing `@contoso.com:tyres` is only a shortcut (`03` §3.5). |
| Assistant builder → Knowledge | Partner topics can be added next to own folders |

This is how users **list the external knowledge they have and delete it**. It
is one list with one button per row, and the same list is where they get it
back.

Empty state (U5): *"No partner topics yet. When a partner shares knowledge
with your company, it appears here."* Admins also get **Invite a partner**.

### 3.3 Asking in chat

First use per user per topic shows one inline consent line (`03` §3.4). The
admin's connection is not the user's consent, because the question text
leaves the server:

```text
This question will go to Contoso GmbH (topic "Tyre warranty"). Nothing else leaves our server.
[Ask Contoso]   [Only our knowledge]   [ ] Don't ask again for this topic
```

The answer is a card in the thread (UX contract §4.7):

```text
From Contoso GmbH · Tyre warranty
  "Sidewall damage caused by road hazards is excluded unless…"   — Warranty terms 2026
  AI answer using a partner's knowledge. Your question was sent to contoso.com.
```

- At most 3 partner topics per message, merged with own knowledge and cited
  separately.
- Timeout: *"Contoso didn't answer in time. Showing an answer from your own
  knowledge only."*
- Revoked: *"Contoso no longer shares this topic."* The row then leaves
  *From partners* on its own.
- Paused: *"Your admin paused partner activity."*
- No status codes and no stack traces (U8).
- An assistant with a partner topic shows **Also asks Contoso** on its card
  and in the composer pill. The assistant owner consented when adding the
  topic; users see it before their first message.

### 3.4 Removing and revoking

| Who | Action | Effect |
| --- | ------ | ------ |
| A user at the receiver | **Remove from my list** | Hidden for me only. It is no longer offered in my picker or palette, and assistants I own drop it with a sentence. |
| The receiver's admin | **Remove for everyone** or **Change** availability | Gone for everyone (or the chosen scope) on the next message |
| The receiver's admin | **Disconnect** | All of the partner's offers disappear |
| The owner | **Remove** on the share row | The partner can't ask from the next request. Their row turns *No longer shared* and is cleaned up after 7 days. |
| Either admin | **Pause** | Everything stops in both directions, nothing is deleted |

---

## 4. Assistants: run it for them, or give them a copy

These are the two ways to share an assistant with a partner, named by what
happens:

| Permission | What the partner gets | What stays home |
| ---------- | --------------------- | --------------- |
| **Can chat** | Their people chat with the assistant from their own Synaplan. It runs on **your** server, with your model, knowledge and tools. | Instructions, files, keys, tools. They see only answers. |
| **Can copy** | Their admin clicks **Get a copy** and receives a draft to change and run on their server (`synaplan-bundle.v1`, `04`). Knowledge is included only if you tick *Include the knowledge files (N files, X MB)*. | Secrets, shares and history (`04` §1). Your later changes don't reach their copy. |

Partner permissions **do not nest.** "Can chat" does not allow copying, and
"Can copy" does not allow chatting. This is deliberate: a supplier often wants
dealers to *use* its advisor without taking its instructions.

### 4.1 Can chat: a hosted assistant

- **Why this is not the parked token market** (`03` §3.2): it is your own
  service answering, exactly as your website chat widget answers visitors. It
  is not a resale of a provider key. You choose the assistant; they choose
  whom to let in.
- **You pay the AI costs.** Each partner has limits (messages per day and AI
  cost per month) enforced through the existing metering (`BUSELOG`/`BCOST`,
  source `PARTNER`). When a limit is reached, their user sees: *"Contoso's
  Fitment advisor has reached today's limit for your company. Try again
  tomorrow."*
- **Versions work as they do locally** (J-AB-2): the partner always talks to
  the published version, and publishing v2 reaches them on their next
  message.
- **Privacy by default.** Our server stores the chat in our user's history.
  Contoso's server stores usage counts only, not content, and the partner page
  says so. People are sent as pseudonymous ids (per partner, stable) so limits
  can be per person; names are sent only if our admin allows it.
- On our side the assistant appears under **Assistants → From partners**
  (chip beside *Mine* / *Shared with me*) with a **Runs at Contoso** badge.
  **Start chat** is the primary action.
- Remote answers are rendered as untrusted content: markdown only, no HTML,
  no tool trigger on our side. Their assistant cannot call our tools.

### 4.2 Can copy: prompts and chat widgets too

The `04` bundle sections give "Can copy" to **assistants, prompts and chat
widgets**. A copied widget arrives inactive with a new id and without
secrets; the result screen shows the new embed code (`04` §3). Copying never
auto-imports: the receiving admin clicks **Get a copy**, sees the `04`
checklist, then **Import as drafts**. Migration by download and upload
(`04` §6) stays available with no partner connection at all.

---

## 5. Synaplan Directory: the seeding platform on `web.synaplan.com`

### 5.1 What it is

An **opt-in address book** of organizations that run Synaplan. The
`synaplan-platform` stack (`web.synaplan.com`) hosts it as a feature of the
normal Synaplan app (`FederationDirectoryModule`), not as a separate product.
Every Synaplan points at it by default (`FEDERATION_DIRECTORY_URL`, backend
runtime config). An empty value turns the Directory off for air-gapped or
government installs, and a network such as a public-administration
association can run its own directory with the same code.

A listing card:

```text
┌───────────────────────────────────────────────────────────┐
│ [logo] Contoso GmbH                    contoso.com ✓        │
│ Tyres for trucks and vans. Germany · EU · de, en            │
│ Offers on request: Tyre warranty · Fitment advisor           │
│                                        [Request to connect] │
└───────────────────────────────────────────────────────────┘
```

- **Listing is opt-in and separate from connecting.** An admin can connect by
  invite links forever without ever being listed.
- **Drafted for you.** **Draft my listing** fills name, logo and description
  from branding, and the showcase from topics and assistants the admin has
  marked *Show in the Directory*. Only titles and descriptions are listed. A
  showcase item means "available on request", never "open to everyone".
- **Found from inside Synaplan.** **Operate → Partners → Find partners**
  searches the Directory by words, industry, language and region. A public,
  read-only page (`web.synaplan.com/directory`) lets people browse without
  Synaplan, which is also the growth lever.

### 5.2 What it is not

- **Not a switchboard.** It never sees a question, an answer, a file or a
  chat. After the introduction the two servers talk directly.
- **Not a trust authority.** A listing grants nothing. Every connection is
  accepted by an admin on both sides.
- **Not required.** Invite links work with the Directory switched off.
- **Not a gossip network.** It is one address book with an API, which is far
  simpler than the replicated directory in `00` §5. That design stays parked.

### 5.3 Request to connect

1. Admin C clicks **Request to connect** on Contoso's card and adds a note.
2. The Directory returns Contoso's public address. C's server sends a signed
   connection request **directly** to Contoso.
3. Contoso's admin sees **Requests (1)** on Operate → Partners, with C's
   verified domain, name, note and "found via Synaplan Directory".
   **Accept** or **Decline**. A decline is silent to C beyond *"Not accepted"*.

### 5.4 Keeping it clean

- **Verified domain.** The listing is signed with the server's key, and the
  Directory checks the key at that domain's `/.well-known/`. Only verified
  servers can list or request.
- **Anti-spam.** Rate limits per requester, a pending-request cap per target,
  **Report listing**, and a per-server switch *Don't accept requests from the
  Directory*.
- **Freshness without a background job on customer servers.** A listing
  updates when the admin changes it. The Directory re-checks the well-known
  lazily (when a listing is shown and the last check is over 24 hours old)
  and hides listings unseen for 60 days.
- **Honest risks, written in the plan.**
  - metadist moderates the default Directory. Publish the moderation rules at
    launch.
  - Business contact data is personal data if it names a person. Recommend a
    role address (`ai@…`).
  - A Directory outage stops new introductions, never existing connections.

---

## 6. Who can be a partner in v1

**A partner is a whole Synaplan server:** self-hosted, or a dedicated tenant
stack (the `ch1` pattern). That is what the admin in this plan runs.

`web.synaplan.com` is one shared server for many independent customers, and
Synaplan has **no organization concept** today (users and groups only; there
is no organization entity in `backend/src/Entity/`). If the shared server
opened itself to partners, every customer would appear as "web.synaplan.com".
So in v1 `web.synaplan.com` **hosts the Directory but does not act as a
partner.** Business accounts on shared servers (§10) need an organization
concept first. That is a separate plan, because it touches sign-up, billing
and IAM.

---

## 7. Admin controls (one panel on Operate → Partners)

| Control | Default | Why |
| ------- | ------- | --- |
| Open to partners | Closed | Nothing is reachable until the admin opens it |
| Who may share with partners | Admins only | The first shares are an admin decision; widen in one click |
| What may be shared | Topics, Can chat, Can copy (each can be turned off) | Some companies only want knowledge |
| Default limits for a new partner | 500 questions/day · 200 assistant messages/day · €20/month AI cost | Named constants; each partner can be edited |
| Where offers from partners go | Admin chooses per item (Everyone preselected) | The user consent line (§3.3) still guards each question |
| Accept requests from the Directory | On once listed | One switch to go invite-only |
| Pause all partner activity | Off | Kill switch, immediate, both directions, nothing deleted |
| Close to partners | — | Ends every connection and removes the listing; says so in one sentence |

---

## 8. Safety model (carried over, not re-opened)

Everything in `03` §3 and `04` §1 still applies. In short:

1. A **signature** covers every server-to-server request (Ed25519 via
   `ext-sodium`, checked in M1), with a nonce and a time window.
2. Outbound URLs get an **SSRF guard**: https only, private and local hosts
   rejected, redirects re-checked, and a dev-only allow-local flag. This
   follows `PublicWebhookUrlValidator`.
3. **Access = accepted connection + a share row.** Being listed in the
   Directory grants nothing, and access is never transitive.
4. **Partner text is data:** quotes and assistant answers are fenced, capped,
   never enable a tool and never rewrite a prompt.
5. **Nothing secret travels:** no files (except an explicit *Can copy* with
   knowledge), no vectors, no keys, no credentials, no shares, no history.
6. **Nothing imports by itself:** copies need **Get a copy**, and offers need
   **Make available**.
7. **Undo is one click and works on the next request:** Remove, Pause,
   Disconnect.
8. **No money between partners in v1:** limits are counters, and hosted
   assistant costs are the owner's own metered spend.

---

## 9. Build plan

Each milestone ends with a journey a person can walk. Backend steps finish on
`make ci-local`; UI steps add `make test-e2e` before push. Mobile class:
backend steps are `backend-only`; UI steps are `ota-candidate`, and their
files go on the allow-list in the same PR.

| # | Milestone | Journeys | Done when |
| - | --------- | -------- | --------- |
| **M0** | **Decide and draw** (docs only). Tick §12, confirm the §1 words, write the EN copy and wireframes for J-P1–J-P8 before any `.vue` (U1), name two design partners (for example the Swiss demo on `ch1` and one self-hosted customer). | — | Reviewer walks every journey on paper without asking "and then where?" |
| **M1** | **Open and connect.** Module, key pair, well-known, readiness check, Operate → Partners empty state, open card, invite link, connect card, partner page (empty columns), Pause, Disconnect, audit events. | J-P1, J-P8 | Two servers connect by invite link and disconnect again; localhost shows the honest "can't be reached" state |
| **M2** | **Share a topic.** Partner recipient in the Share dialog for folders (*Can ask*), topic card with **Suggest**, **View as partner**, catalog + change notice, *New* and **Make available** on the receiver, Sources → From partners (list, remove, remove for everyone, browse), picker + `@` section, consent line, answer card. | J-P2, J-P3, J-P4 | A dealer asks Contoso's topic in chat, gets quotes, removes it from their list, Contoso revokes, it disappears |
| **M3** | **Share an assistant to chat.** *Can chat*, hosted turns with streaming, per-partner limits and cost cap, Assistants → From partners chip, **Runs at** badge, partner topics in the assistant builder. | J-P5 | A dealer chats with Fitment advisor; the limit message appears when reached; publishing v2 reaches them |
| **M4** | **Share a copy.** *Can copy* for assistants, prompts and widgets using `04` P1–P3; **Get a copy** on the receiver with the `04` checklist and result screen. | J-P6 | The dealer's admin imports a draft copy; secrets absent, widget inactive with a new embed code |
| **M5** | **Synaplan Directory.** Directory module, **Draft my listing**, *Show in the Directory*, in-app **Find partners**, **Request to connect**, Requests inbox, public read-only page, `synaplan-platform` rollout (env + docs, one web node at a time per its ops rules). Build can start after M1 in a second track; **it goes live after M2** so the shop window never shows things no one can use yet. | J-P7 | Admin C finds Contoso by typing "tyres", requests, Contoso accepts, C sees Contoso's offers |
| **M6** | **Harden and freeze.** Threat-model pass (`03` Order 7), load check (added chat latency p95 under 3 s or a progress state), two weeks of real use with the design partners, freeze the wire at `protocol: 1`, `docs/PARTNERS.md` for admins plus the protocol page for implementers. | all | Numbers and runbook in the PR; no wire change for two weeks |

`04` P4 (migration by download and upload) does not depend on any milestone
here and may ship at any time.

### Journeys (each names where the result is found in ten seconds, U2)

| Journey | Who | Walk | Found at |
| ------- | --- | ---- | -------- |
| **J-P1** Open and invite | Admin A, admin B | Open to partners → Invite a partner → B clicks link → Connect | Both: Operate → Partners, a new partner row |
| **J-P2** Share a topic | Owner at A | Folder → Share → Contoso → Can ask → Suggest → Share → View as Contoso | Folder's *Shared with* list and the left column of the partner page |
| **J-P3** Receive and make available | Admin B | Badge → Contoso → New: Tyre warranty → Make available to Sales | Right column of the partner page |
| **J-P4** Ask and remove | User at B (Sales) | Sources → From partners → ask in chat → consent → answer card → Remove from my list → Browse → re-add | Sources → From partners, picker, `@` |
| **J-P5** Chat with a partner's assistant | User at B | Assistants → From partners → Start chat → limit reached → owner revokes | Assistants → From partners chip |
| **J-P6** Get a copy | Admin B | Contoso → Order helper (Can copy) → Get a copy → checklist → Import as drafts | Assistants → Mine (draft, "Copied from Contoso") |
| **J-P7** Find and connect | Admin C | Find partners → "tyres" → Request to connect → Contoso accepts | Both: Operate → Partners, Requests then the partner row |
| **J-P8** Stop everything | Either admin | Pause → Resume → Disconnect | Partner page status line |

Exit bar for every UI milestone: the five bullets from UX contract §6 (named
journeys walked, ten-second findability, kind-specific consequence copy in
all five locales, empty/error/flag-off states, dark + V2 + 320 px + WCAG AA).
Partner-specific gates asserted in tests:

- A server without an accepted connection cannot ask, chat or copy.
- A revoked share fails on the very next request.
- Pause stops both directions.
- No file, vector or secret crosses the wire outside an explicit *Can copy*
  with knowledge.
- Partner text cannot trigger a tool.
- Hosted chat never exceeds a partner's limit or cost cap.

### Data model (five small tables; no new vector collection, no periodic job on customer servers)

| Table | Holds |
| ----- | ----- |
| `federation_partner` | Domain, address, pinned key, name, logo, status (invited / requested / active / paused / ended), where it came from, limits, who accepted |
| `federation_topic` | My shared folder's card: owner, folder, name, description, languages, *show in Directory* |
| `federation_incoming` | What partners offer us: partner, kind (topic / assistant), remote key, name, description, permission, status (new / available / withdrawn) |
| `federation_hidden` | Per-user "removed from my list" |
| `federation_usage_day` | Daily counters per partner, direction and item (questions, chats, cost) for limits and "This week" |

**Reused, not rebuilt:**

- `BSHARES` gets subject type `partner` (subject id = partner row) with the
  partner permissions `ask` / `chat` / `copy`. The *Shared with* list,
  **Remove** and the audit log work unchanged. Local availability of incoming
  offers reuses `BSHARES` too, through new resource kinds `partner_topic` and
  `partner_assistant` shared to Everyone or a group.
- Connection, share and revoke events go to the existing audit log.
- The Directory server adds one table, `directory_listing`, only where
  `FederationDirectoryModule` is on.

Caveat for implementers: every code path that reads `BSHARES` assumes the
permissions `view` / `use` / `manage`. `Permission::tryFrom('ask')` returns
null, so a partner row is ignored silently rather than failing. Add explicit
tests that partner rows never widen a local user's `RagScopeResolver` scope.

### Wire (`protocol: 0`, experimental until M6)

| Endpoint | Purpose |
| -------- | ------- |
| `GET /.well-known/synaplan-federation` | Public identity: domain, key, address, name |
| `POST /api/v1/federation/connect` | Accept an invite, request, end |
| `GET /api/v1/federation/catalog` | What you currently share with me (signed); doubles as the reachability check |
| `POST /api/v1/federation/notify` | "What I share with you changed"; the receiver refetches the catalog (this replaces gossip) |
| `POST /api/v1/federation/ask` | Topic question → quotes + source |
| `POST /api/v1/federation/chat` | Hosted assistant turn (streaming) |
| `GET /api/v1/federation/copy/{kind}/{key}` | Bundle for *Can copy* (plus the file archive when included) |

Directory (only where the Directory module runs):

| Endpoint | Purpose |
| -------- | ------- |
| `PUT` / `DELETE /api/v1/directory/listing` | Publish or remove my listing (signed) |
| `GET /api/v1/directory/search` | Words, industry, language, region |
| `POST /api/v1/directory/report` | Report a listing |

Every endpoint ships complete OpenAPI annotations; the admin and user APIs
regenerate the frontend Zod schemas (`make -C frontend generate-schemas`).

---

## 10. Later, in the order worth doing

1. **Business accounts on shared servers.** Introduce an organization concept
   so `web.synaplan.com` customers can be partners (`web.synaplan.com/acme`).
   Separate plan; touches sign-up, billing and IAM.
2. **"Your partner doesn't have Synaplan?"** The invite opens a free workspace
   on `web.synaplan.com`, already connected. This needs item 1 and is the
   viral loop.
3. **Share with a person at a partner** (`anna@contoso.com`, resolved through
   the email domain's Synaplan) instead of the whole company.
4. **Open topics:** an owner may let any verified Directory member ask a topic
   without approval, rate-limited, like a public library.
5. **Ask the Directory** ("who knows about retreading?") suggests *partners to
   connect with*. It never fans a question out to strangers.
6. **Own directories** for closed networks (associations, public
   administration), with the same code and a different
   `FEDERATION_DIRECTORY_URL`.

Still parked, with the re-entry rules of `03` §7: the token market,
settlement and payments, the gossip directory, remote tool calls (MCP bridge),
transitive trust, and the Discord bot.

---

## 11. What this plan changes compared with `03`

| `03` said | Now | Why |
| --------- | --- | --- |
| No directory beyond my links; no seeds (§3.6, §3.10, stop rules 4–5) | **Synaplan Directory** on `web.synaplan.com`, opt-in, introducer only | Finding each other is the product owner's core ask; one address book is simpler and safer than gossip |
| Separate *Publications* tab and table | **Share dialog** with a partner recipient + `federation_topic` card | U12 (reuse, do not fork) and "works like sharing with a colleague" |
| `@domain:keyword` as the address | **Picker and *From partners* lists first**; `@contoso.com:tyres` is a shortcut | Nobody should have to type a protocol to use a partner |
| Knowledge only | **+ assistants** (*Can chat*, *Can copy*) and **copies of prompts and widgets** | Asked for; hosted chat is a service, not token resale |
| 2 tables | **5 tables** + reuse of `BSHARES` and the audit log | The receiving-side lists, per-user remove and limits are what make it usable |
| Orders 0–7 | **Milestones M0–M6**, each ending in a walkable journey | Every step is demonstrable to a non-technical person |

Unchanged from `03`: double opt-in, quotes not files, user consent per topic,
honest exfiltration copy, no money, no gossip, no token market, `protocol: 0`
until frozen, and design partners before code.

---

## 12. Decisions before code (Ask-First per AGENTS.md)

| # | Decision | Recommendation | Ask-First |
| - | -------- | -------------- | --------- |
| 1 | Module availability | Present when `APP_URL` is public https; the feature stays **closed** until the admin opens it | **yes** (product default) |
| 2 | Who may share with partners, default | Admins only | no |
| 3 | Directory default | `FEDERATION_DIRECTORY_URL=https://web.synaplan.com`; listing off until the admin opts in | **yes** (runs on the platform) |
| 4 | Partner scope in v1 | Whole servers only; shared servers host the Directory but are not partners | no |
| 5 | Sharing storage | `BSHARES` with subject `partner`, non-nesting permissions `ask` / `chat` / `copy` | **yes** (IAM change) |
| 6 | Hosted chat content at the owner | Not stored; usage counts only; pseudonymous person ids | no |
| 7 | Default limits | 500 questions/day, 200 assistant messages/day, €20/month AI cost per partner | no |
| 8 | Schema | The five tables in §9 plus `directory_listing` on the Directory server | **yes** |
| 9 | Platform rollout | Directory on `web.synaplan.com` via `synaplan-platform` env + docs, rolled one node at a time | **yes** (ops) |
| 10 | Words on screen | §1 table, added to the `AGENTS.md` term list | no |

---

### One paragraph for the coding agent

Build **Partners** on the default-off `FederationModule`:

- An admin clicks **Open to partners** (readiness check, silent key pair) and
  connects by **invite link** or through the **Synaplan Directory** on
  `web.synaplan.com`, an opt-in introducer that never sees content.
- Sharing uses the **existing Share dialog** with a new recipient kind,
  *partner*, and non-nesting permissions:
  - *Can ask* shares a folder as a topic: quotes plus a source, never files.
  - *Can chat* lets their people use an assistant that runs on our server,
    with limits and our cost.
  - *Can copy* gives them a `synaplan-bundle.v1` draft.
- Receivers find everything under **From partners** in Sources and
  Assistants, with **Remove** on every row. Admins get one partner page that
  answers the five questions, with **View as partner**, **Pause** and
  **Disconnect**.
- Keep every `03` safety rule, the `04` portability rules, U1–U12,
  `protocol: 0` until M6, and walk J-P1–J-P8 in the browser.
