import { i18n } from '@/i18n/instance'

/**
 * The translated name of a system setting, where the app has one: feature
 * switches (People policies) and the fields the config page labels itself.
 * Null means only the server's English title exists.
 */
export function localizedSettingName(key: string): string | null {
  const { t, te } = i18n.global
  const candidates = [
    key.startsWith('FEATURE_') ? `people.policies.feature.${key.slice('FEATURE_'.length)}` : null,
    `admin.config.fields.${key}`,
  ]
  const found = candidates.find((candidate) => candidate !== null && te(candidate))
  return found ? String(t(found)) : null
}
