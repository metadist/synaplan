# Azure Marketplace listing

Everything needed to submit Synaplan as a **free Azure virtual machine offer**,
in the order it has to happen. The engineering side is done and lives in
[`deploy/azure/`](../deploy/azure/README.md); what remains needs a Partner Center
account, which is an ownership decision rather than a code change.

A free offer means Microsoft charges the buyer nothing for the software and takes
no commission. They pay Azure for the VM they run it on, and they pay their AI
provider for the keys they add. Nothing in the product is metered by us, and the
image ships with billing switched off entirely — see
[Billing](#billing-and-subscriptions) below, which certification reviews.

## What has to exist before anything can be submitted

These are account and company matters, not engineering ones.

1. **A Microsoft Partner Center account** with the commercial marketplace program
   enabled. Registering is free and takes about half an hour; the person doing it
   becomes the account owner and needs the authority to accept the publisher
   agreement on behalf of the company.
2. **Company verification.** Partner Center checks the legal entity against
   registry data — company name, registered address, and a domain the company
   demonstrably controls. Usually three to five business days, and nothing can be
   submitted until it clears.
3. **No payout or tax profile is needed** for a free offer. Both become mandatory
   the moment a paid or transactable offer is added, so if that is plausible
   within the year it is worth finishing the profile now.
4. **A publisher ID and a display name**, both public and both effectively
   permanent.
5. **A named support channel** — an address or URL, plus a stated response time.
   Certification requires one even for a free offer.
6. **An offer ID**: lowercase, and **immutable once created**. It becomes part of
   every deployment reference, including the `plan` block a customer's ARM
   template carries.

## Who needs which access

The account owner owns the registration, the company identity and anything
financial, and cannot hand those over. Everything else — the image, the offer,
its plans and versions — is engineering work, and delegating it is worth doing:
whoever ships the release should be the one who submits it.

Partner Center users are managed under **Settings → User management**, and the
account owner invites the engineer with the **Manager** role, which can create
and submit offers but not change the account, the agreements or the payout
profile. The invitation goes to a work account in the same Entra tenant, so the
engineer needs no Azure subscription of their own to reach Partner Center.

## The Azure side, once

Everything below happens in an Azure subscription in the **same Entra tenant** as
the Partner Center account. The publishing user needs at least Contributor on it.

1. **A resource group and a Compute Gallery**, holding two image definitions:
   `synaplan-x64` and `synaplan-arm64`. Both must be Gen2 and
   `TrustedLaunchSupported`, because the Packer build creates its VM with secure
   boot and a vTPM and a captured image can only be published against a
   definition that declares the same.

2. **Access for Partner Center to read that gallery**, without which the
   submission fails with a permission error that names nothing useful:

```bash
az provider register --namespace Microsoft.PartnerCenterIngestion
```

   Then, on the gallery under **Access control (IAM)**, grant the role **Compute
   Gallery Image Reader** to both `Microsoft Partner Center Resource Provider`
   and `Compute Image Registry`.

3. **An app registration for GitHub Actions.** In Entra ID, create an app, add a
   **federated credential** for the repository `metadist/synaplan`, and give the
   app Contributor on the gallery's resource group (to build) and on a
   subscription or resource group (to run the verification deployment). Then
   store `AZURE_CLIENT_ID`, `AZURE_TENANT_ID` and `AZURE_SUBSCRIPTION_ID` as
   repository secrets. **No client secret** — this repository holds no long-lived
   Azure credential and must never hold one. Until `AZURE_CLIENT_ID` exists,
   [`azure-image.yml`](../.github/workflows/azure-image.yml) skips itself with a
   notice, so releases stay green in the meantime.

## Listing assets

Needed once, and worth preparing in parallel with everything above:

- **A logo** as PNG, submitted at the largest size available; Partner Center
  derives the smaller sizes from it.
- **One to five screenshots**, each exactly 1280×720 PNG, each with a caption.
- **A short description** of up to 100 characters and a description of up to
  5000, the latter allowing simple HTML.
- **A privacy policy URL** and the support contact from above.

## Submission sequence

Each step is cheap, and each one catches what the next would otherwise catch
later and slower.

1. **Build the image.** Push a release tag; the workflow builds x86_64 and arm64
   into the gallery, then deploys the x86_64 version through
   `deploy/azure/arm/mainTemplate.json`, runs the smoke test on it through Run
   Command, and deletes the resource group. About 25 minutes and a few cents of
   VM time.
2. **Create the offer** in Partner Center as type **Azure Virtual Machine**, with
   the offer ID from above.
3. **Offer setup and properties**: categories, industries, and the **Microsoft
   standard contract** — Synaplan is Apache 2.0, and the standard contract
   shortens the review.
4. **Offer listing**: the assets from the previous section, plus the Usage
   Instructions text below.
5. **Preview audience**: your own subscription IDs. This is how the offer is
   tested end to end before anyone else can see it.
6. **The plan**: pricing model **Free**, and a technical configuration pointing at
   the gallery version. Declare Trusted Launch, the ports 22, 80 and 443, and the
   properties `cloud-init`, `extensions` and `backup`. Leave **"Requires custom
   ARM template"** off — a VM offer with it on is certified against a different
   path than the one this image is built for.
7. **Submit.** Automated validation runs first, then manual certification, then
   the preview.
8. **Walk the preview yourself** as a customer would: deploy, sign in with the
   generated password, change it, attach a domain, run an update. Then **Go
   live**.

Certification takes two to three weeks from a finished image, most of it waiting.

## After going live

Publish the **Deploy to Azure** button, which deploys
`deploy/azure/arm/mainTemplate.json` straight from this repository — no
Marketplace subscription and no offer needed, so it works from the day the
template is on `main`, before the listing exists.

Phase 2 is the same two ARM files submitted as an **Azure Application** (solution
template) offer: `mainTemplate.json` and `createUiDefinition.json` zipped
together. Two things have to be done before that submission and are not needed
for the VM offer: [ARM-TTK](https://github.com/Azure/arm-ttk) has to pass, and
the customer usage attribution GUID already carried by the template has to be
registered in Partner Center.

## Usage Instructions

Certification requires this text on the listing, and requires every outbound
dependency to be disclosed in it. Paste it as written; it is kept in sync with
[`deploy/azure/README.md`](../deploy/azure/README.md).

---

### Deploying

Deploy through the provided template, or create a VM from the image directly for
a plain single-VM install. The VM needs at least 4 vCPU and 8 GB of memory;
`Standard_D4s_v5` is the default, and `Standard_D4ps_v5` is the Arm equivalent.

The form asks for an administrator email address and, optionally, a domain name.
Everything else has a working default.

### First sign-in

Open the `webUrl` from the deployment outputs. Without a domain name, Synaplan
serves a self-signed certificate and the browser warns — that is expected, and
setting a domain replaces it with a real Let's Encrypt certificate.

The initial administrator password is generated per VM and never leaves your
subscription. Read it with the command in the `revealAdministratorCredentials`
output:

```
az vm run-command invoke --resource-group <group> --name <vm> --command-id RunShellScript --scripts 'sudo synaplan-admin-password'
```

If the deployment created a Key Vault, the same password is also stored there as
the secret `synaplan-admin-password`.

The password is single-use: the application refuses every request except the
password change until you have replaced it.

### Adding an AI provider key

Synaplan cannot answer anything until one AI provider key is configured. Sign in
and go to **Admin → AI Providers**. Supported providers: Groq, OpenAI, Anthropic,
Google Gemini, Mistral, xAI. A key can also be supplied at deployment time, in
which case it is written into the VM's configuration and never appears in a tag.

### Attaching a domain and a real certificate

```
sudo synaplan-tls app.example.com admin@example.com
sudo systemctl restart synaplan
```

Point the domain's A record at the VM first; Let's Encrypt cannot issue a
certificate for a name it cannot reach.

### Administration

Get a shell with no SSH key and no open port 22:

```
az vm run-command invoke --resource-group <group> --name <vm> --command-id RunShellScript --scripts '<command>'
```

| Command | What it does |
| ------- | ------------ |
| `sudo synaplan-snapshot` | Pauses the application, backs up the data disk, resumes. |
| `sudo synaplan-update <version>` | Backs up, installs a released version, verifies it. |
| `sudo synaplan-tls <domain> [email]` | Switches to a real certificate. |
| `sudo synaplan-admin-password` | Prints the initial administrator password. |
| `sudo synaplan-smoke-test` | Checks every service. |

All data — database, uploads, vectors, configuration — lives on a separate
managed disk at `/var/lib/synaplan`. It survives VM replacement.

### Outbound network dependencies

The VM makes outbound HTTPS connections to:

- **The AI provider you configure** (Groq, OpenAI, Anthropic, Google Gemini,
  Mistral or xAI). Synaplan cannot answer anything without one. The key is
  yours, and that provider bills you directly.
- **Let's Encrypt**, only when you configure a domain name.
- **ghcr.io**, only while `sudo synaplan-update` is running.
- **raw.githubusercontent.com**, for the update manifest behind the admin area's
  "a newer version is available" notice. It is a static file, fetched with no VM
  identifier.

Nothing else leaves the VM. There is no telemetry, no licence check and no call
home.

### Support

Apache 2.0 source, issues and release notes: https://github.com/metadist/synaplan

---

## Billing and subscriptions

Synaplan has subscription plans in its source, and certification reviews what a
buyer actually gets, so this is worth stating precisely.

**The image ships with billing switched off.** `firstboot.sh` writes empty
`STRIPE_*` values, which puts the application in open-source mode: no plans, no
quotas, no upgrade prompts, every feature available. That is a requirement, not a
preference — a marketplace offer may not withhold features behind a payment the
listing does not disclose.

**An operator may switch on their own billing afterwards**, with their own Stripe
account, to charge their own end users. That is supported, documented in
[BILLING_SELFHOST.md](BILLING_SELFHOST.md), and fully white-labelled: their
branding, their links, their prices.

**No revenue reaches us through this offer.** The operator is the merchant of
record and Microsoft is not part of the payment. The listing is reach and a
distribution channel, not an income stream.

## Cost

Offering the product is free, and the architecture deliberately requires no paid
supporting service.

Free: the Partner Center account, the offer, certification and the preview; the
ARM templates, virtual network, network security group and public IP; the
managed identity and the role assignment; and Packer and GitHub OIDC. The Compute
Gallery itself is free — only the storage of the image versions and their
replicas is billed, at a few euros a month for two architectures.

What the buyer pays, on their own subscription: the VM, the disks, the outbound
traffic, the Key Vault (a fraction of a cent per operation) and, with daily
backups on, the Recovery Services vault storage.

Deliberately avoided: an Application Gateway (Caddy terminates TLS on the VM), a
NAT gateway (a public IP with a network security group does the same job here),
and any managed database or search service — everything runs in containers on the
one VM, which is what makes the free offer cost the buyer nothing beyond compute.
