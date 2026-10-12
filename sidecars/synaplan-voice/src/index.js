import { createServer } from './server.js'

const port = Number(process.env.PORT ?? 8096)
const server = createServer()
server.listen(port, '0.0.0.0')
