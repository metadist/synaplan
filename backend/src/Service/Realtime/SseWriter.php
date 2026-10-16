<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Service\Chat\Run\ChatRunRecorder;

/**
 * Writes the server-sent events of one chat turn.
 *
 * Every event is one `data:` frame holding `{"status": "<name>", ...payload}`.
 * The names are an external contract — the web chat, the widget, the desktop
 * and the mobile apps read them — and pipeline statuses pass through
 * unchanged. A payload key named `status` wins over the event name, as it
 * always has (the rate-limit error relies on it).
 *
 * While a resumable run is attached, every event is recorded before the
 * abort check: the client may be gone, the turn keeps generating, and what
 * it produces from then on is exactly what a re-attaching client replays.
 *
 * One writer per turn; it keeps the run and the one-shot abort log line.
 */
final class SseWriter
{
    private ?ChatRunRecorder $run = null;

    /**
     * Detaching mid-turn is the normal case (the turn keeps generating for a
     * client that comes back), so the abort is logged once, not per event.
     */
    private bool $abortLogged = false;

    /**
     * @param bool $captureMode skip flushing so a test can read the frames from an output buffer (#2282)
     */
    public function __construct(private readonly bool $captureMode = false)
    {
    }

    /**
     * Prepares PHP for a long event stream: no output buffering (unless
     * capturing), no time limit, and no abort when the client disconnects —
     * a turn finishes in the background and stores its reply for the
     * person's return (#1142, #1223, #1225). An explicit Stop still ends it.
     */
    public function open(): void
    {
        SseOutput::begin($this->captureMode);
        set_time_limit(0);
        ignore_user_abort(true);
    }

    public function attachRun(?ChatRunRecorder $run): void
    {
        $this->run = $run;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function send(string $status, array $data): void
    {
        $event = [
            'status' => $status,
            ...self::sanitizeUtf8($data),
        ];

        // The prompt transcript is for the live frame only. The run recorder
        // would store the whole event and replay it on resume.
        $recorded = $event;
        unset($recorded['providerMessages']);
        $this->run?->record($recorded);

        if (connection_aborted()) {
            if (!$this->abortLogged) {
                $this->abortLogged = true;
                error_log('🔴 StreamController: Connection aborted — continuing in background');
            }

            return;
        }

        echo 'data: '.json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";

        if ($this->captureMode) {
            return;
        }

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Invalid UTF-8 would make the whole frame unencodable.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function sanitizeUtf8(array $data): array
    {
        return array_map(self::sanitizeValue(...), $data);
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::sanitizeValue(...), $value);
        }

        if (is_string($value)) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }
}
