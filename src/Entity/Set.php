<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Model\TimestampInterface;
use App\Model\TimestampTrait;
use App\Repository\SetRepository;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: SetRepository::class)]
#[ORM\Table(name: 'card_set')]
#[ORM\Index(name: 'idx_card_set_date', fields: ['date'])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    normalizationContext: ['groups' => ['set:read']],
)]
class Set implements TimestampInterface
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['set:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: false)]
    #[Groups(['set:read'])]
    private ?string $alteredId = null;

    #[ORM\Column(length: 75, nullable: false)]
    #[Groups(['set:read', 'card:list', 'card:read'])]
    private string $name;

    #[ORM\Column(length: 75, nullable: true)]
    #[Groups(['set:read'])]
    private ?string $name_en = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Groups(['set:read', 'card:list', 'card:read'])]
    private ?string $code = null;

    #[ORM\Column(nullable: false)]
    #[Groups(['set:read'])]
    private bool $isActive = true;

    #[ORM\Column(length: 25, nullable: false)]
    #[Groups(['set:read', 'card:list', 'card:read', 'card_group:read'])]
    private string $reference;

    #[ORM\Column(nullable: true)]
    #[Groups(['set:read'])]
    private ?string $illustration;

    #[ORM\Column(nullable: true)]
    #[Groups(['set:read'])]
    private ?string $illustrationPath;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['set:read'])]
    private ?DateTimeImmutable $date;

    #[ORM\Column(type: "json", nullable: true)]
    private array $cardGoogleSheets = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getAlteredId(): ?string
    {
        return $this->alteredId;
    }

    public function setAlteredId(?string $alteredId): void
    {
        $this->alteredId = $alteredId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getNameEn(): string
    {
        return $this->name_en;
    }

    public function setNameEn(string $name_en): void
    {
        $this->name_en = $name_en;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getIllustration(): ?string
    {
        return $this->illustration;
    }

    public function setIllustration(?string $illustration): void
    {
        $this->illustration = $illustration;
    }

    public function getIllustrationPath(): ?string
    {
        return $this->illustrationPath;
    }

    public function setIllustrationPath(?string $illustrationPath): void
    {
        $this->illustrationPath = $illustrationPath;
    }

    public function getDate(): ?DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function getCardGoogleSheets(): array
    {
        return $this->cardGoogleSheets;
    }

    public function setCardGoogleSheets(array $cardGoogleSheets): void
    {
        $this->cardGoogleSheets = $cardGoogleSheets;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): void
    {
        $this->reference = $reference;
    }
}