import { createServer } from './server.js'

const MIN_SECRET_LENGTH = 32

if ((process.env.LIVE_VOICE_GATEWAY_SECRET ?? '').length < MIN_SECRET_LENGTH) {
  console.error(JSON.stringify({
    service: 'synaplan-voice',
    event: 'config_error',
    message: `LIVE_VOICE_GATEWAY_SECRET must be at least ${MIN_SECRET_LENGTH} characters and match the backend.`,
  }))
  process.exit(1)
}

const port = Number(process.env.PORT ?? 8096)
const server = createServer()
server.listen(port, '0.0.0.0')
