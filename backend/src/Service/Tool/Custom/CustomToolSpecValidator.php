<?php

declare(strict_types=1);

namespace App\Service\Tool\Custom;

use App\Entity\CustomTool;
use App\Service\Tool\SideEffect;

final readonly class CustomToolSpecValidator
{
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const SPEC_KEYS = ['method', 'url', 'headers', 'query', 'body', 'response'];

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
        $this->templates->walk($spec);
        $spec['method'] = $method;
        $spec['url'] = $url;

        return $spec;
    }
}
