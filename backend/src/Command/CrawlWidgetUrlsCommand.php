<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PromptMeta;
use App\Message\CrawlWidgetUrlMessage;
use App\Repository\PromptMetaRepository;
use App\Repository\WidgetRepository;
use App\Service\BillingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Periodically re-crawls widget link-type response URLs based on their configured crawlInterval.
 *
 * Intended to be run as a cron job (e.g. hourly).
 */
#[AsCommand(
    name: 'app:crawl-widget-urls',
    description: 'Re-crawl widget URLs that are due based on their configured crawl interval'
)]
final class CrawlWidgetUrlsCommand extends Command
{
    private const INTERVAL_SECONDS = [
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
    ];

    public function __construct(
        private WidgetRepository $widgetRepository,
        private PromptMetaRepository $promptMetaRepository,
        private EntityManagerInterface $em,
        private MessageBusInterface $messageBus,
        private BillingService $billingService,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('crawl-widget-urls', 900);

        if (!$lock->acquire()) {
            $io->warning('Previous crawl run is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $io->title('Widget URL Crawl Scheduler');

            $dispatched = 0;
            $skipped = 0;

            $flowMetaEntries = $this->promptMetaRepository->findBy(['metaKey' => 'widgetFlowRules']);

            foreach ($flowMetaEntries as $meta) {
                $prompt = $meta->getPrompt();
                if (!$prompt) {
                    continue;
                }

                $ownerId = $prompt->getOwnerId();
                $owner = $this->em->getRepository(\App\Entity\User::class)->find($ownerId);
                if (!$owner) {
                    continue;
                }

                if ($this->billingService->isEnabled() && \in_array($owner->getUserLevel(), ['NEW', 'ANONYMOUS'], true)) {
                    continue;
                }

                $widget = $this->widgetRepository->findOneBy(['ownerId' => $ownerId, 'taskPromptTopic' => $prompt->getTopic()]);
                if (!$widget) {
                    continue;
                }

                try {
                    $flow = json_decode($meta->getMetaValue(), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }

                $crawlStatus = $this->loadCrawlStatus($prompt->getId());

                foreach ($flow['responses'] ?? [] as $response) {
                    if ('link' !== ($response['type'] ?? null)) {
                        continue;
                    }

                    $url = $response['meta']['url'] ?? null;
                    $interval = $response['meta']['crawlInterval'] ?? 'never';
                    $nodeId = $response['id'] ?? null;

                    if (!\is_string($url) || '' === trim($url) || 'never' === $interval || !$nodeId) {
                        continue;
                    }

                    $intervalSeconds = self::INTERVAL_SECONDS[$interval] ?? null;
                    if (!$intervalSeconds) {
                        continue;
                    }

                    $lastCrawl = $crawlStatus[$nodeId] ?? 0;
                    if ((time() - $lastCrawl) < $intervalSeconds) {
                        ++$skipped;
                        continue;
                    }

                    $this->messageBus->dispatch(new CrawlWidgetUrlMessage(
                        $widget->getWidgetId(),
                        trim($url),
                        $ownerId,
                        $nodeId,
                    ));

                    $crawlStatus[$nodeId] = time();
                    ++$dispatched;
                }

                if ($dispatched > 0) {
                    $this->saveCrawlStatus($prompt->getId(), $crawlStatus);
                }
            }

            $io->success(sprintf('Dispatched %d crawl jobs, skipped %d (not due yet).', $dispatched, $skipped));
            $this->logger->info('Widget URL crawl scheduler finished', [
                'dispatched' => $dispatched,
                'skipped' => $skipped,
            ]);
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int> nodeId => timestamp
     */
    private function loadCrawlStatus(int $promptId): array
    {
        $meta = $this->promptMetaRepository->findOneBy([
            'promptId' => $promptId,
            'metaKey' => 'widgetCrawlStatus',
        ]);

        if (!$meta) {
            return [];
        }

        try {
            $data = json_decode($meta->getMetaValue(), true, 512, JSON_THROW_ON_ERROR);

            return \is_array($data) ? $data : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @param array<string, int> $status
     */
    private function saveCrawlStatus(int $promptId, array $status): void
    {
        $meta = $this->promptMetaRepository->findOneBy([
            'promptId' => $promptId,
            'metaKey' => 'widgetCrawlStatus',
        ]);

        if (!$meta) {
            $meta = new PromptMeta();
            $meta->setPromptId($promptId);
            $meta->setMetaKey('widgetCrawlStatus');
            $this->em->persist($meta);
        }

        $meta->setMetaValue(json_encode($status, JSON_THROW_ON_ERROR));
        $this->em->flush();
    }
}
