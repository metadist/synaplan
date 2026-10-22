# Per-user budget — status

Plan: [`00_master_plan.md`](./00_master_plan.md). Not started. No code.

| Step | Class | State |
| ---- | ----- | ----- |
| UB1 | backend-only | not started |
| UB2 | ota-candidate | not started — blocked on UB1 |
| UB3 | ota-candidate | not started — blocked on UB1; the Checkout session and webhook already exist |
| UB4 | no-app-impact | not started — with UB2 |
| UB5 | ota-candidate | not started — blocked on UB1 and UB2 |

Journeys J-UB-1 … J-UB-5 are not walked. A walk is a dated entry here.

2026-10-21: the plan now includes company-login groups. OIDC keeps
assigning membership. The usage budget is set on the directory group.
Stripe stays for people who are not in one. Still no code.
