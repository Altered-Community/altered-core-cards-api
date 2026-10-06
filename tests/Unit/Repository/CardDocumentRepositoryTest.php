<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\CardDocumentRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

final class CardDocumentRepositoryTest extends TestCase
{
    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $rows */
    private function dbResult(array $rows = []): Result
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...[...$rows, false]);
        return $result;
    }

    /** Minimal row as returned by buildSql(), with every column hydrate() reads. */
    private function fullRow(array $overrides = []): array
    {
        $columns = [
            'id', 'reference', 'kickstarter', 'promo', 'transfuge', 'is_serialized', 'variation',
            'collector_number_formated_id', 'set_date', 'set_reference', 'main_cost', 'recall_cost',
            'ocean_power', 'mountain_power', 'forest_power', 'is_banned', 'is_suspended', 'is_errated',
            'faction_code', 'rarity', 'card_type', 'has_effect', 'keywords',
            'name_fr', 'name_en', 'main_effect_fr', 'main_effect_en', 'echo_effect_fr', 'echo_effect_en',
            'gameplay_format',
        ];

        return array_merge(array_fill_keys($columns, null), ['id' => 1, 'sub_types' => '[]'], $overrides);
    }

    private function connectionWithIdBounds(?int $min, ?int $max): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->atMost(1))
            ->method('fetchAssociative')
            ->with($this->stringContains('MIN(id)'))
            ->willReturn(['min_id' => $min, 'max_id' => $max]);
        return $conn;
    }

    // ── streamDocuments ─────────────────────────────────────────────────────

    public function testStreamDocumentsQueriesOneIdRangePerChunk(): void
    {
        $conn  = $this->connectionWithIdBounds(1, 3);
        $calls = [];
        $conn->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params) use (&$calls) {
                $calls[] = $params;
                return $this->dbResult();
            });

        iterator_to_array((new CardDocumentRepository($conn))->streamDocuments(batchSize: 2));

        $this->assertSame([['fromId' => 1, 'toId' => 2], ['fromId' => 3, 'toId' => 4]], $calls);
    }

    public function testStreamDocumentsWithSetReferencesQueriesTheirIdsInChunks(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())->method('fetchAssociative');
        $conn->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(
                $this->stringContains('WHERE cs.reference IN (:setReferences)'),
                ['setReferences' => ['EOLEOP', 'EOLETOP']],
                ['setReferences' => ArrayParameterType::STRING],
            )
            ->willReturn([4, 8, 15]);

        $calls = [];
        $conn->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$calls) {
                $this->assertStringContainsString('WHERE c.id IN (:ids)', $sql);
                $this->assertSame(['ids' => ArrayParameterType::INTEGER], $types);
                $calls[] = $params['ids'];
                return $this->dbResult();
            });

        iterator_to_array((new CardDocumentRepository($conn))->streamDocuments(batchSize: 2, setReferences: ['EOLEOP', 'EOLETOP']));

        $this->assertSame([[4, 8], [15]], $calls);
    }

    public function testStreamDocumentsWithoutSetReferencesHasNoSetFilter(): void
    {
        $conn = $this->connectionWithIdBounds(1, 10);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('c.id BETWEEN :fromId AND :toId'))
            ->willReturn($this->dbResult());

        iterator_to_array((new CardDocumentRepository($conn))->streamDocuments());
    }

    public function testStreamDocumentsOnEmptyTableRunsNoQuery(): void
    {
        $conn = $this->connectionWithIdBounds(null, null);
        $conn->expects($this->never())->method('executeQuery');

        $this->assertSame([], iterator_to_array((new CardDocumentRepository($conn))->streamDocuments()));
    }

    public function testFullDocumentIncludesCostRelation(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->willReturn($this->dbResult([$this->fullRow(['main_cost' => 3, 'recall_cost' => 1])]));

        $doc = (new CardDocumentRepository($conn))->findDocument(1);

        $this->assertSame('mainHigher', $doc['cost_relation']);
    }

    // ── streamPartialDocuments ──────────────────────────────────────────────

    public function testPartialCardTypeGoesThroughJoins(): void
    {
        $conn = $this->connectionWithIdBounds(1, 1);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->logicalAnd(
                $this->stringContains('ct.reference AS card_type'),
                $this->stringContains('LEFT JOIN card_group cg'),
                $this->stringContains('LEFT JOIN card_type  ct'),
                $this->stringContains('c.id BETWEEN :fromId AND :toId'),
                $this->logicalNot($this->stringContains('c.card_type')),
            ))
            ->willReturn($this->dbResult([['id' => '1', 'card_type' => 'CHARACTER']]));

        $batches = iterator_to_array((new CardDocumentRepository($conn))->streamPartialDocuments(['card_type']));

        $this->assertSame([[['id' => 1, 'card_type' => 'CHARACTER']]], $batches);
    }

    public function testPartialCastsIntAndBoolFields(): void
    {
        $conn = $this->connectionWithIdBounds(1, 1);
        $conn->method('executeQuery')
            ->willReturn($this->dbResult([['id' => '1', 'main_cost' => '3', 'recall_cost' => null, 'is_banned' => 0]]));

        $batches = iterator_to_array(
            (new CardDocumentRepository($conn))->streamPartialDocuments(['main_cost', 'recall_cost', 'is_banned'])
        );

        $this->assertSame([[['id' => 1, 'main_cost' => 3, 'recall_cost' => null, 'is_banned' => false]]], $batches);
    }

    public function testUnsupportedPartialFields(): void
    {
        $repo = new CardDocumentRepository($this->createStub(Connection::class));

        $this->assertSame(['name_fr', 'sub_types'], $repo->unsupportedPartialFields(['card_type', 'name_fr', 'keywords', 'sub_types']));
        $this->assertSame([], $repo->unsupportedPartialFields(['set_reference', 'cost_relation', 'gameplay_format']));
    }

    public function testPartialRejectsUnsupportedField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name_fr');

        iterator_to_array((new CardDocumentRepository($this->createStub(Connection::class)))->streamPartialDocuments(['name_fr']));
    }

    // ── counts ──────────────────────────────────────────────────────────────

    public function testCountBySetReferences(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('WHERE cs.reference IN (:setReferences)'),
                ['setReferences' => ['EOLECB']],
                ['setReferences' => ArrayParameterType::STRING],
            )
            ->willReturn('42');

        $this->assertSame(42, (new CardDocumentRepository($conn))->countBySetReferences(['EOLECB']));
    }
}
