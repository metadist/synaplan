# Builds the Azure Marketplace image for Synaplan.
#
# The image contains the application tree, the container runtime, Caddy as TLS
# terminator and the pre-pulled container images — and NO configuration and NO
# secrets. Everything instance-specific is produced on the first boot by
# scripts/firstboot.sh, so the same image can be deployed any number of times.
#
# The result is a version in an Azure Compute Gallery. Partner Center ingests
# from a gallery rather than from a SAS URI: the gallery keeps the image
# replicated and versioned, and the publishing account grants Microsoft read
# access to it instead of handing out a signed URL that expires.
#
# Build:
#   az login
#   packer init  deploy/azure/packer/synaplan.pkr.hcl
#   packer build -var synaplan_version=1.4.0 \
#     -var gallery_resource_group=synaplan-images \
#     -var gallery_name=synaplan \
#     deploy/azure/packer/synaplan.pkr.hcl

packer {
  required_plugins {
    azure = {
      version = ">= 2.1.0"
      source  = "github.com/hashicorp/azure"
    }
  }
}

variable "synaplan_version" {
  type = string
  # Raised by scripts/set-release-version.mjs after every published release, so
  # a build with no arguments produces the image for the release this branch
  # ships. The release workflow still passes it explicitly.
  default     = "4.0.16"
  description = "Released SemVer version to bake in, without a leading v. Never a mutable tag: the first boot pins deploy/.env to exactly this value."

  validation {
    condition     = can(regex("^[0-9]+\\.[0-9]+\\.[0-9]+(-[0-9A-Za-z.-]+)?$", var.synaplan_version))
    error_message = "The version must be an immutable SemVer version such as 1.4.0. Mutable tags like 'latest' are rejected by validate-release.sh at boot anyway."
  }
}

variable "architecture" {
  type        = string
  default     = "x86_64"
  description = "x86_64 or arm64. arm64 produces the Ampere Altra image; the container images are multi-arch."

  validation {
    condition     = contains(["x86_64", "arm64"], var.architecture)
    error_message = "The architecture must be either x86_64 or arm64."
  }
}

variable "location" {
  type        = string
  default     = "westeurope"
  description = "Region the build VM runs in. Where the finished image is available is decided by replication_regions, not by this."
}

variable "vm_size" {
  type        = string
  default     = ""
  description = "Build VM size. Empty picks a sensible default for the architecture — the build only pulls images, so it does not need the runtime size."
}

variable "os_disk_size_gb" {
  type        = number
  default     = 30
  description = "OS disk in GiB. Holds the operating system and the pre-pulled container images; user data lives on a separate data disk."
}

variable "gallery_subscription" {
  type        = string
  default     = ""
  description = "Subscription holding the Compute Gallery. Empty uses the subscription the CLI is logged in to."
}

variable "gallery_resource_group" {
  type        = string
  default     = "synaplan-images"
  description = "Resource group of the Compute Gallery the finished version is published into."
}

variable "gallery_name" {
  type        = string
  default     = "synaplan"
  description = "Name of the Compute Gallery. It must already exist, with the two image definitions below."
}

variable "image_version" {
  type        = string
  default     = ""
  description = "Gallery image version, which must be plain major.minor.patch. Empty derives it from synaplan_version by dropping any pre-release suffix, which a gallery does not accept."
}

variable "replication_regions" {
  type        = list(string)
  default     = ["westeurope", "northeurope", "eastus"]
  description = "Regions the gallery version is replicated to. A customer can only deploy the image in a region it exists in."
}

locals {
  is_arm = var.architecture == "arm64"

  # Canonical's free Ubuntu 24.04 LTS server images. `server` is the Gen2 x64
  # SKU; `server-arm64` is Gen2 by definition. Neither carries a licence cost,
  # and neither needs a `plan_info` block — a paid base image would have to be
  # declared to the Marketplace and would make the offer non-free.
  image_sku = local.is_arm ? "server-arm64" : "server"

  vm_size = var.vm_size != "" ? var.vm_size : (local.is_arm ? "Standard_D4ps_v5" : "Standard_D4s_v5")

  gallery_image_name = local.is_arm ? "synaplan-arm64" : "synaplan-x64"

  image_version = var.image_version != "" ? var.image_version : split("-", var.synaplan_version)[0]

  timestamp = regex_replace(timestamp(), "[- TZ:]", "")
  repo_root = "${path.root}/../../.."
}

source "azure-arm" "synaplan" {
  # No client id and no secret in this repository. Locally that is `az login`;
  # in CI it is the federated credential azure/login@v2 exchanges for a token.
  use_azure_cli_auth = true

  os_type         = "Linux"
  image_publisher = "Canonical"
  image_offer     = "ubuntu-24_04-lts"
  image_sku       = local.image_sku

  location = var.location
  vm_size  = local.vm_size

  os_disk_size_gb = var.os_disk_size_gb

  # Trusted Launch: secure boot and a vTPM. Azure Marketplace requires the image
  # definition to declare it, and the build VM has to be created the same way or
  # the captured image cannot be published against a TrustedLaunchSupported
  # definition.
  security_type       = "TrustedLaunch"
  secure_boot_enabled = true
  vtpm_enabled        = true

  ssh_username = "packer"

  # The intermediate managed image the gallery version is created from. Packer
  # requires it; it is deleted by the gallery publish step.
  managed_image_name                = "synaplan-${var.synaplan_version}-${var.architecture}-${local.timestamp}"
  managed_image_resource_group_name = var.gallery_resource_group

  shared_image_gallery_destination {
    subscription         = var.gallery_subscription
    resource_group       = var.gallery_resource_group
    gallery_name         = var.gallery_name
    image_name           = local.gallery_image_name
    image_version        = local.image_version
    replication_regions  = var.replication_regions
    storage_account_type = "Standard_LRS"
  }

  azure_tags = {
    SynaplanVersion = var.synaplan_version
    Architecture    = var.architecture
    BuiltBy         = "packer"
  }
}

build {
  name    = "synaplan"
  sources = ["source.azure-arm.synaplan"]

  # The file provisioner does not create parent directories.
  provisioner "shell" {
    inline = ["mkdir -p /tmp/synaplan/deploy"]
  }

  # The portable deployment contract, unmodified. The Azure adapter calls these
  # scripts; it never reimplements them.
  provisioner "file" {
    source      = "${local.repo_root}/deploy/compose.yaml"
    destination = "/tmp/synaplan/deploy/compose.yaml"
  }

  provisioner "file" {
    source      = "${local.repo_root}/deploy/scripts"
    destination = "/tmp/synaplan/deploy"
  }

  # The host layer every cloud image shares: TLS terminator configuration, the
  # update sequencer, the stop command and the image-bake pull.
  provisioner "file" {
    source      = "${local.repo_root}/deploy/host"
    destination = "/tmp/synaplan/deploy"
  }

  provisioner "file" {
    source      = "${local.repo_root}/deploy/azure"
    destination = "/tmp/synaplan/deploy"
  }

  provisioner "shell" {
    environment_vars = [
      "SYNAPLAN_VERSION=${var.synaplan_version}",
    ]
    execute_command = "sudo -E bash -c '{{ .Vars }} {{ .Path }}'"
    script          = "${local.repo_root}/deploy/azure/scripts/provision.sh"
  }

  # Second to last, so nothing above can leave a key, a log or a shell history
  # behind.
  provisioner "shell" {
    execute_command = "sudo -E bash -c '{{ .Vars }} {{ .Path }}'"
    script          = "${local.repo_root}/deploy/azure/scripts/harden.sh"
  }

  # Last, and it must be last: deprovisioning removes the build user Packer is
  # connected as, so nothing can run over this SSH session afterwards. It is
  # what generalises the VM — without it every deployed instance would come up
  # carrying this build's host name, user and agent state.
  provisioner "shell" {
    inline = [
      "/usr/sbin/waagent -force -deprovision+user && export HISTSIZE=0 && sync",
    ]
    execute_command = "chmod +x {{ .Path }}; sudo -E sh -c '{{ .Vars }} {{ .Path }}'"
    inline_shebang  = "/bin/sh -x"
    skip_clean      = true
  }

  post-processor "manifest" {
    output     = "packer-manifest.json"
    strip_path = true
    custom_data = {
      synaplan_version   = var.synaplan_version
      architecture       = var.architecture
      gallery_image_name = local.gallery_image_name
      image_version      = local.image_version
    }
  }
}
