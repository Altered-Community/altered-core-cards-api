<?php

namespace App\Command;

use App\Entity\Artist;
use App\Repository\ArtistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:artists',
    description: 'Create or update artists from the Altered TCG reference data',
)]
class ImportArtistsCommand extends Command
{
    private const ARTISTS = [
        'Federico Musetti',
        'Alexandre Honoré',
        'Sweorkie',
        'Bastien Jez',
        'Ina Wong',
        'Dimas Iswara',
        'Edward Chee - Seok Yeong',
        'Max Fiévé',
        'Zero Wen',
        'Polar Engine',
        'Fahmi Fauzi',
        'Nestor Papatriantafyllou',
        'Khoa Viet',
        'HuoMiao Studio',
        'Damian Audino',
        'Ba Vo',
        'Zaeliven',
        'Romain Kurdi',
        'Justice Wong',
        'Edward Cheekokseang',
        'Gaga Zhou',
        'Jean-Baptiste Andrier',
        'Atanas Lozanski',
        'Kevin Sidharta',
        'Gael Giudicelli',
        'Slawek Fedorcuzk',
        'Lirong Fan',
        'Zhou jia',
        'Sebastian Giacobino',
        'Helder Almeida',
        'Paris Loannou',
        'Taras Susak',
        'Marie Cardouat',
        'Anh Tung - Ba Vo',
        'Ed Chee, S.Yong, Stephen',
        'DOBA',
        'Rémi Jacquot',
        'Matteo Spirito',
        'Fori Y.',
        'Christophe Young',
        'Exia',
        'Benoit Barraqué-Curie',
        'Eilene Cherie Witarsah',
        'Nathan Maneval',
        'Giovanni Calore',
        'Alexandre Bonvalot',
        'Anh Tung',
        'Aleksandr Leskinen',
        'Victor Canton',
        'Gamon Studio',
        'Iris Wincker',
        'Aaron Ming',
        'Tristan Bideau',
        'Leena Sooba',
        'Denice Vis',
        'Andy Jauffrit',
        'Jefrey Yonathan',
        'Julien Carrasco',
        'Martin Mottet',
        'Saeed Jalabi',
        'Jamin Amaral Fernandez',
        'Abigael Giroud',
        'Seppyo',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArtistRepository $artistRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = 0;

        foreach (self::ARTISTS as $reference) {
            $reference = trim($reference);
            $artist = $this->artistRepository->findOneByReference($reference);

            if (!$artist) {
                $artist = new Artist();
                $this->em->persist($artist);
                $io->text(sprintf('Creating artist: %s', $reference));
                $count++;
            }

            $artist->setReference($reference);
            $artist->setName($reference);
        }

        $this->em->flush();

        $io->success(sprintf('%d artist(s) created.', $count));

        return Command::SUCCESS;
    }
}
