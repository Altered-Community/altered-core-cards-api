<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\MeilisearchIndexCommand;
use App\Repository\CardDocumentRepository;
use App\Service\MeilisearchService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

final class MeilisearchIndexCommandTest extends TestCase
{
    /** @var list<string> */
    private array $httpCalls = [];

    // ── helpers ─────────────────────────────────────────────────────────────

    private function tester(Connection $conn): CommandTester
    {
        $this->httpCalls = [];
        $httpClient = new MockHttpClient(function (string $method, string $url): never {
            $this->httpCalls[] = "$method $url";
            throw new \RuntimeException('Unexpected Meilisearch call');
        });

        $repository  = new CardDocumentRepository($conn);
        $meilisearch = new MeilisearchService($httpClient, $repository, 'http://meilisearch.test', 'key');

        return new CommandTester(new MeilisearchIndexCommand($meilisearch, $repository));
    }

    // ── guards ──────────────────────────────────────────────────────────────

    public function testSetWithClearIsRejectedBeforeAnyCall(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())->method($this->anything());

        $tester = $this->tester($conn);
        $code   = $tester->execute(['--set' => 'EOLEOP', '--clear' => true]);

        $this->assertSame(Command::INVALID, $code);
        $this->assertStringContainsString('--set cannot be combined with --clear', $tester->getDisplay());
        $this->assertSame([], $this->httpCalls);
    }

    public function testSetWithFieldsIsRejected(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->never())->method($this->anything());

        $tester = $this->tester($conn);
        $code   = $tester->execute(['--set' => 'EOLEOP', '--fields' => 'set_date']);

        $this->assertSame(Command::INVALID, $code);
        $this->assertStringContainsString('--set cannot be combined with --fields', $tester->getDisplay());
    }

    // ── set filter ──────────────────────────────────────────────────────────

    public function testSetOptionCountsAndStreamsOnlyThoseSets(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('cs.reference IN'), ['setReferences' => ['EOLEOP', 'EOLETOP']])
            ->willReturn('3');
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with($this->stringContains('WHERE cs.reference IN (:setReferences)'), ['setReferences' => ['EOLEOP', 'EOLETOP']])
            ->willReturn($result);

        $tester = $this->tester($conn);
        $code   = $tester->execute(['--set' => ' EOLEOP , EOLETOP ,']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Sets: EOLEOP, EOLETOP', $tester->getDisplay());
        $this->assertStringContainsString('Streaming 3 cards', $tester->getDisplay());
    }

    public function testUnknownSetWarnsWithoutStreaming(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('0');
        $conn->expects($this->never())->method('executeQuery');

        $tester = $this->tester($conn);
        $code   = $tester->execute(['--set' => 'TYPO']);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('No cards found for set(s) TYPO', $tester->getDisplay());
        $this->assertSame([], $this->httpCalls);
    }
}
