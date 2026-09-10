<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\SavedTask\Graph\StepInputResolver;
use App\Service\Security\SsrfGuard;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends a signed HTTPS POST of a step result. Hidden from the planner.
 * No retries — the receiver retries. Never includes credentials.
 */
final readonly class OutboundWebhookRunner implements TaskRunner
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
        private StepInputResolver $inputs,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::OutboundWebhook];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::OutboundWebhook,
                'Send the result to another system over HTTPS.',
                available: static fn (): bool => false,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $url = is_string($node->params['url'] ?? null) ? trim($node->params['url']) : '';
        if ('' === $url || !str_starts_with(strtolower($url), 'https://')) {
            return NodeResult::failed('Send to webhook needs an https address');
        }
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            return NodeResult::failed('That address cannot be reached from here');
        }

        $rawInputs = is_array($node->params['inputs'] ?? null) ? $node->params['inputs'] : $node->inputs;
        $mapped = $this->inputs->resolveAll($rawInputs, $context);
        $body = [
            'task' => $context->options['saved_task_id'] ?? null,
            'run' => $context->options['saved_task_run_id'] ?? null,
            'step' => $node->id,
            'result' => $mapped,
        ];
        $json = json_encode($body, \JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $secret = is_string($node->params['secret'] ?? null) ? $node->params['secret'] : '';
        if ('' !== $secret) {
            $headers['X-Synaplan-Signature'] = 'sha256='.hash_hmac('sha256', $json, $secret);
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $json,
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $response->cancel();

                return NodeResult::failed('The other system redirected the request — that is not allowed');
            }
            if ($status >= 400) {
                return NodeResult::failed('The other system did not accept the result');
            }
        } catch (HttpClientExceptionInterface $e) {
            $this->logger->info('OutboundWebhookRunner: call failed', [
                'step' => $node->id,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed('Could not reach the other system');
        }

        return NodeResult::ok('Sent to the other system', [], ['webhook' => ['status' => $status]]);
    }
}
