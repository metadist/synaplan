import assert from "node:assert/strict";
import { EventEmitter } from "node:events";
import test from "node:test";
import { loadConfig } from "../src/config.js";
import {
  createOpenTalkBridge,
  signServiceToken,
  tokenMatches,
  wavFromPcm,
} from "../src/opentalk.js";
import { createApp } from "../src/server.js";

const key = { id: "transcription", secret: "secret" };
const serviceKey = { id: "controller", secret: "secret" };

test("a service token verifies only for its own key and only while it is fresh", () => {
  const now = 1_700_000_000_000;
  const token = signServiceToken(key, now);
  assert.equal(tokenMatches(token, key, now + 1000), true);
  assert.equal(tokenMatches(token, key, now + 61_000), false);
  assert.equal(
    tokenMatches(token, { id: "other", secret: "secret" }, now + 1000),
    false,
  );
  assert.equal(tokenMatches(`${token}x`, key, now + 1000), false);
});

test("pcm becomes a wav window a speech server accepts", () => {
  const wav = wavFromPcm(Int16Array.from([0, 1000, -1000]));
  assert.equal(wav.subarray(0, 4).toString("ascii"), "RIFF");
  assert.equal(wav.subarray(8, 12).toString("ascii"), "WAVE");
  assert.equal(wav.length, 44 + 6);
});

function socketPair() {
  const sockets = [];
  return {
    sockets,
    openSocket() {
      const socket = new EventEmitter();
      socket.readyState = 1;
      socket.sent = [];
      socket.send = (data) => socket.sent.push(JSON.parse(data));
      socket.close = () => {
        socket.readyState = 3;
      };
      socket.removeListener = EventEmitter.prototype.removeListener;
      sockets.push(socket);
      queueMicrotask(() => {
        socket.emit(
          "message",
          JSON.stringify({
            namespace: "core",
            payload: { message: "joined_lobby" },
          }),
        );
      });
      return socket;
    },
  };
}

test("a signed init joins the meeting and a spoken window becomes a subtitle", async () => {
  const windows = [];
  const events = [];
  const media = [];
  const { sockets, openSocket } = socketPair();
  const bridge = createOpenTalkBridge({
    controllerUrl: "http://controller",
    serviceKey,
    initKey: key,
    livekitUrl: "http://livekit:7880",
    now: () => 1_700_000_000_000,
    plugin: {
      async create(room, language) {
        assert.equal(room, "room-1");
        assert.equal(language, "de");
        return { ref: "Ab3dEf9hJkLmN0pQrStUvW", language: "de" };
      },
      async window(ref, fields) {
        windows.push({
          ref,
          ...fields,
          audio: fields.audio.subarray(0, 4).toString("ascii"),
        });
        return { text: "Guten Tag" };
      },
      async event(ref, payload) {
        events.push({ ref, ...payload });
      },
    },
    async connectLiveKit(options) {
      media.push(options);
      return { async close() {} };
    },
    openSocket,
    async fetchImpl(url, options) {
      assert.equal(url, "http://controller/internal/transcription/start");
      assert.match(options.headers.authorization, /^Bearer /);
      return {
        status: 200,
        async text() {
          return JSON.stringify({
            token: "admit",
            roomserver_address: "http://room/",
          });
        },
      };
    },
  });

  const denied = await bridge.accept("", { room_id: "room-1", language: "de" });
  assert.equal(denied.status, 401);

  const accepted = await bridge.accept(
    `Bearer ${signServiceToken(key, 1_700_000_000_000)}`,
    { room_id: "room-1", language: "de" },
  );
  assert.equal(accepted.status, 204);
  const socket = sockets[0];
  assert.deepEqual(
    socket.sent.map(
      (message) => message.payload.event?.kind || message.payload.action,
    ),
    ["enter_room", "request_popout_stream_access_token", "started"],
  );

  socket.emit(
    "message",
    JSON.stringify({
      namespace: "livekit",
      payload: { message: "popout_stream_access_token", token: "lk-token" },
    }),
  );
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(media[0].url, "http://livekit:7880");
  assert.equal(media[0].token, "lk-token");

  await media[0].onParticipant({
    identity: "ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10:connection",
    name: "Ada",
    metadata: JSON.stringify({ email: "ada@example.com", sub: "sub-ada" }),
  });
  await new Promise((resolve) => setImmediate(resolve));
  const people = events.find((event) => event.type === "participants");
  assert.equal(
    people.participants[0].id,
    "ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10",
  );
  assert.equal(people.participants[0].name, "Ada");
  assert.equal(people.participants[0].sub, "sub-ada");

  const loud = new Int16Array(16000 * 4).fill(8000);
  media[0].onFrame("speaker-1", loud);
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(windows.length, 1);
  assert.equal(windows[0].audio, "RIFF");
  assert.equal(windows[0].endpointId, "speaker-1");
  assert.equal(socket.sent.at(-1).payload.event.text, "Guten Tag");

  media[0].onFrame("ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10:connection", loud);
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(
    socket.sent.at(-1).payload.event.participant_id,
    "ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10",
  );

  socket.emit(
    "message",
    JSON.stringify({
      namespace: "transcription",
      payload: {
        message: "service_command",
        command: { kind: "change_language", language: "en" },
      },
    }),
  );
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(events.at(-1).type, "language");
  assert.equal(events.at(-1).language, "en");
  assert.equal(socket.sent.at(-1).payload.event.kind, "updated");
  assert.equal(socket.sent.at(-1).payload.event.language, "en");

  const refused = await bridge.accept(
    `Bearer ${signServiceToken(key, 1_700_000_000_000)}`,
    {
      room_id: "room-1",
      language: "de",
      breakout_room: "side",
    },
  );
  assert.equal(refused.status, 422);

  socket.emit(
    "message",
    JSON.stringify({
      namespace: "transcription",
      payload: { message: "service_command", command: { kind: "stop" } },
    }),
  );
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(events.at(-1).type, "finished");
  assert.equal(socket.sent.at(-1).payload.event.kind, "stopped");
});

test("a meeting that never answers is closed and the plugin session ends", async () => {
  const events = [];
  const bridge = createOpenTalkBridge({
    controllerUrl: "http://controller",
    serviceKey,
    initKey: key,
    now: () => 1_700_000_000_000,
    lobbyWaitMs: 20,
    plugin: {
      async create() {
        return { ref: "Ab3dEf9hJkLmN0pQrStUvW", language: "de" };
      },
      async window() {
        return { text: "" };
      },
      async event(_ref, payload) {
        events.push(payload);
      },
    },
    openSocket() {
      const socket = new EventEmitter();
      socket.readyState = 1;
      socket.sent = [];
      socket.send = () => {};
      socket.close = () => {
        socket.readyState = 3;
        socket.emit("close");
      };
      return socket;
    },
    async fetchImpl() {
      return {
        status: 200,
        async text() {
          return JSON.stringify({
            token: "admit",
            roomserver_address: "http://room/",
          });
        },
      };
    },
  });
  const result = await bridge.accept(
    `Bearer ${signServiceToken(key, 1_700_000_000_000)}`,
    { room_id: "room-1", language: "de" },
  );
  assert.equal(result.status, 502);
  assert.equal(events.at(-1).type, "finished");
});

test("a pause does not stretch the next subtitle back across the silence", async () => {
  let clock = 1_000;
  const windows = [];
  let onFrame = null;
  const { sockets, openSocket } = socketPair();
  const bridge = createOpenTalkBridge({
    controllerUrl: "http://controller",
    serviceKey,
    initKey: key,
    now: () => clock,
    plugin: {
      async create() {
        return { ref: "Ab3dEf9hJkLmN0pQrStUvW", language: "de" };
      },
      async window(_ref, fields) {
        windows.push({ t0Ms: fields.t0Ms, t1Ms: fields.t1Ms });
        return { text: "" };
      },
      async event() {},
    },
    async connectLiveKit({ onFrame: hear }) {
      onFrame = hear;
      return { async close() {} };
    },
    openSocket,
    async fetchImpl() {
      return {
        status: 200,
        async text() {
          return JSON.stringify({
            token: "admit",
            roomserver_address: "http://room/",
          });
        },
      };
    },
  });
  await bridge.accept(`Bearer ${signServiceToken(key, clock)}`, {
    room_id: "room-1",
    language: "de",
  });
  sockets[0].emit(
    "message",
    JSON.stringify({
      namespace: "livekit",
      payload: { message: "popout_stream_access_token", token: "lk" },
    }),
  );
  await new Promise((resolve) => setImmediate(resolve));
  onFrame("speaker-1", new Int16Array(1600));
  clock += 40_000;
  onFrame("speaker-1", new Int16Array(16_000).fill(8000));
  onFrame("speaker-1", new Int16Array(1600));
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(windows.length, 1);
  assert.ok(windows[0].t1Ms - windows[0].t0Ms < 5_000);
});

test("a participant is recorded only after Synaplan accepts them, and a temporary failure is tried again before the meeting ends", async () => {
  const events = [];
  let attempts = 0;
  const { sockets, openSocket } = socketPair();
  let onParticipant = null;
  const bridge = createOpenTalkBridge({
    controllerUrl: "http://controller",
    serviceKey,
    initKey: key,
    livekitUrl: "http://livekit:7880",
    now: () => 1_700_000_000_000,
    sleep: async () => {},
    plugin: {
      async create() {
        return { ref: "Ab3dEf9hJkLmN0pQrStUvW", language: "de" };
      },
      async window() {
        return { text: "" };
      },
      async event(_ref, payload) {
        if (payload.type === "participants") {
          attempts += 1;
          if (attempts < 3) {
            const error = new Error("unavailable");
            error.status = 503;
            throw error;
          }
        }
        events.push(payload);
      },
    },
    async connectLiveKit(options) {
      onParticipant = options.onParticipant;
      return { async close() {} };
    },
    openSocket,
    async fetchImpl() {
      return {
        status: 200,
        async text() {
          return JSON.stringify({
            token: "admit",
            roomserver_address: "http://room/",
          });
        },
      };
    },
  });

  const accepted = await bridge.accept(
    `Bearer ${signServiceToken(key, 1_700_000_000_000)}`,
    { room_id: "room-1", language: "de" },
  );
  assert.equal(accepted.status, 204);
  sockets[0].emit(
    "message",
    JSON.stringify({
      namespace: "livekit",
      payload: { message: "popout_stream_access_token", token: "lk" },
    }),
  );
  await new Promise((resolve) => setImmediate(resolve));
  await onParticipant({
    identity: "ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10:connection",
    name: "Ada",
    metadata: JSON.stringify({ sub: "sub-ada" }),
  });
  sockets[0].emit(
    "message",
    JSON.stringify({
      namespace: "transcription",
      payload: { message: "service_command", command: { kind: "stop" } },
    }),
  );
  for (let turn = 0; turn < 20 && events.at(-1)?.type !== "finished"; turn += 1) {
    await new Promise((resolve) => setImmediate(resolve));
  }

  const recorded = events.filter((event) => event.type === "participants");
  assert.equal(recorded.length, 1);
  assert.equal(recorded[0].participants[0].id, "ed4c78a1-b1ec-49cf-9c9c-ef43b11cfd10");
  assert.equal(attempts, 3);
  assert.equal(events.at(-1).type, "finished");
  assert.ok(events.findIndex((event) => event.type === "participants") < events.length - 1);
});

test("an oversized init is refused before a session starts", async () => {
  const config = loadConfig({
    SYNAPLAN_URL: "http://synaplan",
    SYNAPLAN_TRANSCRIBER_SECRET: "secret",
    TRANSCRIBER_MODE: "opentalk",
    OPENTALK_CONTROLLER_URL: "http://controller",
    OPENTALK_SERVICE_KEY: "controller:secret",
    OPENTALK_INIT_KEY: "transcription:secret",
    OPENTALK_LIVEKIT_URL: "http://livekit:7880",
  });
  let created = 0;
  const server = createApp(config, {
    synaplan: {},
    opentalk: {
      async accept() {
        created += 1;
        return { status: 204, body: {} };
      },
    },
  });
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  try {
    const response = await fetch(`http://127.0.0.1:${address.port}/v1/init`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: "x".repeat(5000),
    });
    assert.equal(response.status, 413);
    assert.equal(created, 0);
  } finally {
    server.close();
  }
});
