import assert from 'node:assert/strict'
import test from 'node:test'
import { muxOpusPackets, oggCrc32, opusPacketSamples } from '../src/oggOpus.js'

test('ogg crc matches the reference vectors', () => {
  assert.equal(oggCrc32(Buffer.from('OggS')), 0x5fb0a94f)
  assert.equal(oggCrc32(Buffer.from(Uint8Array.from({ length: 32 }, (_, i) => i))), 0xc5d43637)
})

test('a 20 ms Opus frame is 960 samples', () => {
  assert.equal(opusPacketSamples(Buffer.from([0xf8, 0x00])), 960)
})

test('muxed audio is an Ogg Opus stream with a valid checksum', () => {
  const packet = Buffer.from([0xf8, 0x01, 0x02, 0x03])
  const ogg = muxOpusPackets([packet, packet], { serial: 7 })
  assert.equal(ogg.subarray(0, 4).toString(), 'OggS')
  assert.equal(ogg.indexOf('OpusHead'), 28)
  const checksum = ogg.readUInt32LE(22)
  const zeroed = Buffer.from(ogg.subarray(0, 27 + ogg[26] + 19))
  zeroed.writeUInt32LE(0, 22)
  assert.equal(oggCrc32(zeroed), checksum)
})
