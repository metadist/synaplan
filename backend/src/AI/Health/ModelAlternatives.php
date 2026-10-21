<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\Entity\Model;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\Model\PickerCapabilities;
use App\Service\ModelConfigService;

/**
 * Other models a person could use for the same task.
 *
 * Suggestions only. Callers show them and wait for a click; nothing here
 * writes a default or switches a model off.
 */
final readonly class ModelAlternatives
{
    public const LIMIT = 5;

    public function __construct(
        private ModelRepository $models,
        private ModelHealthRepository $health,
        private ModelConfigService $usable,
        private ?GroupPolicyService $groupPolicy = null,
    ) {
    }

    /**
     * Selectable, working models of the same task, same provider first.
     *
     * @return list<array{id: int, name: string, service: string}>
     */
    public function forModel(Model $model, ?int $userId = null, ?string $capability = null): array
    {
        $capability ??= $this->defaultCapability($model);
        $selfId = (int) $model->getId();
        $offline = array_flip($this->offlineIds());
        $same = [];
        $other = [];

        foreach ($this->models->findByTag($model->getTag(), true) as $candidate) {
            $id = (int) $candidate->getId();
            if ($id === $selfId || $id < 1) {
                continue;
            }
            if (1 !== $candidate->getActive() || $candidate->isRetired() || $candidate->isHiddenBecauseFree()) {
                continue;
            }
            if (!$this->fits($candidate, $capability)) {
                continue;
            }
            if (isset($offline[$id]) || !$this->usable->isConfiguredModelUsable($id)) {
                continue;
            }
            if (!$this->allowedFor($userId, $id, $capability)) {
                continue;
            }

            if (0 === strcasecmp($candidate->getService(), $model->getService())) {
                $same[] = $candidate;
            } else {
                $other[] = $candidate;
            }
        }

        $listed = [];
        foreach (array_merge($same, $other) as $candidate) {
            if (count($listed) >= self::LIMIT) {
                break;
            }
            $listed[] = [
                'id' => (int) $candidate->getId(),
                'name' => $candidate->getName(),
                'service' => $candidate->getService(),
            ];
        }

        return $listed;
    }

    /**
     * The default slot a one-click change may write for this row.
     *
     * Embedding stays out: switching it rebuilds the search index, and that
     * decision already has its own screen. Null means "show the names, do
     * not offer a button".
     */
    public function defaultCapability(Model $model): ?string
    {
        $tag = strtolower($model->getTag());

        return match ($tag) {
            'chat' => 'CHAT',
            'mem' => 'MEM',
            'pic2text', 'vision' => 'PIC2TEXT',
            'text2pic', 'image' => in_array('pic2pic', $model->getFeatures(), true) ? 'PIC2PIC' : 'TEXT2PIC',
            'text2vid', 'video' => in_array('image2video', $model->getFeatures(), true) ? 'IMG2VID' : 'TEXT2VID',
            'sound2text', 'audio', 'transcription' => 'SOUND2TEXT',
            'text2sound', 'tts' => 'TEXT2SOUND',
            'voice2voice' => 'VOICE_LIVE',
            default => null,
        };
    }

    /**
     * Sharing a tag is not enough: an image editor and a plain image model
     * both use text2pic, and only the editor can take the edit slot.
     */
    private function fits(Model $candidate, ?string $capability): bool
    {
        if (null === $capability || '' === $capability) {
            return true;
        }

        $accepted = PickerCapabilities::forTag(strtoupper($candidate->getTag()), $candidate->getFeatures());

        return in_array($capability, $accepted, true);
    }

    private function allowedFor(?int $userId, int $modelId, ?string $capability): bool
    {
        if (null === $userId || null === $this->groupPolicy) {
            return true;
        }

        return $this->groupPolicy->isModelAllowed($userId, $modelId, $capability);
    }

    /**
     * @return list<int>
     */
    private function offlineIds(): array
    {
        try {
            return $this->health->findOfflineModelIds();
        } catch (\Throwable) {
            return [];
        }
    }
}
