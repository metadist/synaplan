<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Synthetic knowledge files for the document items. The same bytes every run.
 *
 * These are planted facts, not customer documents. Upload the written files
 * into the evaluation account before scoring a knowledge item.
 */
final readonly class SyntheticDocumentGenerator
{
    public const CHECKSUM = 'c23e02616de72f0bf221369f59c9fb334cb0aeda661157024aef57820a3c909e';

    /**
     * @return array<string, string> filename => contents, LF, no BOM
     */
    public function documents(): array
    {
        return [
            'preisliste.txt' => "Preisliste\nListenpreis: 1500 EUR\nAndere Beträge gelten nicht.\n",
            'protokoll.txt' => "Protokoll\nKickoff: 14. März\nAndere Daten gelten nicht.\n",
            'rahmenvertrag.txt' => "Rahmenvertrag A\nVertragsnummer: ZEBRA-41\nKündigungsfrist: drei Monate\nAndere Fristen gelten nicht.\n",
        ];
    }

    public function checksum(): string
    {
        $parts = [];
        foreach ($this->documents() as $name => $body) {
            $parts[] = $name."\n".$body;
        }

        return hash('sha256', implode("\n", $parts));
    }

    public function write(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the document directory.');
        }
        foreach ($this->documents() as $name => $body) {
            $path = $directory.'/'.$name;
            if (false === file_put_contents($path, $body)) {
                throw new \RuntimeException('Could not write '.$name.'.');
            }
        }
    }
}
