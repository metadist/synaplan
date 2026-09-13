<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Service\Compute\Contract\ComputeArtefact;
use App\Service\Compute\Contract\ComputeErrorBody;
use App\Service\Compute\Contract\ComputeHealth;
use App\Service\Compute\Contract\ComputeRunRequest;
use App\Service\Compute\Contract\ComputeRunStatus;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HTTP client for synaplan-compute protocol 1. PHP never talks to Docker.
 */
final readonly class ComputeClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ComputeConfig $config,
    ) {
    }

    public function health(): ComputeHealth
    {
        $response = $this->httpClient->request('GET', $this->config->baseUrl().'/v1/health', [
            'timeout' => 5,
        ]);

        return ComputeHealth::fromJson($response->getContent());
    }

    /**
     * @param iterable<array{name: string, contents: string}> $files
     */
    public function submitRun(ComputeRunRequest $request, iterable $files): string
    {
        if ('' === $request->owner) {
            throw new ComputeRefusedException('missing_owner', 'owner is required');
        }
        $fields = ['request.json' => json_encode($request->toArray(), \JSON_THROW_ON_ERROR)];
        foreach ($files as $file) {
            $fields[$file['name']] = new DataPart($file['contents'], $file['name']);
        }
        $form = new FormDataPart($fields);
        $response = $this->request('POST', '/v1/runs', [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToString(),
            'timeout' => 30,
        ]);
        $data = json_decode($response->getContent(), true);
        $runId = is_array($data) ? ($data['runId'] ?? null) : null;
        if (!is_string($runId) || '' === $runId) {
            throw new ComputeRefusedException('invalid_json', 'Run id missing from sidecar response');
        }

        return $runId;
    }

    public function status(string $runId): ComputeRunStatus
    {
        $response = $this->request('GET', '/v1/runs/'.rawurlencode($runId), ['timeout' => 10]);

        return ComputeRunStatus::fromJson($response->getContent());
    }

    /**
     * @return list<ComputeArtefact>
     */
    public function listArtefacts(string $runId): array
    {
        $response = $this->request('GET', '/v1/runs/'.rawurlencode($runId).'/artefacts', ['timeout' => 10]);

        return ComputeArtefact::listFromJson($response->getContent());
    }

    public function downloadArtefact(string $runId, string $name): string
    {
        $response = $this->request(
            'GET',
            '/v1/runs/'.rawurlencode($runId).'/artefacts/'.rawurlencode($name),
            ['timeout' => 30],
        );

        return $response->getContent();
    }

    public function cancel(string $runId): void
    {
        $this->request('DELETE', '/v1/runs/'.rawurlencode($runId), ['timeout' => 10]);
    }

    /**
     * Snapshot of captured streams after the run is terminal. Never stored
     * on the audit row.
     *
     * @return array{stdout: string, stderr: string}
     */
    public function collectLogs(string $runId, int $maxChars = 8000): array
    {
        $stdout = '';
        $stderr = '';
        try {
            $this->streamLogs($runId, static function (array $event) use (&$stdout, &$stderr): void {
                $data = json_decode($event['data'], true);
                $text = is_array($data) ? (string) ($data['text'] ?? '') : '';
                if ('stdout' === $event['event']) {
                    $stdout .= $text;
                } elseif ('stderr' === $event['event']) {
                    $stderr .= $text;
                }
            });
        } catch (\Throwable) {
            return ['stdout' => '', 'stderr' => ''];
        }

        return [
            'stdout' => mb_substr($stdout, 0, $maxChars),
            'stderr' => mb_substr($stderr, 0, $maxChars),
        ];
    }

    /**
     * @param callable(array{event: string, data: string, id: ?string}): void $onEvent
     */
    public function streamLogs(string $runId, callable $onEvent, ?string $lastEventId = null): void
    {
        $headers = [];
        if (null !== $lastEventId && '' !== $lastEventId) {
            $headers['Last-Event-ID'] = $lastEventId;
        }
        $response = $this->request('GET', '/v1/runs/'.rawurlencode($runId).'/logs', [
            'timeout' => 60,
            'headers' => $headers,
            'buffer' => false,
        ]);
        $buffer = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();
            while (false !== ($pos = strpos($buffer, "\n\n"))) {
                $block = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $event = self::parseSseBlock($block);
                if (null !== $event) {
                    $onEvent($event);
                }
            }
        }
    }

    /**
     * @return array{event: string, data: string, id: ?string}|null
     */
    private static function parseSseBlock(string $block): ?array
    {
        $event = 'message';
        $data = [];
        $id = null;
        foreach (preg_split('/\r\n|\n|\r/', $block) ?: [] as $line) {
            if ('' === $line || str_starts_with($line, ':')) {
                continue;
            }
            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = ltrim($value);
            if ('event' === $field) {
                $event = $value;
            } elseif ('data' === $field) {
                $data[] = $value;
            } elseif ('id' === $field) {
                $id = $value;
            }
        }
        if ([] === $data && 'message' === $event) {
            return null;
        }

        return ['event' => $event, 'data' => implode("\n", $data), 'id' => $id];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['headers'] = array_merge(
            is_array($options['headers'] ?? null) ? $options['headers'] : [],
            ['Authorization' => 'Bearer '.$this->config->token()],
        );
        $response = $this->httpClient->request($method, $this->config->baseUrl().$path, $options);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $this->throwRefused($response, $status);
        }

        return $response;
    }

    private function throwRefused(ResponseInterface $response, int $status): never
    {
        try {
            $error = ComputeErrorBody::fromJson($response->getContent(false));
        } catch (\Throwable) {
            throw new ComputeRefusedException('internal_error', 'Compute sidecar returned HTTP '.$status, httpStatus: $status);
        }

        throw new ComputeRefusedException($error->code, $error->message, $error->details, $status);
    }
}
