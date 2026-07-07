<?php

namespace App\Command;

use App\Service\GameplayFormatSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:gameplay-formats',
    description: 'Sync CardGroup.gameplayFormat from the Altered Reunion formats manifest',
)]
class SyncGameplayFormatsCommand extends Command
{
    public function __construct(
        private readonly GameplayFormatSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $results = $this->syncService->syncAll();

        if (empty($results)) {
            $io->success('No new or changed gameplay format version. Nothing to do.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Format', 'Name', 'Version', 'Added', 'Removed'],
            array_map(
                fn (array $r) => [$r['id'], $r['name'], $r['version'], $r['added'], $r['removed']],
                $results,
            ),
        );

        $io->success(sprintf('%d gameplay format(s) synced.', count($results)));

        return Command::SUCCESS;
    }
}
