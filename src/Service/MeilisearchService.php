<?php

namespace App\Service;

use App\Entity\Card;
use App\Repository\CardDocumentRepository;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MeilisearchService
{
    public const INDEX_NAME = 'cards';

    /**
     * Searchable fields (full-text).
     */
    private const SEARCHABLE_ATTRIBUTES = [
        'name_fr',
        'name_en',
        'main_effect_fr',
        'main_effect_en',
        'echo_effect_fr',
        'echo_effect_en',
        'reference',
        'collector_number_formated_id',
    ];

    /**
     * Sortable fields.
     */
    private const SORTABLE_ATTRIBUTES = [
        'set_date',
        'collector_number_formated_id',
        'main_cost',
        'recall_cost',
        'reference',
        'name_fr',
        'name_en',
    ];

    /**
     * Filterable fields (equality / range filters).
     */
    private const FILTERABLE_ATTRIBUTES = [
        'faction_code',
        'set_reference',
        'rarity',
        'card_type',
        'sub_types',
        'main_cost',
        'recall_cost',
        'ocean_power',
        'mountain_power',
        'forest_power',
        'is_banned',
        'is_suspended',
        'is_errated',
        'is_serialized',
        'kickstarter',
        'promo',
        'transfuge',
        'variation',
        'cost_relation',
        'slot1_trigger', 'slot1_condition', 'slot1_effect',
        'slot2_trigger', 'slot2_condition', 'slot2_effect',
        'slot3_trigger', 'slot3_condition', 'slot3_effect',
        'echo_trigger',  'echo_condition',  'echo_effect',
        'all_triggers', 'all_conditions', 'all_effects',
        'trigger_repeat_count',
        'has_effect',
        'keywords',
        'gameplay_format',
    ];

    private Client $client;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CardDocumentRepository $cardDocumentRepository,
        private readonly string $url,
        private readonly string $apiKey,
    ) {
        $this->client = new Client($url, $apiKey, new Psr18Client($httpClient));
    }

    public function getIndex(): Indexes
    {
        return $this->client->index(self::INDEX_NAME);
    }

    /**
     * Configure searchable + filterable attributes on the index.
     * Call once after index creation (or when config changes).
     */
    public function configureIndex(): void
    {
        $index = $this->getIndex();
        $index->updateSearchableAttributes(self::SEARCHABLE_ATTRIBUTES);
        $index->updateFilterableAttributes(self::FILTERABLE_ATTRIBUTES);
        $index->updateSortableAttributes(self::SORTABLE_ATTRIBUTES);
        // Default maxTotalHits is 1000 — raise it so searches over large name groups
        // (e.g. 1800+ serialized variants of the same card) return all matching IDs.
        $index->updatePagination(['maxTotalHits' => 6000000]);
    }

    /**
     * Index or update a single card.
     */
    public function indexCard(Card $card): void
    {
        $doc = $this->cardDocumentRepository->findDocument($card->getId());
        if ($doc !== null) {
            $this->getIndex()->addDocuments([$doc]);
        }
    }

    /**
     * Push multiple prebuilt documents in a single request (bulk update, e.g. after
     * a batch write that disabled the per-entity MeilisearchSyncListener).
     *
     * @param array<int, array<string, mixed>> $docs
     */
    public function indexDocuments(array $docs): void
    {
        if (empty($docs)) {
            return;
        }
        $this->getIndex()->addDocuments($docs);
    }

    /**
     * Delete a single card from the index.
     */
    public function deleteCard(Card $card): void
    {
        $this->getIndex()->deleteDocument($card->getId());
    }

    public function streamDocuments(int $batchSize = 2000): \Generator
    {
        return $this->cardDocumentRepository->streamDocuments($batchSize);
    }

    /**
     * Return facet distribution for triggers, conditions and effects
     * matching the given filter (same filter string as searchIds).
     *
     * Returns ['triggers' => [alteredId => count], 'conditions' => [...], 'effects' => [...]]
     */
    public function getFacets(?string $filter = null): array
    {
        $params = [
            'limit'  => 0,
            'facets' => ['all_triggers', 'all_conditions', 'all_effects'],
        ];

        if ($filter !== null) {
            $params['filter'] = $filter;
        }

        $results = $this->getIndex()->search(null, $params);
        $dist    = $results->getFacetDistribution() ?? [];

        return [
            'triggers'   => $dist['all_triggers']   ?? [],
            'conditions' => $dist['all_conditions']  ?? [],
            'effects'    => $dist['all_effects']     ?? [],
        ];
    }

    /**
     * Search and return matching card IDs.
     *
     * @param string[] $attributesToSearchOn  Restrict search to specific fields (e.g. locale-specific)
     * @return int[]
     */
    /**
     * @param string[] $attributesToSearchOn
     * @param string[] $sort  e.g. ['set_date:desc', 'collector_number_formated_id:asc']
     * @return int[]
     */
    public function searchIds(string $query = '', array $attributesToSearchOn = [], ?string $filter = null, int $limit = 10000, int $offset = 0, array $sort = []): array
    {
        $params = [
            'limit'                => $limit,
            'attributesToRetrieve' => ['id'],
        ];

        if ($offset > 0) {
            $params['offset'] = $offset;
        }

        if (!empty($attributesToSearchOn)) {
            $params['attributesToSearchOn'] = $attributesToSearchOn;
        }

        if ($filter !== null) {
            $params['filter'] = $filter;
        }

        if (!empty($sort)) {
            $params['sort'] = $sort;
        }

        $results = $this->getIndex()->search($query ?: null, $params);

        return array_column($results->getHits(), 'id');
    }

    /**
     * Estimated total hits for a query+filter combination (limit=0, instant).
     *
     * @param string[] $attributesToSearchOn
     */
    public function countIds(string $query = '', ?string $filter = null, array $attributesToSearchOn = []): int
    {
        $params = ['limit' => 0];

        if ($filter !== null) {
            $params['filter'] = $filter;
        }

        if (!empty($attributesToSearchOn)) {
            $params['attributesToSearchOn'] = $attributesToSearchOn;
        }

        $results = $this->getIndex()->search($query ?: null, $params);

        return $results->getEstimatedTotalHits() ?? 0;
    }
}
