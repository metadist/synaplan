<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 170)]
final readonly class WidgetEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private WidgetRepository $widgets,
        private WidgetSessionRepository $widgetSessions,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->widgets->findBy(['ownerId' => $erasure->userId]) as $widget) {
            foreach ($this->widgetSessions->findBy(['widgetId' => $widget->getWidgetId()]) as $session) {
                $this->em->remove($session);
            }
            $this->em->remove($widget);
        }
    }

    public static function covers(): array
    {
        return [Widget::class, WidgetSession::class];
    }
}
