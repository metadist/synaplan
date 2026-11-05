<?php

declare(strict_types=1);

namespace App\Service\Multitask\Plan;

use App\Entity\Message;
use App\Service\Message\Classification\ImageEditIntent;
use App\Service\Message\Classification\ImageEditKind;
use App\Service\Prompt\UserUtteranceLanguage;

/**
 * Stops an attached-photo look-change from silently becoming file work.
 *
 * Aesthetic edits become `image_generation`. Crop/resize without more context
 * pauses on `ask_user` (image edit vs file work); the answer swaps the next
 * node before the run continues.
 */
final readonly class ImageFileWorkArbitration
{
    public const ARBITRATION = 'image_or_file_work';

    public function __construct(
        private ImageEditIntent $imageEdits = new ImageEditIntent(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null    $allowed
     *
     * @return array<string, mixed>
     */
    public function adjust(array $payload, Message $message, ?array $allowed = null): array
    {
        $kind = $this->imageEdits->kind($message, (string) $message->getText());
        if (ImageEditKind::None === $kind || ImageEditKind::Programmatic === $kind) {
            return $payload;
        }
        if (!$this->hasCodeRun($payload)) {
            return $payload;
        }
        if (!$this->capabilityAllowed('image_generation', $allowed)) {
            return $payload;
        }

        $prompt = trim((string) $message->getText());
        if (ImageEditKind::Aesthetic === $kind || !$this->capabilityAllowed('ask_user', $allowed)) {
            return $this->replaceCodeRunWithImageGeneration($payload, $prompt);
        }

        return $this->askThenImage($payload, $prompt, UserUtteranceLanguage::detect(
            $prompt,
            is_string($payload['language'] ?? null) ? $payload['language'] : ($message->getLanguage() ?: 'en'),
        ));
    }

    public function withChosenWork(TaskPlan $plan, string $askNodeId, string $answer): TaskPlan
    {
        $ask = $plan->nodeById($askNodeId);
        if (null === $ask || Capability::AskUser !== $ask->capability) {
            return $plan;
        }
        if (self::ARBITRATION !== ($ask->params['arbitration'] ?? null)) {
            return $plan;
        }

        $fileOption = trim((string) ($ask->params['file_option'] ?? ''));
        $wantsFileWork = '' !== $fileOption && 0 === strcasecmp(trim($answer), $fileOption);
        $saved = $ask->params['file_work'] ?? [];
        if (!is_array($saved) || !$wantsFileWork) {
            return $plan;
        }

        $payload = $plan->toArray();
        $tasks = $payload['tasks'] ?? [];
        if (!is_array($tasks)) {
            return $plan;
        }
        foreach ($tasks as $i => $task) {
            if (!is_array($task) || ($task['capability'] ?? '') !== 'image_generation') {
                continue;
            }
            $id = is_string($task['id'] ?? null) ? $task['id'] : '';
            $original = $saved[$id] ?? null;
            if (!is_array($original)) {
                continue;
            }
            $tasks[$i]['capability'] = 'code_run';
            $tasks[$i]['params'] = is_array($original['params'] ?? null) ? $original['params'] : [];
            $tasks[$i]['inputs'] = is_array($original['inputs'] ?? null) ? $original['inputs'] : [];
        }
        $payload['tasks'] = $tasks;

        try {
            return TaskPlan::fromArray($payload);
        } catch (\Throwable) {
            return $plan;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasCodeRun(array $payload): bool
    {
        $tasks = $payload['tasks'] ?? null;
        if (!is_array($tasks)) {
            return false;
        }
        foreach ($tasks as $task) {
            if (is_array($task) && 'code_run' === ($task['capability'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>|null $allowed
     */
    private function capabilityAllowed(string $capability, ?array $allowed): bool
    {
        return null === $allowed || in_array($capability, $allowed, true);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function replaceCodeRunWithImageGeneration(array $payload, string $prompt): array
    {
        $tasks = $payload['tasks'] ?? [];
        if (!is_array($tasks)) {
            return $payload;
        }
        foreach ($tasks as $i => $task) {
            if (!is_array($task) || 'code_run' !== ($task['capability'] ?? '')) {
                continue;
            }
            $tasks[$i]['capability'] = 'image_generation';
            $inputs = is_array($task['inputs'] ?? null) ? $task['inputs'] : [];
            if (!isset($inputs['prompt']) || !is_string($inputs['prompt']) || '' === trim($inputs['prompt'])) {
                $inputs['prompt'] = $prompt;
            }
            $tasks[$i]['inputs'] = $inputs;
            $tasks[$i]['params'] = [];
        }
        $payload['tasks'] = $tasks;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function askThenImage(array $payload, string $prompt, string $language): array
    {
        $tasks = $payload['tasks'] ?? [];
        if (!is_array($tasks)) {
            return $payload;
        }

        $askId = $this->askNodeId($tasks);
        $fileWork = [];
        foreach ($tasks as $i => $task) {
            if (!is_array($task) || 'code_run' !== ($task['capability'] ?? '')) {
                continue;
            }
            $id = is_string($task['id'] ?? null) ? $task['id'] : '';
            if ('' === $id) {
                continue;
            }
            $fileWork[$id] = [
                'params' => is_array($task['params'] ?? null) ? $task['params'] : [],
                'inputs' => is_array($task['inputs'] ?? null) ? $task['inputs'] : [],
            ];
            $inputs = $fileWork[$id]['inputs'];
            if (!isset($inputs['prompt']) || !is_string($inputs['prompt']) || '' === trim($inputs['prompt'])) {
                $inputs['prompt'] = $prompt;
            }
            $depends = $task['depends_on'] ?? [];
            if (!is_array($depends)) {
                $depends = [];
            }
            $depends[] = $askId;
            $tasks[$i] = [
                'id' => $id,
                'capability' => 'image_generation',
                'depends_on' => array_values(array_unique(array_map('strval', $depends))),
                'inputs' => $inputs,
                'params' => [],
            ];
        }
        if ([] === $fileWork) {
            return $payload;
        }
        $copy = $this->questionCopy($language);
        array_unshift($tasks, [
            'id' => $askId,
            'capability' => 'ask_user',
            'depends_on' => [],
            'inputs' => [],
            'params' => [
                'question' => $copy['question'],
                'options' => [$copy['image'], $copy['file']],
                'recommended' => $copy['image'],
                'allow_text' => false,
                'arbitration' => self::ARBITRATION,
                'file_option' => $copy['file'],
                'file_work' => $fileWork,
            ],
        ]);
        $payload['tasks'] = $tasks;
        $payload['language'] = $language;

        return $payload;
    }

    /**
     * @param list<mixed> $tasks
     */
    private function askNodeId(array $tasks): string
    {
        $used = [];
        foreach ($tasks as $task) {
            if (is_array($task) && is_string($task['id'] ?? null)) {
                $used[$task['id']] = true;
            }
        }
        if (!isset($used['n_ask'])) {
            return 'n_ask';
        }
        for ($i = 2; $i < 50; ++$i) {
            $id = 'n_ask'.$i;
            if (!isset($used[$id])) {
                return $id;
            }
        }

        return 'n_askx';
    }

    /**
     * @return array{question: string, image: string, file: string}
     */
    private function questionCopy(string $language): array
    {
        return match (UserUtteranceLanguage::normalize($language)) {
            'de' => [
                'question' => 'Möchtest du eine Bildbearbeitung oder Dateiarbeit?',
                'image' => 'Bildbearbeitung',
                'file' => 'Dateiarbeit',
            ],
            'es' => [
                'question' => '¿Quieres una edición de imagen o trabajo con archivos?',
                'image' => 'Edición de imagen',
                'file' => 'Trabajo con archivos',
            ],
            'fr' => [
                'question' => 'Souhaitez-vous une retouche d’image ou un travail sur fichier ?',
                'image' => 'Retouche d’image',
                'file' => 'Travail sur fichier',
            ],
            'tr' => [
                'question' => 'Görsel düzenleme mi, dosya çalışması mı istiyorsunuz?',
                'image' => 'Görsel düzenleme',
                'file' => 'Dosya çalışması',
            ],
            default => [
                'question' => 'Do you want an image edit or file work?',
                'image' => 'Image edit',
                'file' => 'File work',
            ],
        };
    }
}
