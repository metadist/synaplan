<?php

declare(strict_types=1);

namespace App\Realtime\Channel;

use App\Realtime\Exception\InvalidChannelException;

/**
 * Parses raw channel names received from the browser into typed
 * {@see ChannelInterface} instances.
 *
 * Centralised so the token controller / authorizer locator share the same
 * parsing rules. New channel types should be added to {@see self::parse()}.
 *
 * The parser is intentionally strict — anything it cannot recognise as a
 * registered namespace is rejected, which prevents the browser from asking
 * the backend to mint tokens for arbitrary Centrifugo channels.
 */
final class ChannelParser
{
    /**
     * @throws InvalidChannelException when the channel name is malformed or unknown
     */
    public function parse(string $channelName): ChannelInterface
    {
        $channelName = trim($channelName);
        if ('' === $channelName) {
            throw new InvalidChannelException('Channel name is empty');
        }

        $colon = strpos($channelName, ':');
        if (false === $colon || 0 === $colon) {
            throw new InvalidChannelException(sprintf('Channel "%s" is missing a namespace', $channelName));
        }

        $namespace = substr($channelName, 0, $colon);
        $identifier = substr($channelName, $colon + 1);

        if ('' === $identifier) {
            throw new InvalidChannelException(sprintf('Channel "%s" has empty identifier', $channelName));
        }

        return match ($namespace) {
            WidgetSessionChannel::NAMESPACE => $this->parseWidgetIdentifier($identifier, $channelName),
            WidgetTypingChannel::NAMESPACE => $this->parseWidgetTypingIdentifier($identifier, $channelName),
            UserChannel::NAMESPACE => $this->parseUserIdentifier($identifier, $channelName),
            SystemBroadcastChannel::NAMESPACE => new SystemBroadcastChannel($identifier),
            default => throw new InvalidChannelException(sprintf('Unknown channel namespace "%s"', $namespace)),
        };
    }

    /**
     * Typing channels carry the bare `{widgetId}.{sessionId}` pair, mirroring
     * the per-session channel but on a dedicated namespace so Centrifugo can
     * enable client-side publishing without weakening the `widget:*` rules.
     */
    private function parseWidgetTypingIdentifier(string $identifier, string $channelName): WidgetTypingChannel
    {
        $parts = explode('.', $identifier, 2);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new InvalidChannelException(sprintf('Channel "%s" is malformed (expected widgettyping:<widgetId>.<sessionId>)', $channelName));
        }

        return new WidgetTypingChannel($parts[0], $parts[1]);
    }

    /**
     * Widget channels are dispatched on the identifier prefix:
     *   `session.{widgetId}.{sessionId}` => WidgetSessionChannel
     *   `operators.{widgetId}`           => WidgetOperatorsChannel
     */
    private function parseWidgetIdentifier(string $identifier, string $channelName): ChannelInterface
    {
        if (str_starts_with($identifier, 'session.')) {
            $rest = substr($identifier, strlen('session.'));
            $parts = explode('.', $rest, 2);
            if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
                throw new InvalidChannelException(sprintf('Channel "%s" is malformed (expected session.<widgetId>.<sessionId>)', $channelName));
            }

            return new WidgetSessionChannel($parts[0], $parts[1]);
        }

        if (str_starts_with($identifier, 'operators.')) {
            $widgetId = substr($identifier, strlen('operators.'));
            if ('' === $widgetId) {
                throw new InvalidChannelException(sprintf('Channel "%s" is missing widgetId', $channelName));
            }

            return new WidgetOperatorsChannel($widgetId);
        }

        throw new InvalidChannelException(sprintf('Unknown widget channel "%s"', $channelName));
    }

    private function parseUserIdentifier(string $identifier, string $channelName): UserChannel
    {
        if (1 !== preg_match('/^\d+$/', $identifier)) {
            throw new InvalidChannelException(sprintf('Channel "%s" must use a numeric user id', $channelName));
        }

        return new UserChannel((int) $identifier);
    }
}
