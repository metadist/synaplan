<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One redemption of a {@see SignupCode}: who used it, when, and the tier the
 * code granted at that moment. Kept when the code is revoked; removed with
 * the account when the person is erased ({@see \App\Service\Iam\Erasure\SignupCodeUseEraser}),
 * while the code's use counter keeps the slot spent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'BSIGNUP_CODE_USES')]
#[ORM\Index(columns: ['BCODEID'], name: 'idx_signup_code_use_code')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_signup_code_use_user')]
class SignupCodeUse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BCODEID', type: 'bigint')]
    private int $codeId;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BLEVEL', length: 16)]
    private string $level;

    #[ORM\Column(name: 'BUSEDAT', type: 'bigint')]
    private int $usedAt;

    public function __construct(int $codeId, int $userId, string $level, ?int $usedAt = null)
    {
        $this->codeId = $codeId;
        $this->userId = $userId;
        $this->level = $level;
        $this->usedAt = $usedAt ?? time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodeId(): int
    {
        return $this->codeId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getUsedAt(): int
    {
        return $this->usedAt;
    }
}
