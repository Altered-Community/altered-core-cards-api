<?php

namespace App\Command;

use App\Repository\CardDocumentRepository;
use App\Service\MeilisearchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:meilisearch:index',
    description: 'Index all cards into Meilisearch',
)]
final class MeilisearchIndexCommand extends Command
{
    public function __construct(
        private readonly MeilisearchService $meilisearch,
        private readonly CardDocumentRepository $cardDocumentRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('configure', null, InputOption::VALUE_NONE, 'Configure index settings before indexing');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Delete all documents before re-indexing');
        $this->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated fields for partial update (e.g. set_date,collector_number_formated_id)');
        $this->addOption('set', null, InputOption::VALUE_REQUIRED, 'Comma-separated set references to index only those sets (e.g. EOLEOP,EOLETOP)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Indexing cards into Meilisearch…');

        $setOption     = $input->getOption('set');
        $setReferences = $setOption ? array_values(array_filter(array_map('trim', explode(',', $setOption)))) : [];

        if ($setReferences && $input->getOption('fields')) {
            $io->error('--set cannot be combined with --fields.');
            return Command::INVALID;
        }

        if ($setReferences && $input->getOption('clear')) {
            $io->error('--set cannot be combined with --clear (it would wipe every other set).');
            return Command::INVALID;
        }

        $fields       = $input->getOption('fields');
        $partialFields = $fields ? array_values(array_filter(array_map('trim', explode(',', $fields)))) : null;
        $isPartial     = $partialFields !== null;

        if ($isPartial && $unsupported = $this->cardDocumentRepository->unsupportedPartialFields($partialFields)) {
            $io->error(sprintf(
                'Field(s) not supported by --fields: %s. Run a full index (optionally with --set) instead.',
                implode(', ', $unsupported),
            ));
            return Command::INVALID;
        }

        if ($input->getOption('configure')) {
            $io->text('Configuring index attributes…');
            $this->meilisearch->configureIndex();
        }

        if ($input->getOption('clear')) {
            $io->text('Clearing existing documents…');
            $this->meilisearch->getIndex()->deleteAllDocuments();
        }

        if ($isPartial) {
            $io->text(sprintf('Partial update — fields: %s', implode(', ', $partialFields)));
        }

        if ($setReferences) {
            $io->text(sprintf('Sets: %s', implode(', ', $setReferences)));
        }

        $total = $setReferences
            ? $this->cardDocumentRepository->countBySetReferences($setReferences)
            : $this->cardDocumentRepository->countAll();

        if ($total === 0) {
            $io->warning($setReferences
                ? sprintf('No cards found for set(s) %s — check the references.', implode(', ', $setReferences))
                : 'No cards to index.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Streaming %d cards…', $total));

        $progressBar = $io->createProgressBar($total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        $indexed  = 0;
        $stream   = $isPartial
            ? $this->cardDocumentRepository->streamPartialDocuments($partialFields)
            : $this->cardDocumentRepository->streamDocuments(setReferences: $setReferences);

        foreach ($stream as $batch) {
            $json = json_encode($batch, JSON_INVALID_UTF8_IGNORE);

            if ($json === false) {
                $indexed += count($batch);
                $progressBar->advance(count($batch));
                continue;
            }

            try {
                $isPartial
                    ? $this->meilisearch->getIndex()->updateDocumentsJson($json)
                    : $this->meilisearch->getIndex()->addDocumentsJson($json);
            } catch (\Throwable $e) {
                $progressBar->clear();
                foreach ($batch as $doc) {
                    $docJson = json_encode($doc, JSON_INVALID_UTF8_IGNORE);
                    if ($docJson === false) {
                        $io->warning(sprintf('Card ID %d — json_encode failed', $doc['id']));
                        continue;
                    }
                    try {
                        $isPartial
                            ? $this->meilisearch->getIndex()->updateDocumentsJson($docJson)
                            : $this->meilisearch->getIndex()->addDocumentsJson($docJson);
                    } catch (\Throwable $inner) {
                        $io->warning(sprintf(
                            'Card ID %d skipped — Meilisearch rejected it: %s',
                            $doc['id'],
                            $inner->getMessage(),
                        ));
                    }
                }
                $progressBar->display();
            }

            $indexed += count($batch);
            $progressBar->advance(count($batch));
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf('%d cards indexed.', $indexed));

        return Command::SUCCESS;
    }
}
