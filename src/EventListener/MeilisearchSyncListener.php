<?php

namespace App\EventListener;

use App\Entity\Card;
use App\Entity\CardGroup;
use App\Entity\CardGroupTranslation;
use App\Service\MeilisearchService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Keep Meilisearch in sync with Doctrine changes.
 *
 * - Card insert/update  → re-index the card
 * - Card delete         → remove from index
 * - CardGroup update    → re-index all its cards
 * - CardGroupTranslation update → re-index all cards of the parent group
 */
#[AsEntityListener(event: Events::postPersist, entity: Card::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Card::class)]
#[AsEntityListener(event: Events::preRemove, entity: Card::class)]
#[AsEntityListener(event: Events::postUpdate, entity: CardGroup::class)]
#[AsEntityListener(event: Events::postPersist, entity: CardGroupTranslation::class)]
#[AsEntityListener(event: Events::postUpdate, entity: CardGroupTranslation::class)]
final class MeilisearchSyncListener
{
    public function __construct(private readonly MeilisearchService $meilisearch) {}

    public function postPersist(Card|CardGroupTranslation $entity, PostPersistEventArgs $args): void
    {
        if (!$this->meilisearch->isEnable()) {
            return;
        }
        
        if ($entity instanceof Card) {
            $this->meilisearch->indexCard($entity);
        } else {
            foreach($entity->getCardGroup()->getCards() as $card) {
                $this->meilisearch->indexCard($card);
            }
        }
    }

    public function postUpdate(Card|CardGroup|CardGroupTranslation $entity, PostUpdateEventArgs $args): void
    {
        if (!$this->meilisearch->isEnable()) {
            return;
        }

        if ($entity instanceof Card) {
            $this->meilisearch->indexCard($entity);
        } else {
            $group = $entity instanceof CardGroup ? $entity : $entity->getCardGroup();
            foreach($group->getCards() as $card) {
                $this->meilisearch->indexCard($card);
            }
        }
    }

    public function preRemove(Card $card, PreRemoveEventArgs $args): void
    {
        if (!$this->meilisearch->isEnable()) {
            return;
        }

        $this->meilisearch->deleteCard($card);
    }
}
