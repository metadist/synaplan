<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Share;
use App\Repository\AgentRepository;
use App\Repository\ChatRepository;
use App\Repository\PluginDataRepository;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\ShareRepository;
use App\Repository\WidgetRepository;
use App\Service\Iam\ResourceKind\AgentKind;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Shares the user received or granted, and every share on a resource the user
 * owns. Runs before the resources themselves are removed, while they can
 * still be found.
 */
#[AsTaggedItem(priority: 160)]
final readonly class ShareEraser implements UserDataEraserInterface
{
    public function __construct(
        private ShareRepository $shares,
        private ChatRepository $chats,
        private PromptRepository $prompts,
        private WidgetRepository $widgets,
        private SavedTaskRepository $savedTasks,
        private AgentRepository $agents,
        private PluginDataRepository $pluginData,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $userId = $erasure->userId;
        $this->shares->deleteBySubjectUser($userId);
        $this->shares->deleteByGrantedBy($userId);
        foreach ($this->chats->findByUser($userId) as $chat) {
            $this->shares->deleteByResource('conversation', (string) $chat->getId());
        }
        foreach ($this->prompts->findBy(['ownerId' => $userId]) as $prompt) {
            $this->shares->deleteByResource('assistant', (string) $prompt->getId());
        }
        foreach ($this->widgets->findByOwnerId($userId) as $widget) {
            $this->shares->deleteByResource('widget', (string) $widget->getId());
        }
        foreach ($this->savedTasks->findBy(['ownerId' => $userId]) as $task) {
            $this->shares->deleteByResource('saved_task', (string) $task->getId());
        }
        foreach ($this->agents->findByOwner($userId) as $agent) {
            $this->shares->deleteByResource(AgentKind::KEY, (string) $agent->getId());
        }
        $this->shares->deleteByOwnerKnowledgeFolders($userId);

        // Plugin-declared kinds share by plugin_data.id; a stale row would
        // otherwise attach to whoever gets that id next.
        $pluginDataIds = [];
        foreach ($this->pluginData->findBy(['userId' => $userId]) as $item) {
            $id = $item->getId();
            if (null !== $id) {
                $pluginDataIds[] = (int) $id;
            }
        }
        $this->shares->deleteByPluginDataIds($pluginDataIds);
    }

    public static function covers(): array
    {
        return [Share::class];
    }
}
