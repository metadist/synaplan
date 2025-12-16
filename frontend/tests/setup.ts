import { config } from '@vue/test-utils'
import { i18n } from '@/i18n'

config.global.plugins = [i18n]

// Suppress console.log in tests
global.console.log = () => {}

