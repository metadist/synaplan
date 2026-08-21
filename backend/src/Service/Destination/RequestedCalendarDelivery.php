<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\Connection\PlannerChannel;
use App\Service\Connection\PlannerChannelCatalog;
use Psr\Log\LoggerInterface;

/**
 * Puts the events of a generated .ics file into a user's connected calendar —
 * CalDAV or Microsoft 365 (Outlook), whichever the resolved channel is.
 *
 * The calendar sibling of {@see RequestedFolderDelivery}: used by the
 * `calendar_event` multitask runner when the planner names a calendar channel
 * (`params.channel`, e.g. "outlook" or "calendar"). The .ics stays attached to
 * the reply either way — this delivery is the "actually put it in my calendar"
 * upgrade on top, and a failure degrades to the download, never sinks the node.
 */
final readonly class RequestedCalendarDelivery
{
    /**
     * Connection types that count as a calendar target, mapped to the
     * {@see DestinationProvider} id that delivers into them.
     */
    private const PROVIDER_BY_TYPE = [
        'caldav' => 'caldav',
        Connection::TYPE_M365 => 'm365_calendar',
    ];

    public function __construct(
        private ConnectionRepository $connections,
        private DestinationRegistry $destinations,
        private PlannerChannelCatalog $channels,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Deliver one .ics file into the calendar channel named by the planner.
     *
     * @return array{ok: bool, message: string, connection: string|null, channel: string|null, created: int, skipped: int, webLink: string|null}
     */
    public function send(int $ownerId, string $absolutePath, string $fileName, int $messageId, string $channel): array
    {
        $resolved = $this->resolveConnection($ownerId, $channel);
        if (null === $resolved) {
            return $this->failure('no calendar with this name is connected — check Settings → Connections');
        }
        [$connection, $channelKey] = $resolved;

        $connectionPk = $connection->getId();
        if (null === $connectionPk || !is_file($absolutePath)) {
            return $this->failure('the calendar file could not be delivered', $connection->getName(), $channelKey);
        }

        $provider = $this->destinations->get(self::PROVIDER_BY_TYPE[$connection->getType()]);
        $result = $provider->send(
            new ShareableFile(
                fileId: 0,
                ownerId: $ownerId,
                absolutePath: $absolutePath,
                name: $fileName,
                sizeBytes: filesize($absolutePath) ?: 0,
            ),
            ['connection_id' => $connectionPk, 'message_id' => $messageId],
        );

        if (!$result->ok) {
            $this->logger->warning('RequestedCalendarDelivery: delivery failed', [
                'connection_id' => $connectionPk,
                'channel' => $channelKey,
                'code' => $result->code?->value,
                'context' => $result->context,
            ]);

            $reason = 'missing_scope' === ($result->context['reason'] ?? null)
                ? 'the connected account has no calendar permission yet — reconnect it under Settings → Connections'
                : ($result->code->value ?? 'delivery failed');

            return $this->failure(
                sprintf('could not add the event to %s (%s)', $channelKey, $reason),
                $connection->getName(),
                $channelKey,
            );
        }

        $created = (int) ($result->context['created'] ?? $result->reference ?? 0);
        $skipped = (int) ($result->context['skipped'] ?? 0);
        $webLink = is_string($result->context['webLink'] ?? null) && '' !== $result->context['webLink']
            ? $result->context['webLink']
            : null;

        return [
            'ok' => true,
            'message' => 0 === $created && $skipped > 0
                ? sprintf('The event already exists in %s.', $channelKey)
                : sprintf('Added the event to %s.', $channelKey),
            'connection' => $connection->getName(),
            'channel' => $channelKey,
            'created' => $created,
            'skipped' => $skipped,
            'webLink' => $webLink,
        ];
    }

    /**
     * @return array{0: Connection, 1: string}|null
     */
    private function resolveConnection(int $ownerId, string $channel): ?array
    {
        $key = PlannerChannelCatalog::sanitize($channel);
        if ('' === $key) {
            return null;
        }

        $named = $this->channels->find($ownerId, $key);
        if (null === $named || PlannerChannel::KIND_CALENDAR !== $named->kind) {
            return null;
        }

        $connection = $this->connections->findByIdAndOwner($named->connectionId, $ownerId);
        if (null === $connection || !isset(self::PROVIDER_BY_TYPE[$connection->getType()])) {
            return null;
        }

        return [$connection, $named->key];
    }

    /**
     * @return array{ok: bool, message: string, connection: string|null, channel: string|null, created: int, skipped: int, webLink: string|null}
     */
    private function failure(string $message, ?string $connection = null, ?string $channel = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'connection' => $connection,
            'channel' => $channel,
            'created' => 0,
            'skipped' => 0,
            'webLink' => null,
        ];
    }
}
