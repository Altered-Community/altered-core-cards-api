<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\ProviderInterface;
use App\Repository\CardDocumentRepository;
use App\Repository\FilteredCardCountRepository;
use App\Service\FilterCacheKeyService;
use App\Service\MeilisearchFilterBuilderService;
use App\Service\MeilisearchService;
use App\State\SearchAwareCollectionProvider;
use Doctrine\DBAL\Connection;
use Meilisearch\Exceptions\CommunicationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SearchAwareCollectionProviderTest extends TestCase
{
    private const NAME_SEARCH = ['name' => ['fr' => 'aérolithe']];

    private Request $request;

    /** Context received by the inner (Doctrine) provider. */
    private ?array $innerContext = null;

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param list<MockResponse|\Throwable> $responses Meilisearch responses, in call order
     */
    private function provider(array $responses, LoggerInterface $logger): SearchAwareCollectionProvider
    {
        $httpClient = new MockHttpClient(static function () use (&$responses): MockResponse {
            $response = array_shift($responses) ?? throw new \LogicException('Unexpected Meilisearch call');
            if ($response instanceof \Throwable) {
                throw $response;
            }
            return $response;
        });

        $connection  = $this->createStub(Connection::class);
        $meilisearch = new MeilisearchService($httpClient, new CardDocumentRepository($connection), 'http://meilisearch.test', 'key');

        $inner = $this->createStub(ProviderInterface::class);
        $inner->method('provide')->willReturnCallback(function ($operation, $uriVariables, array $context): array {
            $this->innerContext = $context;
            return [];
        });

        $this->request = Request::create('/api/cards');
        $requestStack  = new RequestStack();
        $requestStack->push($this->request);

        return new SearchAwareCollectionProvider(
            $inner,
            $meilisearch,
            new FilterCacheKeyService(),
            new ArrayAdapter(),
            new FilteredCardCountRepository($connection),
            new MeilisearchFilterBuilderService(),
            $requestStack,
            $logger,
        );
    }

    private static function hits(int ...$ids): MockResponse
    {
        return new MockResponse(json_encode([
            'hits'               => array_map(static fn (int $id) => ['id' => $id], $ids),
            'offset'             => 0,
            'limit'              => 30,
            'estimatedTotalHits' => count($ids),
            'processingTimeMs'   => 1,
            'query'              => 'aérolithe',
        ]), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    private function provide(SearchAwareCollectionProvider $provider, array $filters): void
    {
        $provider->provide(new GetCollection(), [], ['filters' => $filters]);
    }

    // ── Meilisearch available ───────────────────────────────────────────────

    public function testSuccessfulSearchUsesMeilisearchIds(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->provide($this->provider([self::hits(1, 2, 3), self::hits(1, 2, 3)], $logger), self::NAME_SEARCH);

        $this->assertSame([1, 2, 3], $this->innerContext['_meili_ids']);
        $this->assertSame(3, $this->innerContext['_meili_total']);
        $this->assertArrayNotHasKey('name', $this->innerContext['filters']);
        $this->assertSame(SearchAwareCollectionProvider::BACKEND_MEILISEARCH, $this->request->attributes->get(SearchAwareCollectionProvider::BACKEND_ATTRIBUTE));
        $this->assertFalse($this->request->attributes->has(SearchAwareCollectionProvider::DEGRADED_ATTRIBUTE));
    }

    public function testCountFailureIsLoggedAndKeepsMeilisearchIds(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('total count failed'),
                $this->callback(static fn (array $context) => $context['query'] === 'aérolithe'
                    && $context['message'] === 'Timeout'),
            );

        $provider = $this->provider([self::hits(1, 2), new TransportException('Timeout')], $logger);
        $this->provide($provider, self::NAME_SEARCH);

        $this->assertSame([1, 2], $this->innerContext['_meili_ids']);
        $this->assertArrayNotHasKey('_meili_total', $this->innerContext);
        $this->assertFalse($this->request->attributes->has(SearchAwareCollectionProvider::DEGRADED_ATTRIBUTE));
    }

    // ── Meilisearch unavailable ─────────────────────────────────────────────

    public function testNameSearchFailureIsLoggedAndMarkedDegraded(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('falling back to SQL'),
                $this->callback(static fn (array $context) => $context['query'] === 'aérolithe'
                    && $context['message'] === 'Idle timeout reached'
                    && $context['exception'] instanceof CommunicationException),
            );

        $provider = $this->provider([new TransportException('Idle timeout reached')], $logger);
        $this->provide($provider, self::NAME_SEARCH);

        $this->assertArrayNotHasKey('_meili_ids', $this->innerContext);
        $this->assertSame(self::NAME_SEARCH['name'], $this->innerContext['filters']['name']);
        $this->assertSame(SearchAwareCollectionProvider::BACKEND_SQL, $this->request->attributes->get(SearchAwareCollectionProvider::BACKEND_ATTRIBUTE));
        $this->assertTrue($this->request->attributes->get(SearchAwareCollectionProvider::DEGRADED_ATTRIBUTE));
    }

    public function testFilterOnlyFailureIsNotDegraded(): void
    {
        // Without a name query, the SQL fallback applies the same filters: the result is complete.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $provider = $this->provider([new TransportException('Idle timeout reached')], $logger);
        $this->provide($provider, ['rarity' => 'COMMON']);

        $this->assertSame(SearchAwareCollectionProvider::BACKEND_SQL, $this->request->attributes->get(SearchAwareCollectionProvider::BACKEND_ATTRIBUTE));
        $this->assertFalse($this->request->attributes->has(SearchAwareCollectionProvider::DEGRADED_ATTRIBUTE));
    }

    // ── no search ───────────────────────────────────────────────────────────

    public function testPlainListingUsesSqlWithoutCallingMeilisearch(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->provide($this->provider([], $logger), []);

        $this->assertSame(SearchAwareCollectionProvider::BACKEND_SQL, $this->request->attributes->get(SearchAwareCollectionProvider::BACKEND_ATTRIBUTE));
        $this->assertFalse($this->request->attributes->has(SearchAwareCollectionProvider::DEGRADED_ATTRIBUTE));
    }
}
