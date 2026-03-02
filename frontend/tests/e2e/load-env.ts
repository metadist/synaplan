/** Load .env before config: ESM runs imports first, so env must be set before config.ts. Uses .env.local; .env.test when E2E_STACK=test. */
import dotenv from 'dotenv'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
dotenv.config({ path: path.join(__dirname, '.env.local') })
if (process.env.E2E_STACK === 'test') {
  dotenv.config({ path: path.join(__dirname, '.env.test'), override: true })
}
