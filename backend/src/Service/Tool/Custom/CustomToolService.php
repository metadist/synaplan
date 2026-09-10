<?php

declare(strict_types=1);

namespace App\Service\Tool\Custom;

use App\Entity\CustomTool;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Repository\CustomToolRepository;
use App\Service\RateLimitService;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolsConfig;

final readonly class CustomToolService
{
    public function __construct(
        private CustomToolRepository $tools,
        private CustomToolSpecValidator $validator,
        private HttpToolExecutor $executor,
        private OpenApiImporter $importer,
        private ToolsConfig $toolsConfig,
        private CredentialRepository $credentials,
        private RateLimitService $rateLimits,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user): array
    {
        $rows = [];
        foreach ($this->tools->findByOwner((int) $user->getId()) as $tool) {
            $rows[] = $this->toArray($tool);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(User $user, array $payload): CustomTool
    {
        if (!$this->toolsConfig->isCustomHttpEnabled((int) $user->getId())) {
            throw new InvalidToolTemplateException('Custom HTTP tools are disabled.');
        }
        $name = (string) ($payload['name'] ?? '');
        $title = (string) ($payload['title'] ?? $name);
        $sideEffect = (string) ($payload['sideEffect'] ?? SideEffect::Write->value);
        $spec = is_array($payload['spec'] ?? null) ? $payload['spec'] : [];
        $spec = $this->validator->validate($spec, $sideEffect, $name);
        $this->assertUniqueName((int) $user->getId(), $name, null);
        $tool = new CustomTool((int) $user->getId(), $name, $title);
        $this->applyPayload($tool, $payload, $spec, $sideEffect, (int) $user->getId());
        $this->tools->save($tool);

        return $tool;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(CustomTool $tool, array $payload, int $ownerId): CustomTool
    {
        $name = is_string($payload['name'] ?? null) ? $payload['name'] : $tool->getName();
        $sideEffect = is_string($payload['sideEffect'] ?? null) ? $payload['sideEffect'] : $tool->getSideEffect();
        $spec = is_array($payload['spec'] ?? null) ? $payload['spec'] : $tool->getSpec();
        $spec = $this->validator->validate($spec, $sideEffect, $name);
        if ($name !== $tool->getName()) {
            $this->assertUniqueName($ownerId, $name, $tool->getId());
        }
        $this->applyPayload($tool, $payload, $spec, $sideEffect, $ownerId);
        $this->tools->save($tool);

        return $tool;
    }

    public function delete(CustomTool $tool): void
    {
        $this->tools->remove($tool);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function try(CustomTool $tool, array $input, User $actor): array
    {
        $limit = $this->rateLimits->checkLimit($actor, 'MESSAGES');
        if (empty($limit['allowed'])) {
            throw new InvalidToolTemplateException('Too many tries. Wait a minute and try again.');
        }
        if (SideEffect::Read->value === $tool->getSideEffect()) {
            $result = $this->executor->execute($tool, $input, (int) $actor->getId());

            return ['sent' => true, 'result' => $result];
        }

        $resolved = $this->executor->resolve($tool, $input, includeSecret: false);

        return ['sent' => false, 'request' => $resolved];
    }

    /**
     * @return array{operations: list<array<string, mixed>>, dropped: int, notices: list<string>}
     */
    public function previewOpenApi(?string $url, ?string $document): array
    {
        if (is_string($url) && '' !== $url) {
            return $this->importer->previewFromUrl($url);
        }
        if (is_string($document) && '' !== $document) {
            return $this->importer->previewFromDocument($document);
        }

        throw new InvalidToolTemplateException('Provide a URL or upload a description');
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return list<CustomTool>
     */
    public function applyOpenApi(User $user, array $operations, ?int $credentialId, string $baseUrl): array
    {
        $created = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $operationId = (string) ($operation['operationId'] ?? '');
            $name = $this->nameFromOperationId($operationId);
            $method = strtoupper((string) ($operation['method'] ?? 'GET'));
            $path = (string) ($operation['path'] ?? '/');
            $sideEffect = (string) ($operation['sideEffect'] ?? 'write');
            $spec = [
                'method' => $method,
                'url' => rtrim($baseUrl, '/').$path,
            ];
            $payload = [
                'name' => $name,
                'title' => (string) ($operation['summary'] ?? $operationId),
                'description' => (string) ($operation['summary'] ?? ''),
                'sideEffect' => $sideEffect,
                'spec' => $spec,
                'inputSchema' => is_array($operation['inputSchema'] ?? null) ? $operation['inputSchema'] : null,
                'type' => CustomTool::TYPE_OPENAPI,
                'sourceRef' => is_string($operation['sourceRef'] ?? null) ? $operation['sourceRef'] : null,
                'credentialId' => $credentialId,
            ];
            try {
                $created[] = $this->create($user, $payload);
            } catch (InvalidToolTemplateException) {
                $payload['name'] = $name.'_'.substr(bin2hex(random_bytes(2)), 0, 4);
                $created[] = $this->create($user, $payload);
            }
        }

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(CustomTool $tool): array
    {
        return [
            'id' => $tool->getId(),
            'name' => $tool->getName(),
            'title' => $tool->getTitle(),
            'description' => $tool->getDescription(),
            'type' => $tool->getType(),
            'sideEffect' => $tool->getSideEffect(),
            'spec' => $tool->getSpec(),
            'inputSchema' => $tool->getInputSchema(),
            'credentialId' => $tool->getCredentialId(),
            'enabled' => $tool->isEnabled(),
            'sourceRef' => $tool->getSourceRef(),
            'created' => $tool->getCreated(),
            'updated' => $tool->getUpdated(),
            'registryName' => $tool->registryName(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $spec
     */
    private function applyPayload(CustomTool $tool, array $payload, array $spec, string $sideEffect, int $ownerId): void
    {
        $tool->setName((string) ($payload['name'] ?? $tool->getName()));
        if (is_string($payload['title'] ?? null)) {
            $tool->setTitle($payload['title']);
        }
        if (array_key_exists('description', $payload)) {
            $tool->setDescription(is_string($payload['description']) ? $payload['description'] : null);
        }
        $tool->setSideEffect($sideEffect);
        $tool->setSpec($spec);
        if (array_key_exists('inputSchema', $payload)) {
            $tool->setInputSchema(is_array($payload['inputSchema']) ? $payload['inputSchema'] : null);
        }
        if (array_key_exists('enabled', $payload)) {
            $tool->setEnabled(true === $payload['enabled'] || 1 === $payload['enabled'] || '1' === $payload['enabled']);
        }
        if (array_key_exists('type', $payload) && is_string($payload['type'])) {
            $tool->setType($payload['type']);
        }
        if (array_key_exists('sourceRef', $payload)) {
            $tool->setSourceRef(is_string($payload['sourceRef']) ? $payload['sourceRef'] : null);
        }
        if (array_key_exists('credentialId', $payload)) {
            $credentialId = is_numeric($payload['credentialId']) ? (int) $payload['credentialId'] : null;
            if (null !== $credentialId && $credentialId > 0) {
                $credential = $this->credentials->findByIdAndOwner($credentialId, $ownerId);
                if (null === $credential) {
                    throw new InvalidToolTemplateException('This login is not available');
                }
                $tool->setCredentialId($credentialId);
            } else {
                $tool->setCredentialId(null);
            }
        }
    }

    private function assertUniqueName(int $ownerId, string $name, ?int $exceptId): void
    {
        $existing = $this->tools->findOneByOwnerAndName($ownerId, $name);
        if (null !== $existing && $existing->getId() !== $exceptId) {
            throw new InvalidToolTemplateException('A tool with this name already exists');
        }
    }

    private function nameFromOperationId(string $operationId): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $operationId));
        $slug = trim($slug, '_');
        if ('' === $slug || 1 !== preg_match(CustomTool::NAME_PATTERN, $slug)) {
            $slug = 'op_'.substr(hash('sha256', $operationId), 0, 10);
        }

        return $slug;
    }
}
