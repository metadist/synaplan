<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Api;

use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\Api\OpenAiGatewayException;
use App\Service\Api\OpenAiGatewayModels;
use App\Service\Api\OpenAiImageGenerationRequest;
use App\Service\Api\OpenAiImageGenerationService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiImageGenerationServiceTest extends TestCase
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $recorded = [];
    private int $limitChecks = 0;

    /**
     * @param list<array<string, mixed>> $images
     */
    private function service(array $images, ?MockHttpClient $http = null): OpenAiImageGenerationService
    {
        $model = (new Model())->setService('Test')->setName('img')->setTag('text2pic')->setProviderId('img-1')->setActive(1);

        $models = $this->createStub(ModelRepository::class);
        $models->method('findActiveByTagAndIdentity')->willReturn($model);

        $facade = $this->createStub(AiFacade::class);
        $facade->method('generateImage')->willReturn(['images' => $images, 'provider' => 'test', 'model' => 'img-1', 'image_count' => \count($images)]);

        $rateLimit = $this->createStub(RateLimitService::class);
        $rateLimit->method('checkLimit')->willReturnCallback(function (): array {
            ++$this->limitChecks;

            return ['allowed' => true];
        });
        $rateLimit->method('checkCostBudget')->willReturn(['allowed' => true]);
        $rateLimit->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata): RecordedUsage {
            $this->recorded[] = [$action, $metadata];

            return new RecordedUsage('0', '0', 0, 0, 0, true);
        });

        return new OpenAiImageGenerationService(
            $facade,
            new OpenAiGatewayModels($models, $this->createStub(ModelConfigService::class)),
            $rateLimit,
            $http ?? new MockHttpClient(static fn () => throw new \LogicException('no HTTP expected')),
            new NullLogger(),
        );
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(3);

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): OpenAiImageGenerationRequest
    {
        return OpenAiImageGenerationRequest::fromBody($body + ['model' => 'img-1', 'prompt' => 'a cat']);
    }

    public function testDataUrlBecomesB64WithoutAFetch(): void
    {
        $result = $this->service([['url' => 'data:image/png;base64,QUJD', 'revised_prompt' => 'a cute cat']])
            ->create($this->user(), $this->request(['response_format' => 'b64_json']), false);

        self::assertSame([['b64_json' => 'QUJD', 'revised_prompt' => 'a cute cat']], $result['data']);
        self::assertSame('IMAGES', $this->recorded[0][0]);
        self::assertSame(1.0, $this->recorded[0][1]['media_usage']['images']);
        self::assertSame('1024x1024', $this->recorded[0][1]['media_usage']['size']);
    }

    public function testHttpsUrlIsFetchedForB64(): void
    {
        $http = new MockHttpClient(new MockResponse('PNGBYTES', ['http_code' => 200]));

        $result = $this->service([['url' => 'https://cdn.example/img.png']], $http)
            ->create($this->user(), $this->request(['response_format' => 'b64_json']), false);

        self::assertSame(base64_encode('PNGBYTES'), $result['data'][0]['b64_json']);
    }

    public function testPlainHttpUrlIsNeverFetchedButTheImageIsStillMetered(): void
    {
        try {
            $this->service([['url' => 'http://169.254.169.254/latest']])
                ->create($this->user(), $this->request(['response_format' => 'b64_json']), false);
            self::fail('Expected OpenAiGatewayException');
        } catch (OpenAiGatewayException $e) {
            self::assertSame('upstream_error', $e->errorCode);
            self::assertSame(502, $e->httpStatus);
        }

        self::assertCount(1, $this->recorded);
    }

    public function testBytesOnlyProviderAnswersWithADataUrl(): void
    {
        $result = $this->service([['b64_json' => 'QUJD']])->create($this->user(), $this->request([]), false);

        self::assertSame([['url' => 'data:image/png;base64,QUJD']], $result['data']);
    }

    public function testNoImagesIsAnUpstreamErrorAndNothingIsMetered(): void
    {
        try {
            $this->service([])->create($this->user(), $this->request([]), false);
            self::fail('Expected OpenAiGatewayException');
        } catch (OpenAiGatewayException $e) {
            self::assertSame('upstream_error', $e->errorCode);
        }

        self::assertSame([], $this->recorded);
    }

    public function testGatedRequestSkipsOnlyTheHourlyCap(): void
    {
        $this->service([['url' => 'https://cdn.example/a.png']])->create($this->user(), $this->request([]), true);
        self::assertSame(0, $this->limitChecks);

        $this->service([['url' => 'https://cdn.example/a.png']])->create($this->user(), $this->request([]), false);
        self::assertSame(1, $this->limitChecks);
    }
}
