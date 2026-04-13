<?php

namespace App\Command;

use App\Builder\CardBuilder;
use App\Entity\Card;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:import:cards',
    description: 'Import cards from Altered Community JSON files',
)]
class ImportCardsCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CardRepository $cardRepository,
        private readonly CardBuilder $cardBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Path to the JSON files directory', 'datas/databases')
            ->addOption('set', 's', InputOption::VALUE_OPTIONAL, 'Import only a specific set (e.g. CORE, ALIZE)')
            ->addOption('rarity', 'r', InputOption::VALUE_OPTIONAL, 'Comma-separated rarity references to import (e.g. COMMON,RARE,UNIQUE)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Importing Cards');

        $directory = $input->getArgument('directory');
        $setFilter = $input->getOption('set');
        $fileNameRegex = '*.json';

        $rarityFilter = null;
        if ($input->getOption('rarity')) {
            $rarityFilter = array_map('strtoupper', array_map('trim', explode(',', $input->getOption('rarity'))));
            $io->info(sprintf('Rarity filter: %s', implode(', ', $rarityFilter)));
            // Regex to filter rarity in file names like 'ALT_DUSTER_B_YZ_96_R2.json'
            $rarityRegexFilter = array_map(fn($r) => ['COMMON'=> 'C', 'RARE' => 'R1|R2', 'EXALTED' => 'E', 'UNIQUE' => 'U'][$r],$rarityFilter);
            $fileNameRegex = sprintf("/^(?:[^_]+_){5}(%s)(?:_[^.]*)?\.json$/", implode('|', $rarityRegexFilter) );
        }

        if (!is_dir($directory)) {
            $io->error(sprintf('Directory not found: %s', $directory));
            return Command::FAILURE;
        }

        $this->em->getConnection()->getConfiguration()->setMiddlewares([]);

        $finder = new Finder();
        $finder->files()->name($fileNameRegex)->in($directory);

        if ($setFilter) {
            $finder->path(sprintf('/^%s\//', preg_quote($setFilter, '/')));
        }

        $io->progressStart(iterator_count($finder));

        $created  = 0;
        $updated  = 0;
        $errors   = 0;
        $batch    = []; // alteredId => data

        foreach ($finder as $file) {
            try {
                $data      = json_decode($file->getContents(), true, 512, JSON_THROW_ON_ERROR);
                $alteredId = $data['id'] ?? null;

                if (!$alteredId) {
                    $errors++;
                    continue;
                }

                $batch[$alteredId] = $data;

                if (count($batch) >= self::BATCH_SIZE) {
                    try {
                        $this->processBatch($batch, $created, $updated, $errors, $io);
                    } catch (\Throwable $e) {
                        $io->progressFinish();
                        $io->error(sprintf('Fatal error during batch flush: %s', $e->getMessage()));
                        $io->error($e->getTraceAsString());
                        return Command::FAILURE;
                    }
                    $batch = [];
                }
            } catch (\Throwable $e) {
                $io->progressFinish();
                $io->error(sprintf('Fatal error on %s: %s', $file->getFilename(), $e->getMessage()));
                $io->error($e->getTraceAsString());
                return Command::FAILURE;
            }

            $io->progressAdvance();
        }

        // Process remaining files
        if (!empty($batch)) {
            try {
                $this->processBatch($batch, $created, $updated, $errors, $io);
            } catch (\Throwable $e) {
                $io->progressFinish();
                $io->error(sprintf('Fatal error during final batch: %s', $e->getMessage()));
                $io->error($e->getTraceAsString());
                return Command::FAILURE;
            }
        }

        $io->progressFinish();
        $io->success(sprintf('Import done: %d created, %d updated, %d errors.', $created, $updated, $errors));

        return Command::SUCCESS;
    }

    private function processBatch(array $batch, int &$created, int &$updated, int &$errors, SymfonyStyle $io): void
    {
        $alteredIds   = array_keys($batch);
        $alteredIdMap = $this->cardRepository->findAlteredIdMap($alteredIds);
        $existingCards = $this->cardRepository->findByAlteredIds(array_keys($alteredIdMap));

        foreach ($batch as $alteredId => $data) {
            try {
                $isNew = !isset($alteredIdMap[$alteredId]);
                $card  = $existingCards[$alteredId] ?? new Card();

                if (isset($data['translations']['fr-fr'])) {
                    $card = $this->cardBuilder->build($card, $data['translations']['fr-fr'], 'fr-fr');
                }

                foreach ($data['translations'] ?? [] as $locale => $translationData) {
                    if ($locale === 'fr-fr') {
                        continue;
                    }
                    $card = $this->cardBuilder->build($card, $translationData, $locale);
                }

                $card = $this->cardBuilder->build($card, $data, 'en-us');

                $this->em->persist($card->getCardGroup());
                $this->em->persist($card);

                $isNew ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $errors++;
                $io->warning(sprintf('Error on %s: %s', $alteredId, $e->getMessage()));
            }
        }

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Flush failed: %s', $e->getMessage()), 0, $e);
        }
        $this->em->clear();
        $this->cardBuilder->clearCache();
    }
}
