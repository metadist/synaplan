import { loadConfig } from './config.js'
import { createElementBot } from './elementBot.js'
import { createPluginClient } from './pluginSession.js'
import { createApp } from './server.js'
import { createSynaplanClient } from './synaplan.js'

const config = loadConfig(process.env)
const synaplan = config.apiKey ? createSynaplanClient({ baseUrl: config.synaplanUrl, apiKey: config.apiKey }) : null
const plugin = config.transcriberSecret ? createPluginClient({ baseUrl: config.synaplanUrl, secret: config.transcriberSecret }) : null
const log = (line) => {
  process.stdout.write(`${new Date().toISOString()} ${line}\n`)
}

const server = createApp(config, { synaplan, plugin, log })
server.listen(config.port, config.host, () => {
  log(`Meeting notes listening on ${config.host}:${config.port} (${config.mode})`)
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
  log(`Element voice messages on as ${config.matrixUserId}`)
} else if (config.elementRequested && !config.matrixReady) {
  log('Element mode is selected, but MATRIX_HOMESERVER, MATRIX_ACCESS_TOKEN, and MATRIX_USER_ID are not all set. Voice messages stay off.')
} else if (config.elementRequested) {
  log('Element mode is selected, but SYNAPLAN_API_KEY is not set. Voice messages stay off.')
}

if (!config.jitsi) {
  log('Jitsi captions are off in this mode.')
} else {
  if (!plugin) {
    log('SYNAPLAN_TRANSCRIBER_SECRET is not set: Jitsi meetings cannot use the meeting-notes plugin.')
  }
  if (!synaplan) {
    log('SYNAPLAN_API_KEY is not set: Jitsi captions without the meeting-notes plugin are refused.')
  }
}
