<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\Scaffold\ProviderScaffolder;
use App\AI\Catalog\Scaffold\ProviderScaffoldRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:provider:scaffold',
    description: 'Add an AI provider as one class: the provider with its descriptor, its unit test, and the model rows and help keys to paste',
)]
final class ProviderScaffoldCommand extends Command
{
    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly ProviderScaffolder $scaffolder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Lowercase provider name, e.g. acme (becomes AcmeProvider and the service name of its models)')
            ->addOption('openai-compatible', null, InputOption::VALUE_NONE, 'Chat completions with a Bearer key, the only template today')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'What people see; default: the name capitalised')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'The /v1 base URL; default: https://api.<name>.example/v1')
            ->addOption('env-var', null, InputOption::VALUE_REQUIRED, 'Environment variable of the platform key; default: <NAME>_API_KEY')
            ->addOption('console-url', null, InputOption::VALUE_REQUIRED, 'Where a person creates a key; default: https://console.<name>.example/')
            ->addOption('flag', null, InputOption::VALUE_REQUIRED, 'Jurisdiction badge (Iconify id)', 'circle-flags:us')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Default chat model id', 'default-chat')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan, write nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing class and test')
            ->setHelp(
                "One provider is one class with a descriptor (`docs/PROVIDERS.md`). The keys\n".
                "store, the recommended defaults, the Messages gateway upstreams, the admin\n".
                "configuration schema, <info>GET /api/v1/ai/providers</info> and the egress\n".
                "allowlist all read that descriptor, so nothing else is edited. What the class\n".
                "cannot carry is printed to paste: the model rows for <info>ModelCatalog</info>\n".
                "and the help sentence for the five locales.\n\n".
                '  <info>php bin/console app:provider:scaffold acme --openai-compatible --base-url https://api.acme.ai/v1</info>'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('openai-compatible')) {
            $io->error('Only the OpenAI-compatible template exists. Pass --openai-compatible, or start a provider with its own wire format from docs/PROVIDERS.md.');

            return Command::INVALID;
        }

        try {
            $request = $this->request($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        if ($this->catalog->has($request->name)) {
            $io->error(sprintf('A provider named "%s" exists (%s).', $request->name, $this->catalog->get($request->name)->displayName));

            return Command::INVALID;
        }

        $plan = $this->scaffolder->plan($request);
        $io->title(sprintf('%s as one class', $request->displayName));

        if ($input->getOption('dry-run')) {
            foreach ($plan->files as $path => $contents) {
                $io->section($this->scaffolder->relative($path));
                $io->writeln($contents);
            }
        } else {
            try {
                $io->listing($this->scaffolder->write($plan, (bool) $input->getOption('force')));
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        foreach ($plan->snippets as $title => $snippet) {
            $io->section('Paste into '.$title);
            $io->writeln($snippet);
            $io->newLine();
        }
        $io->success(sprintf(
            'Next: edit the descriptor and the model rows, run `make ci-local`, then find %s under Admin → AI infrastructure → Providers & keys.',
            $request->displayName,
        ));

        return Command::SUCCESS;
    }

    private function request(InputInterface $input): ProviderScaffoldRequest
    {
        $name = strtolower(trim((string) $input->getArgument('name')));
        $option = static fn (string $key, string $default): string => trim((string) ($input->getOption($key) ?? '')) ?: $default;

        return new ProviderScaffoldRequest(
            name: $name,
            displayName: $option('display-name', ucfirst($name)),
            baseUri: rtrim($option('base-url', 'https://api.'.$name.'.example/v1'), '/'),
            envVar: $option('env-var', strtoupper($name).'_API_KEY'),
            consoleUrl: $option('console-url', 'https://console.'.$name.'.example/'),
            flag: $option('flag', 'circle-flags:us'),
            defaultModel: $option('model', 'default-chat'),
        );
    }
}
