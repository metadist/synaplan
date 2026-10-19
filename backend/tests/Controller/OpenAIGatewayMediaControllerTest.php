<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/embeddings and POST /v1/images/generations through the real kernel
 * against the seeded TestProvider models.
 */
final class OpenAIGatewayMediaControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private string $key;
    private string $embedModel;
    private string $imageModel;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $this->user = (new User())
            ->setMail('gateway-media-'.bin2hex(random_bytes(4)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('gateway-media-'.uniqid())
            ->setUserLevel('BUSINESS');
        $this->user->setCreated(date('YmdHis'));
        $this->user->setEmailVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();

        $this->key = 'sk_'.bin2hex(random_bytes(20));
        $this->em->persist((new ApiKey())
            ->setOwner($this->user)
            ->setKey($this->key)
            ->setStatus('active')
            ->setName('Gateway media test key')
            ->setScopes(['desktop:messages']));
        $this->em->flush();

        $this->embedModel = $this->createModel('vectorize')->getProviderId();
        $this->imageModel = $this->createModel('text2pic')->getProviderId();
    }

    public function testEmbeddingsReturnOneVectorPerInputInOrder(): void
    {
        $body = $this->post('/v1/embeddings', ['model' => $this->embedModel, 'input' => ['first', 'second']]);

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame('list', $body['object']);
        self::assertSame($this->embedModel, $body['model']);
        self::assertCount(2, $body['data']);
        self::assertSame([0, 1], array_column($body['data'], 'index'));
        self::assertSame('embedding', $body['data'][0]['object']);
        self::assertIsArray($body['data'][0]['embedding']);
        self::assertNotEmpty($body['data'][0]['embedding']);
        self::assertGreaterThan(0, $body['usage']['total_tokens']);
        self::assertSame(1, $this->usageRows('EMBEDDINGS'));
    }

    public function testEmbeddingsAcceptASingleStringAndBase64(): void
    {
        $body = $this->post('/v1/embeddings', ['model' => $this->embedModel, 'input' => 'only one', 'encoding_format' => 'base64']);

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertCount(1, $body['data']);
        $decoded = base64_decode($body['data'][0]['embedding'], true);
        self::assertIsString($decoded);
        self::assertSame(0, \strlen($decoded) % 4);
        self::assertGreaterThan(0, \strlen($decoded));
    }

    public function testEmbeddingsFallBackToTheCallersDefaultModel(): void
    {
        $model = $this->createModel('vectorize');
        static::getContainer()->get(ConfigRepository::class)
            ->setValue((int) $this->user->getId(), 'DEFAULTMODEL', 'VECTORIZE', (string) $model->getId());
        $this->em->flush();

        $body = $this->post('/v1/embeddings', ['input' => 'no model named']);

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame($model->getProviderId(), $body['model']);
    }

    public function testEmbeddingsRefuseAModelOfAnotherCapability(): void
    {
        $body = $this->post('/v1/embeddings', ['model' => $this->imageModel, 'input' => 'x']);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
        self::assertSame('model_not_found', $body['error']['code']);
        self::assertSame(0, $this->usageRows('EMBEDDINGS'));
    }

    public function testEmbeddingsRejectTokenArrays(): void
    {
        $body = $this->post('/v1/embeddings', ['model' => $this->embedModel, 'input' => [[1, 2, 3]]]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
        self::assertSame('invalid_input', $body['error']['code']);
    }

    public function testImagesReturnAUrlAndAreMetered(): void
    {
        $body = $this->post('/v1/images/generations', ['model' => $this->imageModel, 'prompt' => 'a lighthouse']);

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertIsInt($body['created']);
        self::assertCount(1, $body['data']);
        self::assertStringStartsWith('https://', $body['data'][0]['url']);
        self::assertSame(1, $this->usageRows('IMAGES'));
    }

    public function testImagesRejectTooManyImages(): void
    {
        $body = $this->post('/v1/images/generations', ['model' => $this->imageModel, 'prompt' => 'x', 'n' => 5]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
        self::assertSame('invalid_n', $body['error']['code']);
    }

    public function testImagesRefuseAnUnknownModel(): void
    {
        $body = $this->post('/v1/images/generations', ['model' => 'does-not-exist', 'prompt' => 'x']);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
        self::assertSame('model_not_found', $body['error']['code']);
    }

    public function testInvalidJsonIsA400(): void
    {
        $this->client->request('POST', '/v1/embeddings', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->key,
            'CONTENT_TYPE' => 'application/json',
        ], content: 'not json');

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
    }

    public function testWithoutAKeyIsA401(): void
    {
        $this->client->request('POST', '/v1/embeddings', server: ['CONTENT_TYPE' => 'application/json'], content: '{"input":"x"}');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->httpStatus());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $this->client->request('POST', $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->key,
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode($payload));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response was not JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function createModel(string $tag): Model
    {
        $model = (new Model())
            ->setService('test')
            ->setName('Gateway '.$tag)
            ->setTag($tag)
            ->setSelectable(1)
            ->setActive(1)
            ->setProviderId('gateway-'.$tag.'-'.bin2hex(random_bytes(4)))
            ->setPriceIn(0)
            ->setPriceOut(0);
        $this->em->persist($model);
        $this->em->flush();

        return $model;
    }

    private function usageRows(string $action): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM BUSELOG WHERE BUSERID = :user AND BACTION = :action',
            ['user' => $this->user->getId(), 'action' => $action],
        );
    }
}
