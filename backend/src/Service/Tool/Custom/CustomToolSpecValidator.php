<?php

declare(strict_types=1);

namespace App\Service\Tool\Custom;

use App\Entity\CustomTool;
use App\Service\Tool\SideEffect;

final readonly class CustomToolSpecValidator
{
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const SPEC_KEYS = ['method', 'url', 'headers', 'query', 'body', 'response'];
    /** Scheme, a literal host (name or IPv4/6 literal) and optional port; no userinfo, no template tokens. */
    private const LITERAL_ORIGIN = '/^https?:\/\/(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?(?:[\/?#]|$)/';

    public function __construct(
        private TemplateRenderer $templates,
    ) {
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     */
    public function validate(array $spec, string $sideEffect, string $name): array
    {
        if (1 !== preg_match(CustomTool::NAME_PATTERN, $name)) {
            throw new InvalidToolTemplateException('Name must be lowercase letters, digits and underscores (3–64 characters)');
        }
        if (null === SideEffect::tryFrom($sideEffect)) {
            throw new InvalidToolTemplateException('Class must be read, write or destructive');
        }
        foreach (array_keys($spec) as $key) {
            if (!in_array($key, self::SPEC_KEYS, true)) {
                throw new InvalidToolTemplateException('Unknown spec field: '.$key);
            }
        }
        $method = strtoupper((string) ($spec['method'] ?? 'GET'));
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidToolTemplateException('HTTP method is not allowed');
        }
        $url = (string) ($spec['url'] ?? '');
        if ('' === $url) {
            throw new InvalidToolTemplateException('URL is required');
        }
        $this->assertLiteralOrigin($url);
        foreach (['headers', 'query'] as $mapKey) {
            if (isset($spec[$mapKey]) && !is_array($spec[$mapKey])) {
                throw new InvalidToolTemplateException(sprintf('"%s" must be a map of names to values', $mapKey));
            }
        }
        $this->templates->walk($spec);
        $spec['method'] = $method;
        $spec['url'] = $url;

        return $spec;
    }

    /**
     * The scheme and host of a tool URL must be literal. Only the path and
     * query may carry `{{input.*}}` — otherwise the model (or a prompt
     * injection) could point a "read" tool at any host it likes.
     */
    private function assertLiteralOrigin(string $url): void
    {
        if (1 !== preg_match(self::LITERAL_ORIGIN, $url)) {
            throw new InvalidToolTemplateException('URL must start with https:// and a fixed host name; use {{input.*}} only in the path or query');
        }
    }
}
