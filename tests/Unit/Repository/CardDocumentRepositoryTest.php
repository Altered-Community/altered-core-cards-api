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
    private function emptyResult(): Result
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);
        return $result;
    }

    public function testStreamDocumentsFiltersOnSetReferences(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('WHERE cs.reference IN (:setReferences)'),
                ['setReferences' => ['EOLEOP', 'EOLETOP']],
                ['setReferences' => ArrayParameterType::STRING],
            )
            ->willReturn($this->emptyResult());

        $batches = iterator_to_array(
            (new CardDocumentRepository($conn))->streamDocuments(setReferences: ['EOLEOP', 'EOLETOP'])
        );

        $this->assertSame([], $batches);
    }

    public function testStreamDocumentsWithoutSetReferencesHasNoSetFilter(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->logicalNot($this->stringContains('WHERE cs.reference')))
            ->willReturn($this->emptyResult());

        iterator_to_array((new CardDocumentRepository($conn))->streamDocuments());
    }

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
