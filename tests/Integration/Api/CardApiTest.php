<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\DataFixtures\AppFixtures;
use App\Entity\Card;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CardApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_ACCEPT', 'application/json');

        $em = static::getContainer()->get('doctrine')->getManager();
        $loader = new Loader();
        $loader->addFixture(new AppFixtures());
        $executor = new ORMExecutor($em, new ORMPurger($em));
        $executor->execute($loader->getFixtures());
    }

    public function testGetCardsCollectionReturns200(): void
    {
        $this->client->request('GET', '/api/cards');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('application/json', $this->client->getResponse()->headers->get('content-type'));

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('member', $data);
        $this->assertArrayHasKey('totalItems', $data);
        $this->assertIsArray($data['member']);
    }

    public function testGetCardsCollectionContainsFixtureCard(): void
    {
        $this->client->request('GET', '/api/cards');

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertGreaterThanOrEqual(1, $data['totalItems']);

        $references = array_column($data['member'], 'reference');
        $this->assertContains(AppFixtures::CARD_REFERENCE, $references);
    }

    public function testGetCardByIdReturns200(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $card = $em->getRepository(Card::class)->findOneBy(['reference' => AppFixtures::CARD_REFERENCE]);

        $this->client->request('GET', '/api/cards/' . $card->getId());

        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('reference', $body);
    }

    public function testGetCardByReferenceReturns200(): void
    {
        $this->client->request('GET', '/api/cards/reference/' . AppFixtures::CARD_REFERENCE);

        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(AppFixtures::CARD_REFERENCE, $body['reference']);
    }

    public function testBatchWithEmptyBodyReturnsBadRequest(): void
    {
        $this->client->request('POST', '/api/cards/batch', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testBatchWithTooManyReferencesReturnsBadRequest(): void
    {
        $references = array_fill(0, 201, AppFixtures::CARD_REFERENCE);

        $this->client->request('POST', '/api/cards/batch', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['references' => $references]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testBatchReturnsMatchingCards(): void
    {
        $this->client->request('POST', '/api/cards/batch', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'references' => [AppFixtures::CARD_REFERENCE],
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame(AppFixtures::CARD_REFERENCE, $data[0]['reference']);
    }

    public function testBatchWithUnknownReferenceReturnsEmptyArray(): void
    {
        $this->client->request('POST', '/api/cards/batch', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'references' => ['UNKNOWN_REF_XYZ'],
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data);
    }

    public function testCollectionIsCacheable(): void
    {
        $this->client->request('GET', '/api/cards');

        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();

        $this->assertSame('sql', $response->headers->get('X-Search-Backend'));
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testNameSearchFallbackIsNotCached(): void
    {
        // Meilisearch is not configured in the test environment: the full-text
        // search falls back to SQL (names only) and must not be cached.
        $this->client->request('GET', '/api/cards?name[fr]=aérolithe');

        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();

        $this->assertSame('sql', $response->headers->get('X-Search-Backend'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
    }
}
