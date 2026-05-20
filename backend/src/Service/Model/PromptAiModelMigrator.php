<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Config;
use App\Entity\PromptMeta;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One-shot migrator: legacy task-prompt `aiModel` metadata → per-user DEFAULTMODEL rows.
 *
 * Only migrates when the referenced model has a known capability tag and the user
 * does not already have a user-scoped default for that capability.
 */
final readonly class PromptAiModelMigrator
{
    private const VALID_CAPABILITIES = [
        'SORT',
        'CHAT',
        'MEM',
        'VECTORIZE',
        'PIC2TEXT',
        'TEXT2PIC',
        'PIC2PIC',
        'TEXT2VID',
        'SOUND2TEXT',
        'TEXT2SOUND',
        'ANALYZE',
    ];

    public function __construct(
        private PromptMetaRepository $promptMetaRepository,
        private PromptRepository $promptRepository,
        private ModelRepository $modelRepository,
        private ConfigRepository $configRepository,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{
     *     scanned: int,
     *     migrated: int,
     *     skipped: int,
     *     cleared: int,
     *     details: list<array{prompt_id: int, topic: string, user_id: int, model_id: int, capability: string, action: string}>
     * }
     */
    public function migrate(bool $apply = false): array
    {
        $entries = $this->promptMetaRepository->findBy(['metaKey' => 'aiModel']);
        $details = [];
        $migrated = 0;
        $skipped = 0;
        $cleared = 0;

        foreach ($entries as $meta) {
            $modelId = (int) $meta->getMetaValue();
            if ($modelId <= 0) {
                continue;
            }

            $prompt = $this->promptRepository->find($meta->getPromptId());
            if (!$prompt) {
                ++$skipped;
                $details[] = [
                    'prompt_id' => $meta->getPromptId(),
                    'topic' => '(missing)',
                    'user_id' => 0,
                    'model_id' => $modelId,
                    'capability' => '',
                    'action' => 'skipped_prompt_missing',
                ];
                continue;
            }

            $ownerId = $prompt->getOwnerId();
            if ($ownerId <= 0) {
                ++$skipped;
                $details[] = [
                    'prompt_id' => (int) $prompt->getId(),
                    'topic' => $prompt->getTopic(),
                    'user_id' => 0,
                    'model_id' => $modelId,
                    'capability' => '',
                    'action' => 'skipped_system_prompt',
                ];
                if ($apply) {
                    $this->clearPromptAiModel($meta);
                    ++$cleared;
                }
                continue;
            }

            $model = $this->modelRepository->find($modelId);
            if (!$model || 1 !== $model->getActive()) {
                ++$skipped;
                $details[] = [
                    'prompt_id' => (int) $prompt->getId(),
                    'topic' => $prompt->getTopic(),
                    'user_id' => $ownerId,
                    'model_id' => $modelId,
                    'capability' => '',
                    'action' => 'skipped_model_unavailable',
                ];
                continue;
            }

            $capability = strtoupper(trim($model->getTag()));
            if (!in_array($capability, self::VALID_CAPABILITIES, true)) {
                ++$skipped;
                $details[] = [
                    'prompt_id' => (int) $prompt->getId(),
                    'topic' => $prompt->getTopic(),
                    'user_id' => $ownerId,
                    'model_id' => $modelId,
                    'capability' => $capability,
                    'action' => 'skipped_ambiguous_capability',
                ];
                continue;
            }

            $existing = $this->configRepository->findOneBy([
                'ownerId' => $ownerId,
                'group' => 'DEFAULTMODEL',
                'setting' => $capability,
            ]);

            if ($existing) {
                ++$skipped;
                $details[] = [
                    'prompt_id' => (int) $prompt->getId(),
                    'topic' => $prompt->getTopic(),
                    'user_id' => $ownerId,
                    'model_id' => $modelId,
                    'capability' => $capability,
                    'action' => 'skipped_user_default_exists',
                ];
                if ($apply) {
                    $this->clearPromptAiModel($meta);
                    ++$cleared;
                }
                continue;
            }

            if ($apply) {
                $config = new Config();
                $config->setOwnerId($ownerId);
                $config->setGroup('DEFAULTMODEL');
                $config->setSetting($capability);
                $config->setValue((string) $modelId);
                $this->em->persist($config);
                $this->clearPromptAiModel($meta);
                ++$cleared;
            }

            ++$migrated;
            $details[] = [
                'prompt_id' => (int) $prompt->getId(),
                'topic' => $prompt->getTopic(),
                'user_id' => $ownerId,
                'model_id' => $modelId,
                'capability' => $capability,
                'action' => $apply ? 'migrated' : 'would_migrate',
            ];
        }

        if ($apply && ($migrated > 0 || $cleared > 0)) {
            $this->em->flush();
        }

        return [
            'scanned' => count($entries),
            'migrated' => $migrated,
            'skipped' => $skipped,
            'cleared' => $cleared,
            'details' => $details,
        ];
    }

    private function clearPromptAiModel(PromptMeta $meta): void
    {
        $meta->setMetaValue('0');
        $this->em->persist($meta);
    }
}
