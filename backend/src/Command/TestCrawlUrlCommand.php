<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UrlContentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-crawl-url',
    description: 'Test the crawler against a single URL to verify extraction quality',
)]
final class TestCrawlUrlCommand extends Command
{
    public function __construct(
        private readonly UrlContentService $urlContentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'The URL to crawl or API endpoint to fetch');
        $this->addOption('api', null, InputOption::VALUE_NONE, 'Test as API endpoint instead of website crawl');
        $this->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method for API test (default: GET)', 'GET');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $url */
        $url = $input->getArgument('url');

        if ($input->getOption('api')) {
            /** @var string $method */
            $method = $input->getOption('method');
            $io->title("Testing API Fetch: {$method} {$url}");

            $io->section('fetchApi() (API response with 8k char limit)');
            $result = $this->urlContentService->fetchApi($url, strtoupper($method));
            $this->renderResult($io, $result);

            return Command::SUCCESS;
        }

        $io->title('Testing Crawler: '.$url);

        $io->section('fetchForCrawling() (full extraction for RAG)');
        $crawlResult = $this->urlContentService->fetchForCrawling($url);
        $this->renderResult($io, $crawlResult);

        $io->section('fetch() (lightweight extraction for chat context)');
        $fetchResult = $this->urlContentService->fetch($url);
        $this->renderResult($io, $fetchResult);

        return Command::SUCCESS;
    }

    private function renderResult(SymfonyStyle $io, \App\Service\UrlContentResult $result): void
    {
        $io->definitionList(
            ['URL' => $result->url],
            ['Success' => $result->success ? 'YES' : 'NO'],
            ['Title' => $result->title ?: '(empty)'],
            ['Hostname' => $result->hostname],
            ['Text Length' => strlen($result->extractedText).' chars'],
            ['Error' => $result->error ?? 'none'],
        );

        if ('' !== $result->extractedText) {
            $io->text('<info>=== Extracted Text ===</info>');
            $io->text($result->extractedText);
        }
    }
}
