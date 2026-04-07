/**
 * Legacy API - Compatibility with old widget system
 */

import { httpClient } from './httpClient'

export const legacyApi = {
  async sendLegacyMessage(widgetId: string, message: string): Promise<unknown> {
    return httpClient('/api/legacy/widget/message', {
      method: 'POST',
      body: JSON.stringify({ widgetId, message }),
    })
  },
}
