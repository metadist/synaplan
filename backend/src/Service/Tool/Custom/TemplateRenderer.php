<?php

declare(strict_types=1);

namespace App\Service\Tool\Custom;

/**
 * Strict subset: {{input.*}}, {{response.*}}, {{credential.header}} only.
 * No expressions, filters, nested braces or unknown tokens.
 */
final readonly class TemplateRenderer
{
    private const TOKEN = '/\{\{\s*(input|response|credential)\.([A-Za-z0-9_]+)\s*\}\}/';

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $response
     */
    public function render(string $template, array $input, array $response = [], ?string $credentialHeader = null): string
    {
        $this->assertSubset($template);

        return (string) preg_replace_callback(self::TOKEN, static function (array $m) use ($input, $response, $credentialHeader): string {
            return match ($m[1]) {
                'input' => self::scalar($input[$m[2]] ?? ''),
                'response' => self::scalar($response[$m[2]] ?? ''),
                default => 'header' === $m[2] ? (string) $credentialHeader : '',
            };
        }, $template);
    }

    public function assertSubset(string $template): void
    {
        if (!str_contains($template, '{{')) {
            return;
        }
        $stripped = preg_replace(self::TOKEN, '', $template);
        if (is_string($stripped) && str_contains($stripped, '{{')) {
            throw new InvalidToolTemplateException('Templates may only use {{input.*}}, {{response.*}} or {{credential.header}}');
        }
        if (preg_match(self::TOKEN, $template, $m) && 'credential' === $m[1] && 'header' !== $m[2]) {
            throw new InvalidToolTemplateException('Only {{credential.header}} is allowed for credentials');
        }
    }

    public function walk(mixed $value): void
    {
        if (is_string($value)) {
            $this->assertSubset($value);

            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->walk($item);
            }
        }
    }

    private static function scalar(mixed $value): string
    {
        if (is_scalar($value) || null === $value) {
            return (string) $value;
        }

        return '';
    }
}
