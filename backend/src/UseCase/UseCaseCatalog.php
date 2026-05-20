<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * Built-in use case catalogue for Synapse Release C.
 *
 * Stable IDs indexed in Qdrant (`synapse_use_cases`). User-facing labels
 * live in frontend i18n (`config.routing.useCases.*`).
 *
 * @phpstan-type UseCaseEntry array{
 *     id: string,
 *     shortDescription: string,
 *     keywords: string,
 * }
 */
final class UseCaseCatalog
{
    /**
     * @return list<UseCaseEntry>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'text_chat',
                'shortDescription' => 'Everyday questions, smalltalk, advice, opinions, coding help, and any request that should be answered in a conversational chat.',
                'keywords' => 'chat, question, advice, coding, programming, general, smalltalk, explain, help',
            ],
            [
                'id' => 'media_generation',
                'shortDescription' => 'Create or edit images, videos, illustrations, animations, or spoken audio from a text description.',
                'keywords' => 'image, picture, photo, video, clip, animation, audio, tts, voice, draw, generate media',
            ],
            [
                'id' => 'file_generation',
                'shortDescription' => 'Generate structured office documents such as spreadsheets, Word documents, presentations, or CSV exports.',
                'keywords' => 'document, spreadsheet, xlsx, docx, pptx, csv, report, export, officemaker',
            ],
            [
                'id' => 'file_analytics',
                'shortDescription' => 'Analyze, summarize, or extract information from uploaded files, PDFs, images, or attached documents.',
                'keywords' => 'analyze file, summarize pdf, document summary, extract text, file content, attachment',
            ],
            [
                'id' => 'comm_send_email',
                'shortDescription' => 'Draft or send an email message, including attaching generated content to an outgoing mail.',
                'keywords' => 'send email, mail, e-mail, compose message, reply, forward',
            ],
            [
                'id' => 'comm_receive_email',
                'shortDescription' => 'Fetch, read, or analyze incoming email messages and act on their content.',
                'keywords' => 'read email, incoming mail, inbox, summarize email, fetch mail',
            ],
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }
}
