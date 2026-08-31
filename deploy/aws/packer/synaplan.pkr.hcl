# Builds the AWS Marketplace AMI for Synaplan.
#
# The image contains the application tree, the container runtime, Caddy as TLS
# terminator and the pre-pulled container images — and NO configuration and NO
# secrets. Everything instance-specific is produced on the first boot by
# scripts/firstboot.sh, so the same AMI can be launched any number of times.
#
# Build:
#   packer init  deploy/aws/packer/synaplan.pkr.hcl
#   packer build -var synaplan_version=1.4.0 deploy/aws/packer/synaplan.pkr.hcl

packer {
  required_plugins {
    amazon = {
      version = ">= 1.3.3"
      source  = "github.com/hashicorp/amazon"
    }
  }
}

variable "synaplan_version" {
  type = string
  # Raised by scripts/set-release-version.mjs after every published release, so
  # a build with no arguments produces the AMI for the release this branch
  # ships. The release workflow still passes it explicitly.
  default     = "4.4.2"
  description = "Released SemVer version to bake in, without a leading v. Never a mutable tag: the first boot pins deploy/.env to exactly this value."

  validation {
    condition     = can(regex("^[0-9]+\\.[0-9]+\\.[0-9]+(-[0-9A-Za-z.-]+)?$", var.synaplan_version))
    error_message = "The version must be an immutable SemVer version such as 1.4.0. Mutable tags like 'latest' are rejected by validate-release.sh at boot anyway."
  }
}

variable "region" {
  type        = string
  default     = "us-east-1"
  description = "Build region. The Marketplace ingests from us-east-1; other regions are produced by copying the AMI."
}

variable "architecture" {
  type        = string
  default     = "x86_64"
  description = "x86_64 or arm64. arm64 produces the Graviton image; the container images are multi-arch."

  validation {
    condition     = contains(["x86_64", "arm64"], var.architecture)
    error_message = "The architecture must be either x86_64 or arm64."
  }
}

variable "instance_type" {
  type        = string
  default     = ""
  description = "Build instance type. Empty picks a sensible default for the architecture — the build only pulls images, so it does not need the runtime size."
}

variable "root_volume_size" {
  type        = number
  default     = 30
  description = "Root volume in GiB. Holds the OS and the pre-pulled container images; user data lives on a separate volume."
}

variable "ami_name_prefix" {
  type        = string
  default     = "synaplan"
  description = "Prefix of the resulting AMI name; the version and a timestamp are appended."
}

locals {
  instance_type = var.instance_type != "" ? var.instance_type : (var.architecture == "arm64" ? "c7g.large" : "c7i.large")
  ssh_username  = "ec2-user"
  repo_root     = "${path.root}/../../.."
}

# Amazon Linux 2023: AWS maintains it, it is free of licence cost for the
# customer, and the Marketplace scanner knows it.
source "amazon-ebs" "synaplan" {
  region        = var.region
  instance_type = local.instance_type
  ssh_username  = local.ssh_username

  source_ami_filter {
    filters = {
      name                = "al2023-ami-2023.*-kernel-6.1-${var.architecture == "arm64" ? "arm64" : "x86_64"}"
      root-device-type    = "ebs"
      virtualization-type = "hvm"
    }
    owners      = ["amazon"]
    most_recent = true
  }

  # Both ASCII only. EC2 rejects anything beyond it in the description, and it
  # does so in ModifyImageAttribute, which runs after the image and its
  # snapshots already exist: an em dash here failed a ten-minute build at its
  # very last call. test-firstboot.sh guards the two lines.
  ami_name        = "${var.ami_name_prefix}-${var.synaplan_version}-${var.architecture}-{{timestamp}}"
  ami_description = "Synaplan ${var.synaplan_version} - AI knowledge management, all-in-one on a single instance"

  launch_block_device_mappings {
    device_name           = "/dev/xvda"
    volume_size           = var.root_volume_size
    volume_type           = "gp3"
    delete_on_termination = true
    # AWS Marketplace cannot ingest or scan an AMI backed by an encrypted
    # snapshot. The workflow verifies this after the build and Marketplace
    # encrypts its own copy during ingestion. Buyer launches are encrypted
    # separately by the CloudFormation templates.
    encrypted = false
  }

  # The published AMI's own root device. Marketplace re-encrypts on ingestion,
  # and an encrypted source AMI cannot be shared with the ingestion account.
  ami_block_device_mappings {
    device_name           = "/dev/xvda"
    volume_size           = var.root_volume_size
    volume_type           = "gp3"
    delete_on_termination = true
  }

  # IMDSv2 only, which is both the AWS default recommendation and what
  # firstboot.sh speaks.
  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 2
  }

  tags = {
    Name            = "${var.ami_name_prefix}-${var.synaplan_version}-${var.architecture}"
    SynaplanVersion = var.synaplan_version
    Architecture    = var.architecture
    BuiltBy         = "packer"
  }
}

build {
  name    = "synaplan"
  sources = ["source.amazon-ebs.synaplan"]

  # The file provisioner does not create parent directories.
  provisioner "shell" {
    inline = ["mkdir -p /tmp/synaplan/deploy /tmp/synaplan/_docker"]
  }

  # The portable deployment contract, unmodified. The AWS adapter calls these
  # scripts; it never reimplements them.
  provisioner "file" {
    source      = "${local.repo_root}/deploy/compose.yaml"
    destination = "/tmp/synaplan/deploy/compose.yaml"
  }

  provisioner "file" {
    source      = "${local.repo_root}/deploy/scripts"
    destination = "/tmp/synaplan/deploy"
  }

  provisioner "file" {
    source      = "${local.repo_root}/deploy/aws"
    destination = "/tmp/synaplan/deploy"
  }

  # compose.yaml bind-mounts ../_docker/centrifugo/config.json relative to
  # deploy/, which on the instance is /opt/synaplan/_docker/.... Without this
  # file Docker creates a directory at the mount point, Centrifugo never
  # becomes healthy, and synaplan.service waits out its 30-minute start budget.
  provisioner "file" {
    source      = "${local.repo_root}/_docker/centrifugo"
    destination = "/tmp/synaplan/_docker/centrifugo"
  }

  provisioner "shell" {
    environment_vars = [
      "SYNAPLAN_VERSION=${var.synaplan_version}",
    ]
    execute_command = "sudo -E bash -c '{{ .Vars }} {{ .Path }}'"
    script          = "${local.repo_root}/deploy/aws/scripts/provision.sh"
  }

  # Last, so nothing above can leave a key, a log or a shell history behind.
  provisioner "shell" {
    execute_command = "sudo -E bash -c '{{ .Vars }} {{ .Path }}'"
    script          = "${local.repo_root}/deploy/aws/scripts/harden.sh"
  }

  post-processor "manifest" {
    output     = "packer-manifest.json"
    strip_path = true
    custom_data = {
      synaplan_version = var.synaplan_version
      architecture     = var.architecture
    }
  }
}
