<?php

declare(strict_types=1);

namespace App\AI\Catalog\Scaffold;

/**
 * What `app:provider:scaffold` was asked for: the one-word provider name and
 * the handful of values a chat-completions provider needs before the
 * developer edits the generated class.
 */
final readonly class ProviderScaffoldRequest
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9]{1,31}$/';

    public function __construct(
        public string $name,
        public string $displayName,
        public string $baseUri,
        public string $envVar,
        public string $consoleUrl,
        public string $flag,
        public string $defaultModel,
    ) {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(sprintf('A provider name is 2–32 lowercase letters and digits, starting with a letter; "%s" is not.', $name));
        }
        // http is for a stub on the Docker network while developing; a
        // shipped provider is https.
        if (1 !== preg_match('/^https?:\/\/[^\s\/]+(\/[^\s]*)?$/', $baseUri) || str_ends_with($baseUri, '/')) {
            throw new \InvalidArgumentException(sprintf('The base URL must be http(s) with a host and no trailing slash; "%s" is not.', $baseUri));
        }
        if (1 !== preg_match('/^[A-Z][A-Z0-9_]*$/', $envVar)) {
            throw new \InvalidArgumentException(sprintf('The environment variable is UPPER_SNAKE_CASE; "%s" is not.', $envVar));
        }
    }

    /** `acme` → `AcmeProvider`. */
    public function className(): string
    {
        return ucfirst($this->name).'Provider';
    }

    public function host(): string
    {
        $host = parse_url($this->baseUri, PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }
}
