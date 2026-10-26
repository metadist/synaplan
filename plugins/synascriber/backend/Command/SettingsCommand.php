<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Command;

use Plugin\SynaScriber\Service\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes the installation-wide meeting-notes settings without HTTP or an
 * administrator account, for deployments. Secrets come in on stdin or from a
 * file, never as arguments: arguments are visible in the process list.
 */
#[AsCommand(
    name: 'synascriber:settings',
    description: 'Update meeting-notes settings from a JSON object on stdin (or --file), or show them (--show)',
)]
final class SettingsCommand extends Command
{
    public function __construct(private readonly Settings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Read the JSON object from this file instead of stdin')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Only print the current settings (secrets as set / not set)')
            ->setHelp(<<<'HELP'
                Keys are the ones of PUT /api/v1/plugins/synascriber/admin/settings, for example:

                  echo '{"enabled": true, "stt_url": "http://whisper:8080", "transcriber_secret": "…"}' \
                    | php bin/console synascriber:settings

                Unknown keys and invalid values are refused and nothing is written.
                Prints the resulting settings as JSON; secrets only say whether they are set.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('show')) {
            $values = $this->read($input);
            if (is_string($values)) {
                $output->writeln(sprintf('<error>%s</error>', $values));

                return Command::INVALID;
            }
            try {
                $this->settings->update($values);
            } catch (\InvalidArgumentException $e) {
                $output->writeln(sprintf('<error>Nothing was stored: %s</error>', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        $output->writeln((string) json_encode($this->settings->publicView(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|string the settings, or why there are none
     */
    private function read(InputInterface $input): array|string
    {
        $file = $input->getOption('file');
        if (is_string($file) && '' !== $file) {
            $raw = is_file($file) ? file_get_contents($file) : false;
            if (false === $raw) {
                return sprintf('Cannot read the settings file "%s".', $file);
            }
        } else {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $stream ??= \STDIN;
            if (\STDIN === $stream && stream_isatty(\STDIN)) {
                return 'Pipe a JSON object of settings into this command, pass --file, or use --show.';
            }
            $raw = stream_get_contents($stream);
        }

        $values = json_decode((string) $raw, true);
        if (!is_array($values) || array_is_list($values) && [] !== $values) {
            return 'The settings are not a JSON object.';
        }

        return $values;
    }
}
