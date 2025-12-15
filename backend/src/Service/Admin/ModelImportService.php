<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class ModelImportService
{
    public function __construct(
        private AiFacade $ai,
        private ModelConfigService $modelConfig,
        private ModelRepository $modelRepository,
        private Connection $connection,
        private ModelSqlValidator $validator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $urls
     *
     * @return array{sql: string, provider: string|null, model: string|null}
     */
    public function generateSqlPreview(int $adminUserId, array $urls, string $textDump, bool $allowDelete = false): array
    {
        $sortingModelId = $this->modelConfig->getDefaultModel('SORT', $adminUserId);
        if (!$sortingModelId) {
            throw new \RuntimeException('No SORT default model configured (BCONFIG DEFAULTMODEL/SORT)');
        }

        $provider = $this->modelConfig->getProviderForModel($sortingModelId);
        $modelName = $this->modelConfig->getModelName($sortingModelId);
        if (!$provider || !$modelName) {
            throw new \RuntimeException('SORT default model is not resolvable (missing provider/model name)');
        }

        $existing = $this->getExistingModelsSnapshot();

        $payload = [
            'allowDelete' => $allowDelete,
            'urls' => array_values(array_filter(array_map('trim', $urls), fn ($u) => '' !== $u)),
            'textDump' => $textDump,
            'existingModels' => $existing,
        ];

        $messages = [
            [
                'role' => 'system',
                'content' => $this->buildSystemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        try {
            $res = $this->ai->chat($messages, $adminUserId, [
                'provider' => $provider,
                'model' => $modelName,
                'temperature' => 0,
            ]);
        } catch (ProviderException $e) {
            $this->logger->error('Model import AI call failed', [
                'provider' => $provider,
                'model' => $modelName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $sql = trim((string) ($res['content'] ?? ''));

        return [
            'sql' => $sql,
            'provider' => $provider,
            'model' => $modelName,
        ];
    }

    /**
     * Apply a validated SQL script inside a transaction.
     *
     * @return array{applied: int, statements: string[]}
     */
    public function applySql(string $sql): array
    {
        $validated = $this->validator->validateAndSplit($sql);
        if (!empty($validated['errors'])) {
            throw new \InvalidArgumentException('Invalid SQL: '.implode(' | ', $validated['errors']));
        }

        $statements = $validated['statements'];
        $applied = 0;

        $this->connection->beginTransaction();
        try {
            foreach ($statements as $stmt) {
                $this->connection->executeStatement($stmt);
                ++$applied;
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return [
            'applied' => $applied,
            'statements' => $statements,
        ];
    }

    /**
     * @return array<int, array{service:string, tag:string, providerId:string, name:string, priceIn:float, inUnit:string, priceOut:float, outUnit:string}>
     */
    private function getExistingModelsSnapshot(): array
    {
        $models = $this->modelRepository->findAll();
        $out = [];
        foreach ($models as $m) {
            $out[] = [
                'service' => $m->getService(),
                'tag' => $m->getTag(),
                'providerId' => $m->getProviderId(),
                'name' => $m->getName(),
                'priceIn' => $m->getPriceIn(),
                'inUnit' => $m->getInUnit(),
                'priceOut' => $m->getPriceOut(),
                'outUnit' => $m->getOutUnit(),
            ];
        }

        return $out;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a precise database-maintenance assistant for Synaplan.

TASK:
Given:
- existingModels (current DB state for table BMODELS)
- either URLs or a plain text dump (pricing/model lists from OpenAI, Anthropic, Groq, Google/Gemini, Ollama, TheHive, etc.)
Generate SQL statements to bring table BMODELS up to date.

CRITICAL OUTPUT RULES:
- Output ONLY SQL statements. No Markdown. No explanations. No JSON.
- Allowed statements: INSERT, UPDATE, DELETE only.
- Table: BMODELS only. No other tables.
- For UPDATE and DELETE: the WHERE clause MUST include ALL THREE: BSERVICE, BTAG, BPROVID (this is the unique identifier).
- Do NOT use BID in WHERE clauses (ids may differ).

SCHEMA (common columns):
- BSERVICE: Provider/service name (e.g. 'OpenAI', 'Anthropic', 'Groq', 'Google', 'Ollama', 'Triton', 'TheHive')
- BTAG: capability tag: chat, vectorize, pic2text, text2pic, text2vid, sound2text, text2sound, analyze, sort (lowercase)
- BPROVID: provider-specific model id (string)
- BNAME: human name (short)
- BSELECTABLE: 0/1 (user can select)
- BACTIVE: 0/1
- BPRICEIN/BPRICEOUT: floats (USD)
- BINUNIT/BOUTUNIT: per1M | perpic | persec | perhour | per1000chars | '-'  (use '-' if unknown/not applicable)
- BQUALITY/BRATING: floats (0-10 / 0-1 style, keep reasonable defaults if unknown)
- BJSON: JSON string with at least {"description":"..."} and optionally {"params":{...},"meta":{...}}

DECISIONS:
- Add new models if they appear in the input and do not exist in existingModels (by BSERVICE+BTAG+BPROVID).
- If a model exists but pricing/name/description changed, UPDATE it.
- Only emit DELETE statements if allowDelete=true AND the input clearly indicates deprecation/removal.

DEFAULTS (when unknown):
- BSELECTABLE=1 for end-user models, 0 for internal/embedding-only models like bge-m3.
- BACTIVE=1
- BQUALITY=7, BRATING=0.5
- BINUNIT/BOUTUNIT='per1M' for text models, otherwise infer from pricing text; if unclear use '-'.

IMPORTANT:
- If a single provider model supports multiple capabilities, create multiple rows (one per BTAG) with the same BSERVICE+BPROVID and different BTAG.
- Use single quotes for SQL string values and escape single quotes by doubling them.

Return a minimal set of statements (only the changes needed).
PROMPT;
    }
}
