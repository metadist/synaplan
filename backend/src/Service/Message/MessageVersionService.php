<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Message;

/**
 * Versions of one answer, and edited copies of one question, stored as
 * message metadata so a reload and the next turn agree on which branch
 * is active.
 *
 * Absent flags mean "this row predates versions" and stay active.
 */
final class MessageVersionService
{
    public const VERSION_GROUP = 'version_group';
    public const VERSION_INDEX = 'version_index';
    public const VERSION_SELECTED = 'version_selected';
    public const EDIT_GROUP = 'edit_group';
    public const EDIT_INDEX = 'edit_index';
    public const EDIT_SELECTED = 'edit_selected';
    public const BRANCH_INACTIVE = 'branch_inactive';
    public const ANSWERS_USER = 'answers_user';

    public function linkAgain(Message $newer, Message $older): void
    {
        $olderId = $older->getId();
        $newerId = $newer->getId();
        if (null === $olderId || null === $newerId) {
            throw new \InvalidArgumentException('Both answers must already be saved.');
        }
        if ($olderId === $newerId) {
            throw new \InvalidArgumentException('An answer cannot be a version of itself.');
        }

        $group = $older->getMeta(self::VERSION_GROUP) ?? (string) $olderId;
        $olderIndex = $this->index($older, self::VERSION_INDEX);
        $older->setMeta(self::VERSION_GROUP, $group);
        $older->setMeta(self::VERSION_INDEX, (string) $olderIndex);
        $older->setMeta(self::VERSION_SELECTED, '0');

        $newer->setMeta(self::VERSION_GROUP, $group);
        $newer->setMeta(self::VERSION_INDEX, (string) ($olderIndex + 1));
        $newer->setMeta(self::VERSION_SELECTED, '1');
    }

    /**
     * @param list<Message> $toHide messages that belong only to the previous branch
     */
    public function linkEdit(Message $newUser, Message $oldUser, Message $newAnswer, array $toHide): void
    {
        $oldId = $oldUser->getId();
        $newId = $newUser->getId();
        if (null === $oldId || null === $newId || null === $newAnswer->getId()) {
            throw new \InvalidArgumentException('The edited question and its answer must already be saved.');
        }

        $group = $oldUser->getMeta(self::EDIT_GROUP) ?? (string) $oldId;
        $oldIndex = $this->index($oldUser, self::EDIT_INDEX);
        $oldUser->setMeta(self::EDIT_GROUP, $group);
        $oldUser->setMeta(self::EDIT_INDEX, (string) $oldIndex);
        $oldUser->setMeta(self::EDIT_SELECTED, '0');

        $newUser->setMeta(self::EDIT_GROUP, $group);
        $newUser->setMeta(self::EDIT_INDEX, (string) ($oldIndex + 1));
        $newUser->setMeta(self::EDIT_SELECTED, '1');

        foreach ($toHide as $message) {
            if ($message->getId() === $newId || $message->getId() === $newAnswer->getId()) {
                continue;
            }
            $message->setMeta(self::BRANCH_INACTIVE, '1');
            $message->setMeta(self::ANSWERS_USER, (string) $oldId);
        }

        $newAnswer->setMeta(self::ANSWERS_USER, (string) $newId);
        $newAnswer->setMeta(self::BRANCH_INACTIVE, '0');
    }

    /**
     * @param list<Message> $siblings every answer in the same version group
     */
    public function selectAnswer(Message $chosen, array $siblings): void
    {
        $group = $chosen->getMeta(self::VERSION_GROUP);
        if (null === $group || '' === $group) {
            throw new \InvalidArgumentException('This answer is not part of a version group.');
        }
        $found = false;
        foreach ($siblings as $message) {
            if ($message->getMeta(self::VERSION_GROUP) !== $group) {
                continue;
            }
            $selected = $message->getId() === $chosen->getId();
            $message->setMeta(self::VERSION_SELECTED, $selected ? '1' : '0');
            $found = $found || $selected;
        }
        if (!$found) {
            throw new \InvalidArgumentException('The chosen answer is not in this version group.');
        }
    }

    /**
     * @param list<Message> $members      questions that share the edit group
     * @param list<Message> $chatMessages every message in the chat
     */
    public function selectEdit(Message $chosen, array $members, array $chatMessages): void
    {
        $group = $chosen->getMeta(self::EDIT_GROUP);
        if (null === $group || '' === $group) {
            throw new \InvalidArgumentException('This question is not part of an edit group.');
        }
        $memberIds = [];
        $found = false;
        foreach ($members as $message) {
            if ($message->getMeta(self::EDIT_GROUP) !== $group) {
                continue;
            }
            $id = $message->getId();
            if (null === $id) {
                continue;
            }
            $selected = $id === $chosen->getId();
            $message->setMeta(self::EDIT_SELECTED, $selected ? '1' : '0');
            $memberIds[(string) $id] = true;
            $found = $found || $selected;
        }
        if (!$found || null === $chosen->getId()) {
            throw new \InvalidArgumentException('The chosen question is not in this edit group.');
        }
        $chosenId = (string) $chosen->getId();
        foreach ($chatMessages as $message) {
            $owner = $message->getMeta(self::ANSWERS_USER);
            if (null === $owner || !isset($memberIds[$owner])) {
                continue;
            }
            $message->setMeta(self::BRANCH_INACTIVE, $owner === $chosenId ? '0' : '1');
        }
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<Message>
     */
    public function activeForContext(array $messages): array
    {
        $active = [];
        foreach ($messages as $message) {
            if ($this->isActive($message)) {
                $active[] = $message;
            }
        }

        return $active;
    }

    public function isActive(Message $message): bool
    {
        if ('0' === $message->getMeta(self::VERSION_SELECTED)) {
            return false;
        }
        if ('0' === $message->getMeta(self::EDIT_SELECTED)) {
            return false;
        }
        if ('1' === $message->getMeta(self::BRANCH_INACTIVE)) {
            return false;
        }

        return true;
    }

    private function index(Message $message, string $key): int
    {
        $raw = $message->getMeta($key);
        $index = is_numeric($raw) ? (int) $raw : 1;

        return max(1, $index);
    }
}
