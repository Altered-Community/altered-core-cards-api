<?php

namespace App\Command;

use App\Repository\SetRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills Card.set for cards imported before CardBuilder learned to derive the
 * set reference from Card.reference (ALT_SET_VARIANT_FACTION_NUM_RARITY) when the
 * source payload had no cardSet.reference — e.g. ALT_EOLECB_A_AX_106_C.
 *
 * Cards left with set = NULL are silently dropped by set.reference filters
 * (CardGroupSetFilter's inner JOIN), which is how this was noticed.
 */
#[AsCommand(
    name: 'app:fix:missing-card-set',
    description: 'Backfill Card.set for cards whose set could not be resolved at import time',
)]
class FixMissingCardSetCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SetRepository $setRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $connection = $this->em->getConnection();
        $rows       = $connection->executeQuery(
            'SELECT id, reference FROM card WHERE set_id IS NULL ORDER BY id'
        )->fetchAllAssociative();

        if (empty($rows)) {
            $io->success('No card is missing a set. Nothing to do.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('<info>%d card(s) missing a set.</info>', count($rows)));
        $io->progressStart(count($rows));

        $fixed    = 0;
        $unmapped = [];
        $setCache = [];
        $batch    = [];

        foreach ($rows as $row) {
            $reference    = $row['reference'];
            $setReference = explode('_', $reference)[1] ?? null;

            if ($setReference === null) {
                $unmapped[] = $reference;
                $io->progressAdvance();
                continue;
            }

            if (!array_key_exists($setReference, $setCache)) {
                $setCache[$setReference] = $this->setRepository->findOneByReference($setReference);
            }
            $set = $setCache[$setReference];

            if (!$set) {
                $unmapped[] = $reference;
                $io->progressAdvance();
                continue;
            }

            $batch[] = ['id' => (int) $row['id'], 'set_id' => $set->getId(), 'set_date' => $set->getDate()];
            $fixed++;

            if (!$dryRun && count($batch) >= self::BATCH_SIZE) {
                $this->flushBatch($connection, $batch);
                $batch = [];
            }

            $io->progressAdvance();
        }

        if (!$dryRun && !empty($batch)) {
            $this->flushBatch($connection, $batch);
        }

        $io->progressFinish();

        $io->success(sprintf(
            '%s%d card(s) matched to a set, %d card(s) left unmapped (no matching set reference).',
            $dryRun ? '[DRY RUN] ' : '',
            $fixed,
            count($unmapped),
        ));

        if (!empty($unmapped)) {
            $io->table(['Unmapped reference'], array_map(fn($ref) => [$ref], array_slice($unmapped, 0, 50)));
            if (count($unmapped) > 50) {
                $io->writeln(sprintf('… and %d more.', count($unmapped) - 50));
            }
        }

        return Command::SUCCESS;
    }

    /** @param list<array{id: int, set_id: int, set_date: ?\DateTimeImmutable}> $batch */
    private function flushBatch(Connection $connection, array $batch): void
    {
        foreach ($batch as $entry) {
            $connection->executeStatement(
                'UPDATE card SET set_id = :set_id, set_date = :set_date WHERE id = :id',
                [
                    'set_id'   => $entry['set_id'],
                    'set_date' => $entry['set_date']?->format('Y-m-d H:i:s'),
                    'id'       => $entry['id'],
                ],
            );
        }
    }
}
