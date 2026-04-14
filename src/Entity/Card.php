<?php

namespace App\Entity;

use App\Filter\CardGroupAliasFilter;
use App\Filter\ReferenceFilter;
use App\Model\TimestampInterface;
use App\Model\TimestampTrait;
use App\Repository\CardRepository;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Entity\Artist;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Index(name: "idx_card_reference", fields: ["reference"])]
#[ORM\Index(name: "idx_card_altered_id", fields: ["alteredId"])]
#[ORM\Index(name: "idx_card_set", fields: ["set"])]
#[ORM\Index(name: "idx_card_rarity", fields: ["rarity"])]
#[ORM\Index(name: "idx_card_card_group", fields: ["cardGroup"])]
#[ORM\Index(name: "idx_card_card_number", fields: ["cardNumber"])]
#[ORM\Index(name: "idx_card_is_serialized", fields: ["isSerialized"])]
#[ORM\Entity(repositoryClass: CardRepository::class)]
#[Gedmo\TranslationEntity(class: CardTranslation::class)]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['card:read']]),
        new Get(
            uriTemplate: '/cards/reference/{reference}',
            uriVariables: [
                'reference' => new Link(fromClass: Card::class, identifiers: ['reference']),
            ],
            normalizationContext: ['groups' => ['card:read']],
            name: 'get_card_by_reference',
        ),
        new GetCollection(
            provider: \App\State\CardCollectionProvider::class,
            normalizationContext: ['groups' => ['card:list']],
            cacheHeaders: ['max_age' => 3600, 'shared_max_age' => 3600, 'vary' => ['Accept', 'Accept-Language']],
            paginationFetchJoinCollection: false,
            paginationClientItemsPerPage: true,
            paginationMaximumItemsPerPage: 1000,
        ),
    ],
    normalizationContext: ['groups' => ['card:read']],
    paginationItemsPerPage: 30,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'reference'    => 'exact',
    'set.reference'=> 'exact',
    'kickstarter'  => 'exact',
    'promo'        => 'exact',
    'isSerialized' => 'exact',
    'variation'    => 'exact',
])]
#[ApiFilter(ReferenceFilter::class, properties: ['rarity'])]
#[ApiFilter(CardGroupAliasFilter::class, properties: [
    'faction.code', 'cardType', 'subTypes',
    'isBanned', 'isSuspended', 'isErrated',
    'mainCost', 'recallCost', 'oceanPower', 'mountainPower', 'forestPower',
])]
#[ApiFilter(\App\Filter\CardNameFilter::class, properties: ['name'])]
#[ApiFilter(OrderFilter::class, properties: ['cardNumber', 'set.date'])]
#[ApiFilter(\App\Filter\CardGroupOrderFilter::class, properties: ['mainCost', 'recallCost', 'oceanPower', 'mountainPower', 'forestPower'])]
#[ApiFilter(\App\Filter\EffectTriggerTypeFilter::class, properties: ['effectTriggerType' => 'cardGroup'])]
#[ApiFilter(\App\Filter\EffectKeywordFilter::class, properties: ['effectKeyword' => 'cardGroup'])]
#[ApiFilter(\App\Filter\HasNoEffectFilter::class, properties: ['hasNoEffect' => 'cardGroup'])]
#[ApiFilter(\App\Filter\SameTriggerCountFilter::class, properties: ['minSameTriggerCount' => 'cardGroup'])]
#[ApiFilter(\App\Filter\EffectSlotFilter::class, properties: ['effectSlot'])]
#[ApiFilter(\App\Filter\ArtistFilter::class)]
class Card implements TimestampInterface
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['card:list', 'card:read', 'card_group:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: false)]
    #[Groups(['card:list', 'card:read', 'card_group:read'])]
    private string $reference;

    #[ORM\Column(length: 50, nullable: false)]
    private ?string $alteredId = null;

    #[ORM\Column(type: "integer", nullable: false)]
    #[Groups(['card:read'])]
    private int $cardNumber = 0;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['card:read'])]
    private ?string $collectorNumberFormatedId = null;

    #[ORM\Column(nullable: true)]
    private ?string $imgPath = null;

    #[ORM\Column(nullable: true)]
    private ?string $downloadedImgPath = null;

    #[ORM\Column(type: "json", nullable: false)]
    private array $allImagePath = [];

    #[ORM\Column(type: "json", nullable: true)]
    #[Groups(['card:read'])]
    private ?array $assets = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['card:read'])]
    private ?string $qrUrlDetail = null;

    #[ORM\Column(type: "boolean", nullable: false)]
    #[Groups(['card:list', 'card:read'])]
    private bool $kickstarter = false;

    #[ORM\Column(type: "boolean", nullable: false)]
    #[Groups(['card:list', 'card:read'])]
    private bool $promo = false;

    #[ORM\Column(type: "boolean", nullable: false)]
    #[Groups(['card:list', 'card:read'])]
    private bool $transfuge = false;

    #[ORM\Column(type: "boolean", nullable: false)]
    #[Groups(['card:list', 'card:read'])]
    private bool $isSerialized = false;

    #[ORM\Column(type: "boolean", nullable: false)]
    #[Groups(['card:read'])]
    private bool $isParentSerialized = false;

    #[ORM\Column(type: "boolean", nullable: false)]
    #[Groups(['card:read'])]
    private bool $isOwnerless = false;

    /**
     * Printing variant: standard | alt-art | promo | kickstarter | serialized
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['card:list', 'card:read', 'card_group:read'])]
    private ?string $variation = 'standard';

    #[ORM\ManyToOne(targetEntity: CardGroup::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['card:list', 'card:read'])]
    private ?CardGroup $cardGroup = null;

    #[ORM\ManyToOne(targetEntity: Rarity::class, cascade: ['persist'])]
    #[SerializedName('cardRarity')]
    private ?Rarity $rarity = null;

    #[ORM\ManyToOne(targetEntity: Set::class)]
    #[Groups(['card:list', 'card:read', 'card_group:read'])]
    private ?Set $set = null;

    #[ORM\ManyToOne(targetEntity: Card::class)]
    private ?Card $parentCard = null;

    #[ORM\OneToMany(targetEntity: CardTranslation::class, mappedBy: 'card', cascade: ['persist'], fetch: 'EAGER')]
    private Collection $translations;

    #[ORM\ManyToMany(targetEntity: Artist::class)]
    #[ORM\JoinTable(name: 'card_artist')]
    #[Groups(['card:read'])]
    private Collection $artists;

    #[Gedmo\Locale]
    private ?string $locale = null;

    public function __construct()
    {
        $this->creationDate = new \DateTimeImmutable();
        $this->translations = new ArrayCollection();
        $this->artists      = new ArrayCollection();
    }

    #[Groups(['card:list', 'card:read', 'card_group:read'])]
    #[SerializedName('imagePath')]
    public function getLocalizedImagePaths(): array
    {
        $result = [];
        foreach ($this->translations as $t) {
            if ($t->getImgPath() !== null) {
                $result[$t->getLocale()] = $t->getImgPath();
            }
        }
        return $result;
    }

    #[Groups(['card:read'])]
    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->getUpdateDate();
    }

    public function getId(): ?int { return $this->id; }

    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): self { $this->reference = $reference; return $this; }

    public function getAlteredId(): ?string { return $this->alteredId; }
    public function setAlteredId(?string $alteredId): self { $this->alteredId = $alteredId; return $this; }

    public function getCardNumber(): int { return $this->cardNumber; }
    public function setCardNumber(int $cardNumber): self { $this->cardNumber = $cardNumber; return $this; }

    public function getCollectorNumberFormatedId(): ?string { return $this->collectorNumberFormatedId; }
    public function setCollectorNumberFormatedId(?string $v): self { $this->collectorNumberFormatedId = $v; return $this; }

    public function getImgPath(?string $locale = null): ?string
    {
        if ($locale) {
            $t = $this->getTranslation($locale);
            if ($t) return $t->getImgPath();
        }
        return $this->imgPath;
    }
    public function setImgPath(?string $imgPath): self { $this->imgPath = $imgPath; return $this; }

    public function getDownloadedImgPath(): ?string { return $this->downloadedImgPath; }
    public function setDownloadedImgPath(?string $v): self { $this->downloadedImgPath = $v; return $this; }

    public function getAllImagePath(): array { return $this->allImagePath; }
    public function setAllImagePath(array $v): self { $this->allImagePath = $v; return $this; }

    public function getAssets(): ?array { return $this->assets; }
    public function setAssets(?array $assets): self { $this->assets = $assets; return $this; }

    public function getQrUrlDetail(): ?string { return $this->qrUrlDetail; }
    public function setQrUrlDetail(?string $v): self { $this->qrUrlDetail = $v; return $this; }

    public function isKickstarter(): bool { return $this->kickstarter; }
    public function setKickstarter(bool $kickstarter): self { $this->kickstarter = $kickstarter; return $this; }

    public function isPromo(): bool { return $this->promo; }
    public function setPromo(bool $promo): self { $this->promo = $promo; return $this; }

    public function isTransfuge(): bool { return $this->transfuge; }
    public function setTransfuge(bool $transfuge): self { $this->transfuge = $transfuge; return $this; }

    public function isSerialized(): bool { return $this->isSerialized; }
    public function setIsSerialized(bool $v): self { $this->isSerialized = $v; return $this; }

    public function isParentSerialized(): bool { return $this->isParentSerialized; }
    public function setIsParentSerialized(bool $v): self { $this->isParentSerialized = $v; return $this; }

    public function isOwnerless(): bool { return $this->isOwnerless; }
    public function setIsOwnerless(bool $v): self { $this->isOwnerless = $v; return $this; }

    public function getVariation(): ?string { return $this->variation; }
    public function setVariation(?string $variation): self { $this->variation = $variation; return $this; }

    public function getCardGroup(): ?CardGroup { return $this->cardGroup; }
    public function setCardGroup(?CardGroup $cardGroup): self { $this->cardGroup = $cardGroup; return $this; }

    public function getRarity(): ?Rarity { return $this->rarity; }
    public function setRarity(?Rarity $rarity): self { $this->rarity = $rarity; return $this; }

    public function getSet(): ?Set { return $this->set; }
    public function setSet(?Set $set): self { $this->set = $set; return $this; }

    public function getParentCard(): ?Card { return $this->parentCard; }
    public function setParentCard(?Card $parentCard): self { $this->parentCard = $parentCard; return $this; }

    public function getTranslations(): Collection { return $this->translations; }
    public function getTranslation(string $locale): ?CardTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }
        return null;
    }
    public function addTranslation(CardTranslation $t): void
    {
        if (!$this->translations->contains($t)) {
            $this->translations->add($t);
            $t->setCard($this);
        }
    }

    public function getArtists(): Collection { return $this->artists; }

    public function addArtist(Artist $artist): self
    {
        if (!$this->artists->contains($artist)) {
            $this->artists->add($artist);
        }
        return $this;
    }

    public function removeArtist(Artist $artist): self
    {
        $this->artists->removeElement($artist);
        return $this;
    }
}
