<?php

declare(strict_types=1);

namespace App\AI\Catalog\Scaffold;

use App\Model\ModelCatalog;

/**
 * Builds the one class a new OpenAI-compatible provider is, its unit test,
 * and the two things the class cannot carry: the model rows for
 * `ModelCatalog` and the help sentence in the five locales. Every table the
 * installation keeps (keys, defaults, upstreams, admin schema, public
 * catalog, egress allowlist) reads the descriptor; nothing else is edited.
 */
final readonly class ProviderScaffolder
{
    public const LOCALES = ['en', 'de', 'es', 'fr', 'tr'];

    public function __construct(
        private string $projectDir,
    ) {
    }

    public function plan(ProviderScaffoldRequest $request): ProviderScaffoldPlan
    {
        $class = $request->className();

        return new ProviderScaffoldPlan(
            files: [
                $this->projectDir.'/src/AI/Provider/'.$class.'.php' => $this->providerClass($request),
                $this->projectDir.'/tests/Unit/AI/Provider/'.$class.'Test.php' => $this->providerTest($request),
            ],
            snippets: [
                'ModelCatalog::MODELS (backend/src/Model/ModelCatalog.php) — one row per model; the lowercase of `service` is the provider name' => $this->modelRow($request),
                'admin.providerHelp.'.$request->name.' — frontend/src/i18n/locales/{en,de,es,fr,tr}/admin.json' => $this->localeKeys($request),
            ],
        );
    }

    /**
     * @return list<string> the paths written
     *
     * @throws \RuntimeException when a file exists and $force is false
     */
    public function write(ProviderScaffoldPlan $plan, bool $force): array
    {
        foreach (array_keys($plan->files) as $path) {
            if (!$force && file_exists($path)) {
                throw new \RuntimeException(sprintf('%s exists; pass --force to overwrite it.', $this->relative($path)));
            }
        }
        $written = [];
        foreach ($plan->files as $path => $contents) {
            $dir = \dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Cannot create %s.', $this->relative($dir)));
            }
            if (false === file_put_contents($path, $contents)) {
                throw new \RuntimeException(sprintf('Cannot write %s.', $this->relative($path)));
            }
            $written[] = $this->relative($path);
        }

        return $written;
    }

    public function relative(string $path): string
    {
        return str_starts_with($path, $this->projectDir.'/') ? substr($path, \strlen($this->projectDir) + 1) : $path;
    }

    public function nextModelId(): int
    {
        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), ModelCatalog::all());

        return ([] === $ids ? 0 : max($ids)) + 1;
    }

    private function providerClass(ProviderScaffoldRequest $r): string
    {
        return $this->render('provider.php.tpl', [
            '{{class}}' => $r->className(),
            '{{name}}' => $r->name,
            '{{baseUri}}' => $r->baseUri,
            '{{defaultModel}}' => $r->defaultModel,
            '{{displayName}}' => $r->displayName,
            '{{envVar}}' => $r->envVar,
            '{{consoleUrl}}' => $r->consoleUrl,
            '{{flag}}' => $r->flag,
            '{{displayNameLiteral}}' => var_export($r->displayName, true),
            '{{fieldDescriptionLiteral}}' => var_export($r->displayName.' API key — OpenAI-compatible chat completions at '.$r->host(), true),
            '{{descriptionLiteral}}' => var_export($r->displayName.' — OpenAI-compatible chat completions at '.$r->host().'.', true),
            '{{hintLiteral}}' => var_export('Create an API key at '.$r->consoleUrl.', then paste it under Admin → AI infrastructure → Providers & keys.', true),
        ]);
    }

    private function providerTest(ProviderScaffoldRequest $r): string
    {
        return $this->render('test.php.tpl', [
            '{{class}}' => $r->className(),
            '{{name}}' => $r->name,
            '{{envVar}}' => $r->envVar,
            '{{host}}' => $r->host(),
            '{{baseUri}}' => $r->baseUri,
        ]);
    }

    /**
     * @param array<string, string> $values
     */
    private function render(string $template, array $values): string
    {
        $source = file_get_contents(__DIR__.'/templates/'.$template);
        if (false === $source) {
            throw new \RuntimeException(sprintf('Scaffold template %s is missing.', $template));
        }

        return strtr($source, $values);
    }

    private function modelRow(ProviderScaffoldRequest $r): string
    {
        $id = $this->nextModelId();
        $service = var_export(ucfirst($r->name), true);
        $model = var_export($r->defaultModel, true);
        $name = var_export($r->displayName.' '.$r->defaultModel, true);
        $description = var_export($r->defaultModel.' via '.$r->displayName.' ('.$r->host().'). Edit price, context and features from the provider\'s model page.', true);
        $host = var_export($r->host(), true);

        return <<<PHP
                    [
                        'id' => {$id},
                        'service' => {$service},
                        'name' => {$name},
                        'tag' => 'chat',
                        'selectable' => 1,
                        'active' => 1,
                        'providerId' => {$model},
                        // Fill in the provider's prices. A row priced 0.0 / 0.0 is
                        // hidden from every picker unless showWhenFree stays 1.
                        'priceIn' => 0.0,
                        'inUnit' => 'per1M',
                        'priceOut' => 0.0,
                        'outUnit' => 'per1M',
                        'showWhenFree' => 1,
                        'quality' => 5,
                        'rating' => 1,
                        'json' => [
                            'description' => {$description},
                            'max_tokens' => 8192,
                            'params' => ['model' => {$model}],
                            'features' => ['tool_use', 'multilingual'],
                            'meta' => ['host' => {$host}, 'jurisdiction' => 'XX'],
                        ],
                    ],
            PHP;
    }

    private function localeKeys(ProviderScaffoldRequest $r): string
    {
        $sentences = [
            'en' => ['%s API key', 'Create an API key in the %s console, then paste it here.'],
            'de' => ['%s-API-Schlüssel', 'Erstellen Sie einen API-Schlüssel in der %s-Konsole und fügen Sie ihn hier ein.'],
            'es' => ['Clave de API de %s', 'Crea una clave de API en la consola de %s y pégala aquí.'],
            'fr' => ['Clé API %s', 'Créez une clé API dans la console %s, puis collez-la ici.'],
            'tr' => ['%s API anahtarı', '%s konsolunda bir API anahtarı oluşturun ve buraya yapıştırın.'],
        ];
        $out = [];
        foreach (self::LOCALES as $locale) {
            [$title, $message] = $sentences[$locale];
            $out[] = sprintf(
                '%s/admin.json → "providerHelp": { "%s": %s }',
                $locale,
                $r->name,
                json_encode(['title' => sprintf($title, $r->displayName), 'message' => sprintf($message, $r->displayName)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
        }

        return implode("\n", $out);
    }
}
