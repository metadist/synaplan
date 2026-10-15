<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Prompt;
use App\Entity\PromptMeta;
use App\Entity\SavedPrompt;
use App\Repository\PromptMetaRepository;
use App\Repository\PromptRepository;
use App\Repository\SavedPromptRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\ResourceKind\SavedPromptKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The user's prompts and saved prompts. Prompt meta rows go before their
 * prompt: BPROMPTMETA.BPROMPTID has no ON DELETE CASCADE.
 */
#[AsTaggedItem(priority: 90)]
final readonly class PromptEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PromptRepository $prompts,
        private PromptMetaRepository $promptMeta,
        private SavedPromptRepository $savedPrompts,
        private ShareRepository $shares,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->prompts->findBy(['ownerId' => $erasure->userId]) as $prompt) {
            $promptId = $prompt->getId();
            if (null !== $promptId) {
                foreach ($this->promptMeta->findByPrompt($promptId) as $meta) {
                    $this->em->remove($meta);
                }
            }
            $this->em->remove($prompt);
        }

        foreach ($this->savedPrompts->findByUser($erasure->userId) as $savedPrompt) {
            $id = $savedPrompt->getId();
            if (null !== $id) {
                $this->shares->deleteByResource(SavedPromptKind::KEY, (string) $id);
            }
            $this->em->remove($savedPrompt);
        }
    }

    public static function covers(): array
    {
        return [Prompt::class, PromptMeta::class, SavedPrompt::class];
    }
}
