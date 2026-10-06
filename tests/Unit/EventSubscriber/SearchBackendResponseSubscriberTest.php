<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\SearchBackendResponseSubscriber;
use App\State\SearchAwareCollectionProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SearchBackendResponseSubscriberTest extends TestCase
{
    // ── helpers ─────────────────────────────────────────────────────────────

    /** Response carrying the cache headers API Platform sets for GET /api/cards. */
    private static function cachedResponse(): Response
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setSharedMaxAge(3600);

        return $response;
    }

    private function dispatch(Request $request, Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $requestType, $response);
        (new SearchBackendResponseSubscriber())->onKernelResponse($event);

        return $event->getResponse();
    }

    private static function request(?string $backend, bool $degraded = false): Request
    {
        $request = Request::create('/api/cards');
        if ($backend !== null) {
            $request->attributes->set(SearchAwareCollectionProvider::BACKEND_ATTRIBUTE, $backend);
        }
        if ($degraded) {
            $request->attributes->set(SearchAwareCollectionProvider::DEGRADED_ATTRIBUTE, true);
        }

        return $request;
    }

    // ── tests ───────────────────────────────────────────────────────────────

    public function testRunsAfterApiPlatformCacheHeaders(): void
    {
        $events = SearchBackendResponseSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
        $this->assertLessThan(0, $events[KernelEvents::RESPONSE][1]);
    }

    public function testMeilisearchResponseKeepsCacheHeaders(): void
    {
        $response = $this->dispatch(self::request(SearchAwareCollectionProvider::BACKEND_MEILISEARCH), self::cachedResponse());

        $this->assertSame('meilisearch', $response->headers->get('X-Search-Backend'));
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('s-maxage'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testNonDegradedSqlResponseKeepsCacheHeaders(): void
    {
        $response = $this->dispatch(self::request(SearchAwareCollectionProvider::BACKEND_SQL), self::cachedResponse());

        $this->assertSame('sql', $response->headers->get('X-Search-Backend'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testDegradedResponseIsNotCached(): void
    {
        $response = $this->dispatch(self::request(SearchAwareCollectionProvider::BACKEND_SQL, degraded: true), self::cachedResponse());

        $this->assertSame('sql', $response->headers->get('X-Search-Backend'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->hasCacheControlDirective('s-maxage'));
    }

    public function testRequestWithoutSearchIsUntouched(): void
    {
        $response = $this->dispatch(self::request(null), self::cachedResponse());

        $this->assertFalse($response->headers->has('X-Search-Backend'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testSubRequestIsUntouched(): void
    {
        $response = $this->dispatch(
            self::request(SearchAwareCollectionProvider::BACKEND_SQL, degraded: true),
            self::cachedResponse(),
            HttpKernelInterface::SUB_REQUEST,
        );

        $this->assertFalse($response->headers->has('X-Search-Backend'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
    }
}
