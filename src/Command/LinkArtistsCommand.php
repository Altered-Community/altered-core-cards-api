<?php

namespace App\Command;

use App\Repository\ArtistRepository;
use App\Repository\CardRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:link:artists',
    description: 'Fetch cards per artist from api.altered.gg and link them by reference',
)]
class LinkArtistsCommand extends Command
{
    private const SOURCE_URL   = 'https://api.altered.gg/cards';
    private const ITEMS_PER_PAGE = 36;
    private const BATCH_SIZE   = 200;

    public function __construct(
        private readonly Connection           $connection,
        private readonly ArtistRepository     $artistRepository,
        private readonly CardRepository       $cardRepository,
        private readonly HttpClientInterface  $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('artist', null, InputOption::VALUE_OPTIONAL, 'Process only this artist reference')
            ->addOption('locale', null, InputOption::VALUE_OPTIONAL, 'Locale for API calls', 'en-us');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $locale = $input->getOption('locale');

        $artists = $input->getOption('artist')
            ? array_filter([$this->artistRepository->findOneByReference($input->getOption('artist'))])
            : $this->artistRepository->findAll();

        $io->title(sprintf('Linking artists to cards (%d artist(s))', count($artists)));

        $totalLinked = 0;

        foreach ($artists as $artist) {
            $io->section($artist->getReference());
            $linked = $this->processArtist($artist->getId(), $artist->getReference(), $locale, $io);
            $totalLinked += $linked;
            $io->text(sprintf('  → %d card(s) linked', $linked));
        }

        $io->success(sprintf('%d total card–artist link(s) created.', $totalLinked));

        return Command::SUCCESS;
    }

    private function processArtist(int $artistId, string $artistReference, string $locale, SymfonyStyle $io): int
    {
        $linked = 0;
        $page   = 1;

        do {
            $data = $this->fetchPage($artistReference, $locale, $page, $io);
            if ($data === null) {
                break;
            }

            $members  = $data['hydra:member'] ?? $data['member'] ?? [];
            $nextPage = $this->getNextPage($data, $page);

            foreach ($members as $item) {
                $reference = $item['reference'] ?? null;
                if (!$reference) {
                    continue;
                }

                $cardId = $this->connection->fetchOne(
                    'SELECT id FROM card WHERE reference = :ref',
                    ['ref' => $reference]
                );

                if (!$cardId) {
                    continue;
                }

                $rows = $this->connection->executeStatement(
                    'INSERT INTO card_artist (card_id, artist_id) VALUES (:card, :artist) ON CONFLICT DO NOTHING',
                    ['card' => $cardId, 'artist' => $artistId]
                );
                $linked += $rows;
            }

            $page = $nextPage;
        } while ($page !== null);

        return $linked;
    }

    private function fetchPage(string $artistReference, string $locale, int $page, SymfonyStyle $io): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::SOURCE_URL, [
                'query' => [
                    'artist'       => $artistReference,
                    'itemsPerPage' => self::ITEMS_PER_PAGE,
                    'locale'       => $locale,
                    'page'         => $page,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $io->warning(sprintf('HTTP %d for artist "%s" page %d', $response->getStatusCode(), $artistReference, $page));
                return null;
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $io->warning(sprintf('Error fetching artist "%s" page %d: %s', $artistReference, $page, $e->getMessage()));
            return null;
        }
    }

    private function getNextPage(array $data, int $currentPage): ?int
    {
        // Hydra pagination: hydra:view.hydra:next contains the next URL
        $nextUrl = $data['hydra:view']['hydra:next'] ?? $data['view']['next'] ?? null;
        if ($nextUrl) {
            return $currentPage + 1;
        }

        // Fallback: if we got a full page, there might be more
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        if (count($members) === self::ITEMS_PER_PAGE) {
            return $currentPage + 1;
        }

        return null;
    }
}
