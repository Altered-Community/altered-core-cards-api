# Installation Docker

## Introduction

_basé sur [Symfony-Docker](https://github.com/dunglas/symfony-docker) recommandé par [Using Docker with Symfony](https://symfony.com/doc/current/setup/docker.html)_

## Prérequis

1. If not already done, [install Docker Compose](https://docs.docker.com/compose/install/) (v2.10+)
2. Avoir la BDD des cartes prêtes [AlteredCommunity/databases](https://github.com/AlteredCommunity/databases).
    - Vous pouvez setup le chemin vers le dossier de la BDD dans `.env.dev`

## Les commandes Docker à connaitre

Dans tous les cas n'hésitez pas à regarder dans le Makefile pour vous souvenir des commandes.

### _Pour les utilisateurices Windows:_
- Run `docker compose build --pull --no-cache` pour build l'image à neuf.
- Run `docker compose up --wait` pour setup et démarrer le projet symfony.
- Run `docker compose down --remove-orphans` pour arrêter les conteneurs docker.
- Pour lancer des commandes dans le conteneur on utilise `docker compose exec php` exemples:
    - `docker compose exec php bash` permet d'entrer en bash dans le conteneur et ensuite de lancer des commandes comme d'habitude comme `php bin/console` ou `composer`
    - on peut aussi directement lancer des commandes sans entrer en bash comme suit: `docker compose exec php php bin/console`

### _Pour les utilisateurices Linux/Mac (avec commande `make`):_
- Run `make build` to build fresh images
- Run `make up` to set up and start a fresh Symfony project
- Run `make down` to stop the Docker containers.
- Pour lancer des commandes plusieurs méthodes (hésitez pas à utiliser `make help` pour lister les commandes disponible via `make`):
    - `make bash` pour entrer dans le container en bash et lancer des commandes comme d'habitude
    - `make sh c="php bin/console"`
    - `make sf` pour lancer des commandes symfony

## Étape par Étape
2. Run la commande de build commande: _`build`_
3. Démarrer les conteneurs commande: _`up`_ (La BDD et les migrations vont se créer et se jouer automatiquement)
4. Ouvrez `https://localhost` dans votre navigateur [accept the auto-generated TLS certificate](https://stackoverflow.com/a/15076602/1352334)
5. Pour les utilisateurices de Make utiliser `Make setup`  afin de gagner du temps mais aller voir l'install classic pour mieux comprendre le setup
6. Stopper les conteneurs quand vous avez fini de travailler _`down`_