# Elestio Partnership Contact Draft

This is a draft for review. Do not send it without project-owner approval.

**To:** contact@elest.io  
**Subject:** Synaplan integration and Fully Managed Catalog requirements

Hello Elestio team,

We are preparing an Elestio integration for
[Synaplan](https://github.com/metadist/synaplan), an Apache-2.0 licensed,
actively released knowledge-management platform. Synaplan provides a web UI and
API, RAG backed by MariaDB and Qdrant, embeddable chat widgets, and multiple
cloud and local AI providers.

Our planned integration uses a production Docker Compose contract with pinned
multi-architecture images, a secure first-administrator bootstrap, health
checks, and lifecycle hooks for backup, restore, and updates. Cloud AI is the
resource-efficient default; local Ollama and Whisper services are an optional
profile.

Before preparing a catalog submission, could you please clarify:

- your current selection and onboarding process for the Fully Managed Catalog;
- required setup, benchmark, security, backup, restore, update, and maintenance
  evidence;
- logo, screenshot, listing-copy, and minimum-resource requirements;
- release, support, security-fix, and breaking-change responsibilities;
- current revenue-share terms; and
- whether you require additional internal scripts or a particular repository
  layout beyond a public `elestio.yml`.

We have already prepared and tested this ourselves on our own Elestio account:
a working `elestio.yml`, a production Docker Compose stack, and a custom
pipeline where we verified fresh installation, persistence, restore, version
upgrade, rollback, and cleanup. We understand that a working `elestio.yml`
enables import but does not guarantee catalog acceptance, and we are ready to
share our results or adjust anything you require.

Best regards,  
The Synaplan team
