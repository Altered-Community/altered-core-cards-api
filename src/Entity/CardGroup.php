<?php

namespace App\Entity;

use App\Filter\ReferenceFilter;
use App\Service\KeywordLocalizer;
use App\Filter\CardNameFilter;
use App\Model\TimestampInterface;
use App\Entity\Rarity;
use App\Model\TimestampTrait;
use App\Repository\CardGroupRepository;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Index(name: "idx_card_group_slug", fields: ["slug"])]
#[ORM\Index(name: "idx_card_group_faction", fields: ["faction"])]
#[ORM\Index(name: "idx_card_group_card_type", fields: ["cardType"])]
#[ORM\Index(name: "idx_card_group_card_type_id", fields: ["cardType", "id"])]
#[ORM\Index(name: "idx_card_group_main_cost", fields: ["mainCost"])]
#[ORM\Index(name: "idx_card_group_recall_cost", fields: ["recallCost"])]
#[ORM\Index(name: "idx_card_group_is_banned", fields: ["isBanned"])]
#[ORM\Index(name: "idx_card_group_is_suspended", fields: ["isSuspended"])]
#[ORM\Index(name: "idx_card_group_is_errated", fields: ["isErrated"])]
#[ORM\Index(name: "idx_card_group_rarity", fields: ["rarity"])]
#[ORM\Index(name: "idx_card_group_faction_rarity", fields: ["faction", "rarity"])]
#[ORM\Entity(repositoryClass: CardGroupRepository::class)]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['card_group:read', 'card_group:read:detail']]),
        new GetCollection(
            provider: \App\State\CardGroupCollectionProvider::class,
            normalizationContext: ['groups' => ['card_group:read']],
            paginationFetchJoinCollection: false,
            forceEager: false,
        ),
    ],
    paginationItemsPerPage: 30,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'slug'         => 'exact',
    'mainCost'     => 'exact',
    'recallCost'   => 'exact',
    'oceanPower'   => 'exact',
    'mountainPower'=> 'exact',
    'forestPower'  => 'exact',
    'isBanned'     => 'exact',
    'isErrated'    => 'exact',
    'isSuspended'  => 'exact',
])]
#[ApiFilter(\App\Filter\CardGroupSetFilter::class)]
#[ApiFilter(\App\Filter\CardGroupCardReferenceFilter::class)]
#[ApiFilter(ReferenceFilter::class, properties: ['cardType', 'subTypes', 'rarity', 'faction' => 'code'])]
#[ApiFilter(\App\Filter\ExcludeReferenceFilter::class, properties: ['cardType', 'subTypes'])]
#[ApiFilter(\App\Filter\EffectTriggerTypeFilter::class, properties: ['effectTriggerType'])]
#[ApiFilter(\App\Filter\EffectKeywordFilter::class, properties: ['effectKeyword'])]
#[ApiFilter(\App\Filter\HasNoEffectFilter::class, properties: ['hasNoEffect'])]
#[ApiFilter(\App\Filter\SameTriggerCountFilter::class, properties: ['minSameTriggerCount'])]
#[ApiFilter(\App\Filter\EffectSlotFilter::class, properties: ['effectSlot'])]
#[ApiFilter(\App\Filter\EchoSlotFilter::class, properties: ['echoSlot', 'hasEchoEffect'])]
#[ApiFilter(\App\Filter\CardNameFilter::class, properties: ['name'])]
#[ApiFilter(OrderFilter::class, properties: ['mainCost', 'recallCost'])]
#[ApiFilter(\App\Filter\AfterIdFilter::class, properties: ['afterId'])]
#[ApiFilter(\App\Filter\CostRelationFilter::class, properties: ['costRelation'])]
#[ApiFilter(\App\Filter\CardGroupTransfugeFilter::class)]
#[ApiFilter(\App\Filter\GameplayFormatFilter::class, properties: ['gameplayFormat'])]
class CardGroup implements TimestampInterface
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private ?int $id = null;

    /**
     * Stable logical identifier, e.g. "OR-017".
     * Derived from faction code + zero-padded card number.
     */
    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private string $slug;

    #[ORM\ManyToOne(targetEntity: Faction::class)]
    #[Groups(['card_group:read', 'card:list', 'card:read', 'card_group:read:detail'])]
    #[ApiProperty(fetchEager: false)]
    private ?Faction $faction = null;

    #[ORM\ManyToOne(targetEntity: Rarity::class, cascade: ['persist'])]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    #[ApiProperty(fetchEager: false)]
    private ?Rarity $rarity = null;

    #[ORM\ManyToOne(targetEntity: CardType::class, cascade: ['persist'])]
    #[Groups(['card_group:read', 'card_group:read:detail', 'card:list', 'card:read'])]
    #[ApiProperty(fetchEager: false)]
    private ?CardType $cardType = null;

    #[ORM\ManyToMany(targetEntity: CardSubType::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'card_group_sub_type_link')]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    #[SerializedName('cardSubTypes')]
    #[ApiProperty(fetchEager: false)]
    private Collection $subTypes;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private ?int $mainCost = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private ?int $recallCost = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private ?int $oceanPower = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private ?int $mountainPower = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private ?int $forestPower = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $displayOceanPower = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $displayMountainPower = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $displayForestPower = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private ?string $permanent = null;

    #[ORM\ManyToOne(targetEntity: MainEffect::class)]
    #[Groups(['card_group:read', 'card:read'])]
    #[ApiProperty(fetchEager: false)]
    private ?MainEffect $effect1 = null;

    #[ORM\ManyToOne(targetEntity: MainEffect::class)]
    #[Groups(['card_group:read', 'card:read'])]
    #[ApiProperty(fetchEager: false)]
    private ?MainEffect $effect2 = null;

    #[ORM\ManyToOne(targetEntity: MainEffect::class)]
    #[Groups(['card_group:read', 'card:read'])]
    #[ApiProperty(fetchEager: false)]
    private ?MainEffect $effect3 = null;

    #[ORM\ManyToOne(targetEntity: MainEffect::class)]
    #[Groups(['card_group:read', 'card:read'])]
    #[ApiProperty(fetchEager: false)]
    private ?MainEffect $echoEffect1 = null;

    #[ORM\ManyToOne(targetEntity: CardHistoryStatus::class, cascade: ['persist'])]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    #[ApiProperty(fetchEager: false)]
    private ?CardHistoryStatus $cardHistoryStatus = null;

    /**
     * Gameplay format keys the card belongs to, e.g. ["STANDARD", "DRAFT"]. GIN-indexed for containment filters.
     * Uses the custom `text_array` DBAL type — without it ORM persistence (fixtures, builders)
     * infers `json` from the PHP array type and Postgres rejects the JSON literal as a malformed array.
     */
    #[ORM\Column(type: 'text_array', columnDefinition: "TEXT[] NOT NULL DEFAULT '{}'")]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private array $gameplayFormat = [];

    #[ORM\Column(type: 'boolean', nullable: false)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private bool $isBanned = false;

    #[ORM\Column(type: 'boolean', nullable: false)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private bool $isErrated = false;

    #[ORM\Column(type: 'boolean', nullable: false)]
    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    private bool $isSuspended = false;

    #[ORM\OneToMany(targetEntity: CardRuling::class, mappedBy: 'cardGroup', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cardRulings;

    #[ORM\OneToMany(targetEntity: LoreEntry::class, mappedBy: 'cardGroup', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $loreEntries;

    #[ORM\OneToMany(targetEntity: CardGroupTranslation::class, mappedBy: 'cardGroup', cascade: ['persist'])]
    private Collection $translations;

    #[ORM\OneToMany(targetEntity: Card::class, mappedBy: 'cardGroup')]
    #[Groups(['card_group:read', 'card_group:read:detail'])]
    #[ApiProperty(fetchEager: false)]
    private Collection $cards;

    public function __construct()
    {
        $this->creationDate = new \DateTimeImmutable();
        $this->subTypes     = new ArrayCollection();
        $this->cardRulings  = new ArrayCollection();
        $this->loreEntries  = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->cards        = new ArrayCollection();
    }

    // --- Virtual getters for API ---

    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    #[SerializedName('name')]
    public function getLocalizedNames(): array
    {
        $result = [];
        foreach ($this->translations as $t) {
            if ($t->getName() !== null) {
                $result[$t->getLocale()] = $t->getName();
            }
        }
        return $result;
    }

    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    #[SerializedName('mainEffect')]
    public function getLocalizedMainEffects(): array
    {
        $result = [];
        foreach ($this->translations as $t) {
            $text = $t->getMainEffect();
            if ($text !== null) {
                $result[$t->getLocale()] = KeywordLocalizer::localize($text, $t->getLocale());
            }
        }
        return $result;
    }

    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    #[SerializedName('echoEffect')]
    public function getLocalizedEchoEffects(): array
    {
        $result = [];
        foreach ($this->translations as $t) {
            $text = $t->getEchoEffect();
            if ($text !== null) {
                $result[$t->getLocale()] = KeywordLocalizer::localize($text, $t->getLocale());
            }
        }
        return $result;
    }

    #[Groups(['card_group:read', 'card:list', 'card:read'])]
    #[SerializedName('displayPowers')]
    public function getDisplayPowers(): array
    {
        return [
            'ocean'    => $this->displayOceanPower,
            'mountain' => $this->displayMountainPower,
            'forest'   => $this->displayForestPower,
        ];
    }

    #[Groups(['card_group:read'])]
    #[SerializedName('cardRulings')]
    public function getSerializedCardRulings(): array
    {
        $result = [];
        foreach ($this->cardRulings as $ruling) {
            $result[$ruling->getLocale() ?? 'en'][] = [
                'question'    => $ruling->getQuestion(),
                'answer'      => $ruling->getAnswer(),
                'eventFormat' => $ruling->getEventFormat(),
                'rulingDate'  => $ruling->getRulingDate()?->format(\DateTimeInterface::ATOM),
            ];
        }
        return $result;
    }

    #[Groups(['card_group:read'])]
    #[SerializedName('loreEntries')]
    public function getSerializedLoreEntries(): array
    {
        $result = [];
        foreach ($this->loreEntries as $entry) {
            $result[$entry->getLocale()][] = [
                'type'     => $entry->getType(),
                'elements' => $entry->getElements(),
            ];
        }
        return $result;
    }

    #[Groups(['card_group:read'])]
    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->getUpdateDate();
    }

    // --- Getters / Setters ---

    public function getId(): ?int { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }

    public function getFaction(): ?Faction { return $this->faction; }
    public function setFaction(?Faction $faction): self { $this->faction = $faction; return $this; }

    public function getRarity(): ?Rarity { return $this->rarity; }
    public function setRarity(?Rarity $rarity): self { $this->rarity = $rarity; return $this; }

    public function getCardType(): ?CardType { return $this->cardType; }
    public function setCardType(?CardType $cardType): self { $this->cardType = $cardType; return $this; }

    public function getSubTypes(): Collection { return $this->subTypes; }
    public function addSubType(CardSubType $subType): self
    {
        foreach ($this->subTypes as $existing) {
            if ($existing->getReference() === $subType->getReference()) {
                return $this;
            }
        }
        $this->subTypes->add($subType);
        return $this;
    }

    public function removeSubType(CardSubType $subType): self
    {
        foreach ($this->subTypes as $existing) {
            if ($existing->getReference() === $subType->getReference()) {
                $this->subTypes->removeElement($existing);
                break;
            }
        }
        return $this;
    }

    public function getMainCost(): ?int { return $this->mainCost; }
    public function setMainCost(?int $mainCost): self { $this->mainCost = $mainCost; return $this; }

    public function getRecallCost(): ?int { return $this->recallCost; }
    public function setRecallCost(?int $recallCost): self { $this->recallCost = $recallCost; return $this; }

    public function getOceanPower(): ?int { return $this->oceanPower; }
    public function setOceanPower(?int $v): self { $this->oceanPower = $v; return $this; }

    public function getMountainPower(): ?int { return $this->mountainPower; }
    public function setMountainPower(?int $v): self { $this->mountainPower = $v; return $this; }

    public function getForestPower(): ?int { return $this->forestPower; }
    public function setForestPower(?int $v): self { $this->forestPower = $v; return $this; }

    public function getDisplayOceanPower(): ?string { return $this->displayOceanPower; }
    public function setDisplayOceanPower(?string $v): self { $this->displayOceanPower = $v; return $this; }

    public function getDisplayMountainPower(): ?string { return $this->displayMountainPower; }
    public function setDisplayMountainPower(?string $v): self { $this->displayMountainPower = $v; return $this; }

    public function getDisplayForestPower(): ?string { return $this->displayForestPower; }
    public function setDisplayForestPower(?string $v): self { $this->displayForestPower = $v; return $this; }

    public function getPermanent(): ?string { return $this->permanent; }
    public function setPermanent(?string $v): self { $this->permanent = $v; return $this; }

    public function getEffect1(): ?MainEffect { return $this->effect1; }
    public function setEffect1(?MainEffect $v): self { $this->effect1 = $v; return $this; }

    public function getEffect2(): ?MainEffect { return $this->effect2; }
    public function setEffect2(?MainEffect $v): self { $this->effect2 = $v; return $this; }

    public function getEffect3(): ?MainEffect { return $this->effect3; }
    public function setEffect3(?MainEffect $v): self { $this->effect3 = $v; return $this; }

    public function getEchoEffect1(): ?MainEffect { return $this->echoEffect1; }
    public function setEchoEffect1(?MainEffect $v): self { $this->echoEffect1 = $v; return $this; }

    public function getCardHistoryStatus(): ?CardHistoryStatus { return $this->cardHistoryStatus; }
    public function setCardHistoryStatus(?CardHistoryStatus $v): self { $this->cardHistoryStatus = $v; return $this; }

    public function getGameplayFormat(): array { return $this->gameplayFormat; }
    public function setGameplayFormat(array $gameplayFormat): self { $this->gameplayFormat = $gameplayFormat; return $this; }

    public function getIsBanned(): bool { return $this->isBanned; }
    public function setIsBanned(bool $v): self { $this->isBanned = $v; return $this; }

    public function getIsErrated(): bool { return $this->isErrated; }
    public function setIsErrated(bool $v): self { $this->isErrated = $v; return $this; }

    public function getIsSuspended(): bool { return $this->isSuspended; }
    public function setIsSuspended(bool $v): self { $this->isSuspended = $v; return $this; }

    public function getCardRulings(): Collection { return $this->cardRulings; }
    public function getLoreEntries(): Collection { return $this->loreEntries; }
    public function getTranslations(): Collection { return $this->translations; }
    public function getCards(): Collection { return $this->cards; }

    public function getTranslation(string $locale): ?CardGroupTranslation
    {
        foreach ($this->translations as $t) {
            if ($t->getLocale() === $locale) {
                return $t;
            }
        }
        return null;
    }
}
