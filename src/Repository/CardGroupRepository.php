<?php

namespace App\Repository;

use App\Entity\CardGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CardGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardGroup::class);
    }

    /**
     * Returns a lightweight map of slug => id (scalars only).
     *
     * @return array<string, int>
     */
    public function findSlugMap(): array
    {
        $rows = $this->createQueryBuilder('cg')
            ->select('cg.slug', 'cg.id')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['slug']] = $row['id'];
        }

        return $map;
    }

    /** @return array<string, CardGroup> */
    public function findBySlugs(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $groups = $this->createQueryBuilder('cg')
            ->where('cg.slug IN (:slugs)')
            ->setParameter('slugs', $slugs)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($groups as $group) {
            $map[$group->getSlug()] = $group;
        }

        return $map;
    }

    /** Distinct gameplay format keys currently in use, for building admin select choices. */
    public function findDistinctGameplayFormats(): array
    {
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT fmt FROM card_group, unnest(gameplay_format) AS fmt ORDER BY fmt'
        );
    }

    /** @return int[] CardGroup ids currently tagged with $format. */
    public function findIdsByGameplayFormat(string $format): array
    {
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM card_group WHERE gameplay_format @> ARRAY[:format]',
            ['format' => strtoupper(trim($format))]
        );
    }

    /** @return array<array{trigger_id: int, trigger_text: string|null, nb: int}> */
    public function getTriggerStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative("
            SELECT at.id AS trigger_id, at.text_fr AS trigger_text, COUNT(DISTINCT cg.id) AS nb
            FROM card_group cg
            JOIN main_effect me
              ON me.id = cg.effect1_id
              OR me.id = cg.effect2_id
              OR me.id = cg.effect3_id
            JOIN ability_trigger at ON at.id = me.ability_trigger_id
            GROUP BY at.id, at.text_fr
            ORDER BY nb DESC
        ");
    }
}
