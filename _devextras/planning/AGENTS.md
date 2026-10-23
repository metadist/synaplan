# Implementation rules

Rules for carrying out a plan in this directory. The product rules stay in
the repository root `AGENTS.md`. This file is how a plan becomes pull
requests.

## The local stack is there to be used

The dev stack runs on this machine (`docker compose up -d`). The app is
[http://localhost:5173](http://localhost:5173), the API is
[http://localhost:8000](http://localhost:8000). Provider keys are already
in `backend/.env`. Use them. Never print a key, never commit one, never
paste one into a pull request, a log or a chat.

Sign in with a seeded account (`docs/DEVELOPMENT.md`, Test Users). When a
journey needs a capability the current chat model does not have — reasoning,
vision, a tool — change the configured model in the app (AI settings) to a
provider that has a key, send a real prompt, and read the result. A unit
test is not that walk. Record what you sent and what came back in the
track's `STATUS.md`. If the stack cannot do it, say so and why; do not
invent the walk.

## One topic at a time

Professionally. One dev branch, one commit, one push, one pull request per
topic. Address the topic, implement the change, commit and push, and wait
for the Copilot feedback. Address that professionally. Link the issues in
the comments. Wait for the CI to go green before you start the next topic.

In practice:

1. Branch off current `main`. The subject is the step's commit line from
   its plan. Conventional Commits. No attribution trailer.
2. Implement that topic only. Run the gate the plan names (`make ci-local`,
   and `make test-e2e` when the change is user-visible) before the push.
3. Open the pull request. The body links the issue with `Fixes #NNNN` and
   says what changed and how to see it.
4. Wait for the Copilot review. Every finding gets a reply on its thread:
   a fixing commit with its hash, or one sentence on why it does not apply.
   Resolve the thread only after the text or the code matches the reply.
5. Wait until **All Checks Passed** is green on that head. A red check is
   fixed on the same branch and waited out again.
6. Only then start the next topic. Do not merge; the owner merges.

## Finished plans leave the live directory

When a plan's last step has landed, move its folder into the year archive,
`YYYY-archive/` (this year: [`2026-archive/`](./2026-archive/readme.txt)).
The live directory stays the list of work that is still open. A dated line
at the top of that track's `STATUS.md` or `README.md` says why it moved.
Drop its row from [`ROADMAP.md`](./ROADMAP.md) §3 and from the live-tracks
table in [`README.md`](./README.md), and add the decision-log line.

The same pass looks for older plans that are already implemented and still
sitting next to the live ones. Move those too. A plan that is only partly
shipped stays where it is.
