# Review — queued for the next sprint

Reviewed 2026-09-29 against `main` as of `d8767c112` and the plan in
[`00_master_plan.md`](./00_master_plan.md). The design stands. The sprint
as F0–F13 does not. This file is the handoff: what is true, what to fix
before coding, and the only slice the next sprint builds.

## Verdict

Build the **know-how link** next. Park the token market, Stripe, and the
gossip mesh until two real installs can ask each other one question and
get excerpts back.

The plan's load-bearing choices are right and should not be reopened in
the sprint:

- Live queries are direct HTTPS. The directory is a side channel. No DHT,
  no FIDO store-and-forward.
- A link is mutual. Publishing a folder onto that link is a second,
  explicit act.
- Peers exchange **text excerpts**, never files and never vectors.
  `VectorSearchService` and `RagScope` stay the search path.
- The module is default-off (`FeatureModuleInterface`, same tag pass as
  `TelegramModule`).

## Findings

1. **Two products are stacked in one sprint.** Knowledge exchange and
   brokered inference share identity and links. They do not share the
   hard code. `MessagesGateway` types `key_source` as `'user'|'operator'`
   (`MessagesGateway.php`) and then runs tool loops, vision policy, and
   streaming. A `peer` source is a real seam, and it is a later sprint.
   "Point Cursor at it and it switches" is true only after that broker
   exists for the OpenAI-compatible controller. It is not true after the
   knowledge link.

2. **`StripeBillingModule` is the wrong seam.** It sells Synaplan plans
   to end users (`STRIPE_PRICE_PRO`, portal, webhook). It does not pay
   one operator from another. That needs Stripe Connect or invoicing,
   plus VAT, and a decision about who the merchant is. The next sprint
   is **barter**: price 0, no ledger, no Stripe. The comparison table in
   §8 stays valid for the later sprint.

3. **Gossip is not required for two partners.** The first cartel is two
   servers. On accept, each side stores the other's signed record. The
   256-bucket digest, 5-minute fan-out, and 14-day TTL matter once a
   third server should learn about the first two without a direct link.
   Shipping gossip before the query works adds a replication bug surface
   for no user-visible result.

4. **Production has three web nodes and one Galera schema.** When gossip
   does land, one Messenger worker runs it. Three FrankenPHP nodes must
   not each gossip. The plan's migration rules (raw `addSql`, no Schema
   API) are already correct.

5. **There is no periodic scheduler to hang "every 5 minutes" on.**
   Saved tasks have their own runner. A later gossip or health probe is
   a console command the worker invokes. Do not assume Symfony Scheduler.

6. **Outbound URLs are an SSRF hole.** Invite and well-known fetch hit a
   host the operator typed, and the record's `api` field can point at a
   different host. HTTPS only, reject private and local hosts, same idea
   as `PublicWebhookUrlValidator`. The `api` host is either the verified
   domain or an allowlisted host. Dev may set an explicit allow-local
   flag, as Telegram does.

7. **The `@` trigger in the first draft was too wide.** A dot alone
   matches `@report.pdf`. The plan now requires a domain **and** a colon
   (`@telekom.de:org`). `@` with neither stays the file palette. Add a
   unit test for `@report.pdf`, `mail@telekom.de`, and `@telekom.de:org`
   before the Vue change.

8. **Only the folder owner publishes.** `RagScope` is owner-bounded. A
   publication is a grant the **owner** makes. A user who can use a
   shared folder must not publish it onto a federation link. Say that in
   the link UI in one sentence.

9. **`ext-sodium` is unverified.** `backend/composer.json` does not
   require it. F1 starts by checking `sodium_crypto_sign_detached` inside
   the backend container. If it is missing, stop and ask. Do not add a
   Composer package on the way past.

10. **Remote excerpts are data.** A sanitizer that strips tool-call
    shapes is useful and incomplete. The system prompt fences them as
    untrusted quotes. They never enable a tool and never rewrite the
    prompt.

11. **Freeze only the wire you ship.** `docs/FEDERATION_PROTOCOL.md` in
    this sprint covers identity, link, and query. Leave `/infer`,
    receipts, and settlement out of `protocol: 1`. An unused frozen
    field is compatibility debt.

12. **Module path.** House modules live in a sub-namespace
    (`App\Module\Channel\TelegramModule`). This one is
    `App\Module\Federation\FederationModule`. Mobile class is
    backend-only until the admin UI exists, then the UI files go on the
    ota allow-list in the same PR.

13. **The Discord plan is parked.** [`../../discord_ai_buddy.md`](../../discord_ai_buddy.md)
    describes a public directory and a bot. This folder is the federation
    to build. The bot consumes it later. Do not start a second protocol
    from that file.

## Next sprint

**Done when:** install A and install B accept one link, A publishes one
knowledge folder under a keyword, B types `@<a-domain>:<keyword>` in
chat, and the answer card quotes A's excerpts. Unpublish stops answers
on the next request. Flag off hides the page and the palette section.
No money changes hands.

| Step | In this sprint | Deliverable |
| ---- | -------------- | ----------- |
| F0 | yes | `docs/FEDERATION_PROTOCOL.md` for identity, link, query only |
| F1 | yes | Module, keypair, well-known, signer, address parser, sodium check |
| F3 | yes | Invite, accept, revoke. Accept stores the peer's signed record |
| F4 | yes | Query + responder over one `RagScope`. Excerpt fence. SSRF checks on outbound URLs |
| F5 | yes, if F1–F4 are green | `Manage → Federation`: empty state, invite, publish, unpublish. Five locales |
| F6 | yes, if F5 is green | Palette + source card for `@domain:keyword` |
| F2, F7–F13 | no | Gossip, tokens, ledger, Stripe, market UI, denylists. Stay in the master plan |

Journeys to walk before merge: **1** (join + link), **2** (publish),
**3** (ask from chat). Journey 4 (buy capacity) and 5 (peer decay) wait
with the parked steps.

Exit bar for F5 and F6, from the UX contract:

1. Journeys 1–3 walked in the browser.
2. The link row and the published topic are findable in ten seconds from
   `Manage → Federation`.
3. Invite, publish, unpublish, and revoke each say their consequence in
   all five locales.
4. Empty state, a down peer ("they did not answer; nothing left this
   server"), and flag-off (no nav, routes 404).
5. Light, dark, V2, 320 px.

Backend steps finish on `make ci-local`. F5 and F6 also need
`make test-e2e` before push.

## Still needs a human yes before the first migration

- The tables for this sprint only: `federation_link`, `federation_topic`
  (the publication), and the peer identity row. Ledger, offers, and
  peer-health tables wait.
- Seeds: empty list is fine. Two dev installs exchange URLs by hand.
- `ext-sodium` present in the backend image.

Payments, gossip, and the token market stay closed until this sprint's
"done when" is true on two running installs.
