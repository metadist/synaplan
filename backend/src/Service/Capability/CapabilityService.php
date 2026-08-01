<?php

namespace App\Service\Capability;

use App\Service\File\FileStorageService;
use App\Service\Summary\SummaryOptions;

/**
 * Assembles the public capability descriptor consumed by external
 * integrations (synaplan-nextcloud, synaplan-opencloud, future clients)
 * so they can discover supported file formats, languages and summary
 * options at runtime instead of hardcoding drifting lists (#676).
 *
 * Everything is derived from the same constants the runtime enforces:
 * file formats/size from {@see FileStorageService}, summary/language
 * options from {@see SummaryOptions}.
 */
final readonly class CapabilityService
{
    /**
     * Grouping of upload extensions by human category. Only extensions that
     * are ALSO present in FileStorageService::ALLOWED_EXTENSIONS are returned,
     * so the descriptor can never advertise a format the backend rejects.
     * Any allowed extension not listed here is surfaced under "other" instead
     * of being silently dropped.
     *
     * @var array<string, list<string>>
     */
    private const EXTENSION_CATEGORIES = [
        'text' => ['txt', 'md', 'csv'],
        'documents' => ['pdf', 'doc', 'docx', 'odt', 'odf', 'odg'],
        'spreadsheets' => ['xls', 'xlsx', 'ods'],
        'presentations' => ['ppt', 'pptx', 'odp'],
        'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
        'video' => ['mp4', 'webm', 'mov', 'avi', 'mkv'],
        'calendar' => ['ics'],
    ];

    /**
     * Build the capability descriptor.
     *
     * @return array{
     *     file_formats: array<string, list<string>>,
     *     languages: list<string>,
     *     summary: array{types: list<string>, lengths: list<string>, focus_areas: list<string>},
     *     max_file_size_bytes: int
     * }
     */
    public function getCapabilities(): array
    {
        return [
            'file_formats' => $this->categorizedFileFormats(),
            'languages' => SummaryOptions::LANGUAGES,
            'summary' => [
                'types' => SummaryOptions::TYPES,
                'lengths' => SummaryOptions::LENGTHS,
                'focus_areas' => SummaryOptions::FOCUS_AREAS,
            ],
            'max_file_size_bytes' => FileStorageService::getMaxFileSize(),
        ];
    }

    /**
     * Group the allowed upload extensions by category, filtered against the
     * canonical allow-list so the two can never disagree.
     *
     * @return array<string, list<string>>
     */
    private function categorizedFileFormats(): array
    {
        $allowed = FileStorageService::getAllowedExtensions();
        $categorized = [];
        $seen = [];

        foreach (self::EXTENSION_CATEGORIES as $category => $extensions) {
            $present = array_values(array_filter(
                $extensions,
                static fn (string $ext): bool => in_array($ext, $allowed, true),
            ));

            if ([] !== $present) {
                $categorized[$category] = $present;
                foreach ($present as $ext) {
                    $seen[$ext] = true;
                }
            }
        }

        // Never silently drop an allowed extension that has no category yet.
        $other = array_values(array_filter(
            $allowed,
            static fn (string $ext): bool => !isset($seen[$ext]),
        ));
        if ([] !== $other) {
            $categorized['other'] = $other;
        }

        return $categorized;
    }
}
