<?php

declare(strict_types=1);

namespace App\DTO;

use OpenApi\Attributes as OA;

/**
 * Read model for the signed-in user's Telegram bot. The token is never included.
 */
#[OA\Schema(
    schema: 'TelegramChannelState',
    required: ['success', 'status', 'botUsername', 'pairingLink', 'chatId', 'lastMessageAt', 'errorCode'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'status', type: 'string', enum: ['none', 'pending_pairing', 'connected', 'error', 'disconnected'], example: 'pending_pairing'),
        new OA\Property(property: 'botUsername', type: 'string', nullable: true, example: 'synaplan_bot'),
        new OA\Property(property: 'pairingLink', type: 'string', nullable: true, example: 'https://t.me/synaplan_bot?start=ABCD2345'),
        new OA\Property(property: 'chatId', type: 'integer', nullable: true, example: 42),
        new OA\Property(property: 'lastMessageAt', type: 'integer', nullable: true, example: 1750000000),
        new OA\Property(property: 'errorCode', type: 'string', nullable: true, example: null),
    ]
)]
final class TelegramChannelState
{
}
