<?php

declare(strict_types=1);

namespace App\Tests\Service\Voice;

use App\Entity\Model;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Exception\VoiceLiveBusyException;
use App\Repository\ConfigRepository;
use App\Service\CostCalculationService;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use App\Service\UsageStatsService;
use App\Service\Voice\VoiceLiveMeter;
use App\Service\Voice\VoiceLiveUsageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VoiceLiveUsageServiceIntegrationTest extends KernelTestCase
{
    public function testFakeTicksLandInTheSharedBudgetAndTheActivityLog(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $user = $this->user($em, 'live-voice-'.uniqid('', true).'@synaplan.internal');
        $model = $this->model($em);
        $usage = $this->usage($em);

        $session = $usage->open($user, $model, 42, 'carplay');
        self::assertSame('relay', $session->getTransport());
        $usage->tick($session, new VoiceLiveMeter(seconds: 60), 900);
        $usage->addAnswer($session, '0.010000', 20, 30);

        $row = $em->getConnection()->fetchAssociative(
            'SELECT BACTION, BSTATUS, BCOST, BMETADATA, BLATENCY FROM BUSELOG WHERE BID = :id',
            ['id' => $session->getUseLogId()],
        );
        self::assertIsArray($row);
        self::assertSame('VOICE_LIVE', $row['BACTION']);
        self::assertSame('running', $row['BSTATUS']);
        self::assertSame('0.060000', $row['BCOST']);
        self::assertSame(900, (int) $row['BLATENCY']);

        $meta = json_decode((string) $row['BMETADATA'], true);
        self::assertIsArray($meta);
        self::assertSame('0.050000', $meta['voice_cost']);
        self::assertSame('0.010000', $meta['answer_cost']);
        self::assertSame(1, $meta['answer_count']);
        self::assertEquals(60.0, $meta['media_usage']['duration_seconds']);
        self::assertSame('carplay', $meta['source']);

        $voiceRows = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM BUSELOG WHERE BUSERID = :user AND BACTION = :action',
            ['user' => $user->getId(), 'action' => 'VOICE_LIVE'],
        );
        $messageRows = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM BUSELOG WHERE BUSERID = :user AND BACTION = :action',
            ['user' => $user->getId(), 'action' => 'MESSAGES'],
        );
        self::assertSame(1, $voiceRows);
        self::assertSame(0, $messageRows);

        $stats = static::getContainer()->get(UsageStatsService::class);
        \assert($stats instanceof UsageStatsService);
        $activity = $stats->getActivityLog((int) $user->getId(), action: 'VOICE_LIVE');
        self::assertSame(1, $activity['total']);
        self::assertSame(60.0, $activity['items'][0]['duration_seconds']);
        self::assertSame(42, $activity['items'][0]['chat_id']);
        self::assertSame('CARPLAY', $activity['items'][0]['channel']);
        self::assertSame(0.06, $activity['items'][0]['cost']);

        $limits = static::getContainer()->get(RateLimitService::class);
        \assert($limits instanceof RateLimitService);
        $budget = $limits->checkCostBudget($user);
        self::assertSame('0.06', $budget['raw_cost']);

        $csv = $stats->exportUsageAsCsv($user);
        self::assertStringContainsString('Duration (s)', $csv);
        self::assertStringContainsString('60.000', $csv);

        $this->expectException(VoiceLiveBusyException::class);
        $usage->open($user, $model, 42, 'web');
    }

    public function testCloseMarksTheRowSuccessful(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $user = $this->user($em, 'live-voice-close-'.uniqid('', true).'@synaplan.internal');
        $model = $this->model($em);
        $usage = $this->usage($em);

        $session = $usage->open($user, $model, null, 'web');
        $usage->close($session, 'user_hangup', new VoiceLiveMeter(seconds: 12));

        self::assertSame('ended', $session->getStatus());
        self::assertSame('user_hangup', $session->getEndReason());
        $status = $em->getConnection()->fetchOne(
            'SELECT BSTATUS FROM BUSELOG WHERE BID = :id',
            ['id' => $session->getUseLogId()],
        );
        self::assertSame('success', $status);
    }

    public function testAdminKillEndsTheStoredSessionAndTheNextTickStops(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $user = $this->user($em, 'live-voice-admin-'.uniqid('', true).'@synaplan.internal');
        $usage = $this->usage($em);
        $session = $usage->open($user, $this->model($em), 7, 'web');

        $sessions = static::getContainer()->get(\App\Service\Voice\VoiceLiveSessionService::class);
        \assert($sessions instanceof \App\Service\Voice\VoiceLiveSessionService);
        $payload = $sessions->adminKill((int) $session->getId());

        self::assertSame('ended', $payload['status']);
        self::assertSame('admin_disabled', $payload['endReason']);
        self::assertSame(7, $payload['chatId']);
        $em->refresh($session);
        self::assertSame('ended', $session->getStatus());

        $decision = $usage->tick($session, new VoiceLiveMeter(seconds: 90), 0);
        self::assertSame('stop', $decision->action);
        self::assertSame('admin_disabled', $decision->reason);

        $missing = static::getContainer()->get(\App\Service\Voice\VoiceLiveSessionService::class);
        \assert($missing instanceof \App\Service\Voice\VoiceLiveSessionService);
        try {
            $missing->adminKill(999999999);
            self::fail('A missing session must be rejected.');
        } catch (\App\Exception\VoiceLiveRejectedException $e) {
            self::assertSame(404, $e->statusCode());
        }
    }

    public function testReaperClosesASessionThatStoppedTicking(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $user = $this->user($em, 'live-voice-reaper-'.uniqid('', true).'@synaplan.internal');
        $usage = $this->usage($em);
        $session = $usage->open($user, $this->model($em), null, 'web');
        $usage->mergeMetadata($session, ['last_tick' => time() - 120]);

        $config = static::getContainer()->get(ConfigRepository::class);
        \assert($config instanceof ConfigRepository);
        $redis = static::getContainer()->get(\App\Service\Infrastructure\RedisService::class);
        \assert($redis instanceof \App\Service\Infrastructure\RedisService);
        $reaper = new \App\Service\Voice\VoiceLiveReaper(
            $em->getRepository(VoiceSession::class),
            $usage,
            new \App\Service\Voice\VoiceLiveConcurrency($redis, $config),
            new \Psr\Log\NullLogger(),
        );

        self::assertGreaterThanOrEqual(1, $reaper->reap());
        $em->refresh($session);
        self::assertSame('ended', $session->getStatus());
        self::assertSame('network_lost', $session->getEndReason());
        $status = $em->getConnection()->fetchOne(
            'SELECT BSTATUS FROM BUSELOG WHERE BID = :id',
            ['id' => $session->getUseLogId()],
        );
        self::assertSame('success', $status);
    }

    private function usage(EntityManagerInterface $em): VoiceLiveUsageService
    {
        $sessions = $em->getRepository(VoiceSession::class);
        $costs = static::getContainer()->get(CostCalculationService::class);
        \assert($costs instanceof CostCalculationService);
        $limits = static::getContainer()->get(RateLimitService::class);
        \assert($limits instanceof RateLimitService);
        $gate = static::getContainer()->get(PremiumFeatureGate::class);
        \assert($gate instanceof PremiumFeatureGate);
        $config = static::getContainer()->get(ConfigRepository::class);
        \assert($config instanceof ConfigRepository);

        return new VoiceLiveUsageService($em, $sessions, $costs, $limits, $gate, $config);
    }

    public function testOpenStoresDirectTransportWhenAsked(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $session = $this->usage($em)->open(
            $this->user($em, 'live-voice-webrtc-'.uniqid('', true).'@synaplan.internal'),
            $this->model($em),
            null,
            'web',
            false,
            'webrtc',
        );

        self::assertSame('webrtc', $session->getTransport());
    }

    private function user(EntityManagerInterface $em, string $email = 'live-voice@synaplan.internal'): User
    {
        $existing = $em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('live-voice-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function model(EntityManagerInterface $em): Model
    {
        $existing = $em->getRepository(Model::class)->findOneBy(['providerId' => 'gpt-live-test']);
        if ($existing instanceof Model) {
            return $existing;
        }

        $model = (new Model())
            ->setService('OpenAI')
            ->setName('GPT-Live Test')
            ->setTag('voice2voice')
            ->setProviderId('gpt-live-test')
            ->setSelectable(1)
            ->setActive(1)
            ->setPriceIn(0.05)
            ->setInUnit('permin')
            ->setPriceOut(0.0)
            ->setOutUnit('-')
            ->setQuality(9)
            ->setRating(1)
            ->setJson(['pricing_mode' => 'per_second']);
        $em->persist($model);
        $em->flush();

        return $model;
    }
}
