<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Service;

use App\Repository\ConfigRepository;

/**
 * Installation-wide settings: BCONFIG owner 0, group P_synascriber.
 */
final readonly class Settings
{
    public const GROUP = 'P_synascriber';
    public const SUPPORTED_LANGUAGES = ['de', 'en', 'es', 'fr', 'tr'];

    private const GLOBAL_OWNER = 0;
    private const MAX_FOLDER_LENGTH = 64;

    private const DEFAULTS = [
        'enabled' => '0',
        'default_language' => 'de',
        'languages' => 'de,en',
        'default_folder' => 'Meetings',
        'stt_url' => '',
        'stt_model' => 'whisper',
        'stt_api_key' => '',
        'prosody_url' => '',
        'jitsi_url' => '',
        'prosody_secret' => '',
        'transcriber_secret' => '',
        'timezone' => 'Europe/Berlin',
        'share_with_participants' => '1',
        'captions' => '1',
    ];

    private const SECRET_KEYS = ['prosody_secret', 'transcriber_secret', 'stt_api_key'];

    public function __construct(private ConfigRepository $config)
    {
    }

    public function get(string $key): string
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException(sprintf('Unknown synascriber setting "%s".', $key));
        }

        return $this->config->getValue(self::GLOBAL_OWNER, self::GROUP, $key) ?? self::DEFAULTS[$key];
    }

    /**
     * Validates and stores the given settings. Unknown keys are rejected.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): void
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $clean[$key] = $this->normalize((string) $key, $value);
        }
        $languages = isset($clean['languages']) ? explode(',', $clean['languages']) : $this->languages();
        $default = $clean['default_language'] ?? $this->defaultLanguage();
        if (!in_array($default, $languages, true)) {
            throw new \InvalidArgumentException(sprintf('The default language "%s" is not among the offered languages.', $default));
        }
        foreach ($clean as $key => $value) {
            $this->config->setValue(self::GLOBAL_OWNER, self::GROUP, $key, $value);
        }
    }

    public function isEnabled(): bool
    {
        return '1' === $this->get('enabled');
    }

    /**
     * Every signed-in participant gets the transcript in their own Files
     * (Generated); off = only the person who started.
     */
    public function shareWithParticipants(): bool
    {
        return '1' === $this->get('share_with_participants');
    }

    /**
     * Live captions in the meeting while notes are on; off = only the file.
     */
    public function captionsOn(): bool
    {
        return '1' === $this->get('captions');
    }

    public function defaultLanguage(): string
    {
        return $this->get('default_language');
    }

    /**
     * @return list<string>
     */
    public function languages(): array
    {
        $list = array_values(array_intersect(explode(',', $this->get('languages')), self::SUPPORTED_LANGUAGES));

        return [] === $list ? [$this->defaultLanguage()] : $list;
    }

    public function defaultFolder(): string
    {
        return $this->get('default_folder');
    }

    public function sttUrl(): string
    {
        return rtrim($this->get('stt_url'), '/');
    }

    /**
     * The `model` field of the transcription request. whisper.cpp ignores it;
     * the OpenAI API, vLLM and the cloud services require it.
     */
    public function sttModel(): string
    {
        return $this->get('stt_model');
    }

    /**
     * Bearer token for the speech-to-text server, empty for a server on the
     * cluster network such as whisper.cpp.
     */
    public function sttApiKey(): string
    {
        return $this->get('stt_api_key');
    }

    public function prosodyUrl(): string
    {
        return rtrim($this->get('prosody_url'), '/');
    }

    public function jitsiUrl(): string
    {
        return $this->get('jitsi_url');
    }

    /**
     * Scheme, host and port of Jitsi: the only window the sign-in page hands
     * a code to, and the only one allowed to embed it. Empty when unset.
     */
    public function jitsiOrigin(): string
    {
        $url = $this->jitsiUrl();
        if ('' === $url) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function prosodySecret(): string
    {
        return $this->get('prosody_secret');
    }

    /**
     * What the transcriber sends in X-Transcriber-Secret. Separate from the
     * Prosody secret: that one is held by Jitsi, this one by the transcriber.
     */
    public function transcriberSecret(): string
    {
        return $this->get('transcriber_secret');
    }

    public function timezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->get('timezone'));
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * Settings for the admin page; secrets only say whether they are set.
     *
     * @return array<string, string|bool|list<string>>
     */
    public function publicView(): array
    {
        $view = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $view[$key] = in_array($key, self::SECRET_KEYS, true) ? '' !== $this->get($key) : $this->get($key);
        }
        $view['enabled'] = $this->isEnabled();
        $view['share_with_participants'] = $this->shareWithParticipants();
        $view['captions'] = $this->captionsOn();
        $view['languages'] = $this->languages();

        return $view;
    }

    public function sanitizeFolder(?string $folder): string
    {
        $folder = trim((string) $folder);
        $folder = (string) preg_replace('/[^\p{L}\p{N} _.\-]/u', '', $folder);

        return '' === $folder ? $this->defaultFolder() : mb_substr($folder, 0, self::MAX_FOLDER_LENGTH);
    }

    private function normalize(string $key, mixed $value): string
    {
        return match ($key) {
            'enabled', 'share_with_participants', 'captions' => $value ? '1' : '0',
            'default_language' => $this->language((string) $value),
            'languages' => implode(',', array_map(fn ($l) => $this->language((string) $l), is_array($value) ? $value : explode(',', (string) $value))),
            'default_folder' => $this->sanitizeFolder((string) $value),
            'stt_url', 'prosody_url', 'jitsi_url' => $this->url((string) $value, $key),
            'stt_model' => $this->model((string) $value),
            'prosody_secret', 'transcriber_secret' => $this->secret($value, $key),
            'stt_api_key' => $this->bearerToken($value, $key),
            'timezone' => $this->zone((string) $value),
            default => throw new \InvalidArgumentException(sprintf('Unknown synascriber setting "%s".', $key)),
        };
    }

    private function language(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::SUPPORTED_LANGUAGES, true)) {
            throw new \InvalidArgumentException(sprintf('Language "%s" is not supported; use one of %s.', $value, implode(', ', self::SUPPORTED_LANGUAGES)));
        }

        return $value;
    }

    /**
     * A secret is taken as given, but only as a string: a cast would turn
     * `true` into the credential "1" and an array into "Array".
     */
    private function secret(mixed $value, string $key): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Setting "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * Goes out as `Authorization: Bearer`, so whitespace and control
     * characters are refused here instead of failing every window later.
     */
    private function bearerToken(mixed $value, string $key): string
    {
        $value = trim($this->secret($value, $key));
        if (preg_match('/[\s\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException(sprintf('Setting "%s" must not contain spaces, line breaks or control characters.', $key));
        }

        return $value;
    }

    /**
     * An empty field falls back to the default, so clearing it on the plugin
     * page does not block saving the other settings.
     */
    private function model(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return self::DEFAULTS['stt_model'];
        }
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$#', $value)) {
            throw new \InvalidArgumentException('Setting "stt_model" must be a model name such as "whisper" or "openai/whisper-large-v3-turbo".');
        }

        return $value;
    }

    private function url(string $value, string $key): string
    {
        $value = trim($value);
        if ('' !== $value && !preg_match('#^https?://[^\s/]+#', $value)) {
            throw new \InvalidArgumentException(sprintf('Setting "%s" must be an http(s) URL.', $key));
        }

        return rtrim($value, '/');
    }

    private function zone(string $value): string
    {
        try {
            return (new \DateTimeZone(trim($value)))->getName();
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('Unknown time zone "%s".', $value));
        }
    }
}
