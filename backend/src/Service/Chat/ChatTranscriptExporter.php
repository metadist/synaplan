<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Chat;
use App\Entity\Message;
use App\Service\Message\MessageVersionService;

/**
 * One chat as Markdown, JSON, or a plain-text PDF.
 */
final class ChatTranscriptExporter
{
    /**
     * @param list<Message> $messages oldest first
     */
    public function markdown(Chat $chat, array $messages): string
    {
        $lines = ['# '.($chat->getTitle() ?: 'Chat'), ''];
        foreach ($messages as $message) {
            if (!$this->include($message)) {
                continue;
            }
            $role = 'IN' === $message->getDirection() ? 'You' : 'Assistant';
            $when = $message->getDateTime();
            $model = trim((string) $message->getMeta('ai_chat_model'));
            $cost = trim((string) $message->getMeta('ai_chat_cost'));
            $heading = '## '.$role;
            if ('' !== $when) {
                $heading .= ' · '.$when;
            }
            $lines[] = $heading;
            if ('Assistant' === $role && '' !== $model) {
                $lines[] = 'Model: '.$model.('' !== $cost ? ' · Cost: '.$cost : '');
            }
            $steps = $this->stepSummary($message);
            if ('' !== $steps) {
                $lines[] = 'Steps: '.$steps;
            }
            $lines[] = '';
            $lines[] = trim($message->getText());
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @param list<Message> $messages
     *
     * @return array<string, mixed>
     */
    public function jsonDocument(Chat $chat, array $messages): array
    {
        $rows = [];
        foreach ($messages as $message) {
            $rows[] = [
                'id' => $message->getId(),
                'role' => 'IN' === $message->getDirection() ? 'user' : 'assistant',
                'text' => $message->getText(),
                'timestamp' => $message->getUnixTimestamp(),
                'model' => $message->getMeta('ai_chat_model'),
                'cost' => $message->getMeta('ai_chat_cost'),
                'active' => $this->include($message),
                'versionGroup' => $message->getMeta(MessageVersionService::VERSION_GROUP),
                'editGroup' => $message->getMeta(MessageVersionService::EDIT_GROUP),
            ];
        }

        return [
            'title' => $chat->getTitle(),
            'archived' => $chat->isArchived(),
            'tags' => $chat->getTags(),
            'messages' => $rows,
        ];
    }

    /**
     * @param list<Message> $messages
     */
    public function pdf(Chat $chat, array $messages): string
    {
        return (new PlainTextPdf())->render($chat->getTitle() ?: 'Chat', $this->markdown($chat, $messages));
    }

    private function include(Message $message): bool
    {
        if ('0' === $message->getMeta(MessageVersionService::VERSION_SELECTED)) {
            return false;
        }
        if ('0' === $message->getMeta(MessageVersionService::EDIT_SELECTED)) {
            return false;
        }
        if ('1' === $message->getMeta(MessageVersionService::BRANCH_INACTIVE)) {
            return false;
        }

        return true;
    }

    private function stepSummary(Message $message): string
    {
        $raw = $message->getMeta('task_plan');
        if (!is_string($raw) || '' === $raw) {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_array($decoded['cards'] ?? null)) {
            return '';
        }
        $parts = [];
        foreach ($decoded['cards'] as $card) {
            if (!is_array($card)) {
                continue;
            }
            $name = is_string($card['capability'] ?? null) ? $card['capability'] : 'step';
            $state = is_string($card['state'] ?? null) ? $card['state'] : '';
            $parts[] = '' !== $state ? $name.' ('.$state.')' : $name;
        }

        return implode(', ', $parts);
    }
}
