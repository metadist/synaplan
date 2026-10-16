import WebSocket from 'ws'
import { createTurnAssembler } from '../turns.js'

function listen(map, event, fn) {
  const list = map.get(event) ?? []
  list.push(fn)
  map.set(event, list)
}

function emit(map, event, payload) {
  for (const fn of map.get(event) ?? []) fn(payload)
}

/**
 * Gemini Live. Facts go through consult_synaplan. A GoAway reconnects with
 * the resumption handle instead of starting a second billed conversation.
 */
export function createGeminiAdapter({
  apiKey,
  url,
  WebSocketImpl = WebSocket,
} = {}) {
  const socketUrl = url ?? `wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent?key=${apiKey ?? ''}`
  const listeners = new Map()
  const turns = createTurnAssembler((turn) => emit(listeners, 'transcript', turn))
  let ws = null
  let config = null
  let handle = ''
  let opened = false
  let closing = false

  const send = (payload) => {
    if (ws && ws.readyState === WebSocketImpl.OPEN) ws.send(JSON.stringify(payload))
  }

  const setup = () => {
    const generationConfig = { responseModalities: ['AUDIO'] }
    const voice = String(config.voice ?? '').trim()
    if (voice !== '') {
      generationConfig.speechConfig = {
        voiceConfig: { prebuiltVoiceConfig: { voiceName: voice } },
      }
    }
    const body = {
      model: `models/${config.model}`,
      generationConfig,
      systemInstruction: { parts: [{ text: config.instructions }] },
      tools: [{
        functionDeclarations: [{
          name: 'consult_synaplan',
          description: 'Ask Synaplan for the answer. Required for every factual question and every request to create an image, video, audio or document. Pass the whole request.',
          parameters: {
            type: 'OBJECT',
            properties: { question: { type: 'STRING' } },
            required: ['question'],
          },
        }],
      }],
      inputAudioTranscription: {},
      outputAudioTranscription: {},
      realtimeInputConfig: {
        automaticActivityDetection: { endOfSpeechSensitivity: 'END_SENSITIVITY_HIGH' },
      },
      contextWindowCompression: { slidingWindow: {} },
      sessionResumption: handle ? { handle } : {},
    }
    send({ setup: body })
  }

  const connect = () => new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('provider_timeout')), 20000)
    ws = new WebSocketImpl(socketUrl)
    ws.on('error', (error) => {
      clearTimeout(timer)
      if (!opened) reject(error)
      else emit(listeners, 'error', error)
    })
    ws.on('open', () => setup())
    ws.on('message', (raw) => {
      let event
      try {
        event = JSON.parse(raw.toString())
      } catch {
        return
      }
      if (event.sessionResumptionUpdate?.newHandle) handle = String(event.sessionResumptionUpdate.newHandle)
      if (event.goAway && !closing) {
        clearTimeout(timer)
        ws?.close()
        connect().catch((error) => emit(listeners, 'error', error))
        return
      }
      if (event.setupComplete) {
        opened = true
        clearTimeout(timer)
        resolve()
      }
      const input = event.serverContent?.inputTranscription
      if (input?.text) turns.append('user', String(input.text))
      if (input?.finished) turns.commit('user')
      const output = event.serverContent?.outputTranscription
      if (output?.text) turns.append('assistant', String(output.text))
      if (output?.finished) turns.commit('assistant')
      for (const part of event.serverContent?.modelTurn?.parts ?? []) {
        if (part.inlineData?.data) emit(listeners, 'audio', Buffer.from(part.inlineData.data, 'base64'))
      }
      for (const call of event.toolCall?.functionCalls ?? []) {
        const args = call.args ?? {}
        emit(listeners, 'tool', {
          id: call.id,
          name: call.name,
          question: String(args.question ?? ''),
        })
      }
      if (event.usageMetadata) emit(listeners, 'usage', mapGeminiUsage(event.usageMetadata))
      if (event.serverContent?.interrupted) {
        turns.commit('assistant')
        emit(listeners, 'interrupted')
      }
      if (event.serverContent?.turnComplete) turns.commit('assistant')
    })
    ws.on('close', () => {
      clearTimeout(timer)
      if (!opened && !handle) reject(new Error('provider_closed'))
    })
  })

  return {
    on: (event, fn) => listen(listeners, event, fn),
    open: async (next) => {
      config = next
      await connect()
    },
    sendAudio(chunk) {
      send({
        realtimeInput: {
          audio: { mimeType: 'audio/pcm;rate=16000', data: Buffer.from(chunk).toString('base64') },
        },
      })
    },
    interrupt() {},
    inject(payload) {
      if (payload.kind !== 'tool') return
      send({
        toolResponse: {
          functionResponses: [{
            id: payload.id,
            name: payload.name,
            response: { result: payload.text, scheduling: 'WHEN_IDLE' },
          }],
        },
      })
    },
    close() {
      closing = true
      ws?.close()
      turns.commitAll()
      emit(listeners, 'closed')
    },
    resumptionHandle: () => handle,
  }
}

export function mapGeminiUsage(usage = {}) {
  const audioIn = tokensFor(usage.promptTokensDetails, 'AUDIO')
  const audioOut = tokensFor(usage.responseTokensDetails ?? usage.candidatesTokensDetails, 'AUDIO')
  return {
    seconds: Number(usage.seconds ?? 0),
    audioInTokens: audioIn,
    audioOutTokens: audioOut,
    audioCacheTokens: tokensFor(usage.cacheTokensDetails, 'AUDIO'),
    textInTokens: Math.max(0, Number(usage.promptTokenCount ?? 0) - audioIn),
    textOutTokens: Math.max(0, Number(usage.responseTokenCount ?? usage.candidatesTokenCount ?? 0) - audioOut),
  }
}

function tokensFor(details, modality) {
  if (!Array.isArray(details)) return 0
  return details
    .filter((row) => row && row.modality === modality)
    .reduce((sum, row) => sum + Number(row.tokenCount ?? 0), 0)
}
