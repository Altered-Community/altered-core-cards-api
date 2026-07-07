<?php

namespace App\MessageHandler;

use App\Message\SyncGameplayFormatsMessage;
use App\Service\GameplayFormatSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncGameplayFormatsMessageHandler
{
    public function __construct(
        private readonly GameplayFormatSyncService $syncService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncGameplayFormatsMessage $message): void
    {
        $results = $this->syncService->syncAll();

        foreach ($results as $result) {
            $this->logger->info('Gameplay format synced', $result);
        }
    }
}
