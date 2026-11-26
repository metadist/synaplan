/**
 * Subscribes to every microphone in an OpenTalk LiveKit room and hands
 * 16 kHz mono frames to the meeting. Loaded only when a meeting starts,
 * so the tests never open the native library.
 */
export async function connectLiveKit({ url, token, onFrame, log = () => {} }) {
  const { AudioStream, Room, RoomEvent, TrackKind } = await import('@livekit/rtc-node')
  const room = new Room()
  room.on(RoomEvent.TrackSubscribed, (track, _publication, participant) => {
    if (track.kind !== TrackKind.KIND_AUDIO) {
      return
    }
    const stream = new AudioStream(track, { sampleRate: 16000, numChannels: 1 })
    const pump = async () => {
      for await (const frame of stream) {
        onFrame(String(participant.identity || 'speaker'), frame.data)
      }
    }
    pump().catch((error) => log(`audio from ${participant.identity} stopped (${error.message})`))
  })
  await room.connect(url, token, { autoSubscribe: true })
  return {
    async close() {
      await room.disconnect()
    },
  }
}
