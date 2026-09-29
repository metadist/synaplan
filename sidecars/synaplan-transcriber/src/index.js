import { loadConfig } from './config.js'
import { createElementBot } from './elementBot.js'
import { createApp } from './server.js'
import { createSynaplanClient } from './synaplan.js'

const config = loadConfig(process.env)
const synaplan = createSynaplanClient({ baseUrl: config.synaplanUrl, apiKey: config.apiKey })
const log = (line) => {
  process.stdout.write(`${new Date().toISOString()} ${line}\n`)
}

const server = createApp(config, { synaplan, log })
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
} else if (config.elementRequested) {
  log('Element mode is selected, but MATRIX_HOMESERVER, MATRIX_ACCESS_TOKEN, and MATRIX_USER_ID are not all set. Voice messages stay off.')
}

if (!config.jitsi) {
  log('Jitsi captions are off in this mode.')
}
