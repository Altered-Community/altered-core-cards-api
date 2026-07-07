<?php

namespace App\Service;

use App\Entity\GameplayFormat;
use App\Repository\GameplayFormatRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs CardGroup.gameplayFormat with the Altered Reunion formats manifest.
 *
 * A format is either:
 *  - a whitelist (`included_refs`): only UNIQUE-rarity printings listed there are legal,
 *    Common/Rare stay always legal.
 *  - a blacklist (`excluded_sets` / `excluded_refs`): everything is legal except printings
 *    from those sets or those exact refs.
 *
 * A CardGroup is legal in a format if at least one of its Card printings is legal.
 */
class GameplayFormatSyncService
{
    /**
     * @param string[] $allowedFormatIds Manifest format ids to sync; others are ignored.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly GameplayFormatRepository $gameplayFormatRepository,
        private readonly GameplayFormatManifestClient $manifestClient,
        private readonly LoggerInterface $logger,
        private readonly array $allowedFormatIds,
    ) {
    }

    /**
     * @return array<int, array{id: string, name: string, version: int, added: int, removed: int}>
     */
    public function syncAll(): array
    {
        $results = [];

        foreach ($this->manifestClient->fetchManifest() as $entry) {
            if (!in_array($entry['id'], $this->allowedFormatIds, true)) {
                continue;
            }

            $known = $this->gameplayFormatRepository->find($entry['id']);

            if ($known !== null && $known->getVersion() === $entry['version']) {
                continue;
            }

            try {
                $definition = $this->manifestClient->fetchDefinition($entry['path']);
                [$added, $removed] = $this->applyDefinition($entry['id'], $definition);

                $format = $known ?? new GameplayFormat($entry['id']);
                $format->setName($entry['name']);
                $format->setVersion($entry['version']);
                $this->em->persist($format);
                $this->em->flush();

                $results[] = [
                    'id'      => $entry['id'],
                    'name'    => $entry['name'],
                    'version' => $entry['version'],
                    'added'   => $added,
                    'removed' => $removed,
                ];
            } catch (\Throwable $e) {
                $this->logger->error('Gameplay format sync failed', [
                    'format' => $entry['id'],
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * @return array{0: int, 1: int} [added, removed]
     */
    private function applyDefinition(string $formatKey, array $definition): array
    {
        [$legalSql, $params, $types] = $this->buildLegalPrintingCondition($definition);
        $params['key'] = $formatKey;
        $types['key']  = \PDO::PARAM_STR;

        $added = $this->connection->executeStatement(
            "UPDATE card_group cg
             SET gameplay_format = array_append(gameplay_format, :key)
             WHERE NOT (:key = ANY(gameplay_format))
             AND EXISTS (
                 SELECT 1 FROM card c
                 LEFT JOIN card_set s ON c.set_id = s.id
                 LEFT JOIN rarity r ON c.rarity_id = r.id
                 WHERE c.card_group_id = cg.id AND ($legalSql)
             )",
            $params,
            $types,
        );

        $removed = $this->connection->executeStatement(
            "UPDATE card_group cg
             SET gameplay_format = array_remove(gameplay_format, :key)
             WHERE :key = ANY(gameplay_format)
             AND NOT EXISTS (
                 SELECT 1 FROM card c
                 LEFT JOIN card_set s ON c.set_id = s.id
                 LEFT JOIN rarity r ON c.rarity_id = r.id
                 WHERE c.card_group_id = cg.id AND ($legalSql)
             )",
            $params,
            $types,
        );

        return [$added, $removed];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildLegalPrintingCondition(array $definition): array
    {
        $conditions = ['1 = 1'];
        $params     = [];
        $types      = [];

        $excludedSets = $definition['excluded_sets'] ?? [];
        if ($excludedSets !== []) {
            $conditions[]           = 's.reference NOT IN (:excludedSets)';
            $params['excludedSets'] = $excludedSets;
            $types['excludedSets']  = ArrayParameterType::STRING;
        }

        $excludedRefs = $definition['excluded_refs'] ?? [];
        if ($excludedRefs !== []) {
            $conditions[]           = 'c.reference NOT IN (:excludedRefs)';
            $params['excludedRefs'] = $excludedRefs;
            $types['excludedRefs']  = ArrayParameterType::STRING;
        }

        if (array_key_exists('included_refs', $definition)) {
            $includedRefs = $definition['included_refs'];
            $conditions[] = $includedRefs === []
                ? "r.reference IS DISTINCT FROM 'UNIQUE'"
                : "(r.reference IS DISTINCT FROM 'UNIQUE' OR c.reference IN (:includedRefs))";

            if ($includedRefs !== []) {
                $params['includedRefs'] = $includedRefs;
                $types['includedRefs']  = ArrayParameterType::STRING;
            }
        }

        return [implode(' AND ', $conditions), $params, $types];
    }
}
