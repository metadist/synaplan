#!/usr/bin/env node

// Which built AMIs may be deleted, and which may never be.
//
// Every release leaves an AMI and a 30 GiB snapshot behind, and nothing used to
// remove them. Twenty-six had accumulated from 4.2.4 onwards — eleven of them
// from a single day of iterating on one release — next to two Packer builders
// that a cancelled run had left stopped on 60 GiB of gp3 for eleven days. It
// reached roughly $0.36 a day, growing with every release, before anyone looked
// at the bill.
//
// The decision lives here rather than inline in the workflow because getting it
// wrong deletes an image a published Marketplace version launches from, which
// cannot be undone. It is covered by tests for that reason.

import { pathToFileURL } from 'node:url'
import { resolve } from 'node:path'

// Releases whose images are kept in full, newest first, on top of anything the
// listing points at. Two rather than one so that the previous release is still
// there to resubmit or compare against when a new one turns out to be broken.
export const DEFAULT_KEEP_RELEASES = 2

// Sorts newest first. Release strings only ever reach here as `x.y.z`, so the
// comparison is numeric per segment — sorting them as text would rank 4.4.10
// below 4.4.9 and quietly expire the newest release.
const byVersionDescending = (a, b) => {
  const left = a.split('.').map(Number)
  const right = b.split('.').map(Number)

  for (let index = 0; index < Math.max(left.length, right.length); index += 1) {
    const difference = (right[index] ?? 0) - (left[index] ?? 0)
    if (difference !== 0) {
      return difference
    }
  }
  return 0
}

const RELEASE = /^\d+\.\d+\.\d+$/

/**
 * @param {object} input
 * @param {Array<{imageId: string, version?: string, snapshotIds?: string[]}>} input.images
 *   Every AMI the account owns, as read from `describe-images`.
 * @param {string[]} input.protectedImageIds
 *   The images a Marketplace version launches from. Deleting one of these
 *   breaks a live listing, so they are kept no matter how old they are — the
 *   listing still offers 4.2.4 alongside the current release.
 * @param {number} [input.keepReleases]
 */
export const selectExpiredImages = ({
  images,
  protectedImageIds,
  keepReleases = DEFAULT_KEEP_RELEASES,
}) => {
  if (!Array.isArray(images)) {
    throw new Error('images must be an array of AMIs')
  }
  // Not defaulted: an empty list is a legitimate answer, a missing one means the
  // caller never asked the listing, and the difference decides whether a
  // published image is deleted.
  if (!Array.isArray(protectedImageIds)) {
    throw new Error('protectedImageIds must be an array; pass [] only if the listing was read and offers nothing')
  }
  if (!Number.isInteger(keepReleases) || keepReleases < 1) {
    throw new Error(`keepReleases must be a positive integer, got ${JSON.stringify(keepReleases)}`)
  }

  // An image without a release tag was not built by this pipeline. Somebody
  // made it by hand, and it is not this job's to delete.
  const ours = images.filter((image) => RELEASE.test(image.version ?? ''))

  const keptReleases = new Set(
    [...new Set(ours.map((image) => image.version))].sort(byVersionDescending).slice(0, keepReleases)
  )
  const kept = new Set(protectedImageIds)

  return ours.filter((image) => !kept.has(image.imageId) && !keptReleases.has(image.version))
}

const readOption = (arguments_, name) => {
  const index = arguments_.indexOf(name)
  return index === -1 ? null : (arguments_[index + 1] ?? null)
}

export const runCli = (arguments_ = []) => {
  const [command, ...options] = arguments_

  if (command !== 'expired') {
    throw new Error(`unknown command ${JSON.stringify(command ?? '')}, expected expired`)
  }

  const keepReleases = readOption(options, '--keep-releases')
  const expired = selectExpiredImages({
    images: JSON.parse(readOption(options, '--images') ?? 'null'),
    protectedImageIds: JSON.parse(readOption(options, '--protected') ?? 'null'),
    ...(keepReleases === null ? {} : { keepReleases: Number(keepReleases) }),
  })

  // One image per line, its snapshots space separated after it, because the
  // caller is a shell that reads it line by line.
  return expired
    .map((image) => `${image.imageId} ${image.version} ${(image.snapshotIds ?? []).join(' ')}`.trimEnd())
    .map((line) => `${line}\n`)
    .join('')
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    process.stdout.write(runCli(process.argv.slice(2)))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
