<?php

namespace App\Controller\Admin;

use App\Form\GameplayFormatFilterType;
use App\Repository\CardGroupRepository;
use App\Repository\CardRepository;
use App\Service\GameplayFormatAdminSearchService;
use App\Service\GameplayFormatImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/gameplay-formats', name: 'admin_gameplay_formats_')]
class GameplayFormatCrudController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly CardRepository $cardRepo,
        private readonly CardGroupRepository $cardGroupRepo,
        private readonly EntityManagerInterface $em,
        private readonly GameplayFormatImportService $importService,
        private readonly GameplayFormatAdminSearchService $searchService,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $formats = $this->cardGroupRepo->findDistinctGameplayFormats();

        $filterForm = $this->createForm(GameplayFormatFilterType::class, null, [
            'format_choices' => $formats,
        ]);
        $filterForm->handleRequest($request);

        $data           = $filterForm->isSubmitted() ? $filterForm->getData() : [];
        $cardNumber     = trim((string) ($data['cardNumber'] ?? ''));
        $gameplayFormat = (string) ($data['gameplayFormat'] ?? '');

        // `card` holds every print (millions of rows) — never run the unfiltered
        // COUNT/SELECT, it would scan the whole table on every page load.
        $hasFilter = $cardNumber !== '' || $gameplayFormat !== '';
        $page      = max(1, $request->query->getInt('page', 1));

        if ($hasFilter) {
            [$cards, $total] = $this->searchService->search($cardNumber, $gameplayFormat, $page, self::PER_PAGE);
        } else {
            $cards = [];
            $total = 0;
        }

        return $this->render('admin/gameplay_formats/index.html.twig', [
            'cards'      => $cards,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'filterForm' => $filterForm,
            'hasFilter'  => $hasFilter,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $cardGroup = $this->cardGroupRepo->find($id);
        if (!$cardGroup) {
            throw $this->createNotFoundException("CardGroup #{$id} introuvable.");
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('gameplay_format_edit_' . $id, $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $values = array_values(array_unique(array_filter(
                array_map(fn(string $v) => strtoupper(trim($v)), $request->request->all('gameplayFormat')),
                fn(string $v) => $v !== '',
            )));
            $cardGroup->setGameplayFormat($values);
            $this->em->flush();

            $this->addFlash('success', 'Gameplay formats mis à jour.');
            return $this->redirectToRoute('admin_gameplay_formats_edit', ['id' => $id]);
        }

        $formats = array_values(array_unique(array_merge(
            $this->cardGroupRepo->findDistinctGameplayFormats(),
            $cardGroup->getGameplayFormat(),
        )));
        sort($formats);

        return $this->render('admin/gameplay_formats/edit.html.twig', [
            'cardGroup' => $cardGroup,
            'formats'   => $formats,
        ]);
    }

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $formatName    = trim((string) $request->request->get('formatName', ''));
        $url           = trim((string) $request->request->get('url', ''));
        $resetPrevious = $request->request->getBoolean('resetPrevious');
        $preview       = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('gameplay_format_import', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            if ($formatName === '' || $url === '') {
                $this->addFlash('error', 'Le nom du gameplay format et l\'URL sont requis.');
            } else {
                $preview = $this->importService->fetchAndValidate($url);
                if (!$preview->ok) {
                    $this->addFlash('error', $preview->error);
                }
            }
        }

        return $this->render('admin/gameplay_formats/import.html.twig', [
            'formatName'    => $formatName,
            'url'           => $url,
            'resetPrevious' => $resetPrevious,
            'preview'       => $preview,
        ]);
    }

    #[Route('/import/confirm', name: 'import_confirm', methods: ['POST'])]
    public function importConfirm(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('gameplay_format_import_confirm', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $formatName    = trim((string) $request->request->get('formatName', ''));
        $url           = trim((string) $request->request->get('url', ''));
        $resetPrevious = $request->request->getBoolean('resetPrevious');

        $result = $this->importService->fetchAndValidate($url);
        if (!$result->ok) {
            $this->addFlash('error', 'Import annulé : ' . $result->error);
            return $this->redirectToRoute('admin_gameplay_formats_import');
        }

        $updated = $this->importService->apply($formatName, $result->matchedCardGroupIds, $resetPrevious);

        $this->addFlash('success', sprintf(
            'Gameplay format "%s" appliqué à %d carte(s) (%d référence(s) non trouvée(s))%s.',
            strtoupper($formatName),
            $updated,
            count($result->unmatchedRefs),
            $resetPrevious ? ', anciennes cartes réinitialisées' : '',
        ));

        return $this->redirectToRoute('admin_gameplay_formats_index');
    }
}
