import assert from 'node:assert/strict'
import test from 'node:test'

import {
  DEFAULT_KEEP_RELEASES,
  selectExpiredImages,
  runCli,
} from '../scripts/ami-retention.mjs'

const image = (version, suffix = '') => ({
  imageId: `ami-${version.replaceAll('.', '')}${suffix}`,
  version,
  snapshotIds: [`snap-${version.replaceAll('.', '')}${suffix}`],
})

const expiredIds = (input) => selectExpiredImages(input).map((each) => each.imageId)

test('keeps the newest releases and expires the rest', () => {
  const images = [image('4.2.4'), image('4.3.0'), image('4.4.2'), image('4.4.3')]

  assert.deepEqual(expiredIds({ images, protectedImageIds: [], keepReleases: 2 }), [
    'ami-424',
    'ami-430',
  ])
  assert.deepEqual(expiredIds({ images, protectedImageIds: [], keepReleases: 1 }), [
    'ami-424',
    'ami-430',
    'ami-442',
  ])
  assert.deepEqual(expiredIds({ images, protectedImageIds: [], keepReleases: 9 }), [])
})

test('keeps every image of a kept release, not just one', () => {
  const images = [image('4.4.3', 'a'), image('4.4.3', 'b'), image('4.2.4')]

  assert.deepEqual(expiredIds({ images, protectedImageIds: [], keepReleases: 1 }), ['ami-424'])
})

// The listing still offers 4.2.4 next to the current release, and its version
// launches from an image this job would otherwise have expired long ago.
// Deleting it cannot be undone: a buyer's launch of that version breaks.
test('never expires an image a listing version launches from', () => {
  const images = [image('4.2.4'), image('4.3.0'), image('4.4.2'), image('4.4.3')]

  assert.deepEqual(
    expiredIds({ images, protectedImageIds: ['ami-424'], keepReleases: 1 }),
    ['ami-430', 'ami-442']
  )
})

// Sorted as text, 4.4.10 ranks below 4.4.9 and the newest release is the first
// thing deleted.
test('orders releases numerically, not alphabetically', () => {
  const images = [image('4.4.9'), image('4.4.10'), image('4.10.0'), image('4.9.0')]

  assert.deepEqual(expiredIds({ images, protectedImageIds: [], keepReleases: 2 }), [
    'ami-449',
    'ami-4410',
  ])
})

// An image somebody built by hand is not this job's to delete, and neither is
// an AMI belonging to something else entirely in the same account.
test('leaves images without a release tag alone', () => {
  const images = [
    image('4.4.3'),
    image('4.2.4'),
    { imageId: 'ami-manual', snapshotIds: ['snap-manual'] },
    { imageId: 'ami-branch', version: '4.4.4-rc1', snapshotIds: [] },
    { imageId: 'ami-latest', version: 'latest', snapshotIds: [] },
  ]

  assert.deepEqual(expiredIds({ images, protectedImageIds: [], keepReleases: 1 }), ['ami-424'])
})

test('reports the snapshots to delete with each image', () => {
  const expired = selectExpiredImages({
    images: [
      { imageId: 'ami-old', version: '4.2.4', snapshotIds: ['snap-a', 'snap-b'] },
      image('4.4.3'),
    ],
    protectedImageIds: [],
    keepReleases: 1,
  })

  assert.deepEqual(expired, [
    { imageId: 'ami-old', version: '4.2.4', snapshotIds: ['snap-a', 'snap-b'] },
  ])
})

// A caller that never managed to read the listing must not be mistaken for one
// that read it and found nothing, or a published image is deleted.
test('refuses to decide without having been told what is published', () => {
  const images = [image('4.4.3')]

  assert.throws(() => selectExpiredImages({ images }), /protectedImageIds must be an array/)
  assert.throws(
    () => selectExpiredImages({ images, protectedImageIds: null }),
    /protectedImageIds must be an array/
  )
  assert.throws(() => selectExpiredImages({ protectedImageIds: [] }), /images must be an array/)
})

test('refuses a retention that would keep nothing', () => {
  const images = [image('4.4.3')]

  for (const keepReleases of [0, -1, 1.5, 'two']) {
    assert.throws(
      () => selectExpiredImages({ images, protectedImageIds: [], keepReleases }),
      /keepReleases must be a positive integer/
    )
  }
})

test('keeps two releases unless told otherwise', () => {
  const images = [image('4.2.4'), image('4.4.2'), image('4.4.3')]

  assert.equal(DEFAULT_KEEP_RELEASES, 2)
  assert.deepEqual(expiredIds({ images, protectedImageIds: [] }), ['ami-424'])
})

test('prints one image per line for a shell to walk', () => {
  const images = JSON.stringify([
    { imageId: 'ami-old', version: '4.2.4', snapshotIds: ['snap-a', 'snap-b'] },
    { imageId: 'ami-new', version: '4.4.3', snapshotIds: ['snap-c'] },
  ])

  assert.equal(
    runCli(['expired', '--images', images, '--protected', '[]', '--keep-releases', '1']),
    'ami-old 4.2.4 snap-a snap-b\n'
  )
  assert.equal(runCli(['expired', '--images', images, '--protected', '["ami-old"]']), '')
})

test('refuses a command it does not know', () => {
  assert.throws(() => runCli(['delete-everything']), /unknown command/)
  assert.throws(() => runCli([]), /unknown command/)
})
