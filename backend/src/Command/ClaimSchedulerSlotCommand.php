<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Scheduler\SchedulerSlotPolicy;
use App\Service\Scheduler\SchedulerSlotStore;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Claims a scheduler slot, or reports how long another node should wait.
 *
 * Exit 0: this process won the claim and stdout is empty.
 * Exit 3: not due (or the claim was lost); stdout is one integer, the seconds to wait.
 * Exit 1: the claim could not be decided; the message is on stderr.
 */
#[AsCommand(
    name: 'app:scheduler:claim',
    description: 'Atomically claim a scheduler slot when it is due',
)]
final class ClaimSchedulerSlotCommand extends Command
{
    public const EXIT_CLAIMED = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_NOT_DUE = 3;

    public function __construct(
        private readonly SchedulerSlotStore $slots,
        private readonly SchedulerSlotPolicy $policy,
        private readonly ClockInterface $clock = new Clock(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slot', InputArgument::REQUIRED, 'Slot to claim: hourly, daily, or health')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Claim when at least this many seconds have passed since the last claim')
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'Claim once on or after this UTC time each day (HH:MM)')
            ->setHelp(<<<'HELP'
                Exit 0 when this process claimed the slot (stdout stays empty).
                Exit 3 when the slot is not due, or another node won the claim; stdout is a single integer, the seconds to wait.
                Exit 1 when the claim could not be decided (stdout stays empty, the reason is on stderr).

                Pass exactly one of --interval (seconds, integer >= 1) or --at (UTC HH:MM).
                A slot that has never run is due immediately.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slot = $input->getArgument('slot');
        if (!is_string($slot) || !isset(SchedulerSlotStore::SETTINGS[$slot])) {
            $this->writeError($output, sprintf(
                'Unknown scheduler slot "%s". Expected hourly, daily, or health.',
                is_string($slot) ? $slot : get_debug_type($slot),
            ));

            return self::EXIT_ERROR;
        }

        $interval = $this->intervalSeconds($input->getOption('interval'));
        $at = $this->atClock($input->getOption('at'));
        $usage = $this->usageError($input, $interval, $at);
        if (null !== $usage) {
            $this->writeError($output, $usage);

            return self::EXIT_ERROR;
        }

        try {
            $now = $this->clock->now()->getTimestamp();
            $snapshot = $this->slots->read(SchedulerSlotStore::SETTINGS[$slot]);
            if (null !== $interval) {
                $decision = $this->policy->evaluateInterval($snapshot->lastStart, $now, $interval);
            } elseif (null !== $at) {
                $decision = $this->policy->evaluateAt($snapshot->lastStart, $now, $at[0], $at[1]);
            } else {
                throw new \LogicException('Scheduler slot claim has no interval and no UTC time.');
            }

            if (!$decision->due) {
                return $this->notDue($output, $decision->secondsUntilDue);
            }

            if ($snapshot->exists) {
                if (!is_string($snapshot->rawValue)) {
                    throw new \LogicException(sprintf('Scheduler slot "%s" is missing its stored timestamp.', $slot));
                }
                $won = $this->slots->claim(SchedulerSlotStore::SETTINGS[$slot], true, $snapshot->rawValue, $now);
            } else {
                $won = $this->slots->claim(SchedulerSlotStore::SETTINGS[$slot], false, null, $now);
            }

            if (!$won) {
                return $this->notDue($output, SchedulerSlotPolicy::RETRY_DELAY_SECONDS);
            }

            return self::EXIT_CLAIMED;
        } catch (RetryableException) {
            return $this->notDue($output, SchedulerSlotPolicy::RETRY_DELAY_SECONDS);
        } catch (\Throwable $e) {
            $this->writeError($output, 'Scheduler slot claim failed: '.$e->getMessage());

            return self::EXIT_ERROR;
        }
    }

    /**
     * @param array{0: int, 1: int}|null $at
     */
    private function usageError(InputInterface $input, ?int $interval, ?array $at): ?string
    {
        $intervalPassed = null !== $input->getOption('interval');
        $atPassed = null !== $input->getOption('at');
        if ($intervalPassed === $atPassed) {
            return 'Pass exactly one of --interval or --at.';
        }

        if ($intervalPassed && null === $interval) {
            return '--interval must be an integer greater than or equal to 1.';
        }

        if ($atPassed && null === $at) {
            return '--at must be a UTC time in HH:MM.';
        }

        return null;
    }

    private function intervalSeconds(mixed $raw): ?int
    {
        if (is_int($raw) && $raw >= 1) {
            return $raw;
        }

        if (!is_string($raw) || 1 !== preg_match('/\A[1-9][0-9]*\z/', $raw)) {
            return null;
        }

        $seconds = (int) $raw;
        if ((string) $seconds !== $raw) {
            return null;
        }

        return $seconds;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function atClock(mixed $raw): ?array
    {
        if (!is_string($raw) || 1 !== preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $raw)) {
            return null;
        }

        return [(int) substr($raw, 0, 2), (int) substr($raw, 3, 2)];
    }

    private function notDue(OutputInterface $output, int $seconds): int
    {
        $output->writeln((string) max(1, $seconds));

        return self::EXIT_NOT_DUE;
    }

    private function writeError(OutputInterface $output, string $message): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($message);
    }
}
