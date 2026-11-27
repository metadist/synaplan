/**
 * Subscribes to every microphone in an OpenTalk LiveKit room and hands
 * 16 kHz mono frames to the meeting. Loaded only when a meeting starts,
 * so the tests never open the native library.
 */
export async function connectLiveKit({
  url,
  token,
  onFrame,
  onParticipant = () => {},
  log = () => {},
}) {
  const { AudioStream, Room, RoomEvent, TrackKind } =
    await import("@livekit/rtc-node");
  const room = new Room();
  const seen = new Set();
  const report = (participant) => {
    const identity = String(participant?.identity || "");
    if (identity === "" || seen.has(identity)) {
      return;
    }
    seen.add(identity);
    onParticipant(participant);
  };
  room.on(RoomEvent.ParticipantConnected, report);
  room.on(RoomEvent.TrackSubscribed, (track, _publication, participant) => {
    report(participant);
    if (track.kind !== TrackKind.KIND_AUDIO) {
      return;
    }
    const stream = new AudioStream(track, {
      sampleRate: 16000,
      numChannels: 1,
    });
    const pump = async () => {
      for await (const frame of stream) {
        onFrame(String(participant.identity || "speaker"), frame.data);
      }
    };
    pump().catch((error) =>
      log(`audio from ${participant.identity} stopped (${error.message})`),
    );
  });
  await room.connect(url, token, { autoSubscribe: true });
  for (const participant of room.remoteParticipants?.values?.() || []) {
    report(participant);
  }
  return {
    async close() {
      await room.disconnect();
    },
  };
}
