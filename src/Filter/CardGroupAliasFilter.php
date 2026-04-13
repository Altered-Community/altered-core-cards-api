<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Exposes short filter names on the Card endpoint that go through cardGroup.
 *
 * Direct field aliases (=):
 *   faction.code, isBanned, isSuspended, isErrated,
 *   mainCost, recallCost, oceanPower, mountainPower, forestPower
 *
 * Reference aliases (.reference =):
 *   cardType, subTypes
 */
final class CardGroupAliasFilter extends AbstractFilter
{
    private const FIELD_MAP = [
        'isBanned'      => 'isBanned',
        'isSuspended'   => 'isSuspended',
        'isErrated'     => 'isErrated',
        'mainCost'      => 'mainCost',
        'recallCost'    => 'recallCost',
        'oceanPower'    => 'oceanPower',
        'mountainPower' => 'mountainPower',
        'forestPower'   => 'forestPower',
    ];

    private const REF_MAP = [
        'cardType' => 'cardType',
        'subTypes' => 'subTypes',
    ];

    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!$this->isPropertyEnabled($property, $resourceClass) || $value === '' || $value === null) {
            return;
        }

        $root    = $queryBuilder->getRootAliases()[0];
        $cgAlias = $this->getOrJoinCardGroup($queryBuilder, $root);

        if ($property === 'faction.code') {
            $fAlias = $queryNameGenerator->generateJoinAlias('fac');
            $p      = $queryNameGenerator->generateParameterName('fac_code');
            $queryBuilder
                ->join("$cgAlias.faction", $fAlias)
                ->andWhere("$fAlias.code IN (:$p)")
                ->setParameter($p, $value);
            return;
        }

        if (isset(self::REF_MAP[$property])) {
            $rel    = self::REF_MAP[$property];
            $rAlias = $queryNameGenerator->generateJoinAlias($rel);
            $p      = $queryNameGenerator->generateParameterName($rel . '_ref');
            $queryBuilder
                ->join("$cgAlias.$rel", $rAlias)
                ->andWhere("$rAlias.reference IN (:$p)")
                ->setParameter($p, $value);
            return;
        }

        if (isset(self::FIELD_MAP[$property])) {
            $field = self::FIELD_MAP[$property];
            $p     = $queryNameGenerator->generateParameterName($field);
            if ($value === 'true')  $value = true;
            if ($value === 'false') $value = false;
            $queryBuilder
                ->andWhere("$cgAlias.$field = :$p")
                ->setParameter($p, $value);
        }
    }

    /**
     * Reuse an existing cardGroup join if one was already added (e.g. by another filter).
     */
    private function getOrJoinCardGroup(QueryBuilder $qb, string $root): string
    {
        foreach ($qb->getDQLPart('join')[$root] ?? [] as $join) {
            if ($join->getJoin() === "$root.cardGroup") {
                return $join->getAlias();
            }
        }
        $alias = 'alias_cg';
        $qb->join("$root.cardGroup", $alias);
        return $alias;
    }

    public function getDescription(string $resourceClass): array
    {
        return [];
    }
}
