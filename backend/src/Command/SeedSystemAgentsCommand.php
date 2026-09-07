<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentPublisher;
use App\Service\Agent\AgentSlugger;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ShareService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:agents:seed-system',
    description: 'Create read-only system assistants from seeded non-tools prompts (not run by the seeder)',
)]
final class SeedSystemAgentsCommand extends Command
{
    public function __construct(
        private readonly PromptRepository $prompts,
        private readonly AgentRepository $agents,
        private readonly AgentPublisher $publisher,
        private readonly ShareService $shares,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Idempotent. Creates BAGENTS rows (BSOURCE=system, owner 0) for each '.
            'seeded non-tools: prompt, publishes v1, and shares them with everyone (use). '.
            'Not invoked by app:seed — run it by hand on a dev instance first.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;
        $skipped = 0;

        /** @var list<Prompt> $systemPrompts */
        $systemPrompts = $this->prompts->createQueryBuilder('p')
            ->where('p.ownerId = 0')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->setParameter('toolsPrefix', 'tools:%')
            ->orderBy('p.topic', 'ASC')
            ->getQuery()
            ->getResult();

        $actor = new User();
        $actor->setMail('system@synaplan.internal');

        foreach ($systemPrompts as $prompt) {
            $promptId = $prompt->getId();
            if (null === $promptId) {
                continue;
            }
            $existing = $this->agents->findByPromptIdAndOwner($promptId, 0);
            if ($existing instanceof Agent) {
                ++$skipped;
                continue;
            }

            $name = $prompt->getShortDescription();
            if ('' === trim($name)) {
                $name = $prompt->getTopic();
            }
            $slug = AgentSlugger::from($name);
            $agent = new Agent(0, $promptId, $slug, $name, AgentDefinition::defaults()->toArray());
            $agent->setSource(Agent::SOURCE_SYSTEM);
            $this->agents->save($agent);
            $this->publisher->publish($agent, $actor, 'Initial system version');

            $agentId = $agent->getId();
            if (null !== $agentId) {
                // Same path as a user-made share: kind checks + audit row, actor 0.
                $this->shares->grantAsSystem(AgentKind::KEY, (string) $agentId, Share::SUBJECT_EVERYONE, 0, Permission::Use);
            }
            ++$created;
        }

        $io->success(sprintf('System assistants: %d created, %d already present.', $created, $skipped));

        return Command::SUCCESS;
    }
}
