<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\FilteredCardCountRepository;
use App\Service\FilterCacheKeyService;
use App\Service\MeilisearchFilterBuilderService;
use App\Service\MeilisearchService;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps CardCollectionProvider and resolves the total item count via Meilisearch
 * when a name (full-text) search is active.
 *
 * Meilisearch is called with limit=0, which returns estimatedTotalHits instantly
 * without fetching any documents. The total is injected into the context under
 * '_meili_total' so CachedCountCollectionProvider can use it directly and skip
 * the expensive Doctrine COUNT query.
 */
final class SearchAwareCollectionProvider implements ProviderInterface
{
    /** API Platform order key → Meilisearch sortable attribute */
    private const ORDER_MAP = [
        'set.date'                  => 'set_date',
        'setDate'                   => 'set_date',
        'collectorNumberFormatedId' => 'collector_number_formated_id',
        'mainCost'                  => 'main_cost',
        'recallCost'                => 'recall_cost',
        'reference'                 => 'reference',
        'name.fr'                   => 'name_fr',
        'name.en'                   => 'name_en',
    ];

    public function __construct(
        private readonly CardCollectionProvider $inner,
        private readonly MeilisearchService $meilisearch,
        private readonly FilterCacheKeyService $cacheKeyService,
        #[Autowire(service: 'cache.card_counts')]
        private readonly CacheItemPoolInterface $cachePool,
        private readonly FilteredCardCountRepository $filteredCountRepo,
        private readonly MeilisearchFilterBuilderService $filterBuilder,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $filters     = $context['filters'] ?? [];
        $nameQuery   = $this->extractNameQuery($filters);
        $meiliFilter = $this->filterBuilder->buildFilter($filters);
        $hasFilters  = $meiliFilter !== null;

        if ($nameQuery === null && !$hasFilters) {
            return $this->inner->provide($operation, $uriVariables, $context);
        }

        if ($this->filterBuilder->hasUnmappedFilters($filters)) {
            return $this->inner->provide($operation, $uriVariables, $context);
        }

        $attrs        = $this->buildAttributesToSearchOn($filters);
        $sort         = $this->buildSort($filters);
        $page         = max(1, (int) ($filters['page'] ?? 1));
        $itemsPerPage = min(max(1, (int) ($filters['itemsPerPage'] ?? 30)), 1000);
        $offset       = ($page - 1) * $itemsPerPage;
        $query        = $nameQuery ?? '';

        $ids = $this->fetchIds($query, $meiliFilter, $attrs, $itemsPerPage, $offset, $sort);
        if ($ids !== null) {
            $context['_meili_ids']      = $ids;
            $context['filters']['page'] = 1;

            $total = $this->fetchTotal($query, $meiliFilter, $attrs);
            if ($total !== null) {
                $context['_meili_total'] = $total;
            }

            foreach (array_keys(MeilisearchFilterBuilderService::FILTER_MAP) as $filterKey) {
                unset($context['filters'][$filterKey]);
            }
            foreach (MeilisearchFilterBuilderService::EFFECT_FILTER_KEYS as $filterKey) {
                unset($context['filters'][$filterKey]);
            }
            unset($context['filters']['name']);
        }

        return $this->inner->provide($operation, $uriVariables, $context);
    }

    private function extractNameQuery(array $filters): ?string
    {
        $name = $filters['name'] ?? null;

        if ($name === null || $name === '' || $name === []) {
            return null;
        }

        if (is_array($name)) {
            $parts = array_filter(array_map('trim', array_values($name)));
            return !empty($parts) ? implode(' ', $parts) : null;
        }

        $trimmed = trim((string) $name);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function fetchIds(string $query, ?string $filter, array $attributesToSearchOn, int $limit = 30, int $offset = 0, array $sort = []): ?array
    {
        try {
            return $this->meilisearch->searchIds($query, $attributesToSearchOn, $filter, $limit, $offset, $sort);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchTotal(string $query, ?string $filter, array $attributesToSearchOn = []): ?int
    {
        try {
            $params = ['limit' => 0];
            if ($filter !== null) {
                $params['filter'] = $filter;
            }
            if (!empty($attributesToSearchOn)) {
                $params['attributesToSearchOn'] = $attributesToSearchOn;
            }
            return $this->meilisearch->getIndex()->search($query ?: null, $params)->getEstimatedTotalHits() ?? 0;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return string[] */
    private function buildSort(array $filters): array
    {
        $order = $filters['order'] ?? [];
        if (!is_array($order)) {
            return [];
        }

        $sort = [];
        foreach ($order as $field => $direction) {
            $meiliField = self::ORDER_MAP[$field] ?? null;
            if ($meiliField === null) {
                continue;
            }
            $dir    = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
            $sort[] = "$meiliField:$dir";
        }

        return $sort;
    }

    /** @return string[] */
    private function buildAttributesToSearchOn(array $filters): array
    {
        $name = $filters['name'] ?? null;
        if (!is_array($name)) {
            return [];
        }

        $attrs = [];
        foreach (array_keys($name) as $locale) {
            $attrs[] = "name_{$locale}";
            $attrs[] = "main_effect_{$locale}";
            $attrs[] = "echo_effect_{$locale}";
        }

        return $attrs;
    }
}
