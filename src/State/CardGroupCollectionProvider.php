<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CardGroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Wraps CachedCountCollectionProvider and batch-loads all OneToMany / ManyToMany
 * associations in a single extra query, eliminating N+1 during serialization.
 *
 * Strategy:
 *  1. Let the inner provider run the paginated query (30 results, 1 SQL).
 *  2. Collect the IDs from those results.
 *  3. Run one DQL query with LEFT JOINs for every collection association,
 *     scoped to those IDs. Doctrine's identity map ensures the same entity
 *     instances are updated in-place with the freshly loaded relations.
 *  4. Return the original paginator — the serializer now finds everything loaded.
 */
final class CardGroupCollectionProvider implements ProviderInterface
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
            if ($item instanceof CardGroup) {
                $ids[] = $item->getId();
            }
        }

        if (empty($ids)) {
            return $result;
        }

        // Joining multiple OneToMany relations in a single query creates a Cartesian product
        // (translations × cardRulings × loreEntries × cards = exponential row count).
        // Instead, run one query per OneToMany and one for all ManyToOne — all scoped to
        // the same IDs. Doctrine's identity map merges them into the same entity instances.

        // Query 1 — ManyToOne only (no Cartesian product risk)
        $this->em->createQueryBuilder()
            ->select('cg, f, r, ctype, chs, e1, e1t, e1c, e1e, e2, e2t, e2c, e2e, e3, e3t, e3c, e3e')
            ->from(CardGroup::class, 'cg')
            ->leftJoin('cg.faction', 'f')
            ->leftJoin('cg.rarity', 'r')
            ->leftJoin('cg.cardType', 'ctype')
            ->leftJoin('cg.cardHistoryStatus', 'chs')
            ->leftJoin('cg.effect1', 'e1')
            ->leftJoin('e1.abilityTrigger', 'e1t')
            ->leftJoin('e1.abilityCondition', 'e1c')
            ->leftJoin('e1.abilityEffect', 'e1e')
            ->leftJoin('cg.effect2', 'e2')
            ->leftJoin('e2.abilityTrigger', 'e2t')
            ->leftJoin('e2.abilityCondition', 'e2c')
            ->leftJoin('e2.abilityEffect', 'e2e')
            ->leftJoin('cg.effect3', 'e3')
            ->leftJoin('e3.abilityTrigger', 'e3t')
            ->leftJoin('e3.abilityCondition', 'e3c')
            ->leftJoin('e3.abilityEffect', 'e3e')
            ->where('cg.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Query 2 — subTypes (ManyToMany)
        $this->em->createQueryBuilder()
            ->select('cg, st')
            ->from(CardGroup::class, 'cg')
            ->leftJoin('cg.subTypes', 'st')
            ->where('cg.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Query 3 — cardRulings (OneToMany)
        $this->em->createQueryBuilder()
            ->select('cg, cr')
            ->from(CardGroup::class, 'cg')
            ->leftJoin('cg.cardRulings', 'cr')
            ->where('cg.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Query 4 — loreEntries (OneToMany)
        $this->em->createQueryBuilder()
            ->select('cg, le')
            ->from(CardGroup::class, 'cg')
            ->leftJoin('cg.loreEntries', 'le')
            ->where('cg.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Query 5 — cards + set (OneToMany + ManyToOne on cards)
        // c.translations are EAGER and will be loaded automatically by Doctrine
        $this->em->createQueryBuilder()
            ->select('cg, c, cs')
            ->from(CardGroup::class, 'cg')
            ->leftJoin('cg.cards', 'c')
            ->leftJoin('c.set', 'cs')
            ->where('cg.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // cg.translations and c.translations are EAGER — Doctrine loads them
        // automatically with IN() queries, no explicit join needed.

        return $result;
    }
}
