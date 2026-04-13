<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Allows ordering Card collections by CardGroup stat fields.
 *
 * Usage: order[mainCost]=asc, order[forestPower]=desc, …
 */
final class CardGroupOrderFilter extends AbstractFilter
{
    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $orderParams = $context['filters']['order'] ?? [];
        if (empty($orderParams)) {
            return;
        }

        $root    = $queryBuilder->getRootAliases()[0];
        $cgAlias = null;

        foreach ($this->properties ?? [] as $key => $prop) {
            $property = is_string($key) ? $key : (string) $prop;
            if (!isset($orderParams[$property]) || $orderParams[$property] === '') {
                continue;
            }

            $dir = strtoupper((string) $orderParams[$property]) === 'DESC' ? 'DESC' : 'ASC';

            if ($cgAlias === null) {
                $cgAlias = $this->getOrJoinCardGroup($queryBuilder, $root, $queryNameGenerator);
            }

            $queryBuilder->addOrderBy("$cgAlias.$property", $dir);
        }
    }

    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        // Ordering is handled in apply()
    }

    private function getOrJoinCardGroup(QueryBuilder $qb, string $root, QueryNameGeneratorInterface $qng): string
    {
        foreach ($qb->getDQLPart('join')[$root] ?? [] as $join) {
            if ($join->getJoin() === "$root.cardGroup") {
                return $join->getAlias();
            }
        }
        $alias = $qng->generateJoinAlias('cg_ord');
        $qb->join("$root.cardGroup", $alias);
        return $alias;
    }

    public function getDescription(string $resourceClass): array
    {
        return [];
    }
}
