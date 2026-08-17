<?php

namespace App\Service;

use App\EventListener\CardSearchListener;
use App\EventListener\MeilisearchSyncListener;
use App\Repository\CardDocumentRepository;
use App\Repository\CardGroupRepository;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports a gameplay-format definition file (e.g. frontier.json, living-legend.json) from a URL.
 *
 * Two manifest shapes are supported:
 *   - allowlist:  { "id": "frontier", "version": 1, "included_refs": ["ALT_..._U_1234", ...] }
 *   - ban-list:   { "id": "living-legend", "version": 1, "excluded_sets": ["CORE", ...], "excluded_refs": ["ALT_..._U_1234", ...] }
 *
 * The shape is auto-detected from which keys are present in the JSON.
 */
final readonly class GameplayFormatImportService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CardRepository $cardRepository,
        private CardGroupRepository $cardGroupRepository,
        private CardDocumentRepository $cardDocumentRepository,
        private CardSearchUpdater $cardSearchUpdater,
        private MeilisearchService $meilisearch,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * Fetches the URL, validates the JSON shape, and resolves included_refs
     * against known Card references. Does not write anything.
     */
    public function fetchAndValidate(string $url): GameplayFormatImportResult
    {
        if (!preg_match('#^https?://#i', $url)) {
            return GameplayFormatImportResult::error("L'URL doit commencer par http:// ou https://.");
        }

        try {
            $data = $this->httpClient->request('GET', $url, ['timeout' => 10, 'max_duration' => 15])->toArray(false);
        } catch (\Throwable $e) {
            return GameplayFormatImportResult::error('Impossible de récupérer le fichier : ' . $e->getMessage());
        }

        $errors = $this->validateShape($data);
        if (!empty($errors)) {
            return GameplayFormatImportResult::error(implode(' ', $errors));
        }

        if (isset($data['included_refs'])) {
            $references = array_values(array_unique($data['included_refs']));
            [$map, $unmatched] = $this->cardRepository->resolveCardGroupIdsByReferences($references);

            return GameplayFormatImportResult::success(
                sourceId: (string) $data['id'],
                version: (int) $data['version'],
                totalRefs: count($references),
                matchedCardGroupIds: array_values(array_unique($map)),
                unmatchedRefs: $unmatched,
            );
        }

        $excludedSetCodes = array_values(array_unique($data['excluded_sets'] ?? []));
        $excludedRefs     = array_values(array_unique($data['excluded_refs'] ?? []));
        [$matchedCardGroupIds, $unmatched] = $this->cardRepository->resolveCardGroupIdsExcluding($excludedSetCodes, $excludedRefs);

        return GameplayFormatImportResult::successExclusion(
            sourceId: (string) $data['id'],
            version: (int) $data['version'],
            totalRefs: count($excludedRefs),
            matchedCardGroupIds: $matchedCardGroupIds,
            unmatchedRefs: $unmatched,
            excludedSetCodes: $excludedSetCodes,
        );
    }

    /**
     * Adds $formatName to the gameplayFormat array of every given CardGroup (idempotent).
     *
     * A CardGroup can have dozens of Card prints (reprints, serialized variants...).
     * The default per-entity listeners (MeilisearchSyncListener, CardSearchListener)
     * would fire once per Card on every touched CardGroup — for a few hundred groups
     * that's thousands of synchronous Meilisearch HTTP calls in one request, which is
     * what caused the 504 on import. Disable them for the bulk write and resync the
     * affected groups in two batched calls instead.
     *
     * @param int[] $cardGroupIds
     * @param bool $resetPrevious When true, CardGroups currently tagged with $formatName
     *                            but absent from $cardGroupIds have the tag removed —
     *                            re-importing then reflects the manifest exactly instead
     *                            of only ever growing the membership.
     * @return int Number of CardGroups actually modified.
     */
    public function apply(string $formatName, array $cardGroupIds, bool $resetPrevious = false): int
    {
        $formatName = strtoupper(trim($formatName));
        if ($formatName === '' || empty($cardGroupIds)) {
            return 0;
        }

        $updatedGroupIds = [];

        MeilisearchSyncListener::$disabled = true;
        CardSearchListener::$disabled      = true;
        try {
            foreach ($this->cardGroupRepository->findBy(['id' => $cardGroupIds]) as $cardGroup) {
                $current = $cardGroup->getGameplayFormat();
                if (!in_array($formatName, $current, true)) {
                    $cardGroup->setGameplayFormat([...$current, $formatName]);
                    $updatedGroupIds[] = $cardGroup->getId();
                }
            }

            if ($resetPrevious) {
                $staleIds = array_diff(
                    $this->cardGroupRepository->findIdsByGameplayFormat($formatName),
                    $cardGroupIds,
                );
                foreach ($this->cardGroupRepository->findBy(['id' => $staleIds]) as $cardGroup) {
                    $cardGroup->setGameplayFormat(array_values(array_diff(
                        $cardGroup->getGameplayFormat(),
                        [$formatName],
                    )));
                    $updatedGroupIds[] = $cardGroup->getId();
                }
            }

            $this->em->flush();
        } finally {
            MeilisearchSyncListener::$disabled = false;
            CardSearchListener::$disabled      = false;
        }

        if (empty($updatedGroupIds)) {
            return 0;
        }

        foreach ($updatedGroupIds as $cardGroupId) {
            $this->cardSearchUpdater->upsertByCardGroupId($cardGroupId);
        }

        try {
            $this->meilisearch->indexDocuments(
                $this->cardDocumentRepository->findDocumentsByCardGroupIds($updatedGroupIds)
            );
        } catch (\Throwable $e) {
            // The gameplayFormat write already succeeded in Postgres — a Meilisearch
            // hiccup here shouldn't fail the import, just leave the index briefly stale.
            $this->logger->error('Gameplay-format import: bulk Meilisearch reindex failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return count($updatedGroupIds);
    }

    /** @return string[] */
    private function validateShape(mixed $data): array
    {
        if (!is_array($data)) {
            return ['Le fichier ne contient pas un objet JSON valide.'];
        }

        $errors = [];

        if (empty($data['id']) || !is_string($data['id'])) {
            $errors[] = 'Champ "id" manquant ou invalide.';
        }

        if (!isset($data['version']) || !is_int($data['version'])) {
            $errors[] = 'Champ "version" manquant ou invalide.';
        }

        $hasIncludedRefs = array_key_exists('included_refs', $data);
        $hasExclusionKeys = array_key_exists('excluded_sets', $data) || array_key_exists('excluded_refs', $data);

        if (!$hasIncludedRefs && !$hasExclusionKeys) {
            $errors[] = 'Le fichier doit contenir soit "included_refs", soit "excluded_sets"/"excluded_refs".';
            return $errors;
        }

        if ($hasIncludedRefs) {
            $errors = [...$errors, ...$this->validateStringArray($data['included_refs'], 'included_refs', allowEmpty: false)];
        } else {
            if (empty($data['excluded_sets']) && empty($data['excluded_refs'])) {
                $errors[] = 'Au moins un des champs "excluded_sets" ou "excluded_refs" doit être non vide.';
            }
            if (array_key_exists('excluded_sets', $data)) {
                $errors = [...$errors, ...$this->validateStringArray($data['excluded_sets'], 'excluded_sets', allowEmpty: true)];
            }
            if (array_key_exists('excluded_refs', $data)) {
                $errors = [...$errors, ...$this->validateStringArray($data['excluded_refs'], 'excluded_refs', allowEmpty: true)];
            }
        }

        return $errors;
    }

    /** @return string[] */
    private function validateStringArray(mixed $value, string $field, bool $allowEmpty): array
    {
        if (!is_array($value) || (!$allowEmpty && empty($value))) {
            return [sprintf('Champ "%s" manquant ou invalide.', $field)];
        }

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                return [sprintf('Le champ "%s" doit contenir uniquement des chaînes non vides.', $field)];
            }
        }

        return [];
    }
}
