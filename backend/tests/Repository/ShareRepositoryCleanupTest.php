<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Share;
use App\Repository\ShareRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deleting a user must not leave plugin-kind share rows behind: plugin_data
 * ids are reused, so a stale row would attach to the next owner of that id.
 */
final class ShareRepositoryCleanupTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ShareRepository $shares;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->shares = static::getContainer()->get(ShareRepository::class);
    }

    public function testPluginKindSharesAreRemovedByPluginDataIdOnly(): void
    {
        $resourceId = (string) random_int(900000, 999999);
        $plugin = $this->share('synaform:form', $resourceId);
        $conversation = $this->share('conversation', $resourceId);

        try {
            $this->shares->deleteByPluginDataIds([(int) $resourceId]);
            $this->em->clear();

            self::assertNull($this->shares->find($plugin->getId()), 'plugin-kind share must be gone');
            self::assertNotNull($this->shares->find($conversation->getId()), 'built-in kinds are untouched');
        } finally {
            foreach ([$plugin->getId(), $conversation->getId()] as $id) {
                $row = $this->shares->find($id);
                if ($row instanceof Share) {
                    $this->em->remove($row);
                }
            }
            $this->em->flush();
        }
    }

    public function testEmptyIdListIsANoOp(): void
    {
        $before = $this->shares->count([]);
        $this->shares->deleteByPluginDataIds([]);
        self::assertSame($before, $this->shares->count([]));
    }

    private function share(string $kind, string $resourceId): Share
    {
        $share = new Share();
        $share->setResourceKind($kind);
        $share->setResourceId($resourceId);
        $share->setSubjectType(Share::SUBJECT_EVERYONE);
        $share->setSubjectId(0);
        $share->setPermission('read');
        $share->setGrantedBy(1);
        $this->em->persist($share);
        $this->em->flush();

        return $share;
    }
}
