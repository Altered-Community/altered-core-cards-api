<?php

namespace App\Entity;

use App\Model\TimestampInterface;
use App\Model\TimestampTrait;
use App\Repository\GameplayFormatRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks the version of each gameplay format last synced from the Altered
 * Reunion formats manifest, so the sync job can detect new/changed formats.
 */
#[ORM\Entity(repositoryClass: GameplayFormatRepository::class)]
class GameplayFormat implements TimestampInterface
{
    use TimestampTrait;

    /**
     * Manifest format id, e.g. "frontier". Used as-is as the gameplayFormat key on CardGroup.
     */
    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $version;

    public function __construct(string $id)
    {
        $this->id           = $id;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getVersion(): int { return $this->version; }
    public function setVersion(int $version): self { $this->version = $version; return $this; }
}
