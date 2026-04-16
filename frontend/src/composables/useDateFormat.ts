import { useI18n } from 'vue-i18n'

const MINUTE_MS = 60_000
const HOUR_MS = 3_600_000
const DAY_MS = 86_400_000
const DAYS_RELATIVE_THRESHOLD = 7

export const useDateFormat = () => {
  const { t, locale } = useI18n()

  const formatTime = (date: Date): string => {
    return new Intl.DateTimeFormat(locale.value, {
      hour: '2-digit',
      minute: '2-digit',
    }).format(date)
  }

  const formatDate = (date: Date): string => {
    return new Intl.DateTimeFormat(locale.value, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).format(date)
  }

  const formatDateTime = (date: Date): string => {
    return new Intl.DateTimeFormat(locale.value, {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    }).format(date)
  }

  const formatRelativeTime = (date: Date): string => {
    const now = Date.now()
    const diffMs = now - date.getTime()

    if (diffMs < MINUTE_MS) return t('common.justNow')

    const diffMins = Math.floor(diffMs / MINUTE_MS)
    if (diffMins < 60) return t('common.minutesAgo', { count: diffMins }, diffMins)

    const diffHours = Math.floor(diffMs / HOUR_MS)
    if (diffHours < 24) return t('common.hoursAgo', { count: diffHours }, diffHours)

    const diffDays = Math.floor(diffMs / DAY_MS)
    if (diffDays < DAYS_RELATIVE_THRESHOLD)
      return t('common.daysAgo', { count: diffDays }, diffDays)

    return formatDate(date)
  }

  const getDateLabel = (date: Date): string => {
    const today = new Date()
    today.setHours(0, 0, 0, 0)

    const target = new Date(date)
    target.setHours(0, 0, 0, 0)

    const diffMs = today.getTime() - target.getTime()
    const diffDays = Math.round(diffMs / DAY_MS)

    if (diffDays === 0) return t('common.today')
    if (diffDays === 1) return t('common.yesterday')

    return formatDate(date)
  }

  return { formatTime, formatDate, formatDateTime, formatRelativeTime, getDateLabel }
}
