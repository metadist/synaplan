<?php

declare(strict_types=1);

namespace App\Service\Dav;

/**
 * CalDAV on top of the WebDAV client (connector plan 07 C12). The target's
 * base URL is the calendar collection itself, e.g.
 * `https://host/remote.php/dav/calendars/{user}/{calendar}`.
 *
 * Read-before-write is a correctness requirement (sign-off S13): the UID query
 * plus the deterministic UID make re-delivery idempotent, and `If-None-Match`
 * on PUT is the safety net against a race between query and write.
 */
final readonly class CalDavClient
{
    public function __construct(
        private WebDavClient $webDav,
    ) {
    }

    /**
     * True when the calendar already holds an event with this UID
     * (REPORT calendar-query with a UID text-match).
     *
     * @throws DavException
     */
    public function eventExists(DavTarget $target, string $uid): bool
    {
        $body = $this->webDav->report($target, '', $this->uidQueryXml($uid));

        // A multistatus with no matches contains no <response> elements.
        return 1 === preg_match('/<[a-z0-9]*:?response[\s>]/i', $body);
    }

    /**
     * Create the event, addressed by its UID so re-delivery hits the same
     * resource. Returns true when created, false when an event with this UID
     * already exists (HTTP 412 via `If-None-Match: *`) — idempotent success,
     * never a duplicate.
     *
     * @throws DavException
     */
    public function putEvent(DavTarget $target, string $uid, string $vcalendar): bool
    {
        $status = $this->webDav->put(
            $target,
            $uid.'.ics',
            $vcalendar,
            'text/calendar; charset=utf-8',
            ['If-None-Match' => '*'],
        );

        return 412 !== $status;
    }

    private function uidQueryXml(string $uid): string
    {
        $escaped = htmlspecialchars($uid, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');

        return <<<XML
            <?xml version="1.0" encoding="utf-8" ?>
            <c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
              <d:prop><d:getetag/></d:prop>
              <c:filter>
                <c:comp-filter name="VCALENDAR">
                  <c:comp-filter name="VEVENT">
                    <c:prop-filter name="UID">
                      <c:text-match collation="i;octet">{$escaped}</c:text-match>
                    </c:prop-filter>
                  </c:comp-filter>
                </c:comp-filter>
              </c:filter>
            </c:calendar-query>
            XML;
    }
}
