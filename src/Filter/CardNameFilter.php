<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Partial name search on Card via cardGroup.translations.
 *
 * Accepted query param: name[fr]=foo  or  name[en]=foo
 * Multiple locales can be passed at once (OR between them).
 */
final class CardNameFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ($property !== 'name' || $value === null || $value === '' || $value === []) {
            return;
        }

        $root    = $queryBuilder->getRootAliases()[0];
        $cgAlias = $this->getOrJoinCardGroup($queryBuilder, $root);

        // name=foo  →  search across all locales (no locale restriction)
        if (is_string($value)) {
            $search = trim($value);
            if ($search === '') {
                return;
            }
            $tAlias = $queryNameGenerator->generateJoinAlias('cgt');
            $pName  = $queryNameGenerator->generateParameterName('name_search');
            $queryBuilder
                ->leftJoin("$cgAlias.translations", $tAlias)
                ->andWhere($queryBuilder->expr()->like("LOWER($tAlias.name)", ":$pName"))
                ->setParameter($pName, '%' . mb_strtolower($search) . '%');
            return;
        }

        // name[fr]=foo  or  name[en]=foo  →  locale-specific search (OR between locales)
        $orParts = [];

        foreach ($value as $locale => $search) {
            $search = trim((string) $search);
            if ($search === '') {
                continue;
            }

            $tAlias = $queryNameGenerator->generateJoinAlias('cgt');
            $pLoc   = $queryNameGenerator->generateParameterName('name_locale');
            $pName  = $queryNameGenerator->generateParameterName('name_search');

            $queryBuilder
                ->leftJoin("$cgAlias.translations", $tAlias, 'WITH', "$tAlias.locale = :$pLoc")
                ->setParameter($pLoc, $locale);

            $orParts[] = $queryBuilder->expr()->like("LOWER($tAlias.name)", ":$pName");
            $queryBuilder->setParameter($pName, '%' . mb_strtolower($search) . '%');
        }

        if (empty($orParts)) {
            return;
        }

        $queryBuilder->andWhere($queryBuilder->expr()->orX(...$orParts));
    }

    private function getOrJoinCardGroup(QueryBuilder $qb, string $root): string
    {
        foreach ($qb->getDQLPart('join')[$root] ?? [] as $join) {
            if ($join->getJoin() === "$root.cardGroup") {
                return $join->getAlias();
            }
        }
        $alias = 'alias_cg_name';
        $qb->join("$root.cardGroup", $alias);
        return $alias;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'name' => [
                'property' => 'name',
                'type'     => 'string',
                'required' => false,
                'description' => 'Search in all locales',
            ],
            'name[fr]' => [
                'property' => 'name',
                'type'     => 'string',
                'required' => false,
            ],
            'name[en]' => [
                'property' => 'name',
                'type'     => 'string',
                'required' => false,
            ],
        ];
    }
}
