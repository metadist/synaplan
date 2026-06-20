<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Service\AiFacade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Throwaway manual smoke test for the Higgsfield image-to-video path.
 *
 * Drives the real production path: AiFacade::generateVideo() with the
 * higgsfield provider, platform credentials resolved from env, then downloads
 * the produced mp4 into the backend docroot so it can be viewed locally.
 */
#[AsCommand(name: 'app:higgsfield:test-video', description: 'Manual Higgsfield image->video smoke test')]
final class HiggsfieldTestVideoCommand extends Command
{
    public function __construct(
        private readonly AiFacade $aiFacade,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('image', InputArgument::OPTIONAL, 'Public image URL to animate', 'https://www.metadist.de/wp-content/uploads/2024/02/vid6_cover-e1740316537997.png')
            ->addArgument('prompt', InputArgument::OPTIONAL, 'Motion prompt', 'Cinematic subtle camera push-in; the man speaks and gestures naturally; gentle, realistic ambient motion.')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Clip length in seconds', '5')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output file path', '/app/public/higgsfield-test.mp4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $imageUrl = (string) $input->getArgument('image');
        $prompt = (string) $input->getArgument('prompt');
        $duration = (int) $input->getOption('duration');
        $outPath = (string) $input->getOption('out');

        $io->title('Higgsfield image -> video smoke test');
        $io->definitionList(
            ['image_url' => $imageUrl],
            ['prompt' => $prompt],
            ['duration' => $duration.'s'],
            ['out' => $outPath],
        );

        $startedAt = microtime(true);

        try {
            $result = $this->aiFacade->generateVideo($prompt, null, [
                'provider' => 'higgsfield',
                'image_url' => $imageUrl,
                'duration' => $duration,
                'progress_callback' => function (array $p) use ($io): void {
                    $io->writeln(sprintf(
                        '  [poll #%d] status=%s elapsed=%ds (~%d%%)',
                        $p['attempt'] ?? 0,
                        $p['status'] ?? '?',
                        $p['elapsed_seconds'] ?? 0,
                        $p['percent'] ?? 0,
                    ));
                },
            ]);
        } catch (\Throwable $e) {
            $io->error('generateVideo failed: '.$e->getMessage());
            $io->writeln('<comment>'.get_class($e).'</comment>');

            return Command::FAILURE;
        }

        $elapsed = round(microtime(true) - $startedAt, 1);

        $videos = $result['videos'] ?? [];
        $videoUrl = is_array($videos) && isset($videos[0]['url']) ? (string) $videos[0]['url'] : '';

        $io->section('Result');
        $io->writeln('provider: '.($result['provider'] ?? '?'));
        $io->writeln('model:    '.($result['model'] ?? '?'));
        $io->writeln('duration: '.($result['duration_seconds'] ?? '?'));
        $io->writeln('url:      '.($videoUrl ?: '(none)'));
        $io->writeln('elapsed:  '.$elapsed.'s');

        if ('' === $videoUrl) {
            $io->error('No video URL returned.');

            return Command::FAILURE;
        }

        try {
            $bytes = $this->httpClient->request('GET', $videoUrl, ['timeout' => 120])->getContent();
            @mkdir(\dirname($outPath), 0775, true);
            file_put_contents($outPath, $bytes);
        } catch (\Throwable $e) {
            $io->error('Download failed: '.$e->getMessage());
            $io->writeln('You can still open the URL directly: '.$videoUrl);

            return Command::FAILURE;
        }

        $io->success(sprintf('Saved %s (%d bytes). View at http://localhost:8000/%s', $outPath, filesize($outPath) ?: 0, basename($outPath)));

        return Command::SUCCESS;
    }
}
