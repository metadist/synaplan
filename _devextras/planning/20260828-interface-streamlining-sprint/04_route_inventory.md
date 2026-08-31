# Route inventory — after the shell streamlining

**Date:** 2026-08-28  
**Sprint:** 4  
**Rule:** no URL was deleted. `/tools/*` and `/config/*` stay as redirects.

| Path | Class | Parent |
| --- | --- | --- |
| `/` | canonical | work |
| `/files` | canonical | work |
| `/files/search` | detail | work |
| `/files/incoming` | detail | work |
| `/files/generated` | detail | work |
| `/files/vectors` | detail (admin chrome) | work |
| `/channels` | canonical | manage |
| `/channels/widgets` | canonical | manage |
| `/channels/widgets/:id` | detail | manage |
| `/channels/widgets/:id/chats` | detail | manage |
| `/channels/widgets/live-support` | canonical | manage |
| `/channels/email` | canonical | manage |
| `/channels/connections` | canonical | manage |
| `/channels/mcp` | canonical | manage |
| `/channels/api` | canonical | manage |
| `/channels/api/docs` | canonical | manage |
| `/channels/tasks` | canonical | manage |
| `/channels/agents` | canonical | manage |
| `/ai/models` | canonical | manage |
| `/ai/instructions` | canonical | manage |
| `/ai/routing` | canonical | manage |
| `/ai/summarizer` | canonical | manage |
| `/plugins` | canonical | work (rail) |
| `/plugins/:name` | detail | work (rail) |
| `/admin` | canonical | operate |
| `/admin/features` | canonical | operate |
| `/admin/model-status` | canonical | operate |
| `/admin/setup` | canonical | operate |
| `/admin/config` | canonical | operate |
| `/profile` | canonical | personal |
| `/settings` | canonical | personal |
| `/memories` | canonical | personal |
| `/statistics` | canonical | personal |
| `/feedbacks` | canonical | personal |
| `/subscription` | canonical | personal |
| `/login` `/register` `/forgot-password` `/reset-password` | public-contract | public |
| `/shared/:token` | public-contract | public |
| `/addin/connect` | public-contract | public |
| `/account-deletion` | public-contract | public |
| `/setup` | public-contract | operate |
| `/onboarding` | utility | public |
| `/tools/*` `/config/*` | redirect | manage (legacy) |

No orphan destination: Live support has a Manage menu home. Public-contract
routes are never remove-candidates.

Deferred i18n **key** cleanup (values already unused in the rail):
`settings.appMode.*`, `nav.aiSetup`, `nav.channels` as top-level labels.
Zero-reference grep (including `ChatWidget.vue` and `widget.ts`) is required
before any key deletion.
