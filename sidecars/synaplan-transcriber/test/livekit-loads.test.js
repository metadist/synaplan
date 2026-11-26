import assert from 'node:assert/strict'
import test from 'node:test'

test('the LiveKit client loads in this image', async () => {
  const { AudioStream, Room, TrackKind } = await import('@livekit/rtc-node')
  assert.equal(typeof Room, 'function')
  assert.equal(typeof AudioStream, 'function')
  assert.equal(typeof TrackKind.KIND_AUDIO, 'number')
})
