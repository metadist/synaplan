<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Bundle\BundleEnvelopeException;
use App\Bundle\BundleEnvelopeValidator;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\AgentTemplate\Exception\AgentTemplateNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AgentTemplateCatalog
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/agents')]
        private string $directory,
        private BundleEnvelopeValidator $envelope,
        private AgentDefinitionValidator $definitions,
    ) {
    }

    /**
     * @return list<AgentTemplate>
     */
    public function all(): array
    {
        return $this->load();
    }

    public function get(string $id): AgentTemplate
    {
        foreach ($this->load() as $template) {
            if ($template->id === $id) {
                return $template;
            }
        }

        throw AgentTemplateNotFoundException::forId($id);
    }

    /**
     * @return list<AgentTemplate>
     */
    private function load(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $paths = glob(rtrim($this->directory, '/').'/*.json') ?: [];
        sort($paths);
        $templates = [];
        foreach ($paths as $path) {
            $templates[] = $this->read($path);
        }

        return $templates;
    }

    private function read(string $path): AgentTemplate
    {
        $json = (string) file_get_contents($path);
        try {
            $envelope = $this->envelope->parse($json, ['agents', 'prompts']);
        } catch (BundleEnvelopeException $e) {
            throw new \RuntimeException(sprintf('Template %s is not a valid bundle: %s', basename($path), $e->getMessage()), 0, $e);
        }
        $block = $envelope['template'];
        if (!is_array($block)) {
            throw new \RuntimeException(sprintf('Template %s has no template block', basename($path)));
        }
        $item = $this->agentItem($envelope['sections'], basename($path));
        $definition = is_array($item['definition'] ?? null) ? $item['definition'] : [];
        $validated = $this->definitions->validate($definition)->toArray();
        $name = is_string($item['name'] ?? null) && '' !== trim($item['name']) ? trim($item['name']) : (string) $block['id'];
        $description = is_string($item['description'] ?? null) && '' !== trim($item['description']) ? trim($item['description']) : null;
        $icon = is_string($item['icon'] ?? null) ? $item['icon'] : '';
        $instruction = is_string($item['instruction'] ?? null) ? trim($item['instruction']) : '';
        if ('' === $instruction) {
            throw new \RuntimeException(sprintf('Template %s has no instruction', basename($path)));
        }
        $requires = is_array($block['requires'] ?? null) ? $block['requires'] : [];
        $tryIt = is_array($block['tryIt'] ?? null) ? $block['tryIt'] : [];

        return new AgentTemplate(
            (string) $block['id'],
            (string) $block['version'],
            (string) $block['titleKey'],
            (string) $block['summaryKey'],
            (string) $block['category'],
            $requires,
            $tryIt,
            new AgentTemplateBody($name, $description, $icon, $instruction, $validated),
        );
    }

    /**
     * @param list<array{kind: string, version: int, items: list<array<string, mixed>>}> $sections
     *
     * @return array<string, mixed>
     */
    private function agentItem(array $sections, string $file): array
    {
        foreach ($sections as $section) {
            if ('agents' !== $section['kind'] || [] === $section['items']) {
                continue;
            }
            $item = $section['items'][0];
            if (count($section['items']) > 1) {
                throw new \RuntimeException(sprintf('Template %s must contain one assistant', $file));
            }

            return $item;
        }

        throw new \RuntimeException(sprintf('Template %s has no assistant', $file));
    }
}
