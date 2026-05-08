<?php

declare(strict_types=1);

namespace App\Realtime\Channel;

/**
 * Channel for the operator-facing dashboard of a single widget.
 *
 * Subscribers: the widget owner + any team members with access. Receives
 * notifications about new visitor messages across ALL sessions of this
 * widget (replaces the legacy 3-second `/notifications` polling loop).
 *
 * Channel name format: `widget:operators.{widgetId}`.
 */
final readonly class WidgetOperatorsChannel implements ChannelInterface
{
    public const NAMESPACE = 'widget';

    public function __construct(
        public string $widgetId,
    ) {
    }

    public function name(): string
    {
        return sprintf('%s:operators.%s', self::NAMESPACE, $this->widgetId);
    }

    public function namespace(): string
    {
        return self::NAMESPACE;
    }
}
