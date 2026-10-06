<?php

namespace App\Builder;

use App\Entity\CardGroup;
use App\Entity\CardGroupTranslation;
use App\Entity\CardHistoryStatus;
use App\Entity\CardRuling;
use App\Entity\CardSubType;
use App\Entity\CardType;
use App\Entity\LoreEntry;
use App\Entity\MainEffect;
use App\Repository\CardGroupRepository;
use App\Repository\CardHistoryStatusRepository;
use App\Repository\CardSubTypeRepository;
use App\Repository\CardTypeRepository;
use App\Repository\FactionRepository;
use App\Repository\LoreEntryRepository;
use App\Repository\MainEffectRepository;
use App\Repository\RarityRepository;
use App\Service\EffectParser;
use Doctrine\ORM\EntityManagerInterface;

class CardGroupBuilder
{
    /** @var array<string, CardGroup> */
    private array $groupCache = [];

    /** @var array<string, true>  slug+locale pairs already built this batch */
    private array $builtCache = [];

    /** @var array<string, CardType> */
    private array $cardTypeCache = [];

    /** @var array<string, CardSubType> */
    private array $subTypeCache = [];

    /** @var array<string, CardHistoryStatus> */
    private array $historyStatusCache = [];

    /** @var array<string, MainEffect> */
    private array $effectCache = [];

    /** @var array<string, MainEffect> new (un-persisted) effects created this batch */
    private array $newEffects = [];

    /** @var array<string, \App\Entity\Faction> */
    private array $factionCache = [];

    /** @var array<int, true> spl_object_id of effects whose text was set this batch */
    private array $dirtyEffects = [];

    /** @var array<string, int> abilityKey → DB id, populated by preloadCaches via DBAL */
    private array $effectIdCache = [];

    /** @var array<int, array> effect DB id → parsed keywords, flushed via DBAL in reconcileNewEffects */
    private array $pendingKeywords = [];

    public function __construct(
        private readonly FactionRepository           $factionRepository,
        private readonly CardTypeRepository          $cardTypeRepository,
        private readonly CardSubTypeRepository       $cardSubTypeRepository,
        private readonly CardHistoryStatusRepository $cardHistoryStatusRepository,
        private readonly MainEffectRepository        $mainEffectRepository,
        private readonly CardGroupRepository         $cardGroupRepository,
        private readonly LoreEntryRepository         $loreEntryRepository,
        private readonly RarityRepository            $rarityRepository,
        private readonly EffectParser                $effectParser,
        private readonly EntityManagerInterface      $em,
    ) {}

    public function clearCache(): void
    {
        $this->groupCache         = [];
        $this->builtCache         = [];
        $this->cardTypeCache      = [];
        $this->subTypeCache       = [];
        $this->historyStatusCache = [];
        $this->effectCache        = [];
        $this->newEffects         = [];
        $this->factionCache       = [];
        $this->dirtyEffects       = [];
        $this->effectIdCache      = [];
        $this->pendingKeywords    = [];
    }

    /**
     * Pre-warm group + effect caches with a single bulk query each,
     * eliminating per-card SELECT N+1 queries during batch processing.
     *
     * @param string[] $slugs       all CardGroup slugs expected in this batch
     * @param string[] $abilityKeys all MainEffect.abilityKey values expected in this batch
     */
    public function preloadCaches(array $slugs, array $abilityKeys): void
    {
        foreach ($this->cardGroupRepository->findBySlugs($slugs) as $slug => $group) {
            $this->groupCache[$slug] ??= $group;
        }

        if (empty($abilityKeys)) {
            return;
        }

        // Load only id + ability_key — no entity hydration, no identity map overhead
        $placeholders = implode(',', array_fill(0, count($abilityKeys), '?'));
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT id, ability_key FROM main_effect WHERE ability_key IN ({$placeholders})",
            array_values($abilityKeys),
        );
        foreach ($rows as $row) {
            $this->effectIdCache[$row['ability_key']] ??= (int) $row['id'];
        }

        // For ability_keys not found by name, build the combined text from ability component
        // tables and look up by text. This handles UNIQUE serialized cards whose JSON carries
        // a different Equinox ID for the same effect text (e.g. _195 vs _209).
        $missing = array_filter($abilityKeys, fn($k) => !isset($this->effectIdCache[$k]));
        if (empty($missing)) {
            return;
        }

        $conn = $this->em->getConnection();

        $tIdGds = array_unique(array_map(fn($k) => (int) (explode('_', $k)[0] ?? 0), $missing));
        $cIdGds = array_unique(array_map(fn($k) => (int) (explode('_', $k)[1] ?? 0), $missing));
        $eIdGds = array_unique(array_map(fn($k) => (int) (explode('_', $k)[2] ?? 0), $missing));

        $tTexts = $conn->fetchAllKeyValue(
            'SELECT altered_id, text_en FROM ability_trigger WHERE altered_id IN (' . implode(',', $tIdGds) . ')'
        );
        $cTexts = $conn->fetchAllKeyValue(
            'SELECT altered_id, text_en FROM ability_condition WHERE altered_id IN (' . implode(',', $cIdGds) . ')'
        );
        $eTexts = $conn->fetchAllKeyValue(
            'SELECT altered_id, text_en FROM ability_effect WHERE altered_id IN (' . implode(',', $eIdGds) . ')'
        );

        foreach ($missing as $abilityKey) {
            $parts = explode('_', $abilityKey);
            if (count($parts) !== 3) continue;

            [$tIdGd, $cIdGd, $eIdGd] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];

            $textParts = array_filter([
                $tTexts[$tIdGd] ?? null,
                $cTexts[$cIdGd] ?? null,
                $eTexts[$eIdGd] ?? null,
            ]);
            if (empty($textParts)) continue;

            $text = implode(' ', $textParts);
            $row  = $conn->fetchAssociative(
                'SELECT id FROM main_effect WHERE text_en = ? LIMIT 1',
                [$text],
            );

            if ($row) {
                $this->effectIdCache[$abilityKey] = (int) $row['id'];
            }
        }
    }

    public function computeSlug(array $data): string
    {
        return $this->buildSlug($data);
    }

    /**
     * Before flushing a batch: ensure every MainEffect in this batch (new or existing)
     * has a safe DB representation with no composite collision.
     *
     * - New effects (id=null): INSERT via DBAL, then load the managed entity.
     * - Existing effects modified this batch: detect UPDATE collisions via COALESCE SELECT.
     * In both cases, detach the stale Doctrine entity and redirect CardGroup references
     * to the correct DB-backed entity. This prevents uniq_main_effect_texts violations
     * from both INSERT and UPDATE paths.
     */
    public function reconcileNewEffects(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();

        // Pass 1 — in-memory dedup.
        // Two effects in the same batch can independently get the same text combination
        // (e.g. different abilityKeys whose translated texts happen to be identical).
        // Doctrine would then try to UPDATE/INSERT both rows, violating uniq_main_effect_texts.
        // Fix: detect these duplicates before hitting the DB and merge them into one entity.
        $textToCanonical = [];
        foreach ($this->effectCache as $cacheKey => $effect) {
            // Same skip as Pass 2: managed effects not touched this batch can't collide.
            // Also avoids triggering lazy-load on Doctrine proxy references.
            if ($effect->getId() !== null && !isset($this->dirtyEffects[spl_object_id($effect)])) {
                continue;
            }

            $comboKey = sprintf('%s|%s|%s|%s|%s',
                $effect->getTextFr() ?? '',
                $effect->getTextEn() ?? '',
                $effect->getTextDe() ?? '',
                $effect->getTextEs() ?? '',
                $effect->getTextIt() ?? '',
            );

            if ($comboKey === '||||') {
                continue;
            }

            if (isset($textToCanonical[$comboKey])) {
                $canonical = $textToCanonical[$comboKey];
                if ($canonical === $effect) {
                    continue; // same object under two cache keys — nothing to merge
                }
                $em->detach($effect);

                foreach ($this->groupCache as $group) {
                    if ($group->getEffect1() === $effect) $group->setEffect1($canonical);
                    if ($group->getEffect2() === $effect) $group->setEffect2($canonical);
                    if ($group->getEffect3() === $effect) $group->setEffect3($canonical);
                    if ($group->getEchoEffect1() === $effect) $group->setEchoEffect1($canonical);
                }

                $this->effectCache[$cacheKey] = $canonical;
            } else {
                $textToCanonical[$comboKey] = $effect;
            }
        }

        // Pass 2 — DB dedup: match each effect against its DB row by text combo.
        $selectSql = "SELECT id FROM main_effect
                      WHERE COALESCE(text_fr,'') = :fr AND COALESCE(text_en,'') = :en
                        AND COALESCE(text_de,'') = :de AND COALESCE(text_es,'') = :es
                        AND COALESCE(text_it,'') = :it
                      LIMIT 1";

        foreach ($this->effectCache as $text => $effect) {
            // Managed effects whose text wasn't touched this batch can't violate the constraint.
            if ($effect->getId() !== null && !isset($this->dirtyEffects[spl_object_id($effect)])) {
                continue;
            }

            $params = [
                'fr' => $effect->getTextFr() ?? '',
                'en' => $effect->getTextEn() ?? '',
                'de' => $effect->getTextDe() ?? '',
                'es' => $effect->getTextEs() ?? '',
                'it' => $effect->getTextIt() ?? '',
            ];

            if ($params === ['fr' => '', 'en' => '', 'de' => '', 'es' => '', 'it' => '']) {
                continue;
            }

            $foundId = $conn->fetchOne($selectSql, $params);

            if (!$foundId && $effect->getId() === null) {
                // New effect: INSERT via DBAL. The DO UPDATE is a no-op that forces
                // RETURNING to fire even on conflict, giving us the canonical row id
                // without a separate SELECT that could miss when text combos differ.
                $foundId = $conn->fetchOne(
                    'INSERT INTO main_effect (text_fr, text_en, text_de, text_es, text_it, keywords, ability_key)
                     VALUES (:fr_v, :en_v, :de_v, :es_v, :it_v, :kw, :ak)
                     ON CONFLICT (COALESCE(text_fr,\'\'), COALESCE(text_en,\'\'), COALESCE(text_de,\'\'), COALESCE(text_es,\'\'), COALESCE(text_it,\'\'))
                     DO UPDATE SET text_fr = main_effect.text_fr
                     RETURNING id',
                    [
                        'fr_v' => $effect->getTextFr(),
                        'en_v' => $effect->getTextEn(),
                        'de_v' => $effect->getTextDe(),
                        'es_v' => $effect->getTextEs(),
                        'it_v' => $effect->getTextIt(),
                        'kw'   => $effect->getKeywords() !== null ? json_encode($effect->getKeywords()) : null,
                        'ak'   => $effect->getAbilityKey(),
                    ]
                );
            }

            if (!$foundId) {
                continue;
            }

            $foundId = (int) $foundId;

            // No conflict: this entity IS the row that matches its composite
            if ($effect->getId() === $foundId) {
                continue;
            }

            // Conflict — detach stale entity, load the canonical row
            $em->detach($effect);

            $managed = $em->find(MainEffect::class, $foundId);
            if ($managed === null) {
                continue;
            }

            foreach ($this->groupCache as $group) {
                if ($group->getEffect1() === $effect) $group->setEffect1($managed);
                if ($group->getEffect2() === $effect) $group->setEffect2($managed);
                if ($group->getEffect3() === $effect) $group->setEffect3($managed);
                if ($group->getEchoEffect1() === $effect) $group->setEchoEffect1($managed);
            }

            $this->effectCache[$text] = $managed;
        }

        $this->newEffects = [];

        // Flush keywords for proxy-based effects in one batch UPDATE per chunk.
        if (!empty($this->pendingKeywords)) {
            $conn   = $em->getConnection();
            $chunks = array_chunk($this->pendingKeywords, 500, preserve_keys: true);
            foreach ($chunks as $chunk) {
                $values = [];
                $params = [];
                $i      = 0;
                foreach ($chunk as $id => $keywords) {
                    $values[]        = "(:id_{$i}::int, :kw_{$i}::jsonb)";
                    $params["id_{$i}"] = $id;
                    $params["kw_{$i}"] = json_encode($keywords);
                    $i++;
                }
                $conn->executeStatement(
                    sprintf(
                        'UPDATE main_effect SET keywords = v.kw
                         FROM (VALUES %s) AS v(id, kw)
                         WHERE main_effect.id = v.id AND main_effect.keywords IS NULL',
                        implode(', ', $values),
                    ),
                    $params,
                );
            }
            $this->pendingKeywords = [];
        }

        // Safety net: any effect still not managed by Doctrine (detached or new) gets re-attached.
        // This covers edge cases where the redirect above missed an entity.
        foreach ($this->groupCache as $group) {
            foreach (['getEffect1', 'getEffect2', 'getEffect3', 'getEchoEffect1'] as $getter) {
                $fx = $group->{$getter}();
                if ($fx === null || $em->contains($fx)) {
                    continue;
                }
                $fxId = $fx->getId();
                $setter = 'set' . substr($getter, 3);
                if ($fxId !== null) {
                    $managed = $em->find(MainEffect::class, $fxId);
                    if ($managed !== null) {
                        $group->{$setter}($managed);
                    }
                } else {
                    $em->persist($fx);
                }
            }
        }
    }

    /**
     * Find or create the CardGroup for the given card data.
     * The slug is derived from faction code + zero-padded card number extracted from the reference.
     */
    public function findOrCreate(array $data): CardGroup
    {
        $slug = $this->buildSlug($data);

        if (isset($this->groupCache[$slug])) {
            return $this->groupCache[$slug];
        }

        $group = $this->cardGroupRepository->findOneBy(['slug' => $slug]);
        if (!$group) {
            $group = new CardGroup();
            $group->setSlug($slug);
        }

        return $this->groupCache[$slug] = $group;
    }

    public function build(CardGroup $group, array $data, string $locale): CardGroup
    {
        $cacheKey = $group->getSlug() . ':' . $locale;
        if (isset($this->builtCache[$cacheKey])) {
            return $group;
        }
        $this->builtCache[$cacheKey] = true;

        $language = explode('-', $locale)[0];

        // Gameplay flags — only written when present in data (translation-only payloads omit these)
        if (array_key_exists('isBanned', $data)) $group->setIsBanned((bool) $data['isBanned']);
        if (array_key_exists('isErrated', $data)) $group->setIsErrated((bool) $data['isErrated']);
        if (array_key_exists('isSuspended', $data)) $group->setIsSuspended((bool) $data['isSuspended']);

        if (!$group->getRarity() && isset($data['rarity']['reference'])) {
            $rarity = $this->rarityRepository->findOneByReference($data['rarity']['reference']);
            if ($rarity) {
                $group->setRarity($rarity);
            }
        }

        if (array_key_exists('cardHistoryStatus', $data)) {
            if (isset($data['cardHistoryStatus']['reference'])) {
                $group->setCardHistoryStatus($this->findOrCreateHistoryStatus($data['cardHistoryStatus'], $locale));
            } else {
                $group->setCardHistoryStatus(null);
            }
        }

        if (array_key_exists('cardType', $data) && isset($data['cardType']['reference'])) {
            $group->setCardType($this->findOrCreateCardType($data['cardType'], $locale));
        }

        if (array_key_exists('cardSubTypes', $data) && is_array($data['cardSubTypes'])) {
            foreach ($data['cardSubTypes'] as $subTypeData) {
                if (!isset($subTypeData['reference'])) continue;
                $group->addSubType($this->findOrCreateCardSubType($subTypeData, $locale));
            }
        }

        if (isset($data['mainFaction']['reference'])) {
            $code = $data['mainFaction']['reference'];
            $this->factionCache[$code] ??= $this->factionRepository->findOneByCode($code);
            if ($this->factionCache[$code]) {
                $group->setFaction($this->factionCache[$code]);
            }
        }

        // Gameplay stats — set from en-us (canonical) only
        if ($locale === 'en-us') {
            if (array_key_exists('elements', $data)) {
                $elements = $data['elements'];
                $group->setMainCost($this->parseCost($elements['MAIN_COST'] ?? null));
                $group->setRecallCost($this->parseCost($elements['RECALL_COST'] ?? null));
                $group->setOceanPower($this->parseCost($elements['OCEAN_POWER'] ?? null));
                $group->setMountainPower($this->parseCost($elements['MOUNTAIN_POWER'] ?? null));
                $group->setForestPower($this->parseCost($elements['FOREST_POWER'] ?? null));
                $group->setDisplayOceanPower(isset($elements['OCEAN_POWER']) ? (string) $elements['OCEAN_POWER'] : null);
                $group->setDisplayMountainPower(isset($elements['MOUNTAIN_POWER']) ? (string) $elements['MOUNTAIN_POWER'] : null);
                $group->setDisplayForestPower(isset($elements['FOREST_POWER']) ? (string) $elements['FOREST_POWER'] : null);
                $group->setPermanent($elements['PERMANENT'] ?? null);

                // MAIN_EFFECT_KEYS carries cardEffect.reference values from Equinox JSON (abilityKey lookup).
                // UNIQUE cards have no MAIN_EFFECT text but always carry MAIN_EFFECT_KEYS.
                if (array_key_exists('MAIN_EFFECT', $elements) || !empty($elements['MAIN_EFFECT_KEYS'])) {
                    $keys         = $elements['MAIN_EFFECT_KEYS'] ?? [];
                    $displayTexts = $elements['MAIN_EFFECT_DISPLAY_TEXTS'] ?? null;

                    if ($displayTexts !== null) {
                        // Use per-display texts: number of effect slots = number of displays.
                        // Avoids misalignment when elements.MAIN_EFFECT has more double-space
                        // segments than there are registered displays (e.g. GIGANTIC + inline {J}).
                        $group->setEffect1($this->findOrCreateEffect($displayTexts[0] ?? null, 'en', $keys[0] ?? null));
                        $group->setEffect2(array_key_exists(1, $displayTexts) ? $this->findOrCreateEffect($displayTexts[1], 'en', $keys[1] ?? null) : null);
                        $group->setEffect3(array_key_exists(2, $displayTexts) ? $this->findOrCreateEffect($displayTexts[2], 'en', $keys[2] ?? null) : null);
                    } else {
                        $parts = array_key_exists('MAIN_EFFECT', $elements)
                            ? array_values(array_filter(array_map('trim', explode('  ', $elements['MAIN_EFFECT']))))
                            : [];
                        // When keys are available their count is authoritative — the text split
                        // can produce more segments than actual slots (e.g. internal double-spaces
                        // in GIGANTIC-style effects). Fall back to text-count only when no keys.
                        $slotCount = !empty($keys) ? count($keys) : count($parts);
                        $group->setEffect1($this->findOrCreateEffect($parts[0] ?? null, 'en', $keys[0] ?? null));
                        $group->setEffect2($slotCount >= 2 ? $this->findOrCreateEffect($parts[1] ?? null, 'en', $keys[1] ?? null) : null);
                        $group->setEffect3($slotCount >= 3 ? $this->findOrCreateEffect($parts[2] ?? null, 'en', $keys[2] ?? null) : null);
                    }
                }

                if (array_key_exists('ECHO_EFFECT', $elements) || !empty($elements['ECHO_EFFECT_KEYS'])) {
                    $echoParts = array_key_exists('ECHO_EFFECT', $elements)
                        ? array_values(array_filter(array_map('trim', explode('  ', $elements['ECHO_EFFECT']))))
                        : [];
                    $echoKeys  = $elements['ECHO_EFFECT_KEYS'] ?? [];
                    $resolved  = $this->findOrCreateEffect($echoParts[0] ?? null, 'en', $echoKeys[0] ?? null);
                    if ($resolved !== null) {
                        $group->setEchoEffect1($resolved);
                    }
                }
            }
        }

        // CardGroup translation (name + effects per locale)
        $translation = $group->getTranslation($language);
        if (!$translation) {
            $translation = new CardGroupTranslation();
            $translation->setLocale($language);
            $translation->setCardGroup($group);
            $group->getTranslations()->add($translation);
        }

        if (array_key_exists('name', $data)) {
            $translation->setName($data['name']);
        }

        if (array_key_exists('elements', $data)) {
            $elements = $data['elements'];
            if (array_key_exists('MAIN_EFFECT', $elements)) {
                $translation->setMainEffect($elements['MAIN_EFFECT']);

                $parts  = array_values(array_filter(array_map('trim', explode('  ', $elements['MAIN_EFFECT']))));
                $setter = 'setText' . ucfirst($language);

                foreach ([
                    [$parts[0] ?? null, $group->getEffect1()],
                    [$parts[1] ?? null, $group->getEffect2()],
                    [$parts[2] ?? null, $group->getEffect3()],
                ] as [$part, $fx]) {
                    // Skip effects with an ID — they're pre-populated with all locale texts.
                    // Calling getText*() on a Doctrine proxy triggers lazy-load; getId() does not.
                    if ($part === null || $fx === null || $fx->getId() !== null) {
                        continue;
                    }
                    $fx->{$setter}($part);
                    $this->dirtyEffects[spl_object_id($fx)] = true;
                }
            } else {
                $translation->setMainEffect(null);
                // Only clear effect links when there are no MAIN_EFFECT_KEYS either.
                // UNIQUE cards have no MAIN_EFFECT text but carry keys — effects linked above must not be erased.
                if ($locale === 'en-us' && empty($elements['MAIN_EFFECT_KEYS'])) {
                    $group->setEffect1(null);
                    $group->setEffect2(null);
                    $group->setEffect3(null);
                }
            }
            if (array_key_exists('ECHO_EFFECT', $elements)) {
                $translation->setEchoEffect($elements['ECHO_EFFECT']);

                $echoParts = array_values(array_filter(array_map('trim', explode('  ', $elements['ECHO_EFFECT']))));
                $setter    = 'setText' . ucfirst($language);

                $fx = $group->getEchoEffect1();
                if (($echoParts[0] ?? null) !== null && $fx !== null && $fx->getId() === null) {
                    $fx->{$setter}($echoParts[0]);
                    $this->dirtyEffects[spl_object_id($fx)] = true;
                }
            } else {
                $translation->setEchoEffect(null);
                if ($locale === 'en-us' && array_key_exists('ECHO_EFFECT_KEYS', $elements) && empty($elements['ECHO_EFFECT_KEYS'])) {
                    $group->setEchoEffect1(null);
                }
            }
        }

        // Rulings
        if (array_key_exists('cardRulings', $data)) {
            $this->buildCardRulings($group, $data['cardRulings'], $locale);
        }

        // Lore entries
        if (array_key_exists('loreEntries', $data)) {
            $this->buildLoreEntries($group, $data['loreEntries'], $locale);
        }

        return $group;
    }

    private function buildSlug(array $data): string
    {
        // Reference format: ALT_CORE_B_OR_17_U_185
        // parts[3]=faction, parts[4]=card number, parts[5]=rarity, parts[6]=variant (U only)
        $parts      = explode('_', $data['reference'] ?? '');
        $faction    = strtoupper($parts[3] ?? ($data['mainFaction']['reference'] ?? 'NEUTRAL'));
        $cardNumber = (int) ($parts[4] ?? 0);
        $rarity     = strtoupper($parts[5] ?? 'C');

        // Unique cards: each variant has its own CardGroup (different effects per copy)
        // CORE and COREKS uniques get a set suffix so they never share a CardGroup
        // (Equinox COREKS data has wrong mainFaction for some uniques, causing slug collisions)
        if ($rarity === 'U' && isset($parts[6]) && $parts[6] !== '') {
            $set    = strtoupper($parts[1] ?? '');
            $suffix = in_array($set, ['CORE', 'COREKS'], true) ? '-' . $set : '';
            return sprintf('%s-%03d-U-%s%s', $faction, $cardNumber, $parts[6], $suffix);
        }

        // Common / Rare: group all reprints together
        return sprintf('%s-%03d-%s', $faction, $cardNumber, $rarity);
    }

    private function buildCardRulings(CardGroup $group, array $rulings, string $locale): void
    {
        $language = explode('-', $locale)[0];

        foreach ($group->getCardRulings() as $existing) {
            if ($existing->getLocale() === $language) {
                $group->getCardRulings()->removeElement($existing);
            }
        }

        foreach ($rulings as $rulingData) {
            $ruling = new CardRuling();
            $ruling->setCardGroup($group);
            $ruling->setLocale($language);
            $ruling->setQuestion($rulingData['question'] ?? '');
            $ruling->setAnswer($rulingData['answer'] ?? '');
            $ruling->setEventFormat($rulingData['eventFormat'] ?? null);

            if (isset($rulingData['createdAt'])) {
                try {
                    $ruling->setRulingDate(new \DateTimeImmutable($rulingData['createdAt']));
                } catch (\Exception) {}
            }

            $group->getCardRulings()->add($ruling);
        }
    }

    private function buildLoreEntries(CardGroup $group, array $entries, string $locale): void
    {
        $language = explode('-', $locale)[0];

        foreach ($entries as $entryData) {
            $alteredId = $entryData['id'] ?? null;
            if (!$alteredId) continue;

            // Find existing lore entry for this group+alteredId+locale
            $entry = null;
            foreach ($group->getLoreEntries() as $existing) {
                if ($existing->getAlteredId() === $alteredId && $existing->getLocale() === $language) {
                    $entry = $existing;
                    break;
                }
            }

            if (!$entry) {
                $entry = new LoreEntry();
                $entry->setAlteredId($alteredId);
                $entry->setLocale($language);
                $entry->setCardGroup($group);
                $group->getLoreEntries()->add($entry);
            }

            $entry->setType($entryData['loreEntryType']['reference'] ?? null);

            $elements = [];
            foreach ($entryData['loreEntryElements'] ?? [] as $element) {
                $elements[] = [
                    'type' => $element['loreEntryElementType']['reference'] ?? null,
                    'text' => $element['text'] ?? null,
                ];
            }
            $entry->setElements($elements);
        }
    }

    private function findOrCreateEffect(?string $text, string $locale, ?string $abilityKey = null): ?MainEffect
    {
        if ($text === null && $abilityKey === null) return null;

        $text     = $text !== null ? trim($text) : null;
        $cacheKey = $abilityKey ?? $text;

        if ($cacheKey === '' || $cacheKey === null) return null;

        if (isset($this->effectCache[$cacheKey])) {
            return $this->effectCache[$cacheKey];
        }

        // Fast path: effect pre-populated by app:import:abilities:equinox — use a proxy,
        // no entity hydration, no identity-map lookup, no DB query.
        if ($abilityKey !== null && isset($this->effectIdCache[$abilityKey])) {
            $id     = $this->effectIdCache[$abilityKey];
            $effect = $this->em->getReference(MainEffect::class, $id);

            if ($locale === 'en' && $text !== null) {
                $keywords = $this->effectParser->parseKeywords($text);
                if (!empty($keywords)) {
                    $this->pendingKeywords[$id] ??= $keywords;
                }
            }

            return $this->effectCache[$cacheKey] = $effect;
        }

        // Fallback ORM path (effect not pre-populated or no abilityKey)
        $effect = null;
        if ($abilityKey !== null) {
            $effect = $this->mainEffectRepository->findOneByAbilityKey($abilityKey);
        }

        if ($effect === null && $text !== null) {
            $finder = 'findOneByText' . ucfirst($locale);
            $effect = $this->mainEffectRepository->{$finder}($text);
        }

        if ($effect === null) {
            if ($text === null) {
                return null;
            }
            $effect = new MainEffect();
            if ($abilityKey !== null) {
                $effect->setAbilityKey($abilityKey);
            }
            $this->newEffects[$cacheKey] = $effect;
        }

        if ($text !== null) {
            $getter = 'getText' . ucfirst($locale);
            if ($effect->{$getter}() === null) {
                $setter = 'setText' . ucfirst($locale);
                $effect->{$setter}($text);
                $this->dirtyEffects[spl_object_id($effect)] = true;
            }
        }

        if ($locale === 'en' && $text !== null && $effect->getKeywords() === null) {
            $keywords = $this->effectParser->parseKeywords($text);
            $effect->setKeywords($keywords ?: null);
        }

        return $this->effectCache[$cacheKey] = $effect;
    }

    /**
     * PERMANENT and LANDMARK_PERMANENT were the same design intent in practice and have
     * been folded into one type — Equinox JSON can still carry the raw "PERMANENT"
     * reference for older cards, so every import path is normalized here rather than
     * relying on each caller to know about the merge.
     */
    private function normalizeCardTypeReference(string $reference): string
    {
        return $reference === 'PERMANENT' ? 'LANDMARK_PERMANENT' : $reference;
    }

    private function findOrCreateCardType(array $data, string $locale): CardType
    {
        $reference = $this->normalizeCardTypeReference($data['reference']);

        if (!isset($this->cardTypeCache[$reference])) {
            $cardType = $this->cardTypeRepository->findOneByReference($reference);
            if (!$cardType) {
                $cardType = new CardType();
                $cardType->setReference($reference);
            }
            $this->cardTypeCache[$reference] = $cardType;
        }

        $cardType = $this->cardTypeCache[$reference];
        $language = explode('-', $locale)[0];
        $setter   = 'setName' . ucfirst($language);
        if (isset($data['name']) && method_exists($cardType, $setter)) {
            $cardType->{$setter}($data['name']);
        }

        return $cardType;
    }

    private function findOrCreateCardSubType(array $data, string $locale): CardSubType
    {
        $reference = $data['reference'];

        if (!isset($this->subTypeCache[$reference])) {
            $subType = $this->cardSubTypeRepository->findOneByReference($reference);
            if (!$subType) {
                $subType = new CardSubType();
                $subType->setReference($reference);
            }
            $this->subTypeCache[$reference] = $subType;
        }

        $subType  = $this->subTypeCache[$reference];
        $language = explode('-', $locale)[0];
        $setter   = 'setName' . ucfirst($language);
        if (isset($data['name']) && method_exists($subType, $setter)) {
            $subType->{$setter}($data['name']);
        }

        return $subType;
    }

    private function parseCost(?string $value): ?int
    {
        if ($value === null) return null;
        $numeric = preg_replace('/\D/', '', $value);
        return $numeric !== '' ? (int) $numeric : null;
    }

    private function findOrCreateHistoryStatus(array $data, string $locale): CardHistoryStatus
    {
        $reference = $data['reference'];

        if (!isset($this->historyStatusCache[$reference])) {
            $status = $this->cardHistoryStatusRepository->findOneByReference($reference);
            if (!$status) {
                $status = new CardHistoryStatus();
                $status->setReference($reference);
            }
            $this->historyStatusCache[$reference] = $status;
        }

        $status   = $this->historyStatusCache[$reference];
        $language = explode('-', $locale)[0];
        $setter   = 'setName' . ucfirst($language);
        if (isset($data['name']) && method_exists($status, $setter)) {
            $status->{$setter}($data['name']);
        }

        return $status;
    }
}
