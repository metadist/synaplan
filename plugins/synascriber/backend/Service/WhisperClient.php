<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Speech-to-text over the OpenAI transcription API: whisper.cpp's
 * `whisper-server`, vLLM, or a cloud service, with filters against what
 * Whisper invents on silence. Replaced by Synaplan's own Whisper provider
 * once Synaplan has its server mode.
 */
final readonly class WhisperClient
{
    private const TIMEOUT_SECONDS = 60;
    private const NO_SPEECH_LIMIT = 0.6;
    private const LOW_CONFIDENCE_LIMIT = -1.0;

    /** Phrases Whisper invents on silence or noise, compared in lower case without punctuation. */
    private const HALLUCINATIONS = [
        'untertitel im auftrag des zdf',
        'untertitel der amaraorg community',
        'untertitelung des zdf',
        'vielen dank fürs zuschauen',
        'danke fürs zuschauen',
        'bis zum nächsten mal',
        'amen',
        'tschüss',
        'thank you for watching',
        'thanks for watching',
        'subtitles by the amaraorg community',
        'sous-titrage société radio-canada',
        'gracias por ver el video',
    ];

    public function __construct(
        private HttpClientInterface $http,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Recognised text of one window, why nothing was kept (`no_speech`: the
     * server heard none; `hallucination`: only filtered phrases), where the
     * last kept speech ends and where each kept phrase ends (the speech
     * server splits at short pauses), in ms from the start of the window.
     *
     * @return array{text: string, dropped: string|null, speechEndMs: int|null, segmentEndsMs: list<int>}
     */
    public function recognize(string $audio, string $language): array
    {
        $base = $this->settings->sttUrl();
        if ('' === $base) {
            throw new SessionException('not_configured', 'No speech-to-text server is set.', 503);
        }

        $fields = [
            'file' => new DataPart($audio, 'window.ogg', 'audio/ogg'),
            'model' => $this->settings->sttModel(),
            'response_format' => 'verbose_json',
            'temperature' => '0',
        ];
        if (in_array($language, Settings::SUPPORTED_LANGUAGES, true)) {
            $fields['language'] = $language;
        }
        $form = new FormDataPart($fields);

        try {
            $response = $this->http->request('POST', $base.'/v1/audio/transcriptions', [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => [...$form->getPreparedHeaders()->toArray(), ...$this->authorization()],
                'body' => $form->bodyToIterable(),
            ]);
            $body = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            $this->logger->warning('synascriber: speech server not reachable', ['error' => $e->getMessage()]);
            throw new SessionException('stt_unreachable', 'The speech-to-text server did not answer.', 502);
        }
        if ($status >= 400) {
            throw new SessionException('stt_error', sprintf('The speech-to-text server answered %d.', $status), 502);
        }

        return $this->filter($body);
    }

    public function healthy(): bool
    {
        $base = $this->settings->sttUrl();
        if ('' === $base) {
            return false;
        }
        try {
            return 200 === $this->http->request('GET', $base.'/health', ['timeout' => 5, 'headers' => $this->authorization()])->getStatusCode();
        } catch (ExceptionInterface) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function authorization(): array
    {
        $key = $this->settings->sttApiKey();

        return '' === $key ? [] : ['Authorization: Bearer '.$key];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{text: string, dropped: string|null, speechEndMs: int|null, segmentEndsMs: list<int>}
     */
    private function filter(array $body): array
    {
        $segments = is_array($body['segments'] ?? null) ? $body['segments'] : [['text' => $body['text'] ?? '']];
        $kept = '';
        $heard = false;
        $ends = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            // Whisper ends segments inside words too; a new word starts with a
            // space in the raw text, so join raw and only clean up afterwards.
            $raw = (string) ($segment['text'] ?? '');
            $text = trim($raw);
            $noSpeech = (float) ($segment['no_speech_prob'] ?? 0.0);
            $logProb = (float) ($segment['avg_logprob'] ?? 0.0);
            if ('' === $text || ($noSpeech > self::NO_SPEECH_LIMIT && $logProb < self::LOW_CONFIDENCE_LIMIT)) {
                continue;
            }
            $heard = true;
            if ($this->isHallucination($text) || $this->isRepetition($text)) {
                continue;
            }
            $kept .= $raw;
            if (is_numeric($segment['end'] ?? null)) {
                $ends[] = (int) round((float) $segment['end'] * 1000);
            }
        }

        $kept = (string) preg_replace('/\s+/u', ' ', $kept);
        $kept = trim((string) preg_replace('/\s+([,.;:!?])/u', '$1', $kept));
        $dropped = '' !== $kept ? null : ($heard ? 'hallucination' : 'no_speech');

        sort($ends);
        $ends = '' !== $kept ? array_values(array_unique($ends)) : [];

        return ['text' => $kept, 'dropped' => $dropped, 'speechEndMs' => [] !== $ends ? max($ends) : null, 'segmentEndsMs' => $ends];
    }

    private function isHallucination(string $text): bool
    {
        $plain = trim((string) preg_replace('/[^\p{L}\p{N} \-]/u', '', mb_strtolower($text)));
        if ('' === $plain) {
            return true;
        }
        foreach (self::HALLUCINATIONS as $phrase) {
            if (str_contains($plain, $phrase) && mb_strlen($plain) <= mb_strlen($phrase) + 12) {
                return true;
            }
        }

        return false;
    }

    private function isRepetition(string $text): bool
    {
        $words = preg_split('/\s+/u', mb_strtolower($text)) ?: [];

        return count($words) >= 8 && count(array_unique($words)) <= 2;
    }
}
