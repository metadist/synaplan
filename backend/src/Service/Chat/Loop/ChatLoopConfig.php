<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Repository\ConfigRepository;

/**
 * Whether a web chat turn may run on the agent loop as a run.
 *
 * LOOP_ENABLED=1 forces it on and LOOP_ENABLED=0 forces it off. An empty
 * variable follows the LOOP.ENABLED row, which ships as off. A missing row
 * is off.
 */
final readonly class ChatLoopConfig
{
    public const GROUP = 'LOOP';
    public const ENABLED = 'ENABLED';

    public function __construct(
        private ConfigRepository $configRepository,
        private string $envValue = '',
    ) {
    }

    public function isEnabled(): bool
    {
        $env = strtolower(trim($this->envValue));
        if (\in_array($env, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (\in_array($env, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        $stored = $this->configRepository->getValue(0, self::GROUP, self::ENABLED);

        return null !== $stored && \in_array(strtolower(trim($stored)), ['1', 'true', 'on', 'yes'], true);
    }
}
