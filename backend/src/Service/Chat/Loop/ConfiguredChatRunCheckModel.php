<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Research\Run\ResearchModelChoice;
use App\Service\Research\Run\ResearchModelLookup;

/**
 * The checking model an admin set under Admin → Research. Nothing is picked
 * when none is set, when the person may not use it, when it is not usable
 * now, or when it is of the chat model's own family: the same family is no
 * second reader.
 */
final readonly class ConfiguredChatRunCheckModel implements ChatRunCheckModel
{
    public const GROUP = 'DEFAULTMODEL';
    public const SETTING = 'VERIFY';

    /** A retired model points at its successor; a chain longer than this is broken. */
    private const MAX_SUCCESSORS = 5;

    public function __construct(
        private ConfigRepository $config,
        private ResearchModelLookup $lookup,
        private ModelRepository $models,
    ) {
    }

    public function pick(ChatRunState $state): ?ResearchModelChoice
    {
        $stored = $this->config->getValue(0, self::GROUP, self::SETTING);
        if (!\is_string($stored) || !ctype_digit($stored) || (int) $stored < 1) {
            return null;
        }
        $choice = $this->usable($state->userId, $this->lookup->byId($state->userId, (int) $stored));
        if (null === $choice) {
            return null;
        }
        $chat = $this->chatModel($state);
        if (null !== $chat && ($chat->id === $choice->id || ('' !== $choice->family && $chat->family === $choice->family))) {
            return null;
        }

        return $choice;
    }

    private function usable(int $userId, ?ResearchModelChoice $choice): ?ResearchModelChoice
    {
        for ($i = 0; null !== $choice && $i <= self::MAX_SUCCESSORS; ++$i) {
            if (!$choice->retired) {
                return $choice->allowed && $choice->healthy ? $choice : null;
            }
            $choice = null !== $choice->successorId ? $this->lookup->byId($userId, $choice->successorId) : null;
        }

        return null;
    }

    private function chatModel(ChatRunState $state): ?ResearchModelChoice
    {
        if (null === $state->provider || null === $state->model) {
            return null;
        }
        $id = $this->models->findChatIdByProvider($state->provider, $state->model);

        return null === $id ? null : $this->lookup->byId($state->userId, $id);
    }
}
