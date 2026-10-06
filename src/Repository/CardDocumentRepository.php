<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Builds raw card documents for search indexing.
 * Single source of truth for the DBAL query — no SQL elsewhere.
 */
final class CardDocumentRepository
{
    /** Fields that require joined tables — cannot be selected directly from card. */
    private const EFFECT_FIELDS = [
        'slot1_trigger', 'slot1_condition', 'slot1_effect',
        'slot2_trigger', 'slot2_condition', 'slot2_effect',
        'slot3_trigger', 'slot3_condition', 'slot3_effect',
        'echo_trigger',  'echo_condition',  'echo_effect',
        'trigger_repeat_count', 'has_effect', 'keywords',
        'transfuge',
        'all_triggers', 'all_conditions', 'all_effects',
    ];

    /** Joins available to partial updates, in dependency order (cg before f/r/ct). */
    private const PARTIAL_JOINS = [
        'cg' => 'LEFT JOIN card_group cg ON cg.id = c.card_group_id',
        'cs' => 'LEFT JOIN card_set   cs ON cs.id = c.set_id',
        'f'  => 'LEFT JOIN faction    f  ON f.id  = cg.faction_id',
        'r'  => 'LEFT JOIN rarity     r  ON r.id  = cg.rarity_id',
        'ct' => 'LEFT JOIN card_type  ct ON ct.id = cg.card_type_id',
    ];

    /**
     * Scalar fields supported by partial updates: field => [SQL expression, joins, cast].
     * Expressions mirror buildSql() so partial and full documents stay identical.
     */
    private const PARTIAL_FIELDS = [
        'reference'                    => ['c.reference',                          [],           null],
        'variation'                    => ['c.variation',                          [],           null],
        'collector_number_formated_id' => ['c.collector_number_formated_id',       [],           null],
        'kickstarter'                  => ['c.kickstarter',                        [],           'bool'],
        'promo'                        => ['c.promo',                              [],           'bool'],
        'is_serialized'                => ['c.is_serialized',                      [],           'bool'],
        'set_date'                     => ['COALESCE(c.set_date, cs.date::date)',  ['cs'],       null],
        'set_reference'                => ['cs.reference',                         ['cs'],       null],
        'card_type'                    => ['ct.reference',                         ['cg', 'ct'], null],
        'faction_code'                 => ['f.code',                               ['cg', 'f'],  null],
        'rarity'                       => ['r.reference',                          ['cg', 'r'],  null],
        'main_cost'                    => ['cg.main_cost',                         ['cg'],       'int'],
        'recall_cost'                  => ['cg.recall_cost',                       ['cg'],       'int'],
        'ocean_power'                  => ['cg.ocean_power',                       ['cg'],       'int'],
        'mountain_power'               => ['cg.mountain_power',                    ['cg'],       'int'],
        'forest_power'                 => ['cg.forest_power',                      ['cg'],       'int'],
        'is_banned'                    => ['cg.is_banned',                         ['cg'],       'bool'],
        'is_suspended'                 => ['cg.is_suspended',                      ['cg'],       'bool'],
        'is_errated'                   => ['cg.is_errated',                        ['cg'],       'bool'],
    ];

    public function __construct(private readonly Connection $connection) {}

    /**
     * Fields that cannot be used with streamPartialDocuments() (e.g. aggregated names / sub_types).
     *
     * @param  string[] $fields
     * @return string[]
     */
    public function unsupportedPartialFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn($f) => !isset(self::PARTIAL_FIELDS[$f])
                && $f !== 'cost_relation'
                && $f !== 'gameplay_format'
                && !in_array($f, self::EFFECT_FIELDS, true),
        ));
    }

    /**
     * Stream only specific card fields, in id-range chunks.
     * Use for partial Meilisearch updates when only a few fields changed.
     *
     * @param string[] $fields
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function streamPartialDocuments(array $fields, int $batchSize = 2000): \Generator
    {
        $unsupported = $this->unsupportedPartialFields($fields);
        if ($unsupported) {
            throw new \InvalidArgumentException(sprintf('Unsupported partial field(s): %s', implode(', ', $unsupported)));
        }

        $needsCostRelation    = in_array('cost_relation', $fields, true);
        $needsEffects         = (bool) array_intersect($fields, self::EFFECT_FIELDS);
        $needsGameplayFormat  = in_array('gameplay_format', $fields, true);
        $scalarFields         = array_values(array_filter($fields, fn($f) => isset(self::PARTIAL_FIELDS[$f])));

        $cols     = array_map(fn($f) => self::PARTIAL_FIELDS[$f][0] . " AS $f", $scalarFields);
        $joinKeys = array_merge([], ...array_map(fn($f) => self::PARTIAL_FIELDS[$f][1], $scalarFields));

        if ($needsCostRelation || $needsEffects || $needsGameplayFormat) {
            $joinKeys[] = 'cg';
            $cols[]     = 'cg.main_cost AS _main_cost';
            $cols[]     = 'cg.recall_cost AS _recall_cost';
        }

        if ($needsGameplayFormat) {
            $cols[] = 'cg.gameplay_format';
        }

        $joins = array_values(array_intersect_key(self::PARTIAL_JOINS, array_flip($joinKeys)));

        if ($needsEffects) {
            $joins[] = 'LEFT JOIN card_search   cks ON cks.card_id = c.id';
            $joins[] = 'LEFT JOIN ability_trigger   at1 ON at1.id = cks.t1';
            $joins[] = 'LEFT JOIN ability_condition ac1 ON ac1.id = cks.c1';
            $joins[] = 'LEFT JOIN ability_effect    ae1 ON ae1.id = cks.e1';
            $joins[] = 'LEFT JOIN ability_trigger   at2 ON at2.id = cks.t2';
            $joins[] = 'LEFT JOIN ability_condition ac2 ON ac2.id = cks.c2';
            $joins[] = 'LEFT JOIN ability_effect    ae2 ON ae2.id = cks.e2';
            $joins[] = 'LEFT JOIN ability_trigger   at3 ON at3.id = cks.t3';
            $joins[] = 'LEFT JOIN ability_condition ac3 ON ac3.id = cks.c3';
            $joins[] = 'LEFT JOIN ability_effect    ae3 ON ae3.id = cks.e3';
            $joins[] = 'LEFT JOIN ability_trigger   ate ON ate.id = cks.et1';
            $joins[] = 'LEFT JOIN ability_condition ace ON ace.id = cks.ec1';
            $joins[] = 'LEFT JOIN ability_effect    aee ON aee.id = cks.ee1';
            $cols[]  = 'at1.altered_id AS slot1_trigger, ac1.altered_id AS slot1_condition, ae1.altered_id AS slot1_effect'
                . ', at2.altered_id AS slot2_trigger, ac2.altered_id AS slot2_condition, ae2.altered_id AS slot2_effect'
                . ', at3.altered_id AS slot3_trigger, ac3.altered_id AS slot3_condition, ae3.altered_id AS slot3_effect'
                . ', ate.altered_id AS echo_trigger, ace.altered_id AS echo_condition, aee.altered_id AS echo_effect'
                . ', cks.has_effect, cks.keywords, c.transfuge';
        }

        $sql = sprintf(
            'SELECT c.id%s FROM card c %s WHERE c.id BETWEEN :fromId AND :toId ORDER BY c.id',
            $cols ? ', ' . implode(', ', $cols) : '',
            implode(' ', $joins),
        );

        foreach ($this->idRanges($batchSize) as [$fromId, $toId]) {
            $result = $this->connection->executeQuery($sql, ['fromId' => $fromId, 'toId' => $toId]);

            $batch = [];
            while (($row = $result->fetchAssociative()) !== false) {
                $doc = ['id' => (int) $row['id']];

                foreach ($scalarFields as $f) {
                    $doc[$f] = match (self::PARTIAL_FIELDS[$f][2]) {
                        'int'   => $row[$f] !== null ? (int) $row[$f] : null,
                        'bool'  => (bool) $row[$f],
                        default => $row[$f],
                    };
                }

                if ($needsCostRelation || $needsEffects) {
                    $doc['cost_relation'] = $this->computeCostRelation($row['_main_cost'], $row['_recall_cost']);
                }

                if ($needsEffects) {
                    $doc += $this->hydrateEffectFields($row);
                }

                if ($needsGameplayFormat) {
                    $doc['gameplay_format'] = $this->parsePgTextArray($row['gameplay_format']);
                }

                $batch[] = $doc;
            }

            if ($batch) {
                yield $batch;
            }
        }
    }

    /**
     * Stream all card documents as flat arrays, in id-range chunks of $batchSize ids.
     * Chunking keeps each query small so Postgres never sorts / groups the whole table at once
     * (a single query over all cards fills pgsql_tmp on prod).
     * Pass $setReferences to restrict to the cards of those sets (e.g. ['EOLEOP', 'EOLETOP']):
     * their ids are fetched first and queried in chunks of $batchSize.
     *
     * @param  string[] $setReferences
     * @return \Generator<int, array<int, array<string, mixed>>>
     */
    public function streamDocuments(int $batchSize = 2000, array $setReferences = []): \Generator
    {
        if ($setReferences) {
            // Sets are scattered across the id space: fetch their ids first, then query by id list.
            $ids = $this->connection->fetchFirstColumn(
                'SELECT c.id FROM card c JOIN card_set cs ON cs.id = c.set_id WHERE cs.reference IN (:setReferences) ORDER BY c.id',
                ['setReferences' => $setReferences],
                ['setReferences' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );

            $sql = $this->buildSql('WHERE c.id IN (:ids)');
            foreach (array_chunk($ids, $batchSize) as $chunk) {
                yield $this->fetchDocuments($sql, ['ids' => $chunk], ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]);
            }

            return;
        }

        $sql = $this->buildSql('WHERE c.id BETWEEN :fromId AND :toId');
        foreach ($this->idRanges($batchSize) as [$fromId, $toId]) {
            $batch = $this->fetchDocuments($sql, ['fromId' => $fromId, 'toId' => $toId]);

            if ($batch) {
                yield $batch;
            }
        }
    }

    /**
     * Build a single card document by ID.
     * Returns null if the card does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function findDocument(int $cardId): ?array
    {
        $row = $this->connection->executeQuery(
            $this->buildSql('WHERE c.id = :id'),
            ['id' => $cardId]
        )->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Build documents for every card belonging to the given CardGroups, in one query.
     * Used to bulk-reindex a small, targeted set of groups (e.g. after a gameplay-format
     * import) without the N+1 HTTP-per-card cost of calling findDocument() in a loop.
     *
     * @param  int[] $cardGroupIds
     * @return array<int, array<string, mixed>>
     */
    public function findDocumentsByCardGroupIds(array $cardGroupIds): array
    {
        if (empty($cardGroupIds)) {
            return [];
        }

        return $this->fetchDocuments(
            $this->buildSql('WHERE cg.id IN (:cardGroupIds)'),
            ['cardGroupIds' => $cardGroupIds],
            ['cardGroupIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    /**
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $types
     * @return array<int, array<string, mixed>>
     */
    private function fetchDocuments(string $sql, array $params, array $types = []): array
    {
        $result = $this->connection->executeQuery($sql, $params, $types);

        $docs = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $docs[] = $this->hydrate($row);
        }

        return $docs;
    }

    /**
     * Count total cards (for progress bars, etc.).
     */
    public function countAll(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM card');
    }

    /**
     * Count cards belonging to the given set references.
     *
     * @param string[] $setReferences
     */
    public function countBySetReferences(array $setReferences): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM card c JOIN card_set cs ON cs.id = c.set_id WHERE cs.reference IN (:setReferences)',
            ['setReferences' => $setReferences],
            ['setReferences' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function sanitize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $encoded = json_encode($text, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return null;
        }
        $clean = json_decode($encoded);
        if (!is_string($clean)) {
            return null;
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);
    }

    /**
     * Consecutive [from, to] id ranges covering the card table.
     *
     * @return \Generator<int, array{int, int}>
     */
    private function idRanges(int $chunkSize): \Generator
    {
        $bounds = $this->connection->fetchAssociative('SELECT MIN(id) AS min_id, MAX(id) AS max_id FROM card');

        if ($bounds === false || $bounds['min_id'] === null) {
            return;
        }

        for ($from = (int) $bounds['min_id'], $max = (int) $bounds['max_id']; $from <= $max; $from += $chunkSize) {
            yield [$from, $from + $chunkSize - 1];
        }
    }

    private function buildSql(string $where = ''): string
    {
        return <<<SQL
            SELECT
                c.id,
                c.reference,
                c.kickstarter,
                c.promo,
                c.transfuge,
                c.is_serialized,
                c.variation,
                c.collector_number_formated_id,
                COALESCE(c.set_date, cs.date::date)                                   AS set_date,
                cs.reference                                                          AS set_reference,
                cg.main_cost,
                cg.recall_cost,
                cg.ocean_power,
                cg.mountain_power,
                cg.forest_power,
                cg.is_banned,
                cg.is_suspended,
                cg.is_errated,
                f.code                                                                AS faction_code,
                r.reference                                                           AS rarity,
                ct.reference                                                          AS card_type,
                at1.altered_id                                                        AS slot1_trigger,
                ac1.altered_id                                                        AS slot1_condition,
                ae1.altered_id                                                        AS slot1_effect,
                at2.altered_id                                                        AS slot2_trigger,
                ac2.altered_id                                                        AS slot2_condition,
                ae2.altered_id                                                        AS slot2_effect,
                at3.altered_id                                                        AS slot3_trigger,
                ac3.altered_id                                                        AS slot3_condition,
                ae3.altered_id                                                        AS slot3_effect,
                ate.altered_id                                                        AS echo_trigger,
                ace.altered_id                                                        AS echo_condition,
                aee.altered_id                                                        AS echo_effect,
                cks.has_effect,
                cks.keywords,
                MAX(CASE WHEN cgt.locale = 'fr' THEN cgt.name        END)            AS name_fr,
                MAX(CASE WHEN cgt.locale = 'en' THEN cgt.name        END)            AS name_en,
                MAX(CASE WHEN cgt.locale = 'fr' THEN cgt.main_effect END)            AS main_effect_fr,
                MAX(CASE WHEN cgt.locale = 'en' THEN cgt.main_effect END)            AS main_effect_en,
                MAX(CASE WHEN cgt.locale = 'fr' THEN cgt.echo_effect END)            AS echo_effect_fr,
                MAX(CASE WHEN cgt.locale = 'en' THEN cgt.echo_effect END)            AS echo_effect_en,
                COALESCE(
                    json_agg(DISTINCT cst.reference) FILTER (WHERE cst.reference IS NOT NULL),
                    '[]'
                )                                                                     AS sub_types,
                cg.gameplay_format                                                    AS gameplay_format
            FROM card c
            LEFT JOIN card_group               cg   ON cg.id  = c.card_group_id
            LEFT JOIN card_set                 cs   ON cs.id  = c.set_id
            LEFT JOIN faction                  f    ON f.id   = cg.faction_id
            LEFT JOIN rarity                   r    ON r.id   = cg.rarity_id
            LEFT JOIN card_type                ct   ON ct.id  = cg.card_type_id
            LEFT JOIN card_search              cks  ON cks.card_id = c.id
            LEFT JOIN ability_trigger          at1  ON at1.id = cks.t1
            LEFT JOIN ability_condition        ac1  ON ac1.id = cks.c1
            LEFT JOIN ability_effect           ae1  ON ae1.id = cks.e1
            LEFT JOIN ability_trigger          at2  ON at2.id = cks.t2
            LEFT JOIN ability_condition        ac2  ON ac2.id = cks.c2
            LEFT JOIN ability_effect           ae2  ON ae2.id = cks.e2
            LEFT JOIN ability_trigger          at3  ON at3.id = cks.t3
            LEFT JOIN ability_condition        ac3  ON ac3.id = cks.c3
            LEFT JOIN ability_effect           ae3  ON ae3.id = cks.e3
            LEFT JOIN ability_trigger          ate  ON ate.id = cks.et1
            LEFT JOIN ability_condition        ace  ON ace.id = cks.ec1
            LEFT JOIN ability_effect           aee  ON aee.id = cks.ee1
            LEFT JOIN card_group_translation   cgt  ON cgt.card_group_id = cg.id
            LEFT JOIN card_group_sub_type_link cgsl ON cgsl.card_group_id = cg.id
            LEFT JOIN card_sub_type            cst  ON cst.id = cgsl.card_sub_type_id
            $where
            GROUP BY
                c.id, c.reference, c.kickstarter, c.promo, c.transfuge,
                c.is_serialized, c.variation, c.collector_number_formated_id, c.set_date,
                cs.reference, cs.date,
                cg.main_cost, cg.recall_cost, cg.ocean_power, cg.mountain_power, cg.forest_power,
                cg.is_banned, cg.is_suspended, cg.is_errated, cg.gameplay_format,
                f.code, r.reference, ct.reference,
                at1.altered_id, ac1.altered_id, ae1.altered_id,
                at2.altered_id, ac2.altered_id, ae2.altered_id,
                at3.altered_id, ac3.altered_id, ae3.altered_id,
                ate.altered_id, ace.altered_id, aee.altered_id,
                cks.has_effect, cks.keywords
            ORDER BY c.id
        SQL;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'reference'      => $row['reference'],
            'name_fr'        => $this->sanitize($row['name_fr']),
            'name_en'        => $this->sanitize($row['name_en']),
            'main_effect_fr' => $this->sanitize($row['main_effect_fr']),
            'main_effect_en' => $this->sanitize($row['main_effect_en']),
            'echo_effect_fr' => $this->sanitize($row['echo_effect_fr']),
            'echo_effect_en' => $this->sanitize($row['echo_effect_en']),
            'faction_code'   => $row['faction_code'],
            'set_reference'  => $row['set_reference'],
            'rarity'         => $row['rarity'],
            'card_type'      => $row['card_type'],
            'sub_types'      => json_decode((string) $row['sub_types'], true),
            'gameplay_format' => $this->parsePgTextArray($row['gameplay_format']),
            'main_cost'      => $row['main_cost'] !== null ? (int) $row['main_cost'] : null,
            'recall_cost'    => $row['recall_cost'] !== null ? (int) $row['recall_cost'] : null,
            'ocean_power'    => $row['ocean_power'] !== null ? (int) $row['ocean_power'] : null,
            'mountain_power' => $row['mountain_power'] !== null ? (int) $row['mountain_power'] : null,
            'forest_power'   => $row['forest_power'] !== null ? (int) $row['forest_power'] : null,
            'is_banned'      => (bool) $row['is_banned'],
            'is_suspended'   => (bool) $row['is_suspended'],
            'is_errated'     => (bool) $row['is_errated'],
            'is_serialized'  => (bool) $row['is_serialized'],
            'kickstarter'    => (bool) $row['kickstarter'],
            'promo'          => (bool) $row['promo'],
            'transfuge'      => (bool) $row['transfuge'],
            'variation'                    => $row['variation'],
            'collector_number_formated_id' => $row['collector_number_formated_id'],
            'set_date'                     => $row['set_date'],
            'cost_relation'                => $this->computeCostRelation($row['main_cost'], $row['recall_cost']),
        ] + $this->hydrateEffectFields($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrateEffectFields(array $row): array
    {
        $t1 = isset($row['slot1_trigger'])  ? ($row['slot1_trigger']  !== null ? (int) $row['slot1_trigger']  : null) : null;
        $t2 = isset($row['slot2_trigger'])  ? ($row['slot2_trigger']  !== null ? (int) $row['slot2_trigger']  : null) : null;
        $t3 = isset($row['slot3_trigger'])  ? ($row['slot3_trigger']  !== null ? (int) $row['slot3_trigger']  : null) : null;
        $te = isset($row['echo_trigger'])   ? ($row['echo_trigger']   !== null ? (int) $row['echo_trigger']   : null) : null;

        $c1 = isset($row['slot1_condition']) && $row['slot1_condition'] !== null ? (int) $row['slot1_condition'] : null;
        $c2 = isset($row['slot2_condition']) && $row['slot2_condition'] !== null ? (int) $row['slot2_condition'] : null;
        $c3 = isset($row['slot3_condition']) && $row['slot3_condition'] !== null ? (int) $row['slot3_condition'] : null;
        $ce = isset($row['echo_condition'])  && $row['echo_condition']  !== null ? (int) $row['echo_condition']  : null;

        $e1 = isset($row['slot1_effect']) && $row['slot1_effect'] !== null ? (int) $row['slot1_effect'] : null;
        $e2 = isset($row['slot2_effect']) && $row['slot2_effect'] !== null ? (int) $row['slot2_effect'] : null;
        $e3 = isset($row['slot3_effect']) && $row['slot3_effect'] !== null ? (int) $row['slot3_effect'] : null;
        $ee = isset($row['echo_effect'])  && $row['echo_effect']  !== null ? (int) $row['echo_effect']  : null;

        return [
            'slot1_trigger'   => $t1,
            'slot1_condition' => $c1,
            'slot1_effect'    => $e1,
            'slot2_trigger'   => $t2,
            'slot2_condition' => $c2,
            'slot2_effect'    => $e2,
            'slot3_trigger'   => $t3,
            'slot3_condition' => $c3,
            'slot3_effect'    => $e3,
            'echo_trigger'    => $te,
            'echo_condition'  => $ce,
            'echo_effect'     => $ee,
            'all_triggers'    => array_values(array_unique(array_filter([$t1, $t2, $t3, $te]))),
            'all_conditions'  => array_values(array_unique(array_filter([$c1, $c2, $c3, $ce]))),
            'all_effects'     => array_values(array_unique(array_filter([$e1, $e2, $e3, $ee]))),
            'trigger_repeat_count' => $this->computeTriggerRepeatCount($t1, $t2, $t3, $te),
            'has_effect'  => isset($row['has_effect']) ? (bool) $row['has_effect'] : false,
            'keywords'    => isset($row['keywords'])   ? $this->parsePgTextArray($row['keywords']) : [],
            'transfuge'   => isset($row['transfuge'])  ? (bool) $row['transfuge'] : false,
        ];
    }

    private function computeCostRelation(mixed $main, mixed $recall): ?string
    {
        if ($main === null || $recall === null) {
            return null;
        }
        $m = (int) $main;
        $r = (int) $recall;
        if ($m === $r) return 'equal';
        if ($m > $r)  return 'mainHigher';
        return 'recallHigher';
    }

    private function computeTriggerRepeatCount(?int $t1, ?int $t2, ?int $t3, ?int $te): int
    {
        $counts = [];
        foreach ([$t1, $t2, $t3, $te] as $t) {
            if ($t !== null) {
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
        }
        return !empty($counts) ? max($counts) : 0;
    }

    /** Parse PostgreSQL TEXT[] string e.g. "{CORIACE,FUGACE}" into a PHP array. */
    private function parsePgTextArray(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '{}' || $raw === '') {
            return [];
        }
        return array_values(array_filter(explode(',', trim($raw, '{}'))));
    }
}
