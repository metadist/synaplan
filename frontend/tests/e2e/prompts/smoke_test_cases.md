# Core Smoke Test Cases

These are the first, most essential checks. Keep them short, deterministic, and focused on critical paths.

## Auth & Routing
- User signs in with valid credentials, sees the dashboard.
- User signs out from any page and is redirected to the login view.

## Workspace & Setup
- New workspace onboarding completes with required profile or org details saved.

## Knowledge & RAG
- User uploads a common document type and sees it processed and searchable.
- User queries the knowledge base and receives relevant AI-assisted results with citations.

## Chat & Widget
- User starts a chat in the main app and receives a streamed AI response without errors.
- Embedded chat widget loads on an external page and can send and receive messages.

## Communications & Billing
- System sends a templated email such as an invite or reset, and the link opens correctly.
- User with a limited plan hits feature limits and sees the correct upgrade prompt or block.

## Settings, Resilience & Observability
- User updates core settings such as API keys or config, and changes take effect on reload.
- System remains usable during a backend restart with graceful retries rather than hard failures.
- Key user actions like login, upload, and chat are recorded and retrievable in logs or metrics.
