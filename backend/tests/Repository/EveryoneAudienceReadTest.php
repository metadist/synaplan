<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Share;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;
use App\Service\RAG\RagScopeResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The disabled everyone audience is a read-path rule: user grants drop out of
 * findForSubjects (and therefore RAG), while a system grant (grantedBy 0) stays.
 */
final class EveryoneAudienceReadTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<object> */
    private array $toRemove = [];

    private string $previousEveryone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()->get('doctrine')->getManager();
        $config = $this->config();
        $this->previousEveryone = (string) $config->getValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_EVERYONE_SHARES);
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_EVERYONE_SHARES, IamConfig::EVERYONE_SHARES_DISABLED);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->config()->setValue(
            0,
            IamConfig::CONFIG_GROUP,
            IamConfig::KEY_EVERYONE_SHARES,
            '' !== $this->previousEveryone ? $this->previousEveryone : IamConfig::EVERYONE_SHARES_ANY_OWNER,
        );
        foreach (array_reverse($this->toRemove) as $entity) {
            if ($this->em->contains($entity)) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    public function testDisabledAudienceDropsUserGrantsFromLookupAndRag(): void
    {
        $owner = $this->createUser();
        $member = $this->createUser();
        $userFolder = 'user-'.bin2hex(random_bytes(4));
        $platformFolder = 'platform-'.bin2hex(random_bytes(4));
        $this->shareFolder($owner, $userFolder, (int) $owner->getId());
        $this->shareFolder($owner, $platformFolder, 0);

        $shares = $this->shares();
        $memberId = (int) $member->getId();
        $found = $shares->findForSubjects($memberId, [], KnowledgeFolderKind::KEY);
        $ids = array_map(static fn (Share $share): string => $share->getResourceId(), $found);

        self::assertContains(KnowledgeFolderKind::resourceId((int) $owner->getId(), $platformFolder), $ids);
        self::assertNotContains(KnowledgeFolderKind::resourceId((int) $owner->getId(), $userFolder), $ids);

        $folders = $this->ragFolders($memberId);
        self::assertContains($platformFolder, $folders);
        self::assertNotContains($userFolder, $folders);

        $this->config()->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_EVERYONE_SHARES, IamConfig::EVERYONE_SHARES_ANY_OWNER);
        $this->em->flush();

        $restored = array_map(
            static fn (Share $share): string => $share->getResourceId(),
            $shares->findForSubjects($memberId, [], KnowledgeFolderKind::KEY),
        );
        self::assertContains(KnowledgeFolderKind::resourceId((int) $owner->getId(), $userFolder), $restored);
        self::assertContains($userFolder, $this->ragFolders($memberId));
    }

    /**
     * @return list<string>
     */
    private function ragFolders(int $userId): array
    {
        $folders = [];
        foreach (static::getContainer()->get(RagScopeResolver::class)->resolve($userId, null) as $scope) {
            if (null !== $scope->groupKey) {
                $folders[] = $scope->groupKey;
            }
        }

        return $folders;
    }

    private function shareFolder(User $owner, string $folder, int $grantedBy): void
    {
        $share = new Share();
        $share->setResourceKind(KnowledgeFolderKind::KEY);
        $share->setResourceId(KnowledgeFolderKind::resourceId((int) $owner->getId(), $folder));
        $share->setSubjectType(Share::SUBJECT_EVERYONE);
        $share->setSubjectId(0);
        $share->setPermission(Permission::Use->value);
        $share->setGrantedBy($grantedBy);
        $this->em->persist($share);
        $this->em->flush();
        $this->toRemove[] = $share;
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setMail('everyone-'.bin2hex(random_bytes(4)).'@test.com');
        $user->setPw('test123');
        $user->setProviderId('WEB');
        $user->setUserLevel('NEW');
        $this->em->persist($user);
        $this->em->flush();
        $this->toRemove[] = $user;

        return $user;
    }

    private function shares(): ShareRepository
    {
        $repository = $this->em->getRepository(Share::class);
        self::assertInstanceOf(ShareRepository::class, $repository);

        return $repository;
    }

    private function config(): ConfigRepository
    {
        $config = static::getContainer()->get(ConfigRepository::class);
        self::assertInstanceOf(ConfigRepository::class, $config);

        return $config;
    }
}
