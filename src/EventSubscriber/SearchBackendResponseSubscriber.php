<?php

namespace App\EventSubscriber;

use App\State\SearchAwareCollectionProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exposes which backend answered a card search (X-Search-Backend) and makes
 * degraded responses uncacheable.
 *
 * When Meilisearch is unavailable, a full-text search falls back to SQL, which
 * only matches card names (not effect texts). That partial result must not be
 * reused for the operation's max-age (1 h), so Cache-Control is overridden
 * with no-store.
 *
 * Runs after API Platform has set the operation's cache headers.
 */
final class SearchBackendResponseSubscriber implements EventSubscriberInterface
{
    public const BACKEND_HEADER = 'X-Search-Backend';

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $attributes = $event->getRequest()->attributes;
        $backend    = $attributes->get(SearchAwareCollectionProvider::BACKEND_ATTRIBUTE);
        if ($backend === null) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set(self::BACKEND_HEADER, $backend);

        if ($attributes->getBoolean(SearchAwareCollectionProvider::DEGRADED_ATTRIBUTE)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->remove('Expires');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }
}
