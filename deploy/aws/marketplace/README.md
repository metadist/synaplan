# AWS Marketplace submission assets

AWS Marketplace AMI products are architecture-specific. The reusable templates
in `deploy/aws/cloudformation/` support both x86_64 and arm64 for direct
deployments, while the generated templates here expose only instance types that
can boot the product AMI:

```bash
node scripts/generate-aws-marketplace-templates.mjs
node scripts/generate-aws-marketplace-templates.mjs --check
```

The diagrams meet the Marketplace portal's 1560 × 878 requirement and describe
the two x86_64 CloudFormation delivery options:

```bash
node scripts/generate-aws-marketplace-diagrams.mjs
node scripts/generate-aws-marketplace-diagrams.mjs --check
```

Render `diagrams/*.svg` to PNG without resizing or cropping before uploading the
PNGs and the matching generated templates to the public submission-assets S3
prefix. Never make the AMI, its snapshots, credentials, logs, or customer data
public.

Use an immutable, versioned prefix:

```text
s3://<submission-assets-bucket>/<version>/<architecture>/
├── synaplan-new-vpc.yaml
├── synaplan-existing-vpc.yaml
├── synaplan-new-vpc.png
└── synaplan-existing-vpc.png
```

Only that object prefix needs anonymous `s3:GetObject`; keep public ACLs blocked
and grant the read through a narrow bucket policy. The Marketplace portal copies
all four objects during submission, so no write permission and no public bucket
listing are needed.

The service and resource icons under `diagrams/icons/` are the official AWS
Architecture Icons from the Q3 2025 asset package, downloaded from the
[AWS Architecture Icons](https://aws.amazon.com/architecture/icons/) page.
AWS permits customers and partners to use these assets in architecture diagrams.
