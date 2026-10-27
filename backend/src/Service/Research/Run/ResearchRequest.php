<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;

/** A start request that has already been checked. */
final readonly class ResearchRequest
{
    public const MAX_QUESTION = 4000;

    /** @var list<string> */
    private const FIELDS = [
        'question', 'depth', 'sources', 'language', 'output', 'instructions',
        'context', 'chat_id', 'assistant_id', 'models', 'world', 'seed_sources', 'parent',
    ];

    public function __construct(
        public string $question,
        public ResearchDepth $depth,
        public ?int $chatId,
        public ResearchSources $sources = new ResearchSources(),
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body, bool $session, User $user, ChatRepository $chats): self
    {
        self::checkFields($body);

        return new self(
            self::question($body),
            self::depth($body),
            self::chat($body, $session, $user, $chats),
            ResearchSources::fromBody($body),
        );
    }

    /**
     * Unknown fields are refused; the optional ones only need the right shape.
     *
     * @param array<string, mixed> $body
     */
    private static function checkFields(array $body): void
    {
        foreach (array_keys($body) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new ResearchRejected(400, 'invalid', (string) $field, 'That field is not part of a research request.');
            }
        }
        if (null !== ($body['assistant_id'] ?? null) && !is_int($body['assistant_id'])) {
            throw new ResearchRejected(400, 'invalid', 'assistant_id', 'That assistant was not found.');
        }
        self::optionalText($body, 'instructions', self::MAX_QUESTION);
        self::optionalText($body, 'language', 32);
        self::optionalText($body, 'output', 32);
        self::parent($body);
        self::objectOrNull($body, 'sources');
        self::objectOrNull($body, 'models');
        self::objectOrNull($body, 'world');
        self::context($body);
        self::seedSources($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function question(array $body): string
    {
        $question = $body['question'] ?? null;
        if (!is_string($question)) {
            throw new ResearchRejected(400, 'invalid', 'question', 'Write the question the research should answer.');
        }
        $question = trim($question);
        $length = mb_strlen($question);
        if ($length < 1 || $length > self::MAX_QUESTION) {
            throw new ResearchRejected(400, 'invalid', 'question', 'The question needs between 1 and 4000 characters.');
        }

        return $question;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function depth(array $body): ResearchDepth
    {
        $depth = $body['depth'] ?? null;
        if (!is_string($depth)) {
            throw new ResearchRejected(400, 'invalid', 'depth', 'Choose quick, standard, or thorough.');
        }
        $match = ResearchDepth::tryFrom($depth);
        if (null === $match) {
            throw new ResearchRejected(400, 'invalid', 'depth', 'Choose quick, standard, or thorough.');
        }

        return $match;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function chat(array $body, bool $session, User $user, ChatRepository $chats): ?int
    {
        $present = array_key_exists('chat_id', $body) && null !== $body['chat_id'];
        if (!$session) {
            if ($present) {
                throw new ResearchRejected(400, 'invalid', 'chat_id', 'An API request does not take a chat. The report stays on the research run.');
            }

            return null;
        }
        if (!$present || !is_numeric($body['chat_id'])) {
            throw new ResearchRejected(400, 'invalid', 'chat_id', 'Choose the chat the report should go into.');
        }
        $chatId = (int) $body['chat_id'];
        $chat = $chats->find($chatId);
        if (!$chat instanceof Chat || $chat->getUserId() !== $user->getId()) {
            throw new ResearchRejected(404, 'not_found', 'chat_id', 'That chat was not found.');
        }

        return $chatId;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalText(array $body, string $field, int $max): void
    {
        if (!array_key_exists($field, $body) || null === $body[$field]) {
            return;
        }
        if (!is_string($body[$field]) || mb_strlen($body[$field]) > $max) {
            throw new ResearchRejected(400, 'invalid', $field, 'That value is too long.');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function parent(array $body): void
    {
        if (!array_key_exists('parent', $body) || null === $body['parent']) {
            return;
        }
        if (!is_string($body['parent']) || !ResearchIds::isValid($body['parent'])) {
            throw new ResearchRejected(400, 'invalid', 'parent', 'That earlier research was not found.');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function objectOrNull(array $body, string $field): void
    {
        if (!array_key_exists($field, $body) || null === $body[$field]) {
            return;
        }
        if (!is_array($body[$field])) {
            throw new ResearchRejected(400, 'invalid', $field, 'That value has to be an object.');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function context(array $body): void
    {
        if (!array_key_exists('context', $body) || null === $body['context']) {
            return;
        }
        $context = $body['context'];
        if (!is_array($context)) {
            throw new ResearchRejected(400, 'invalid', 'context', 'The earlier messages have to be a list.');
        }
        $turns = $context['turns'] ?? [];
        if (!is_array($turns) || count($turns) > 6) {
            throw new ResearchRejected(400, 'invalid', 'context', 'Keep the earlier messages to six.');
        }
        foreach ($turns as $turn) {
            if (!is_array($turn) || !is_string($turn['text'] ?? null) || mb_strlen($turn['text']) > 2000) {
                throw new ResearchRejected(400, 'invalid', 'context', 'Each earlier message needs 2000 characters or fewer.');
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function seedSources(array $body): void
    {
        if (!array_key_exists('seed_sources', $body) || null === $body['seed_sources']) {
            return;
        }
        $sources = $body['seed_sources'];
        if (!is_array($sources) || count($sources) > 50) {
            throw new ResearchRejected(400, 'invalid', 'seed_sources', 'A research run can start from at most 50 pages.');
        }
        foreach ($sources as $source) {
            if (!is_array($source) || !is_string($source['url'] ?? null) || '' === trim($source['url'])) {
                throw new ResearchRejected(400, 'invalid', 'seed_sources', 'Each starting page needs an address.');
            }
        }
    }
}
