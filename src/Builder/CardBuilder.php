<?php

namespace App\Builder;

use App\Entity\Card;
use App\Entity\CardTranslation;
use App\Entity\Rarity;
use App\Repository\RarityRepository;
use App\Repository\SetRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class CardBuilder
{
    /** @var array<string, Rarity> */
    private array $rarityCache = [];

    public function __construct(
        private readonly SetRepository     $setRepository,
        private readonly RarityRepository  $rarityRepository,
        private readonly CardGroupBuilder  $cardGroupBuilder,
    ) {}

    public function clearCache(): void
    {
        $this->rarityCache = [];
        $this->cardGroupBuilder->clearCache();
    }

    public function reconcileNewEffects(EntityManagerInterface $em): void
    {
        $this->cardGroupBuilder->reconcileNewEffects($em);
    }

    public function computeSlug(array $data): string
    {
        return $this->cardGroupBuilder->computeSlug($data);
    }

    /** @param string[] $slugs  @param string[] $abilityKeys */
    public function preloadCaches(array $slugs, array $abilityKeys): void
    {
        $this->cardGroupBuilder->preloadCaches($slugs, $abilityKeys);
    }

    /**
     * Build a card with all its locales in one pass.
     * CardGroup + gameplay stats are processed only once (en-us),
     * then only translations and fr-fr specific fields are applied per locale.
     *
     * @param array<string, array> $localizedPayloads  locale → data array
     */
    public function buildAllLocales(Card $card, array $localizedPayloads): Card
    {
        $enData = $localizedPayloads['en-us'] ?? null;
        if ($enData === null) {
            return $card;
        }

        // ── Base build: CardGroup + gameplay stats (en-us only) ──────────────
        $cardGroup = $this->cardGroupBuilder->findOrCreate($enData);
        $cardGroup = $this->cardGroupBuilder->build($cardGroup, $enData, 'en-us');
        $card->setCardGroup($cardGroup);

        // ── Printing identity (set once, same for all locales) ────────────────
        $this->applyPrintingIdentity($card, $enData);

        // ── Per-locale: CardGroup translation + Card translation + fr-fr specifics ─────
        foreach ($localizedPayloads as $locale => $payload) {
            if ($locale === 'en-us') {
                continue;
            }
            // Build CardGroupTranslation for this locale (name, mainEffect, echoEffect, lore…).
            // Gameplay stats (costs, powers) are guarded by $locale === 'en-us' inside the builder,
            // so calling this for fr-fr / de-de / … is safe and has no double-write side effects.
            $this->cardGroupBuilder->build($cardGroup, $payload, $locale);
            $this->applyLocaleData($card, $payload, $locale);
        }

        // en-us translation last (ensures name/image from en-us payload)
        $this->applyLocaleData($card, $enData, 'en-us');

        return $card;
    }

    public function build(Card $card, array $data, string $locale): Card
    {
        if ($card->getId()) {
            $card->setUpdatedDate(new DateTimeImmutable());
        }

        // ── Find or create CardGroup and build gameplay fields ────────────────
        $cardGroup = $this->cardGroupBuilder->findOrCreate($data);
        $cardGroup = $this->cardGroupBuilder->build($cardGroup, $data, $locale);
        $card->setCardGroup($cardGroup);

        $this->applyPrintingIdentity($card, $data);

        if ($locale === 'fr-fr') {
            $this->applyFrFrFields($card, $data);
        }

        $card = $this->buildTranslation($card, $data, $locale);

        return $card;
    }

    private function applyPrintingIdentity(Card $card, array $data): void
    {
        $card->setAlteredId($data['id']);
        $card->setReference($data['reference']);
        $card->setKickstarter(array_key_exists('ks', $data) && $data['ks']);
        $card->setIsSerialized((bool) ($data['isSerialized'] ?? false));
        $card->setIsParentSerialized((bool) ($data['isParentSerialized'] ?? false));
        $card->setIsOwnerless((bool) ($data['isOwnerless'] ?? false));

        $variation = $this->deriveVariationFromReference($data['reference']);
        if (!empty($data['ks'])) $variation = 'kickstarter';
        if (!empty($data['isSerialized'])) $variation = 'serialized';
        $card->setVariation($variation);

        if (array_key_exists('qrUrlDetail', $data)) {
            $card->setQrUrlDetail($data['qrUrlDetail']);
        }

        if (array_key_exists('isExclusive', $data)) {
            $card->setIsExclusive((bool) $data['isExclusive']);
        }
        if (array_key_exists('isPublic', $data)) {
            $card->setIsPublic((bool) $data['isPublic']);
        }
        if (array_key_exists('lowerPrice', $data) && $data['lowerPrice'] !== null) {
            $card->setLowerPrice((float) $data['lowerPrice']);
        }
        if (isset($data['cardProduct']['reference'])) {
            $card->setCardProduct($data['cardProduct']['reference']);
        }

        if (!$card->getSet()) {
            $dbSet = $this->setRepository->findOneByReference($data['cardSet']['reference'] ?? '');
            if ($dbSet) {
                $card->setSet($dbSet);
            }
        }

        if (array_key_exists('rarity', $data) && isset($data['rarity']['reference'])) {
            $card->setRarity($this->findOrCreateRarity($data['rarity'], 'en-us'));
        }
    }

    /**
     * Reference format: ALT_SET_VARIANT_FACTION_NUM_RARITY[_UNIQUENUM] — the 3rd
     * underscore-separated segment is the true printing variant (B=standard,
     * P=promo, A=alt-art), independent of locale and reliable for every set,
     * unlike the collectorNumberFormatted-position heuristic below (which only
     * ever ran for fr-fr and never detected alt-art at all).
     */
    private function deriveVariationFromReference(string $reference): string
    {
        $parts = explode('_', $reference);

        return match ($parts[2] ?? null) {
            'P' => 'promo',
            'A' => 'alt-art',
            default => 'standard',
        };
    }

    private function applyFrFrFields(Card $card, array $data): void
    {
        if (array_key_exists('imagePath', $data)) {
            $card->setImgPath($data['imagePath']);
        }
        if (array_key_exists('collectorNumberFormatted', $data)) {
            $cnf = $data['collectorNumberFormatted'];
            $card->setCollectorNumberFormatedId($cnf);

            if (isset($cnf[4]) && $cnf[4] === 'P') {
                $card->setPromo(true);
            }
            if (isset($cnf[8]) && $cnf[8] === 'F') {
                $card->setTransfuge(true);
            }

            if ($cnf !== null) {
                $card->setCardNumber((int) substr($cnf, 4, 3));
            }
        }
        if (array_key_exists('allImagePath', $data)) {
            $card->setAllImagePath($data['allImagePath']);
        }
        if (array_key_exists('assets', $data) && is_array($data['assets'])) {
            $card->setAssets(array_values(array_merge(...array_values($data['assets']))));
        }

        // Transfuge detection: faction in reference differs from mainFaction
        if (isset($data['mainFaction']['reference'])) {
            $refParts    = explode('_', $data['reference'] ?? '');
            $refFaction  = strtoupper($refParts[3] ?? '');
            $mainFaction = strtoupper($data['mainFaction']['reference']);
            if ($refFaction !== '' && $refFaction !== $mainFaction) {
                $card->setTransfuge(true);
            }
        }
    }

    private function applyLocaleData(Card $card, array $data, string $locale): void
    {
        if ($locale === 'fr-fr') {
            $this->applyFrFrFields($card, $data);
        }
        $card = $this->buildTranslation($card, $data, $locale);
    }

    private function buildTranslation(Card $card, array $data, string $locale): Card
    {
        $language        = explode('-', $locale)[0];
        $cardTranslation = $card->getTranslation($language);

        if (!$cardTranslation) {
            $cardTranslation = new CardTranslation();
            $cardTranslation->setLocale($language);
            $cardTranslation->setCard($card);
            $card->addTranslation($cardTranslation);
        } else {
            $cardTranslation->setUpdatedDate(new DateTimeImmutable());
            $card->setUpdatedDate(new DateTimeImmutable());
        }

        if (array_key_exists('imagePath', $data)) {
            $cardTranslation->setImgPath($data['imagePath']);
        }
        if (array_key_exists('name', $data)) {
            $cardTranslation->setName($data['name']);
        }
        if (array_key_exists('collectorNumberFormatted', $data)) {
            $cardTranslation->setCollectorNumberFormatedId($data['collectorNumberFormatted']);
        }

        return $card;
    }

    private function findOrCreateRarity(array $data, string $locale): Rarity
    {
        $reference = $data['reference'];

        if (!isset($this->rarityCache[$reference])) {
            $rarity = $this->rarityRepository->findOneByReference($reference);
            if (!$rarity) {
                $rarity = new Rarity();
                $rarity->setReference($reference);
            }
            $this->rarityCache[$reference] = $rarity;
        }

        $rarity   = $this->rarityCache[$reference];
        $language = explode('-', $locale)[0];
        $setter   = 'setName' . ucfirst($language);
        if (isset($data['name']) && method_exists($rarity, $setter)) {
            $rarity->{$setter}($data['name']);
        }

        return $rarity;
    }
}
