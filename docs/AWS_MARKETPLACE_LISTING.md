# AWS Marketplace listing

Everything needed to submit Synaplan as a **free AMI product**, in the order it
has to happen. The engineering side is done and lives in
[`deploy/aws/`](../deploy/aws/README.md); what remains needs a seller account,
which is an ownership decision rather than a code change.

A free listing means AWS charges the buyer nothing for the software and takes no
commission. They pay Amazon for the instance they run it on, and they pay their
AI provider for the keys they add. Nothing in the product is metered by us, and
the AMI ships with billing switched off entirely — see
[Billing](#billing-and-subscriptions) below, which AWS reviews.

## What has to exist before anything can be submitted

These are account and company matters, not engineering ones. Seller registration
is consolidated with AWS Partner Central, so both happen in one flow, and one
person has to carry it: whoever holds the legal authority to accept the AWS
Partner Network terms becomes the **alliance lead**.

1. **An AWS account for the listing**, separate from any account used for
   customer work, with a valid payment method and in good standing — the
   registration refuses an account that is not. This is where AMIs are built and
   where the listing lives. AWS recommends a newly created account; an existing
   one qualifies only if it was created after 27 September 2017. It may sit in the
   company's AWS Organization as long as that organization represents the same
   legal entity, and a standalone account is the recommendation otherwise.
   **Choose it carefully: once a product is listed, the account behind it cannot
   be changed.**
2. **Seller registration** as the alliance lead, which asks for:
   - the legal company name, which has to be unique across all AWS Marketplace
     sellers;
   - a government-issued photo ID for identity verification, plus the business
     tax ID and its registration country for business verification — both are
     checks on the company, not billing settings, and they apply to a free
     listing as well;
   - an alliance lead contact on a company mailbox, which AWS requires to be a
     person rather than an alias;
   - the public seller profile: display name, company description, website, and a
     logo (SVG or PNG on a transparent background, up to 300×150 px, max 500 kB).
3. **A product logo**, at least 110 px wide, aspect ratio between 1:1 and 2:1.
4. **A named support channel** — an address or URL, plus a stated response time.
   AWS requires one even for a free product.
5. **An EULA decision**: the AWS Marketplace standard contract, or our own text.
   The standard contract is the recommendation; Synaplan is Apache 2.0, and the
   standard contract shortens the review.
6. **No tax, banking, disbursement or KYC setup is needed** for a free listing —
   only the profile step above. Those become mandatory the moment a **paid**
   listing is added, and KYC alone can take weeks, so if a paid listing is
   plausible within the year it is worth finishing the registration now.
7. **Time**: identity and business verification first, then roughly 5 to 14
   business days for the Seller Operations review of the listing itself. The
   automated AMI scan is usually under an hour.

Fixed technical constraints, which the build enforces: the source AMI must live
in **us-east-1**, be unencrypted, EBS-backed and HVM, and be shared with AWS
Marketplace ingestion account `679593333241`. Marketplace encrypts its copy
during ingestion; every buyer volume is also encrypted by the deployment
templates. Every CloudFormation template needs an architecture diagram
([`deploy/aws/cloudformation/architecture.md`](../deploy/aws/cloudformation/architecture.md)).

## Who needs which access

The alliance lead owns the registration, the company identity and anything
financial, and cannot hand those over. Everything else — the AMI, the product,
its versions — is engineering work, and delegating it is worth doing: whoever
ships the release should be the one who submits it.

Partner Central and the Management Portal are both reached with an identity in
the **listing account**, so the engineer needs no AWS account of their own and
has nothing to accept: the alliance lead, or whoever administers IAM, creates the
identity for them. A company mailbox is the only prerequisite on their side,
because that is where the invitation goes.

1. Create the user in the listing account — IAM Identity Center if the company
   already uses single sign-on, otherwise an IAM user is enough for one person.
2. Attach:
   - `AWSMarketplaceSellerProductsFullAccess` for the Products pages and AMI
     management: create the product, add a version, run Test Add Version. It
     covers neither seller settings nor tax nor banking, which is the point.
   - `AWSMarketplaceSellerFullAccess` instead of it, if the engineer should also
     reach Settings — the page where the ingestion role below is named.
   - plus permissions for the AWS side that no Marketplace policy covers:
     deploying the roles template, launching and deleting the verification
     stacks, snapshots, `taskcat`. `AdministratorAccess` in this one account is
     the pragmatic choice; it holds no customer data, and the alliance lead keeps
     the root user and the billing.

One extra step applies **only if the company already used the legacy Partner
Central** with company-email logins instead of an AWS account: that identity has
to be linked to the listing account once, and each existing Partner Central user
is then mapped to an IAM role whose name **starts with `PartnerCentralRoleFor`**
(**Account linking → Manage linked account → Map to IAM role**) — Partner Central
refuses a role named anything else. A registration that starts fresh on the new
Partner Central has no legacy identity and skips this entirely.

AWS explicitly discourages signing in to the Management Portal as the account
root user; a user or role is the supported path.

Testing the product does not need a second AWS account either. A **Limited**
listing is visible and subscribable from the seller account itself (and from the
Seller Operations test account); an allowlist of account IDs is there for when a
launch from a genuine buyer's account is worth checking.

## The two IAM roles

Both are created by one template, once, in the listing account:

```bash
aws cloudformation deploy \
  --region us-east-1 \
  --stack-name synaplan-marketplace-roles \
  --template-file deploy/aws/cloudformation/marketplace-roles.yaml \
  --capabilities CAPABILITY_NAMED_IAM
```

The same command updates the roles later, and it has to be run again whenever
the template changes: the account keeps the permissions it was deployed with, so
a permission added here reaches the build role only after a redeploy. A missing
one shows up as `AccessDenied` in the verification stack, after the AMI it was
meant to verify has already been built.

- **`AWSMarketplaceAmiIngestion`** lets AWS Marketplace copy a submitted AMI into
  its own account for the security scan. Every submitted version names it, in the
  Add Version form's *IAM access role ARN* field or, when a version is submitted
  automatically, in the `AmiSource` that goes with it. Store its ARN as the repository secret
  `AWS_MARKETPLACE_INGESTION_ROLE_ARN`.
- **The build role** is assumed by this repository's release workflow through
  GitHub OIDC — there is no access key anywhere in this repository, and there
  must never be. Store its ARN as the repository secret
  `AWS_AMI_BUILD_ROLE_ARN`. Until that secret exists,
  [`aws-ami.yml`](../.github/workflows/aws-ami.yml) skips itself with a notice,
  so releases stay green in the meantime. The role trusts the workflow's
  `ami-build` GitHub environment rather than a branch, so a deployment fix can
  be built and verified from its pull request branch before it is merged; to
  require a human approval per build, add a required-reviewers protection rule
  to that environment in the repository settings.

## Submission sequence

Each step is cheap, and each one catches what the next would otherwise catch
later and slower.

1. **Build the AMI.** Push a release tag; the workflow builds x86_64 and arm64 in
   us-east-1, verifies their snapshots are unencrypted, shares both source AMIs
   with the Marketplace ingestion account, then launches each of them through
   `synaplan-new-vpc.yaml`, runs the smoke test over Session Manager, and deletes
   the stacks. About 20 minutes and a few cents of instance time.
2. **Test Add Version** in the Management Portal. A free automated compliance
   scan that reports exactly what the review would otherwise reject: password
   authentication, root login, known CVEs, credentials left in the image.
   `deploy/aws/scripts/harden.sh` fails the Packer build on the first three, so
   this should be a formality. Only needed while the listing is new — once the
   secrets below are set, every release's versions are offered automatically.
3. **Create the product** as a Limited listing — published, but visible only to
   the seller account and any account we allowlist. Subscribe and launch it the
   way a customer will, including one-click "Launch from Website". This is the
   real end-to-end test.
4. **Multi-region check** with `taskcat` and
   [`deploy/aws/.taskcat.yml`](../deploy/aws/.taskcat.yml), limited to two or
   three regions. Each region is real instance time for no extra signal beyond
   the third.
5. **Request public visibility.**

## Submitting a version automatically

Once the listing exists,
[`marketplace-versions.yml`](../.github/workflows/marketplace-versions.yml) offers
each release to it through the Marketplace Catalog API. It runs hourly, and needs
two secrets — without either of them it skips itself with a notice rather than
failing every hour:

| Secret | Value |
| --- | --- |
| `AWS_MARKETPLACE_PRODUCT_ID` | the listing's product ID, `prod-…` |
| `AWS_MARKETPLACE_INGESTION_ROLE_ARN` | the `IngestionRoleArn` output of the roles stack |

Nothing else has to be switched on, and nothing has to be switched over later.

### Why one release takes several runs

Each architecture becomes a **separate version**, titled
`<version> - Intel or AMD (x86_64)` and `<version> - Graviton (arm64)`. That is
AWS's rule, not a choice: all delivery options of one version must share the same
`AmiSource`, so one version can carry only one AMI. The two sit next to each other
in the buyer's version picker with nothing but the title to go by, which is why it
names the hardware and not only the architecture.

Those two versions cannot be submitted together. AWS answers a second
`AddDeliveryOptions` on the same product with an error while the first is in
flight, and ingesting an AMI is slow — AWS copies the image into every region of
the listing and scans it for vulnerabilities, which its own documentation puts at
*a few hours*. So one version goes per run, and a release is complete after two
of them. When waiting out the hour is not worth it, start the workflow by hand:
**Actions** → **Marketplace Versions** → **Run workflow**, from `main` — it
refuses any other branch, because it would read that branch's version pin and
offer a version nobody released.

### What it submits, and what it will not

The workflow keeps no state of its own, which is what makes an hourly job safe
here. Everything it decides on, it reads:

- **the release** from the version the deployment catalog pins on `main`, so a
  tag whose rollout a guard held back is not offered;
- **the image** from the `SynaplanVersion`, `Architecture` and `SmokeTested` tags
  on the AMI;
- **the recommended instance type** from the listing's own available types, and
  each type's architecture from EC2;
- **what is already submitted** from the listing *and* from the change set
  history.

Both halves of that last point are needed, because what the listing shows is not
everything it has been sent. A version AWS is still ingesting is not part of the
entity yet, and one that failed never becomes part of it. Only the change set
history knows about either, and without it a version in flight would be sent a
second time, for AWS to reject with `DUPLICATE_VERSION_TITLE`.

Which architecture is still missing is decided in
[`scripts/marketplace-change-set.mjs`](../scripts/marketplace-change-set.mjs),
under test, rather than in the workflow. It was two lines of `jq` there once, and
they were wrong: inside `index(...)` a `.` refers to that filter's own input, so
the interpolated title never matched anything, every architecture always counted
as missing, and the same version was offered again on every run.

**A failed version is not retried.** AWS fails a version because it found
something in the image, and the same image fails again; an hourly retry would
only repeat a rejection somebody has already read. Build a new AMI, or dispatch
the workflow once the reason is gone.

`SmokeTested` is the tag [`aws-ami.yml`](../.github/workflows/aws-ami.yml) writes
onto an image that booted and served — every architecture is launched, one
instance each. It is required, not preferred: the AMI build may be dispatched
from any branch, so a deployment fix can be verified from its pull request, and
that mark is what keeps such a trial build out of a public listing. Nothing in
the build offers a version itself — one place submits, so a re-run cannot offer
the same version twice.

### The listing's instance types

A version names a **recommended instance type**, and it has to be one the listing
offers under *Compatibility*. It is read from there rather than chosen in code,
because a type the listing does not offer is rejected — `arm64` was once offered
with a Graviton recommendation against an Intel-only listing, and AWS answered
`RECOMMENDED_INSTANCE_TYPE_NOT_AVAILABLE` half a minute later. Which type belongs
to which architecture is asked of EC2, not read off the family letter.

**An architecture the listing cannot serve is skipped**, with a line in the run
summary rather than a failure. So `arm64` is only offered once the listing lists a
Graviton type. The listing should offer what
[`synaplan-new-vpc.yaml`](../deploy/aws/cloudformation/synaplan-new-vpc.yaml)
accepts, which is:

```text
c7i.xlarge  m7i.xlarge  m7i.2xlarge  r7i.xlarge
c7g.xlarge  m7g.xlarge  m7g.2xlarge
```

The first type the listing names for an architecture is the one recommended, so
their order there is worth a thought.

A rejection is also what this workflow reports: it turns that run red and, if
`DISCORD_RELEASE_WEBHOOK` is set, says so in the channel. It stays silent
otherwise, because an hourly job that reports "nothing to do" teaches everyone to
ignore it.

A submitted version still sits in AWS review for days, and nothing here
publishes it. If a submission turns out to be wrong, cancel its change set —
`CancelChangeSet` is part of the build role's permissions:

```bash
aws marketplace-catalog cancel-change-set \
  --catalog AWSMarketplace --change-set-id <id>
```

### Finding the product ID

Management Portal → **Products** → **Server**. Open the listing; the ID is in the
detail view and in the URL:

```text
https://aws.amazon.com/marketplace/management/products/server/prod-xxxxxxxxxxxxx
```

Or ask the API, with the build role's new catalog permissions:

```bash
aws marketplace-catalog list-entities \
  --region us-east-1 \
  --catalog AWSMarketplace \
  --entity-type AmiProduct \
  --query 'EntitySummaryList[].{Id:EntityId,Name:Name,Visibility:Visibility}'
```

An empty list means the listing has not been created yet — do the steps above
first. `marketplace-versions.yml` stays skipped until the secret is set, so
releases keep working in the meantime.

## Usage Instructions

AWS requires this text on the listing, and requires every outbound dependency to
be disclosed in it. Paste it as written; it is kept in sync with
[`deploy/aws/README.md`](../deploy/aws/README.md).

---

### Launching

Launch through the provided CloudFormation template, or with "Launch from
Website" for a plain single-instance install. The instance needs at least 4 vCPU
and 8 GB of memory; `m7i.xlarge` is the default.

The template asks for an administrator email address and, optionally, a domain
name. Everything else has a working default.

### First sign-in

Open the `WebUrl` from the stack outputs. Without a domain name, Synaplan serves
a self-signed certificate and the browser warns — that is expected, and setting a
domain replaces it with a real Let's Encrypt certificate.

The initial administrator password is generated per instance and never leaves
your account. Read it with the command in the `AdministratorPassword` stack
output:

```
aws ssm get-parameter --with-decryption --name /synaplan/<instance-id>/admin-password --query Parameter.Value --output text
```

The password is single-use: the application refuses every request except the
password change until you have replaced it.

### Adding an AI provider key

Synaplan cannot answer anything until one AI provider key is configured. Sign in
and go to **Admin → AI Providers**, or store the key without signing in:

```
aws ssm put-parameter --type SecureString --name /synaplan/<instance-id>/config/<provider>-api-key --value YOUR_API_KEY
```

then reboot the instance. Supported providers: Groq, OpenAI, Anthropic, Google
Gemini, Mistral, xAI.

### Attaching a domain and a real certificate

```
sudo synaplan-tls app.example.com admin@example.com
sudo systemctl restart synaplan
```

Point the domain's A record at the instance first; Let's Encrypt cannot issue a
certificate for a name it cannot reach.

### Administration

Get a shell with no SSH key and no open port 22:

```
aws ssm start-session --target <instance-id>
```

| Command | What it does |
| ------- | ------------ |
| `sudo synaplan-snapshot` | Pauses the application, snapshots the data volume, resumes. |
| `sudo synaplan-update <version>` | Backs up, installs a released version, verifies it. |
| `sudo synaplan-tls <domain> [email]` | Switches to a real certificate. |
| `sudo synaplan-smoke-test` | Checks every service. |

All data — database, uploads, vectors, configuration — lives on a separate
encrypted EBS volume at `/var/lib/synaplan`. It survives instance replacement,
and deleting the stack snapshots it rather than destroying it.

### Outbound network dependencies

The instance makes outbound HTTPS connections to:

- **The AI provider you configure** (Groq, OpenAI, Anthropic, Google Gemini,
  Mistral or xAI). Synaplan cannot answer anything without one. The key is
  yours, and that provider bills you directly.
- **Let's Encrypt**, only when you configure a domain name.
- **ghcr.io**, only while `sudo synaplan-update` is running.
- **raw.githubusercontent.com**, for the update manifest behind the admin
  area's "a newer version is available" notice. It is a static file, fetched
  with no instance identifier.

Nothing else leaves the instance. There is no telemetry, no licence check and no
call home.

The CloudFormation templates carry an optional `GrantBedrockAccess` switch, off
by default. It grants the instance role permission to call Amazon Bedrock models
so that a future release can run entirely inside your AWS account, with no
third-party provider account at all.

### Support

Apache 2.0 source, issues and release notes: https://github.com/metadist/synaplan

---

## Billing and subscriptions

Synaplan has subscription plans in its source, and AWS reviews what a buyer
actually gets, so this is worth stating precisely.

**The AMI ships with billing switched off.** `firstboot.sh` writes empty
`STRIPE_*` values, which puts the application in open-source mode: no plans, no
quotas, no upgrade prompts, every feature available. That is a requirement, not
a preference — a Marketplace product may not withhold features behind a payment
the listing does not disclose.

**An operator may switch on their own billing afterwards**, with their own
Stripe account, to charge their own end users. That is supported, documented in
[BILLING_SELFHOST.md](BILLING_SELFHOST.md), and fully white-labelled: their
branding, their links, their prices.

**No revenue reaches us through this listing.** The operator is the merchant of
record and AWS is not part of the payment. The listing is reach and a
distribution channel, not an income stream.

## Cost

Offering the product is free, and the architecture deliberately requires no paid
supporting service.

Free: seller registration, the listing, the AMI scan and Test Add Version (a free
listing also carries no commission); CloudFormation, IAM, VPC, internet gateway,
security groups, routing; SSM Parameter Store on the standard tier, Session
Manager and SSM documents; Data Lifecycle Manager itself, where only the snapshot
storage costs anything; and Packer, cfn-lint, taskcat and GitHub OIDC.

Deliberately avoided: a NAT gateway (~32 USD/month — a public subnet with an
internet gateway does the same job here), an Application Load Balancer
(~18 USD/month — Caddy terminates TLS on the instance), and Secrets Manager
(0.40 USD per secret per month — the Parameter Store standard tier is free).
