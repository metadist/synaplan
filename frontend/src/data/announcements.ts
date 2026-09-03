/**
 * Catalogue of one-time product announcements.
 *
 * An announcement is shown once, in a modal, to the visitors it applies to, and
 * is remembered per browser once closed. Adding one means adding an entry here
 * plus its four translations — no component work.
 *
 * Two rules keep the feature from turning into noise:
 *
 *  - `until` retires it. Someone who signs up long after the fact should not be
 *    greeted with news that stopped being news, so an expired entry is skipped
 *    even for people who never saw it.
 *  - `applies` decides the audience. Anything tied to our hosted service must
 *    check the operator's own configuration, so a self-hosted instance never
 *    advertises somebody else's product to its users.
 */

/** What the catalogue may base its audience decisions on. */
export interface AnnouncementContext {
  /** App Store link the operator published; empty when they have none. */
  iosAppUrl: string
  /** Play Store link the operator published; empty when they have none. */
  androidAppUrl: string
  /** True inside the native shell, false in a browser. */
  isNativeApp: boolean
  /**
   * Best-effort OS of the browser itself (not the native shell). Kept on the
   * context so a later announcement can still order platform-specific actions.
   */
  deviceOs: 'ios' | 'android' | 'other'
  /** UI locale, used to pick the marketing-site language prefix. */
  locale: string
}

/** One call-to-action button the modal renders. */
export interface AnnouncementAction {
  /** Resolves `<i18nKey>.<labelKey>` for the button text. */
  labelKey: string
  /** Where the button leads. */
  url: string
}

export interface Announcement {
  /**
   * Identity under which the dismissal is stored. Never reuse an id for
   * different content — returning visitors would never see the new one.
   */
  id: string
  /** Translation prefix; resolves `<prefix>.title` and `<prefix>.body`. */
  i18nKey: string
  /** Last day it may appear, `YYYY-MM-DD`, inclusive. */
  until: string
  /** Whether this visitor belongs to the audience. */
  applies: (context: AnnouncementContext) => boolean
  /**
   * Buttons to offer, in display order. Omit it (or return an empty array)
   * for news that is only an acknowledgement — the modal then shows a single
   * dismiss button instead.
   */
  actions?: (context: AnnouncementContext) => AnnouncementAction[]
  /**
   * Optional illustration, as a path below `public/`. Without one the modal
   * falls back to the instance's own brand mark.
   */
  image?: string
}

const MARKETING_SITE = 'https://www.synaplan.com'

/**
 * Official download chooser on the marketing site. German UI gets `/de/app`;
 * every other locale lands on the default English page — the website only
 * ships those two languages.
 */
function marketingAppUrl(locale: string): string {
  const prefix = locale.toLowerCase().startsWith('de') ? '/de' : ''

  return `${MARKETING_SITE}${prefix}/app`
}

/** One button to the chooser page, so nobody is sent to the wrong store. */
function marketingAppActions({ locale }: AnnouncementContext): AnnouncementAction[] {
  return [{ labelKey: 'getTheApp', url: marketingAppUrl(locale) }]
}

export const announcements: Announcement[] = [
  {
    id: 'mobile-apps-launch',
    i18nKey: 'announcements.mobileApp',
    until: '2026-11-30',
    // Only where there is something to install: the operator published at
    // least one app, and the reader is not already looking at this from
    // inside it.
    applies: ({ iosAppUrl, androidAppUrl, isNativeApp }) =>
      ('' !== iosAppUrl || '' !== androidAppUrl) && !isNativeApp,
    actions: marketingAppActions,
    // No illustration: the one we have is an iPhone-only mockup, which would
    // misrepresent the announcement for an Android visitor. The modal falls
    // back to the instance's own brand mark instead (see `image` above).
  },
]
