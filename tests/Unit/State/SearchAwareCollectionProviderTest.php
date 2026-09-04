<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\State\SearchAwareCollectionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the Meilisearch-side counterpart of the order[...]
 * sorting bugs: order[reference] / order[name.<lang>] were not in ORDER_MAP,
 * so they were silently dropped whenever a request routed through Meilisearch
 * (e.g. combined with rarity/set.reference/faction.code — very common in practice).
 */
final class SearchAwareCollectionProviderTest extends TestCase
{
    private function buildSort(array $filters): array
    {
        $provider = (new \ReflectionClass(SearchAwareCollectionProvider::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SearchAwareCollectionProvider::class, 'buildSort');

        return $method->invoke($provider, $filters);
    }

    public function testReferenceIsMappedToMeilisearchSortableAttribute(): void
    {
        $this->assertSame(['reference:asc'], $this->buildSort(['order' => ['reference' => 'asc']]));
    }

    public function testLocalizedNameIsMappedToMeilisearchSortableAttribute(): void
    {
        $this->assertSame(['name_fr:desc'], $this->buildSort(['order' => ['name.fr' => 'desc']]));
        $this->assertSame(['name_en:asc'], $this->buildSort(['order' => ['name.en' => 'asc']]));
    }

    public function testMultiFieldOrderPreservesDeclaredPriority(): void
    {
        $sort = $this->buildSort(['order' => [
            'mainCost' => 'asc',
            'set.date' => 'desc',
            'collectorNumberFormatedId' => 'asc',
        ]]);

        $this->assertSame(['main_cost:asc', 'set_date:desc', 'collector_number_formated_id:asc'], $sort);
    }
}
