<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\CloudWatch;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\CloudWatchItemRepository;
use App\Repository\CloudWatchRepository;
use App\Repository\ExternalIdentityRepository;
use App\Security\OAuthBearerAuthenticator;
use App\Service\CloudWatch\CloudWatchException;
use App\Service\CloudWatch\CloudWatchService;
use App\Service\CloudWatch\WatchLimits;
use App\Service\CloudWatch\WatchPublisher;
use App\Service\CloudWatch\WatchSentence;
use App\Service\CloudWatch\WatchTasks;
use App\Service\File\CloudFilesConfig;
use App\Service\PlatformLink\SourceInstanceResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CloudWatchIdentityTest extends TestCase
{
    public function testATokenForOneAccountDoesNotUseTheOlderAccount(): void
    {
        $older = $this->identity(1, 'ada');
        $newer = $this->identity(2, 'bob');
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findByUserId')->willReturn([$older, $newer]);
        $request = new Request();
        $request->attributes->set(OAuthBearerAuthenticator::EXTERNAL_ID_ATTRIBUTE, 'bob');

        $found = $this->service($identities)->identity($this->user(), $request);

        self::assertSame($newer, $found);
    }

    public function testAKeyUsesTheAccountItIsBoundTo(): void
    {
        $bound = $this->identity(2, 'bob');
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findOneByApiKeyId')->willReturn($bound);
        $key = $this->createStub(ApiKey::class);
        $key->method('getId')->willReturn(99);
        $request = new Request();
        $request->attributes->set('api_key', $key);

        $found = $this->service($identities)->identity($this->user(), $request);

        self::assertSame($bound, $found);
    }

    public function testATurnedOffAccountIsRefusedEvenWhenAnotherAccountIsOn(): void
    {
        $older = $this->identity(1, 'ada');
        $older->setStatus(ExternalIdentity::STATUS_PAUSED);
        $newer = $this->identity(2, 'bob');
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findByUserId')->willReturn([$older, $newer]);
        $request = new Request();
        $request->attributes->set(OAuthBearerAuthenticator::EXTERNAL_ID_ATTRIBUTE, 'ada');

        $this->expectException(CloudWatchException::class);
        $this->service($identities)->identity($this->user(), $request);
    }

    private function service(ExternalIdentityRepository $identities): CloudWatchService
    {
        $sources = $this->createStub(SourceInstanceResolver::class);
        $sources->method('fromRequest')->willReturn('pi_cloud');

        return new CloudWatchService(
            $this->createStub(CloudWatchRepository::class),
            $this->createStub(CloudWatchItemRepository::class),
            $identities,
            $this->createStub(WatchTasks::class),
            $sources,
            $this->createStub(CloudFilesConfig::class),
            $this->createStub(WatchLimits::class),
            $this->createStub(WatchSentence::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(WatchPublisher::class),
        );
    }

    private function user(): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 7);

        return $user;
    }

    private function identity(int $id, string $externalId): ExternalIdentity
    {
        $identity = new ExternalIdentity();
        (new \ReflectionProperty(ExternalIdentity::class, 'id'))->setValue($identity, $id);
        $identity->setUserId(7);
        $identity->setInstanceId('pi_cloud');
        $identity->setExternalId($externalId);

        return $identity;
    }
}
