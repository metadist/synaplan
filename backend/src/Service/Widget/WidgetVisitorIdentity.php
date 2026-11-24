<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\Widget;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Decides whose data a widget turn may fetch. The visitor's browser names
 * the id, so the id alone proves nothing: the embedding site signs it on
 * its server with the widget's signing key, and only a signed, unexpired id
 * is used for the owner's `{externalUserId}` API calls.
 *
 * The signature is `<expiry>.<mac>`: `expiry` a Unix timestamp at most a day
 * ahead, `mac` the hex HMAC-SHA256 of "widgetId\nid\nexpiry" under the
 * signing key. The key is derived from APP_SECRET, the widget id and the
 * widget's key version, so nothing secret is stored and rotating it is a
 * counter.
 *
 * A widget that passed visitor ids before signing existed keeps working:
 * a migration marked it, and its unsigned ids are still used until the owner
 * turns signing on. A widget created since has no such mark.
 */
final readonly class WidgetVisitorIdentity
{
    public const ID_FIELD = 'externalUserId';
    public const SIGNATURE_FIELD = 'externalUserIdSignature';
    /** Config key of the rotation counter. Server-managed: never taken from a config update. */
    public const KEY_VERSION = 'visitorKeyVersion';
    /** Config mark of a widget from before signing. Server-managed: set by a migration, only ever removed. */
    public const UNSIGNED_ALLOWED = 'allowUnsignedVisitorId';
    public const MAX_LIFETIME_SECONDS = 86400;

    private const KEY_PURPOSE = 'widget-visitor-id';
    private const SKEW_SECONDS = 300;
    private const MAX_ID_LENGTH = 255;
    private const REASON_UNSIGNED = 'unsigned';
    private const SIGNATURE_PATTERN = '/^(\d{1,12})\.([0-9a-f]{64})$/';

    public function __construct(
        private string $appSecret,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * The visitor id of a widget request body, or '' when it is missing or
     * its signature does not hold.
     *
     * @param array<mixed> $data
     */
    public function verified(Widget $widget, array $data): string
    {
        $id = \is_string($data[self::ID_FIELD] ?? null) ? trim($data[self::ID_FIELD]) : '';
        if ('' === $id) {
            return '';
        }

        $reason = $this->refusal($widget, $id, $data[self::SIGNATURE_FIELD] ?? null);
        if (self::REASON_UNSIGNED === $reason && !$this->signatureRequired($widget)) {
            $this->logger->notice('Widget visitor id used without a signature', [
                'widget_id' => $widget->getWidgetId(),
                'visitor' => $this->fingerprint($widget, $id),
            ]);

            return $id;
        }
        if (null !== $reason) {
            $this->logger->warning('Widget visitor id refused', [
                'widget_id' => $widget->getWidgetId(),
                'reason' => $reason,
                'visitor' => $this->fingerprint($widget, $id),
            ]);

            return '';
        }

        $this->logger->info('Widget visitor id accepted', [
            'widget_id' => $widget->getWidgetId(),
            'visitor' => $this->fingerprint($widget, $id),
        ]);

        return $id;
    }

    /** The key the embedding site signs with. Shown to the widget's owner only. */
    public function signingKey(Widget $widget): string
    {
        if ('' === $this->appSecret) {
            throw new \LogicException('APP_SECRET is empty; a widget signing key cannot be derived.');
        }

        return hash_hmac('sha256', sprintf('%s|%s|%d', self::KEY_PURPOSE, $widget->getWidgetId(), $this->keyVersion($widget)), $this->appSecret);
    }

    public function sign(Widget $widget, string $id, int $expiry): string
    {
        return $expiry.'.'.$this->mac($widget, $id, $expiry);
    }

    /** False only for a widget from before signing whose owner has not turned signing on yet. */
    public function signatureRequired(Widget $widget): bool
    {
        return true !== $widget->getConfigValue(self::UNSIGNED_ALLOWED, false);
    }

    /** Turns signing on for a widget from before signing. There is no way back. */
    public function requireSignature(Widget $widget): void
    {
        if ($this->signatureRequired($widget)) {
            return;
        }

        $config = $widget->getConfig();
        unset($config[self::UNSIGNED_ALLOWED]);
        $widget->setConfig($config);
        $widget->touch();
        $this->em->flush();

        $this->logger->notice('Widget visitor signature now required', ['widget_id' => $widget->getWidgetId()]);
    }

    /** Replaces the signing key; every signature made with the old one stops working. */
    public function rotate(Widget $widget): string
    {
        $widget->setConfigValue(self::KEY_VERSION, $this->keyVersion($widget) + 1);
        $widget->touch();
        $this->em->flush();

        $this->logger->notice('Widget visitor signing key rotated', ['widget_id' => $widget->getWidgetId()]);

        return $this->signingKey($widget);
    }

    private function refusal(Widget $widget, string $id, mixed $signature): ?string
    {
        if (\strlen($id) > self::MAX_ID_LENGTH) {
            return 'id_too_long';
        }
        if (!\is_string($signature) || '' === $signature) {
            return self::REASON_UNSIGNED;
        }
        if ('' === $this->appSecret) {
            return 'no_signing_key';
        }
        if (1 !== preg_match(self::SIGNATURE_PATTERN, $signature, $parts)) {
            return 'malformed';
        }

        $expiry = (int) $parts[1];
        $now = $this->clock->now()->getTimestamp();
        if ($expiry <= $now) {
            return 'expired';
        }
        if ($expiry > $now + self::MAX_LIFETIME_SECONDS + self::SKEW_SECONDS) {
            return 'lifetime_too_long';
        }

        return hash_equals($this->mac($widget, $id, $expiry), $parts[2]) ? null : 'bad_signature';
    }

    private function mac(Widget $widget, string $id, int $expiry): string
    {
        return hash_hmac('sha256', $widget->getWidgetId()."\n".$id."\n".$expiry, $this->signingKey($widget));
    }

    private function keyVersion(Widget $widget): int
    {
        $version = $widget->getConfigValue(self::KEY_VERSION, 1);

        return \is_int($version) && $version > 0 ? $version : 1;
    }

    /** Lets a log reader tell visitors apart without the log holding their ids. */
    private function fingerprint(Widget $widget, string $id): string
    {
        return substr(hash_hmac('sha256', 'log|'.$widget->getWidgetId().'|'.$id, $this->appSecret), 0, 16);
    }
}
