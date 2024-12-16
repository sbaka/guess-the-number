<?php

namespace App\Controller;

use App\Entity\Player;
use App\Entity\Game;
use App\Form\GameType;
use App\Service\GameService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Form\GuessType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class GameController extends AbstractController
{
    private $entityManager;
    private $gameService;

    // Le constructeur pour injecter les services nécessaires
    public function __construct(EntityManagerInterface $entityManager, GameService $gameService)
    {
        $this->entityManager = $entityManager;
        $this->gameService = $gameService;
    }

    #[Route('/', name: 'app_index')]
    public function index(Request $request): Response
    {
        // Création du formulaire pour la création du jeu
        $form = $this->createForm(GameType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Récupère les données du formulaire (nom des joueurs)
            $game = $form->getData();
            $session = $request->getSession();

            $playerNames = $game->getPlayerNames();
            $playerNames = array_filter($playerNames, function ($name) {
                return !empty(trim($name));
            });

            if (count($playerNames) < 2) {
                $this->addFlash('error', 'Vous devez avoir au moins 2 joueurs');
                return $this->render('game/index.html.twig', [
                    'form' => $form->createView(),
                ]);
            }

            // Prépare les joueurs en utilisant GameService (ajoute des objets Player à partir des noms)
            $players = $this->gameService->preparePlayers($playerNames);

            // Génère un numéro secret basé sur la difficulté choisie
            $secretNumber = $this->gameService->generateSecretNumber($game->getDifficulty());

            foreach ($players as $playerData) {
                $player = new Player();
                $player->setName($playerData['name']);
                $player->setScore(0); // Initialisation du score à 0
                $player->setGame($game); // Associe le joueur au jeu
                $game->addPlayer($player); // Ajoute le joueur au jeu
            }

            $game->setPlayedAt(new \DateTime());

            // Sauvegarde le jeu et ses joueurs dans la base de données
            $this->entityManager->persist($game);
            $this->entityManager->flush();

            $session->set('gameId', $game->getId());
            $session->set('players', $players);
            $session->set('currentPlayer', 0);
            $session->set('secretNumber', $secretNumber);
            $session->set('maxAttempts', 5);
            $session->set('gameOver', false);
            $session->set('difficulty', $game->getDifficulty());

            // Redirige vers la page de devinette des numéros
            return $this->redirectToRoute('number_guessing');
        }
        return $this->render('game/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/game/number-guessing', name: 'number_guessing')]
    public function numberGuessing(Request $request): Response
    {
        $session = $request->getSession();

        // Vérifie si le jeu est en cours
        if ($session->get('gameOver')) {
            return $this->redirectToRoute('app_index');
        }

        $form = $this->createForm(GuessType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $guess = $data['guess'];
            $players = $session->get('players');
            $currentPlayerIndex = $session->get('currentPlayer');
            $secretNumber = $session->get('secretNumber');
            $maxAttempts = $session->get('maxAttempts');

            // Met à jour l'état du jeu
            $message = '';
            if ($guess < $secretNumber) {
                $message = 'Trop petit !';
            } elseif ($guess > $secretNumber) {
                $message = 'Trop grand !';
            } else {
                $message = 'Bravo, vous avez trouvé le nombre !';
            }
            $this->gameService->updateGameState($players, $currentPlayerIndex, $secretNumber, $maxAttempts, $guess, $message);
            
            // Sauvegarde les scores et l'état du jeu
            // foreach ($players as $playerData) {
            //     $player = $this->entityManager->getRepository(Player::class)->find($playerData['id']);
            //     $player->setScore($playerData['score']);
            //     $this->entityManager->persist($player);
            // }
            $this->entityManager->flush();
            
            $session->set('players', $players);
            $session->set('currentPlayer', $currentPlayerIndex);
            $session->set('message', $message);
        }

        return $this->render('game/number_guessing.html.twig', [
            'form' => $form->createView(),
            'players' => $session->get('players'),
            'currentPlayer' => $session->get('currentPlayer'),
            'maxAttempts' => $session->get('maxAttempts'),
            'gameOver' => $session->get('gameOver'),
            'message' => $session->get('message'),
        ]);
    }

    #[Route('/game/restart', name: 'game_restart')]
    public function restartGame(Request $request): Response
    {
        $session = $request->getSession();

        // Récupère les données de la session
        $players = $session->get('players');
        $difficulty = $session->get('difficulty');

        // Réinitialise les scores et les tentatives des joueurs
        foreach ($players as $key => $player) {
            $players[$key] = [
                'name' => $player['name'],
                'score' => 0,
                'attempts_used' => 0
            ];
        }

        // Génère un nouveau numéro secret
        $secretNumber = $this->gameService->generateSecretNumber($difficulty);

        // Réinitialise les paramètres du jeu
        $session->set('players', $players);
        $session->set('currentPlayer', 0);
        $session->set('secretNumber', $secretNumber);
        $session->set('maxAttempts', 5);
        $session->set('gameOver', false);

        // Redirige vers la page de devinette des numéros
        return $this->redirectToRoute('number_guessing');
    }

    #[Route('/game/reset', name: 'game_reset')]
    public function resetGame(Request $request): Response
    {
        $session = $request->getSession();

        // Effacer les informations de jeu stockées dans la session
        $session->remove('gameId');
        $session->remove('players');
        $session->remove('currentPlayer');
        $session->remove('secretNumber');
        $session->remove('maxAttempts');
        $session->remove('gameOver');
        $session->remove('difficulty');

        // Rediriger vers la page d'accueil
        return $this->redirectToRoute('app_index');
    }

    #[Route('/game/list', name: 'game_list')]
    public function list(): Response
    {
        // Récupérer tous les jeux existants depuis la base de données via le repository de 'Game'
        $games = $this->entityManager->getRepository(Game::class)->findAll();

        // Rendre la vue 'list.html.twig' avec la liste récupérée
        return $this->render('game/list.html.twig', [
            'games' => $games,
        ]);
    }
}
