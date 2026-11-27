import { loadConfig } from './config.js'
import { createElementBot } from './elementBot.js'
import { createOpenTalkBridge } from './opentalk.js'
import { connectLiveKit } from './opentalkMedia.js'
import { createPluginClient } from './pluginSession.js'
import { createApp } from './server.js'
import { createSynaplanClient } from './synaplan.js'

const config = loadConfig(process.env)
const synaplan = config.apiKey ? createSynaplanClient({ baseUrl: config.synaplanUrl, apiKey: config.apiKey }) : null
const plugin = config.transcriberSecret ? createPluginClient({ baseUrl: config.synaplanUrl, secret: config.transcriberSecret }) : null
const log = (line) => {
  process.stdout.write(`${new Date().toISOString()} ${line}\n`)
}

const opentalk = config.opentalk
  ? createOpenTalkBridge({
    controllerUrl: config.opentalkControllerUrl,
    serviceKey: config.opentalkServiceKey,
    initKey: config.opentalkInitKey,
    livekitUrl: config.opentalkLivekitUrl,
    plugin,
    connectLiveKit,
    log,
  })
  : null
const server = createApp(config, { synaplan, plugin, opentalk, log })
const armed = []
if (config.jitsi && plugin) {
  armed.push('Jitsi meeting notes')
}
if (config.jitsi && synaplan) {
  armed.push('Jitsi captions without the plugin')
}
if (config.opentalk) {
  armed.push(`OpenTalk subtitles via ${config.opentalkControllerUrl}`)
}
if (config.element) {
  armed.push(`Element voice messages as ${config.matrixUserId}`)
}
server.listen(config.port, config.host, () => {
  log(`Meeting notes listening on ${config.host}:${config.port} (${config.mode}): ${armed.length > 0 ? armed.join('; ') : 'no path is armed'}.`)
})

if (config.element) {
  const bot = createElementBot({
    homeserver: config.matrixHomeserver,
    accessToken: config.matrixToken,
    userId: config.matrixUserId,
    synaplan,
    model: config.model,
    language: config.language,
    config,
    onLog: log,
  })
  bot.run()
} else if (config.elementRequested && !config.matrixReady) {
  log('Element mode is selected, but MATRIX_HOMESERVER, MATRIX_ACCESS_TOKEN, and MATRIX_USER_ID are not all set. Voice messages stay off.')
} else if (config.elementRequested) {
  log('Element mode is selected, but SYNAPLAN_API_KEY is not set. Voice messages stay off.')
}

if (config.jitsi && !plugin) {
  log('SYNAPLAN_TRANSCRIBER_SECRET is not set: Jitsi meetings cannot use the meeting-notes plugin.')
}
if (config.jitsi && !synaplan) {
  log('SYNAPLAN_API_KEY is not set: Jitsi captions without the meeting-notes plugin are refused.')
}
