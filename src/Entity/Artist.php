<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Model\TimestampInterface;
use App\Model\TimestampTrait;
use App\Repository\ArtistRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: ArtistRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_artist_reference', fields: ['reference'])]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
    ],
    normalizationContext: ['groups' => ['artist:read']],
    paginationEnabled: false,
)]
class Artist implements TimestampInterface
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['artist:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 150, unique: true)]
    #[Groups(['artist:read'])]
    private string $reference;

    #[ORM\Column(length: 150)]
    #[Groups(['artist:read', 'card:read', 'card:list'])]
    private string $name;

    public function __construct()
    {
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): self { $this->reference = $reference; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
}
