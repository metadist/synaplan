<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\JsonResponseDecoder;
use App\AI\StructuredOutput\Schema\CalendarEntriesSchema;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Prompt\PromptCatalog;
use App\Repository\PromptRepository;
use App\Service\ModelConfigService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Takes calendar entries out of text that was read for the person: the three
 * flights of a booking picture, the meeting of an invitation, the dates of a
 * mail an earlier step found.
 *
 * The planner cannot do this. It writes its plan before anything is read, so
 * it knows neither how many entries there are nor their times. This runs
 * after the reading, on the text analytics model.
 *
 * The model proposes; the text decides. An entry is returned only when the
 * read text names its start time ({@see ClockTimeEvidence}), which is the
 * same promise the calendar step makes for a time the person typed: no hour
 * is invented.
 */
final readonly class CalendarEntryExtractor
{
    public const PROMPT_TOPIC = 'tools:calendar_entries';

    public const MAX_ENTRIES = 20;

    private const MAX_READ_CHARS = 24000;

    private const MAX_TOKENS = 3000;

    private const MAX_TITLE_CHARS = 120;

    private const MAX_DETAIL_CHARS = 600;

    public function __construct(
        private AiFacade $aiFacade,
        private PromptRepository $prompts,
        private ModelConfigService $models,
        private StructuredOutputConfig $structuredOutput,
        private LoggerInterface $logger,
        private ClockInterface $clock = new NativeClock(),
        private JsonResponseDecoder $decoder = new JsonResponseDecoder(),
    ) {
    }

    /**
     * @param string               $request  what the person asked for, in their words
     * @param string               $readText the text the entries are taken from
     * @param \DateTimeZone        $zone     the person's zone, for "now" (a year the text leaves out)
     * @param array<string, mixed> $usage    attribution for the usage row (source, chat, agent)
     *
     * @throws \RuntimeException when no model or no instructions are configured, or the reply is not JSON
     */
    public function extract(string $request, string $readText, \DateTimeZone $zone, ?int $userId, array $usage = []): CalendarEntryExtraction
    {
        $readText = mb_substr(trim($readText), 0, self::MAX_READ_CHARS);
        if ('' === $readText) {
            return new CalendarEntryExtraction([]);
        }

        $model = $this->models->getSummaryModelConfig($userId);
        if (null === $model['provider'] || null === $model['model']) {
            throw new \RuntimeException('No model is configured for reading calendar entries.');
        }

        $payload = json_encode([
            'request' => mb_substr(trim($request), 0, 2000),
            'now' => $this->clock->now()->setTimezone($zone)->format('l, Y-m-d H:i').' ('.$zone->getName().')',
            'read' => $readText,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) {
            throw new \RuntimeException('The text could not be prepared for reading calendar entries.');
        }

        $options = $usage + [
            'provider' => $model['provider'],
            'model' => $model['model'],
            'temperature' => 0,
            'max_tokens' => self::MAX_TOKENS,
            'disable_thinking' => true,
        ];
        if ($this->structuredOutput->isEnabled($userId)) {
            $options['structured_output'] = CalendarEntriesSchema::build();
        }

        $response = $this->aiFacade->chat([
            ['role' => 'system', 'content' => $this->instructions()],
            ['role' => 'user', 'content' => $payload],
        ], $userId, $options);

        $decoded = $this->decoder->decode((string) ($response['content'] ?? ''))->data;
        if (null === $decoded) {
            throw new \RuntimeException('The calendar entries came back in a form that could not be read.');
        }

        [$entries, $rejected] = $this->accept($decoded['entries'] ?? $decoded, $readText);
        $this->logger->info('CalendarEntryExtractor: entries read', [
            'accepted' => count($entries),
            'rejected_without_named_time' => $rejected,
            'read_chars' => mb_strlen($readText),
        ]);

        return new CalendarEntryExtraction($entries, $rejected, [
            'provider' => $response['provider'] ?? $model['provider'],
            'model' => $response['model'] ?? $model['model'],
            'model_id' => $model['model_id'],
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'facade_metered' => true === ($response['facade_metered'] ?? false),
            'facade_charged_cost' => $response['facade_charged_cost'] ?? null,
            'facade_price_known' => $response['facade_price_known'] ?? true,
        ]);
    }

    /**
     * Keep what the read text backs: a parseable start whose clock time the
     * text names. An end the text does not name is dropped, not the entry.
     *
     * @return array{0: list<CalendarEntry>, 1: int} entries and the number refused
     */
    private function accept(mixed $proposed, string $readText): array
    {
        if (!is_array($proposed)) {
            return [[], 0];
        }

        $entries = [];
        $seen = [];
        $rejected = 0;
        foreach ($proposed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $start = $this->wallClock($row['start'] ?? null);
            if (null === $start) {
                continue;
            }
            if (!ClockTimeEvidence::names(new \DateTimeImmutable($start), $readText)) {
                ++$rejected;
                continue;
            }

            $title = $this->line($row['title'] ?? null, self::MAX_TITLE_CHARS) ?? 'Meeting';
            $key = mb_strtolower($title).'|'.$start;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $end = $this->wallClock($row['end'] ?? null);
            if (null !== $end && !ClockTimeEvidence::names(new \DateTimeImmutable($end), $readText)) {
                $end = null;
            }

            $entries[] = new CalendarEntry(
                $title,
                $start,
                $end,
                $this->line($row['location'] ?? null, self::MAX_TITLE_CHARS),
                $this->text($row['description'] ?? null),
            );
            if (count($entries) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return [$entries, $rejected];
    }

    /**
     * "2027-03-12T06:45:00" from whatever date-time the model wrote. A zone
     * or offset is cut off: the time is the one on the page, and the calendar
     * step places it in the person's zone.
     */
    private function wallClock(mixed $value): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^\s*(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2}))?/', $value, $m)) {
            return null;
        }
        $candidate = $m[1].'T'.$m[2].':'.($m[3] ?? '00');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $candidate);
        // A date that rolls over (2026-02-31) is not what the text said.
        if (false === $parsed || $parsed->format('Y-m-d\TH:i:s') !== $candidate) {
            return null;
        }

        return $candidate;
    }

    private function line(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/\s+/u', ' ', $value));

        return '' === $clean ? null : mb_substr($clean, 0, $max);
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return mb_substr(trim($value), 0, self::MAX_DETAIL_CHARS);
    }

    private function instructions(): string
    {
        try {
            $row = $this->prompts->findByTopic(self::PROMPT_TOPIC, 0);
        } catch (\Throwable) {
            $row = null;
        }
        if (null !== $row && '' !== trim($row->getPrompt())) {
            return $row->getPrompt();
        }
        foreach (PromptCatalog::all() as $entry) {
            if (self::PROMPT_TOPIC === $entry['topic']) {
                return $entry['prompt'];
            }
        }

        throw new \RuntimeException('The instructions for reading calendar entries are missing.');
    }
}
