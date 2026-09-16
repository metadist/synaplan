<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageMetaRepository;
use App\Repository\UserRepository;
use App\Service\Runtime\RuntimeProfile;

/**
 * The single place that turns "this turn carries an agentId" into a
 * {@see RuntimeProfile}.
 *
 * The pin comes from the request (`options['agentId']`) or from the
 * `AGENTID` meta the API layer stored on the message. The profile is
 * resolved exactly once here; every consumer downstream (classifier,
 * processor, chat handler) reads the object instead of scalar copies.
 */
final readonly class AgentPinResolver
{
    public const META_KEY = 'AGENTID';

    public function __construct(
        private AgentConfig $agentConfig,
        private AgentRuntimeResolver $runtimeResolver,
        private UserRepository $users,
        private MessageMetaRepository $messageMeta,
    ) {
    }

    /**
     * @param array<string, mixed> $options classifier hints (`agentId`, `agentDraft`)
     *
     * @return RuntimeProfile|null null when the turn is not pinned or the feature is off for the user
     */
    public function resolve(Message $message, array $options): ?RuntimeProfile
    {
        $agentId = $this->pinnedAgentId($message, $options);
        if ($agentId < 1) {
            return null;
        }

        $userId = $message->getUserId();
        if (!$this->agentConfig->isEnabled($userId)) {
            return null;
        }

        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return null;
        }

        $useDraft = array_key_exists('agentDraft', $options) && (bool) $options['agentDraft'];
        $chatId = $message->getChatId();

        return $this->runtimeResolver->resolve(
            $agentId,
            $user,
            $useDraft,
            null !== $chatId && $chatId > 0 ? $chatId : null,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function pinnedAgentId(Message $message, array $options): int
    {
        $fromRequest = isset($options['agentId']) ? (int) $options['agentId'] : 0;
        if ($fromRequest > 0) {
            return $fromRequest;
        }

        $messageId = $message->getId();
        if (null === $messageId) {
            return 0;
        }

        $meta = $this->messageMeta->findOneBy([
            'messageId' => $messageId,
            'metaKey' => self::META_KEY,
        ]);

        return null !== $meta && is_numeric($meta->getMetaValue()) ? (int) $meta->getMetaValue() : 0;
    }
}
