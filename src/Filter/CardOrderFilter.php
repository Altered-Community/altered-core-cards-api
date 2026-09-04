<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Unified ordering for the Card collection.
 *
 * Handles every sortable field (local columns, CardGroup stat fields, the
 * localized name) in a single filter so that ORDER BY clauses are built in
 * the exact priority given by the `order[...]` query parameters — the first
 * param is the primary sort, later ones only break ties on it.
 *
 * Splitting ordering across several ApiFilter classes (as before) makes the
 * final ORDER BY order depend on filter *registration* order instead of the
 * order the client asked for, which silently reshuffles multi-field sorts.
 *
 * Usage: order[reference]=asc, order[mainCost]=asc&order[set.date]=desc, order[name.fr]=asc, …
 */
final class CardOrderFilter extends AbstractFilter
{
    private const LOCAL_FIELDS = ['reference', 'cardNumber', 'collectorNumberFormatedId'];
    private const CARD_GROUP_FIELDS = ['mainCost', 'recallCost', 'oceanPower', 'mountainPower', 'forestPower'];

    public function apply(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $orderParams = $context['filters']['order'] ?? [];
        if (!is_array($orderParams) || empty($orderParams)) {
            return;
        }

        $root = $queryBuilder->getRootAliases()[0];
        $cgAlias = null;
        $translationAliases = [];

        foreach ($orderParams as $property => $direction) {
            if (!is_string($property) || !is_scalar($direction) || $direction === '') {
                continue;
            }

            $dir = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';

            if ($property === 'set.date') {
                $queryBuilder->addOrderBy("$root.setDate", $dir);
                continue;
            }

            if (in_array($property, self::LOCAL_FIELDS, true)) {
                $queryBuilder->addOrderBy("$root.$property", $dir);
                continue;
            }

            if (in_array($property, self::CARD_GROUP_FIELDS, true)) {
                $cgAlias ??= $this->getOrJoinCardGroup($queryBuilder, $root, $queryNameGenerator);
                $queryBuilder->addOrderBy("$cgAlias.$property", $dir);
                continue;
            }

            if (str_starts_with($property, 'name.')) {
                $locale = substr($property, 5);
                if ($locale === '') {
                    continue;
                }

                $cgAlias ??= $this->getOrJoinCardGroup($queryBuilder, $root, $queryNameGenerator);
                $tAlias = $translationAliases[$locale]
                    ??= $this->joinCardGroupTranslation($queryBuilder, $cgAlias, $locale, $queryNameGenerator);
                $queryBuilder->addOrderBy("$tAlias.name", $dir);
            }
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
        // Ordering is handled in apply(), where the full order[] param list is
        // available at once — needed to preserve cross-field priority.
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

    private function joinCardGroupTranslation(QueryBuilder $qb, string $cgAlias, string $locale, QueryNameGeneratorInterface $qng): string
    {
        $alias = $qng->generateJoinAlias('cgt_ord');
        $param = $qng->generateParameterName('ord_locale');
        $qb->leftJoin("$cgAlias.translations", $alias, 'WITH', "$alias.locale = :$param");
        $qb->setParameter($param, $locale);
        return $alias;
    }

    public function getDescription(string $resourceClass): array
    {
        $description = [];
        foreach ([...self::LOCAL_FIELDS, 'set.date', ...self::CARD_GROUP_FIELDS] as $property) {
            $description["order[$property]"] = [
                'property' => $property,
                'type'     => 'string',
                'required' => false,
                'schema'   => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ];
        }
        foreach (['fr', 'en', 'it', 'es', 'de'] as $locale) {
            $description["order[name.$locale]"] = [
                'property' => 'name',
                'type'     => 'string',
                'required' => false,
                'schema'   => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ];
        }

        return $description;
    }
}
