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
  /** True inside the native shell, false in a browser. */
  isNativeApp: boolean
}

export interface Announcement {
  /**
   * Identity under which the dismissal is stored. Never reuse an id for
   * different content — returning visitors would never see the new one.
   */
  id: string
  /** Translation prefix; resolves `<prefix>.title`, `.body` and `.action`. */
  i18nKey: string
  /** Last day it may appear, `YYYY-MM-DD`, inclusive. */
  until: string
  /** Whether this visitor belongs to the audience. */
  applies: (context: AnnouncementContext) => boolean
  /**
   * Target of the call-to-action. Omit it for news that is only an
   * acknowledgement, and the modal shows a single dismiss button instead.
   */
  actionUrl?: (context: AnnouncementContext) => string
  /**
   * Optional illustration, as a path below `public/`. Without one the modal
   * falls back to the instance's own brand mark.
   */
  image?: string
}

/**
 * Tags a store link so App Store Connect attributes the install to this
 * campaign, preserving a query string the operator may already have set.
 */
function withCampaign(url: string, campaign: string): string {
  return `${url}${url.includes('?') ? '&' : '?'}ct=${campaign}`
}

export const announcements: Announcement[] = [
  {
    id: 'ios-app-launch',
    i18nKey: 'announcements.iosApp',
    until: '2026-11-30',
    // Only where there is something to install: the operator published an app,
    // and the reader is not already looking at this from inside it.
    applies: ({ iosAppUrl, isNativeApp }) => '' !== iosAppUrl && !isNativeApp,
    actionUrl: ({ iosAppUrl }) => withCampaign(iosAppUrl, 'web-announcement'),
    image: '/announcements/iphone-app.webp',
  },
]
