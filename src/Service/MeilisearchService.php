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
        'variation',
    ];

    private Client $client;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CardDocumentRepository $cardDocumentRepository,
        private readonly string $url,
        private readonly string $apiKey,
        private readonly bool $enabled,
    ) {
        if (!$enabled)
            $this->client = new Client($url, $apiKey, new Psr18Client($httpClient));
    }

    public function isEnable(): bool
    {
        return $this->enabled;
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
     * Search and return matching card IDs.
     *
     * @param string[] $attributesToSearchOn  Restrict search to specific fields (e.g. locale-specific)
     * @return int[]
     */
    public function searchIds(string $query = '', array $attributesToSearchOn = [], int $limit = 10000): array
    {
        $params = [
            'limit'                => $limit,
            'attributesToRetrieve' => ['id'],
        ];

        if (!empty($attributesToSearchOn)) {
            $params['attributesToSearchOn'] = $attributesToSearchOn;
        }

        $results = $this->getIndex()->search($query ?: null, $params);

        return array_column($results->getHits(), 'id');
    }
}
