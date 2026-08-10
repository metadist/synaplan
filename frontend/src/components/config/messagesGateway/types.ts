import type { MessagesGatewaySettings } from '@/services/api/messagesGatewayApi'

/**
 * The settings panel's working copy: the same settings the flags endpoint
 * accepts, but with every one resolved to a concrete value for the controls to
 * bind to. Derived from the generated request schema so a new backend setting
 * cannot be forgotten here.
 */
export type GatewayForm = {
  [K in keyof Required<MessagesGatewaySettings>]-?: NonNullable<MessagesGatewaySettings[K]>
}
