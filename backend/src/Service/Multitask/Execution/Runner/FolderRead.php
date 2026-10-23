<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\PlatformInstance;

/**
 * Where a `folder_fetch` step read from, and the card summary of how it went.
 * The card renders its sentence from `outcome` in the reader's language; the
 * step's English text stays what later steps and the answer read.
 */
final readonly class FolderRead
{
    public const READING = 'reading';
    public const READ = 'read';
    public const PARTIAL = 'partial';
    public const LISTED = 'listed';
    public const REFUSED = 'refused';
    public const NOT_FOUND = 'not_found';
    public const UNREACHABLE = 'unreachable';
    public const NOTHING = 'nothing';
    public const BUSY = 'busy';
    public const NOT_CONNECTED = 'not_connected';

    /** Names a card carries; the step text keeps the rest for the answer. */
    private const MAX_NAMES = 100;

    public function __construct(
        public string $app,
        public string $label,
        public string $host,
        public string $folder,
        public ?string $openUrl = null,
    ) {
    }

    public static function clientLabel(string $client): string
    {
        return match ($client) {
            PlatformInstance::CLIENT_NEXTCLOUD => 'Nextcloud',
            PlatformInstance::CLIENT_OPENCLOUD => 'OpenCloud',
            PlatformInstance::CLIENT_OWNCLOUD => 'ownCloud',
            default => 'cloud',
        };
    }

    /** "Nextcloud › Invoices/2026" for the step's English text. */
    public function where(): string
    {
        $name = '' !== $this->label ? $this->label : 'the folder';

        return '' !== $this->folder ? $name.' › '.$this->folder : $name;
    }

    /**
     * @param list<string>                              $files   names that were read (or listed)
     * @param list<array{name: string, reason: string}> $skipped names that were left out, with why
     *
     * @return array{folder_fetch: true, folder_read: array<string, mixed>}
     */
    public function metadata(string $outcome, int $read = 0, int $total = 0, array $files = [], array $skipped = [], bool $stopped = false): array
    {
        return [
            'folder_fetch' => true,
            'folder_read' => [
                'outcome' => $outcome,
                'app' => $this->app,
                'label' => $this->label,
                'host' => $this->host,
                'folder' => $this->folder,
                'read' => $read,
                'total' => max($total, $read),
                'files' => array_slice($files, 0, self::MAX_NAMES),
                'skipped' => array_slice($skipped, 0, self::MAX_NAMES),
                'stopped' => $stopped,
                'open_url' => $this->openUrl,
            ],
        ];
    }
}
