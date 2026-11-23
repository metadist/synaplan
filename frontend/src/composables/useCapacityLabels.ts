import { useI18n } from 'vue-i18n'

/**
 * Readable label for one value of a Capacity report column (route class,
 * AI source, tool source, outcome, …). A value this release does not know
 * yet shows as itself instead of a missing-key path.
 */
export function useCapacityLabels(): { label: (group: string, value: string) => string } {
  const { t, te } = useI18n()
  return {
    label: (group, value) => {
      const key = `capacity.${group}.${value}`
      return te(key) ? t(key) : value
    },
  }
}
