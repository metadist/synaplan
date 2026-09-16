#!/usr/bin/env node

import { readFile, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const diagramRoot = resolve(repoRoot, 'deploy/aws/marketplace/diagrams')
const iconRoot = resolve(diagramRoot, 'icons')
const checkOnly = process.argv.includes('--check')

const iconNames = [
  'amazon-bedrock',
  'amazon-ebs',
  'amazon-ec2',
  'amazon-vpc',
  'aws-iam',
  'aws-systems-manager',
  'data-lifecycle-manager',
  'internet-gateway',
  'parameter-store',
]

const icons = Object.fromEntries(
  await Promise.all(
    iconNames.map(async (name) => {
      const svg = await readFile(resolve(iconRoot, `${name}.svg`), 'utf8')
      return [name, `data:image/svg+xml;base64,${Buffer.from(svg).toString('base64')}`]
    }),
  ),
)

const image = (name, x, y, size = 64) =>
  `<image href="${icons[name]}" x="${x}" y="${y}" width="${size}" height="${size}"/>`

const card = ({
  x,
  y,
  width,
  height,
  icon,
  title,
  lines,
  optional = false,
}) => `
  <g>
    <rect x="${x}" y="${y}" width="${width}" height="${height}" rx="12"
      fill="#ffffff" stroke="${optional ? '#879196' : '#147eba'}"
      stroke-width="2" ${optional ? 'stroke-dasharray="8 6"' : ''}/>
    ${image(icon, x + 16, y + 18, 48)}
    <text x="${x + 76}" y="${y + 35}" class="card-title">${title}</text>
    ${lines
      .map(
        (line, index) =>
          `<text x="${x + 76}" y="${y + 59 + index * 21}" class="card-text">${line}</text>`,
      )
      .join('\n    ')}
  </g>`

const diagram = ({ existingVpc }) => {
  const networkStroke = existingVpc ? '#879196' : '#248814'
  const networkDash = existingVpc ? 'stroke-dasharray="10 7"' : ''
  const networkQualifier = existingVpc
    ? 'Customer-provided network (not created by this template)'
    : 'Network created by this CloudFormation template'
  const subnetQualifier = existingVpc
    ? 'Existing subnet selected by buyer'
    : 'Public subnet 10.20.1.0/24'
  const gatewayQualifier = existingVpc
    ? 'Existing internet/NAT gateway'
    : 'Internet gateway + route table'

  return `<svg xmlns="http://www.w3.org/2000/svg" width="1560" height="878" viewBox="0 0 1560 878">
  <defs>
    <style>
      text { font-family: Arial, Helvetica, sans-serif; fill: #16191f; }
      .title { font-size: 30px; font-weight: 700; }
      .subtitle { font-size: 16px; fill: #5f6b7a; }
      .boundary-title { font-size: 18px; font-weight: 700; }
      .boundary-note { font-size: 14px; fill: #5f6b7a; }
      .card-title { font-size: 14px; font-weight: 700; }
      .card-text { font-size: 12px; fill: #354150; }
      .label { font-size: 13px; font-weight: 600; fill: #354150; }
      .tiny { font-size: 12px; fill: #5f6b7a; }
    </style>
    <marker id="arrow" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
      <path d="M0,0 L10,3.5 L0,7 Z" fill="#545b64"/>
    </marker>
    <marker id="arrow-blue" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">
      <path d="M0,0 L10,3.5 L0,7 Z" fill="#147eba"/>
    </marker>
  </defs>

  <rect width="1560" height="878" fill="#ffffff"/>
  <text x="40" y="46" class="title">Synaplan on AWS - ${existingVpc ? 'Existing VPC' : 'New VPC'}</text>
  <text x="40" y="72" class="subtitle">Single EC2 all-in-one deployment | x86_64 Marketplace AMI | CloudFormation delivery option</text>

  <rect x="205" y="100" width="1120" height="700" rx="18" fill="#f8fbff"
    stroke="#232f3e" stroke-width="3"/>
  <text x="230" y="132" class="boundary-title">Buyer AWS account</text>
  <text x="410" y="132" class="boundary-note">All application data remains in the buyer account</text>

  <rect x="285" y="160" width="760" height="450" rx="16" fill="#f7fff5"
    stroke="${networkStroke}" stroke-width="3" ${networkDash}/>
  ${image('amazon-vpc', 305, 174, 48)}
  <text x="365" y="193" class="boundary-title">${existingVpc ? 'Existing Amazon VPC' : 'Amazon VPC 10.20.0.0/16'}</text>
  <text x="365" y="215" class="boundary-note">${networkQualifier}</text>

  <rect x="350" y="235" width="630" height="315" rx="14" fill="#f5faff"
    stroke="${networkStroke}" stroke-width="2" ${networkDash}/>
  <text x="370" y="264" class="boundary-title">${subnetQualifier}</text>
  <text x="370" y="286" class="boundary-note">Security group: inbound TCP 80/443 from AllowedWebCidr; no inbound SSH</text>
  <text x="370" y="307" class="boundary-note">${gatewayQualifier}; outbound HTTPS enabled</text>

  <g>
    <rect x="42" y="306" width="150" height="106" rx="14" fill="#ffffff" stroke="#545b64" stroke-width="2"/>
    <circle cx="117" cy="339" r="18" fill="#545b64"/>
    <path d="M82 391 Q117 356 152 391" fill="none" stroke="#545b64" stroke-width="8"/>
    <text x="117" y="432" text-anchor="middle" class="label">Operator / browser</text>
  </g>

  ${card({
    x: 410,
    y: 330,
    width: 500,
    height: 175,
    icon: 'amazon-ec2',
    title: 'Amazon EC2 - Synaplan AMI 4.2.4',
    lines: [
      'm7i.xlarge recommended (4 vCPU, 16 GiB)',
      'Caddy: HTTPS termination on TCP 443/80',
      'Docker Compose: app, MariaDB, Redis, Qdrant,',
      'Tika and Centrifugo; IMDSv2 required',
    ],
  })}

  ${image('internet-gateway', 232, 327, 48)}
  <text x="256" y="392" text-anchor="middle" class="tiny">${existingVpc ? 'Existing gateway' : 'Internet gateway'}</text>

  <path d="M192 358 H232" stroke="#545b64" stroke-width="2.5" marker-end="url(#arrow)"/>
  <path d="M280 358 H410" stroke="#545b64" stroke-width="2.5" marker-end="url(#arrow)"/>
  <text x="302" y="346" class="label">HTTPS</text>

  ${card({
    x: 300,
    y: 640,
    width: 325,
    height: 105,
    icon: 'amazon-ebs',
    title: 'Encrypted root EBS',
    lines: ['30 GiB gp3', 'OS + pre-pulled images; deleted with EC2'],
  })}
  ${card({
    x: 650,
    y: 640,
    width: 360,
    height: 105,
    icon: 'amazon-ebs',
    title: 'Encrypted data EBS',
    lines: ['20-16,000 GiB gp3 at /var/lib/synaplan', 'Database, files, vectors, secrets; snapshot on delete'],
  })}
  <path d="M580 505 V640" stroke="#147eba" stroke-width="2" marker-end="url(#arrow-blue)"/>
  <path d="M750 505 V640" stroke="#147eba" stroke-width="2" marker-end="url(#arrow-blue)"/>

  ${card({
    x: 1075,
    y: 170,
    width: 220,
    height: 105,
    icon: 'aws-iam',
    title: 'AWS IAM',
    lines: ['Least-privilege EC2 role', 'Session Manager + own resources'],
  })}
  ${card({
    x: 1075,
    y: 295,
    width: 220,
    height: 125,
    icon: 'aws-systems-manager',
    title: 'AWS Systems Manager',
    lines: ['Session Manager (no SSH)', 'Command document', 'SecureString admin password'],
  })}
  ${card({
    x: 1075,
    y: 440,
    width: 220,
    height: 105,
    icon: 'parameter-store',
    title: 'Parameter Store',
    lines: ['Admin password', 'Optional provider keys'],
  })}
  ${card({
    x: 1075,
    y: 565,
    width: 220,
    height: 105,
    icon: 'data-lifecycle-manager',
    title: 'EBS Data Lifecycle',
    lines: ['Optional daily snapshots', 'Optional app quiesce hook'],
    optional: true,
  })}
  ${card({
    x: 1075,
    y: 690,
    width: 220,
    height: 85,
    icon: 'amazon-bedrock',
    title: 'Amazon Bedrock',
    lines: ['Optional IAM access; off by default'],
    optional: true,
  })}

  <path d="M910 365 H1075" stroke="#147eba" stroke-width="2" marker-end="url(#arrow-blue)"/>
  <path d="M910 405 H1075" stroke="#147eba" stroke-width="2" marker-end="url(#arrow-blue)"/>
  <path d="M1010 690 H1075" stroke="#147eba" stroke-width="2" marker-end="url(#arrow-blue)"/>

  <rect x="1360" y="188" width="165" height="105" rx="12" fill="#fff8f0" stroke="#dd6b10" stroke-width="2"/>
  <text x="1442" y="220" text-anchor="middle" class="card-title">AI providers</text>
  <text x="1442" y="245" text-anchor="middle" class="card-text">Groq, OpenAI, Anthropic,</text>
  <text x="1442" y="266" text-anchor="middle" class="card-text">Gemini, Mistral, xAI</text>

  <rect x="1360" y="332" width="165" height="86" rx="12" fill="#fff8f0" stroke="#dd6b10" stroke-width="2"/>
  <text x="1442" y="365" text-anchor="middle" class="card-title">Let's Encrypt</text>
  <text x="1442" y="391" text-anchor="middle" class="card-text">Only with a domain</text>

  <rect x="1360" y="458" width="165" height="86" rx="12" fill="#fff8f0" stroke="#dd6b10" stroke-width="2"/>
  <text x="1442" y="491" text-anchor="middle" class="card-title">ghcr.io</text>
  <text x="1442" y="517" text-anchor="middle" class="card-text">Only during updates</text>

  <path d="M1325 245 H1360" stroke="#545b64" stroke-width="2" stroke-dasharray="6 5" marker-end="url(#arrow)"/>
  <path d="M1325 374 H1360" stroke="#545b64" stroke-width="2" stroke-dasharray="6 5" marker-end="url(#arrow)"/>
  <path d="M1325 500 H1360" stroke="#545b64" stroke-width="2" stroke-dasharray="6 5" marker-end="url(#arrow)"/>

  <text x="40" y="836" class="tiny">Solid blue/green: created by CloudFormation | Dashed gray: existing buyer resource or optional resource | Orange: external integration</text>
  <text x="40" y="858" class="tiny">Template creates one EC2 instance, security group, IAM role/profile, encrypted EBS data volume, SSM document and optional Data Lifecycle Manager policy.</text>
</svg>
`
}

const outputs = {
  'synaplan-new-vpc.svg': diagram({ existingVpc: false }),
  'synaplan-existing-vpc.svg': diagram({ existingVpc: true }),
}

let stale = false
for (const [fileName, generated] of Object.entries(outputs)) {
  const outputPath = resolve(diagramRoot, fileName)
  if (checkOnly) {
    const current = await readFile(outputPath, 'utf8').catch(() => '')
    if (current !== generated) {
      console.error(
        `${outputPath} is stale; run node scripts/generate-aws-marketplace-diagrams.mjs`,
      )
      stale = true
    }
  } else {
    await writeFile(outputPath, generated)
    console.log(`Generated ${outputPath}`)
  }
}

if (stale) process.exit(1)
