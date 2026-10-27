<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CloudWatchItem;
use App\Repository\CloudWatchItemRepository;
use App\Service\CloudWatch\WatchPromoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A file that has been "running" for 30 minutes did not finish. Mark it failed
 * so the watch does not stay on it.
 */
#[AsCommand(name: 'app:cloud-watches:sweep', description: 'Fail watch items that have been running for more than 30 minutes')]
final class SweepCloudWatchesCommand extends Command
{
    public const STUCK_SECONDS = 1800;

    public function __construct(
        private readonly CloudWatchItemRepository $items,
        private readonly EntityManagerInterface $entityManager,
        private readonly WatchPromoter $promoter,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $promoted = $this->promoter->promote(time());
        $stuck = $this->items->stuckRunning(time() - self::STUCK_SECONDS);
        foreach ($stuck as $item) {
            $item->mark(CloudWatchItem::STATE_FAILED, CloudWatchItem::REASON_FAILED_RUN);
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Finished %d quiet watch item(s). Failed %d stuck watch item(s).', $promoted, count($stuck)));

        return Command::SUCCESS;
    }
}
