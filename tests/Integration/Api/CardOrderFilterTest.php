<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Card;
use App\Entity\CardGroup;
use App\Entity\CardGroupTranslation;
use App\Entity\CardType;
use App\Entity\Faction;
use App\Entity\Rarity;
use App\Entity\Set;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression tests for the sorting bugs reported against /api/cards:
 *  - order[reference] was not honored (silently ignored)
 *  - order[name.<lang>] was not honored (silently ignored)
 *  - combining several order[...] params did not respect their declared
 *    priority (later params were applied with equal/higher priority than
 *    the first one instead of only breaking ties on it)
 */
final class CardOrderFilterTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_ACCEPT', 'application/json');

        $this->em = static::getContainer()->get('doctrine')->getManager();
        (new ORMPurger($this->em))->purge();

        $this->loadFixtures();
    }

    private function loadFixtures(): void
    {
        $em = $this->em;

        $faction = (new Faction())->setName('Axiom')->setCode('AX')->setPosition(1);
        $cardType = (new CardType())->setReference('HERO')->setNameFr('Héros')->setNameEn('Hero');
        $rarity = (new Rarity())->setReference('COMMON')->setNameFr('Commun')->setNameEn('Common')->setPosition(1);
        $em->persist($faction);
        $em->persist($cardType);
        $em->persist($rarity);

        $setCore = new Set();
        $setCore->setName('Core');
        $setCore->setNameEn('Core');
        $setCore->setReference('CORE');
        $setCore->setAlteredId('CORE');
        $setCore->setDate(new \DateTimeImmutable('2023-01-01'));
        $setCore->setCreationDate(new \DateTimeImmutable());
        $em->persist($setCore);

        $setEole = new Set();
        $setEole->setName('Eole');
        $setEole->setNameEn('Eole');
        $setEole->setReference('EOLE');
        $setEole->setAlteredId('EOLE');
        $setEole->setDate(new \DateTimeImmutable('2024-01-01'));
        $setEole->setCreationDate(new \DateTimeImmutable());
        $em->persist($setEole);

        // mainCost / name deliberately NOT in alphabetical/creation order,
        // so a fallback (insertion or PK) order would not accidentally pass.
        $groupSpecs = [
            'cg1' => ['mainCost' => 0, 'name' => 'Zebra'],
            'cg2' => ['mainCost' => 1, 'name' => 'Yankee'],
            'cg3' => ['mainCost' => 0, 'name' => 'Alpha'],
            'cg4' => ['mainCost' => 1, 'name' => 'Bravo'],
        ];
        $cardGroups = [];
        foreach ($groupSpecs as $key => $spec) {
            $cardGroup = (new CardGroup())
                ->setSlug('slug-' . $key)
                ->setFaction($faction)
                ->setCardType($cardType)
                ->setRarity($rarity)
                ->setMainCost($spec['mainCost'])
                ->setRecallCost(1);
            $em->persist($cardGroup);

            $translation = (new CardGroupTranslation())
                ->setCardGroup($cardGroup)
                ->setLocale('fr')
                ->setName($spec['name']);
            $em->persist($translation);

            $cardGroups[$key] = $cardGroup;
        }

        // References/collector numbers interleave EOLE/CORE on insertion so a
        // set-grouped fallback order is distinguishable from a real global sort.
        $cardSpecs = [
            ['ref' => 'ALT_B_1', 'set' => $setEole, 'group' => $cardGroups['cg1'], 'collector' => 'EOLE-001-C-FR'],
            ['ref' => 'ALT_D_1', 'set' => $setEole, 'group' => $cardGroups['cg2'], 'collector' => 'EOLE-003-C-FR'],
            ['ref' => 'ALT_A_1', 'set' => $setCore, 'group' => $cardGroups['cg3'], 'collector' => 'CORE-002-C-FR'],
            ['ref' => 'ALT_C_1', 'set' => $setCore, 'group' => $cardGroups['cg4'], 'collector' => 'CORE-004-C-FR'],
        ];

        foreach ($cardSpecs as $i => $spec) {
            $card = (new Card())
                ->setReference($spec['ref'])
                ->setAlteredId($spec['ref'])
                ->setCardNumber($i + 1)
                ->setCardGroup($spec['group'])
                ->setRarity($rarity)
                ->setSet($spec['set'])
                ->setCollectorNumberFormatedId($spec['collector'])
                ->setKickstarter(false)
                ->setPromo(false)
                ->setIsSerialized(false)
                ->setIsOwnerless(false)
                ->setTransfuge(false)
                ->setIsParentSerialized(false);
            $em->persist($card);
        }

        $em->flush();
        $em->clear();
    }

    private function getReferences(array $order): array
    {
        // rarity=COMMON matches every fixture card here; it exists only to keep
        // $filters non-empty so CachedCountCollectionProvider skips its Postgres-only
        // pg_class row-estimate path (unrelated to ordering) and runs a plain COUNT.
        $this->client->request('GET', '/api/cards', [
            'itemsPerPage' => 50,
            'rarity' => 'COMMON',
            'order' => $order,
        ]);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        return array_column($data['member'], 'reference');
    }

    public function testOrderByReferenceIsGloballyAlphabetical(): void
    {
        $this->assertSame(
            ['ALT_A_1', 'ALT_B_1', 'ALT_C_1', 'ALT_D_1'],
            $this->getReferences(['reference' => 'asc']),
        );
    }

    public function testOrderByLocalizedNameIsGloballyAlphabetical(): void
    {
        // fr names: A=Alpha, B=Zebra, C=Bravo, D=Yankee
        $this->assertSame(
            ['ALT_A_1', 'ALT_C_1', 'ALT_D_1', 'ALT_B_1'],
            $this->getReferences(['name.fr' => 'asc']),
        );
    }

    public function testMultiFieldOrderRespectsDeclaredPriority(): void
    {
        // mainCost is primary: A/B=0, C/D=1. set.date/collectorNumberFormatedId
        // must only break ties within each mainCost group, never reorder across it.
        $this->assertSame(
            ['ALT_B_1', 'ALT_A_1', 'ALT_D_1', 'ALT_C_1'],
            $this->getReferences([
                'mainCost' => 'asc',
                'set.date' => 'desc',
                'collectorNumberFormatedId' => 'asc',
            ]),
        );
    }
}
