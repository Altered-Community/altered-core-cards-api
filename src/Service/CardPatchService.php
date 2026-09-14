<?php

namespace App\Service;

use App\Entity\Card;
use App\Entity\CardGroup;
use App\Entity\CardPatchLog;
use App\Entity\CardTranslation;
use App\EventListener\CardSearchListener;
use App\EventListener\MeilisearchSyncListener;
use App\Repository\CardDocumentRepository;
use App\Repository\CardPatchLogRepository;
use App\Repository\CardRepository;
use App\Repository\CardSubTypeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies dated card-patch files (datas/card_patches/YYYY-MM-DD__description.json) to
 * Card/CardGroup, tracking what's already been applied in card_patch_log so a reimport
 * only ever processes files it hasn't seen yet, in filename (date) order.
 *
 * Fields are routed to the entity that actually owns them:
 *  - CardPatchValidator::CARD_FIELDS            -> Card, exact reference only.
 *  - CardPatchValidator::CARD_GROUP_FIELDS      -> CardGroup, exact or trailing-wildcard reference.
 *  - CardPatchValidator::LOCALIZED_CARD_FIELDS  -> Card's per-locale CardTranslation, exact reference only.
 *  - CardPatchValidator::LIST_CARD_GROUP_FIELDS -> CardGroup, exact or trailing-wildcard reference,
 *    applied as a full replacement of the existing list.
 * A wildcard match can span many Card rows sharing the same CardGroup (e.g. every
 * serialized instance of a unique) — those are deduplicated before writing.
 */
final readonly class CardPatchService
{
    private const string PATCH_DIR = __DIR__ . '/../../datas/card_patches';

    public function __construct(
        private CardRepository $cardRepository,
        private CardPatchLogRepository $cardPatchLogRepository,
        private CardPatchValidator $validator,
        private CardSearchUpdater $cardSearchUpdater,
        private MeilisearchService $meilisearch,
        private CardDocumentRepository $cardDocumentRepository,
        private CardSubTypeRepository $cardSubTypeRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /** @return string[] sorted basenames of *.json files in the patch directory */
    public function listPatchFiles(): array
    {
        if (!is_dir(self::PATCH_DIR)) {
            return [];
        }

        $names = array_map('basename', glob(self::PATCH_DIR . '/*.json') ?: []);
        sort($names);

        return $names;
    }

    /** @return string[] validation errors; empty means the file is valid */
    public function validateFile(string $filename): array
    {
        $content = @file_get_contents(self::PATCH_DIR . '/' . $filename);
        if ($content === false) {
            return ['Impossible de lire le fichier.'];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['JSON invalide : ' . json_last_error_msg()];
        }

        return $this->validator->validate($data, $filename);
    }

    /** @return CardPatchApplyResult[] */
    public function applyPending(bool $dryRun = false, bool $force = false, ?string $onlyFile = null): array
    {
        $results = [];
        foreach ($this->listPatchFiles() as $filename) {
            if ($onlyFile !== null && $filename !== $onlyFile) {
                continue;
            }

            $results[] = $this->applyFile($filename, $dryRun, $force);
        }

        return $results;
    }

    private function applyFile(string $filename, bool $dryRun, bool $force): CardPatchApplyResult
    {
        $existing = $this->cardPatchLogRepository->findByFilename($filename);
        if ($existing !== null && !$force) {
            return CardPatchApplyResult::alreadyApplied($filename);
        }

        $content = @file_get_contents(self::PATCH_DIR . '/' . $filename);
        if ($content === false) {
            return CardPatchApplyResult::invalid($filename, ['Impossible de lire le fichier.']);
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return CardPatchApplyResult::invalid($filename, ['JSON invalide : ' . json_last_error_msg()]);
        }

        $errors = $this->validator->validate($data, $filename);
        if ($errors !== []) {
            return CardPatchApplyResult::invalid($filename, $errors);
        }

        [$rowsUpdated, $rowsSkipped, $touchedCardGroupIds, $wildcardMatches] =
            $this->resolveUpdates($data['updates'], applyChanges: !$dryRun);

        if ($dryRun) {
            return CardPatchApplyResult::applied($filename, $rowsUpdated, $rowsSkipped, $wildcardMatches);
        }

        $log = $existing ?? new CardPatchLog();
        $log->setFilename($filename)
            ->setChecksum(hash('sha256', $content))
            ->setAppliedAt(new \DateTimeImmutable())
            ->setRowsUpdated($rowsUpdated)
            ->setRowsSkipped($rowsSkipped);

        MeilisearchSyncListener::$disabled = true;
        CardSearchListener::$disabled      = true;
        try {
            $this->em->persist($log);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent run committed this exact file first — nothing left to do here.
            return CardPatchApplyResult::alreadyApplied($filename);
        } finally {
            MeilisearchSyncListener::$disabled = false;
            CardSearchListener::$disabled      = false;
        }

        $this->resyncMeilisearch($touchedCardGroupIds);

        return CardPatchApplyResult::applied($filename, $rowsUpdated, $rowsSkipped, $wildcardMatches);
    }

    /**
     * @param array<int, array{reference: string, fields: array<string, mixed>}> $updates
     * @return array{0: int, 1: int, 2: int[], 3: array<string, string[]>}
     */
    private function resolveUpdates(array $updates, bool $applyChanges): array
    {
        $rowsUpdated         = 0;
        $rowsSkipped         = 0;
        $touchedCardGroupIds = [];
        $wildcardMatches     = [];

        $exactUpdates    = array_values(array_filter($updates, static fn(array $u) => !str_contains($u['reference'], '*')));
        $wildcardUpdates = array_values(array_filter($updates, static fn(array $u) => str_contains($u['reference'], '*')));

        if ($exactUpdates !== []) {
            $cards = $this->cardRepository->findByReferences(array_column($exactUpdates, 'reference'));

            foreach ($exactUpdates as $update) {
                $card = $cards[$update['reference']] ?? null;
                if ($card === null) {
                    ++$rowsSkipped;
                    continue;
                }

                if ($applyChanges) {
                    $this->applyFieldsToCard($card, $update['fields'], $touchedCardGroupIds);
                }
                ++$rowsUpdated;
            }
        }

        foreach ($wildcardUpdates as $update) {
            $pattern = $update['reference'];
            $cards   = $this->cardRepository->findByReferenceLike($this->toLikePattern($pattern));

            $wildcardMatches[$pattern] = array_map(static fn(Card $c) => $c->getReference(), $cards);

            if ($cards === []) {
                ++$rowsSkipped;
                continue;
            }

            $seenGroupIds = [];
            foreach ($cards as $card) {
                $cardGroup = $card->getCardGroup();
                if ($cardGroup === null || isset($seenGroupIds[$cardGroup->getId()])) {
                    continue;
                }
                $seenGroupIds[$cardGroup->getId()] = true;

                if ($applyChanges) {
                    foreach ($update['fields'] as $field => $value) {
                        if (in_array($field, CardPatchValidator::LIST_CARD_GROUP_FIELDS, true)) {
                            $this->setCardGroupListField($cardGroup, $field, $value);
                            continue;
                        }
                        $this->setCardGroupField($cardGroup, $field, $value);
                    }
                    $touchedCardGroupIds[] = $cardGroup->getId();
                }
                ++$rowsUpdated;
            }
        }

        return [$rowsUpdated, $rowsSkipped, array_values(array_unique($touchedCardGroupIds)), $wildcardMatches];
    }

    /** @param int[] $touchedCardGroupIds */
    private function applyFieldsToCard(Card $card, array $fields, array &$touchedCardGroupIds): void
    {
        foreach ($fields as $field => $value) {
            if (in_array($field, CardPatchValidator::LOCALIZED_CARD_FIELDS, true)) {
                $this->setCardLocalizedField($card, $field, $value);
                if ($cardGroup = $card->getCardGroup()) {
                    $touchedCardGroupIds[] = $cardGroup->getId();
                }
                continue;
            }

            if (in_array($field, CardPatchValidator::LIST_CARD_GROUP_FIELDS, true)) {
                $cardGroup = $card->getCardGroup();
                if ($cardGroup === null) {
                    continue;
                }
                $this->setCardGroupListField($cardGroup, $field, $value);
                $touchedCardGroupIds[] = $cardGroup->getId();
                continue;
            }

            if (in_array($field, CardPatchValidator::CARD_GROUP_FIELDS, true)) {
                $cardGroup = $card->getCardGroup();
                if ($cardGroup === null) {
                    continue;
                }
                $this->setCardGroupField($cardGroup, $field, $value);
                $touchedCardGroupIds[] = $cardGroup->getId();
                continue;
            }

            $this->setCardField($card, $field, $value);
        }
    }

    private function setCardField(Card $card, string $field, mixed $value): void
    {
        match ($field) {
            'collectorNumberFormatedId' => $card->setCollectorNumberFormatedId($value),
            'lowerPrice'                => $card->setLowerPrice($value),
            'cardProduct'               => $card->setCardProduct($value),
            'isPublic'                  => $card->setIsPublic($value),
            'isExclusive'               => $card->setIsExclusive($value),
            default => throw new \LogicException("Champ Card non géré par CardPatchService : $field"),
        };
    }

    private function setCardGroupField(CardGroup $cardGroup, string $field, mixed $value): void
    {
        match ($field) {
            'isBanned'    => $cardGroup->setIsBanned($value),
            'isSuspended' => $cardGroup->setIsSuspended($value),
            'isErrated'   => $cardGroup->setIsErrated($value),
            default => throw new \LogicException("Champ CardGroup non géré par CardPatchService : $field"),
        };
    }

    /** @param array<string, string> $localizedValues locale (fr_FR, en_US, ...) => text */
    private function setCardLocalizedField(Card $card, string $field, array $localizedValues): void
    {
        match ($field) {
            'name' => $this->setCardName($card, $localizedValues),
            default => throw new \LogicException("Champ localisé non géré par CardPatchService : $field"),
        };
    }

    /** @param array<string, string> $localizedNames locale (fr_FR, en_US, ...) => name */
    private function setCardName(Card $card, array $localizedNames): void
    {
        foreach ($localizedNames as $localeKey => $name) {
            $locale      = $this->shortLocale($localeKey);
            $translation = $card->getTranslation($locale);

            if ($translation === null) {
                $translation = new CardTranslation();
                $translation->setLocale($locale);
                $translation->setCard($card);
                $card->addTranslation($translation);
            }

            $translation->setName($name);
        }
    }

    /** @param string[] $value */
    private function setCardGroupListField(CardGroup $cardGroup, string $field, array $value): void
    {
        match ($field) {
            'subTypes' => $this->replaceSubTypes($cardGroup, $value),
            default => throw new \LogicException("Champ liste non géré par CardPatchService : $field"),
        };
    }

    /** @param string[] $references full replacement of the CardGroup's sub-types */
    private function replaceSubTypes(CardGroup $cardGroup, array $references): void
    {
        foreach ($cardGroup->getSubTypes()->toArray() as $existing) {
            $cardGroup->removeSubType($existing);
        }

        foreach ($references as $reference) {
            $subType = $this->cardSubTypeRepository->findOneByReference($reference);
            if ($subType === null) {
                throw new \LogicException("CardSubType inconnu : $reference");
            }
            $cardGroup->addSubType($subType);
        }
    }

    /** fr_FR / en_US / ... -> fr / en / ... (internal storage format, see CardTranslation::locale) */
    private function shortLocale(string $localeKey): string
    {
        return strtolower(explode('_', $localeKey)[0]);
    }

    /** Translates a trailing-wildcard reference into an escaped SQL LIKE pattern. */
    private function toLikePattern(string $reference): string
    {
        $literal = rtrim($reference, '*');
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $literal);

        return str_ends_with($reference, '*') ? $escaped . '%' : $escaped;
    }

    /** @param int[] $cardGroupIds */
    private function resyncMeilisearch(array $cardGroupIds): void
    {
        if ($cardGroupIds === []) {
            return;
        }

        foreach ($cardGroupIds as $cardGroupId) {
            $this->cardSearchUpdater->upsertByCardGroupId($cardGroupId);
        }

        try {
            $this->meilisearch->indexDocuments(
                $this->cardDocumentRepository->findDocumentsByCardGroupIds($cardGroupIds)
            );
        } catch (\Throwable $e) {
            // The Card/CardGroup write already succeeded in Postgres — a Meilisearch
            // hiccup here shouldn't fail the patch run, just leave the index briefly stale.
            $this->logger->error('Card patch: bulk Meilisearch reindex failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
