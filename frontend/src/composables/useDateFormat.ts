import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const MINUTE_MS = 60_000
const HOUR_MS = 3_600_000
const DAYS_RELATIVE_THRESHOLD = 7

function calendarDayDiff(a: Date, b: Date): number {
  const utcA = Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())
  const utcB = Date.UTC(b.getFullYear(), b.getMonth(), b.getDate())
  return Math.floor((utcA - utcB) / 86_400_000)
}

export const useDateFormat = () => {
  const { t, locale } = useI18n()

  const timeFormatter = computed(
    () =>
      new Intl.DateTimeFormat(locale.value, {
        hour: '2-digit',
        minute: '2-digit',
      })
  )

  const dateFormatter = computed(
    () =>
      new Intl.DateTimeFormat(locale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
      })
  )

  const dateTimeFormatter = computed(
    () =>
      new Intl.DateTimeFormat(locale.value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
  )

  const formatTime = (date: Date): string => timeFormatter.value.format(date)

  const formatDate = (date: Date): string => dateFormatter.value.format(date)

  const formatDateTime = (date: Date): string => dateTimeFormatter.value.format(date)

  const formatRelativeTime = (date: Date): string => {
    const now = Date.now()
    const diffMs = now - date.getTime()

    if (diffMs < MINUTE_MS) return t('common.justNow')

    const diffMins = Math.floor(diffMs / MINUTE_MS)
    if (diffMins < 60) return t('common.minutesAgo', { count: diffMins }, diffMins)

    const diffHours = Math.floor(diffMs / HOUR_MS)
    if (diffHours < 24) return t('common.hoursAgo', { count: diffHours }, diffHours)

    const diffDays = calendarDayDiff(new Date(now), date)
    if (diffDays > 0 && diffDays < DAYS_RELATIVE_THRESHOLD)
      return t('common.daysAgo', { count: diffDays }, diffDays)

    return formatDate(date)
  }

  const getDateLabel = (date: Date): string => {
    const diffDays = calendarDayDiff(new Date(), date)

    if (diffDays === 0) return t('common.today')
    if (diffDays === 1) return t('common.yesterday')

    return formatDate(date)
  }

  return { formatTime, formatDate, formatDateTime, formatRelativeTime, getDateLabel }
}
