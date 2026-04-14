<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Card;
use App\Entity\CardGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wraps CachedCountCollectionProvider and batch-loads all associations
 * needed for card:list serialization, eliminating N+1 queries.
 *
 * Strategy:
 *  1. Let the inner provider run the paginated query (1 SQL).
 *  2. Collect the card IDs from the page.
 *  3. Run one DQL query with LEFT JOINs for every association used during
 *     serialization, scoped to those IDs. Doctrine's identity map ensures
 *     the same entity instances are updated in-place.
 *  4. Return the original paginator — the serializer finds everything loaded.
 */
final class CardCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly CachedCountCollectionProvider $inner,
        private readonly EntityManagerInterface $em,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->inner->provide($operation, $uriVariables, $context);

        if (!$result instanceof \Traversable) {
            return $result;
        }

        $ids = [];
        foreach ($result as $item) {
            if ($item instanceof Card) {
                $ids[] = $item->getId();
            }
        }

        if (empty($ids)) {
            return $result;
        }

        // One query to batch-load all lazy ManyToOne associations for the current page.
        // OneToMany / EAGER collections (c.translations, cg.translations) are intentionally
        // excluded — Doctrine loads them automatically via IN() queries, joining them here
        // would create a Cartesian product and multiply the result set size.
        $this->em->createQueryBuilder()
            ->select('c, s, cg, cgf, cgr, cgct, cgchs')
            ->from(Card::class, 'c')
            ->leftJoin('c.set', 's')
            ->leftJoin('c.cardGroup', 'cg')
            ->leftJoin('cg.faction', 'cgf')
            ->leftJoin('cg.rarity', 'cgr')
            ->leftJoin('cg.cardType', 'cgct')
            ->leftJoin('cg.cardHistoryStatus', 'cgchs')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
