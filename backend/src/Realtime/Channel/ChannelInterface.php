<?php

declare(strict_types=1);

namespace App\Realtime\Channel;

/**
 * Strongly-typed identifier for a Centrifugo channel.
 *
 * The channel name format is `{namespace}:{identifier}` and MUST match the
 * namespace declared in `_docker/centrifugo/config.json`. Adding a new
 * realtime feature is a 3-step process:
 *
 *   1. Implement this interface (one class per channel shape).
 *   2. Implement {@see \App\Realtime\Authorizer\ChannelAuthorizerInterface}
 *      with `#[AutoconfigureTag('app.realtime.authorizer', ['namespace' => '...'])]`.
 *   3. Add the namespace block in centrifugo config.json (presence/history/etc.).
 *
 * Channel names are intentionally opaque on the wire — keep human-readable
 * metadata in the event payload, not in the channel name.
 */
interface ChannelInterface
{
    /**
     * Fully-qualified channel name, e.g. `widget:session.wdg_x.sid_y`.
     */
    public function name(): string;

    /**
     * Centrifugo namespace this channel belongs to (left-hand side of `:`).
     */
    public function namespace(): string;
}
