<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Approval;
use App\Service\InternalEmailService;
use App\Service\Tool\ApprovalExpiryService;
use App\Service\Tool\ToolsConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:approvals:digest',
    description: 'Send a daily digest of pending tool approvals'
)]
final class DigestApprovalsCommand extends Command
{
    public function __construct(
        private readonly ApprovalExpiryService $expiry,
        private readonly ToolsConfig $toolsConfig,
        private readonly InternalEmailService $mail,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('approvals-digest', 120);
        if (!$lock->acquire()) {
            $io->note('A previous digest is still running.');

            return Command::SUCCESS;
        }

        try {
            $sent = 0;
            foreach ($this->expiry->pendingForDigest() as $row) {
                $user = $row['user'];
                if (ToolsConfig::NOTIFY_DIGEST !== $this->toolsConfig->notifyMode((int) $user->getId())) {
                    continue;
                }
                $address = trim($user->getMail());
                if ('' === $address || str_ends_with(strtolower($address), '@synaplan.local')) {
                    continue;
                }
                $previews = array_map(
                    static fn (Approval $approval): string => (string) $approval->getPreview(),
                    $row['approvals'],
                );
                $this->mail->sendApprovalDigestEmail($address, $previews, count($row['approvals']));
                ++$sent;
            }
            $io->writeln(sprintf('Sent %d approval digests.', $sent));
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
