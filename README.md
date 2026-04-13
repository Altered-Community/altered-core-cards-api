# Altered Core — API communautaire

API REST open source pour le jeu de cartes **Altered**, construite avec Symfony 8, API Platform et PostgreSQL.
Elle expose les cartes, sets.

---

## Stack technique

- **PHP 8.4** + **Symfony 8**
- **API Platform 4**
- **PostgreSQL 16**
- **Doctrine ORM** + migrations
- **JWT** (LexikJWTAuthenticationBundle)
- **Gedmo** (traductions, soft delete)
- **Docker** (PostgreSQL via Symfony CLI)

---

## Installation complète via Docker

[Documentation disponible ici !](docs/install-with-docker.md)

Cet installation est recommandé car on est sur d'avoir tous le même environnement de dev (mais peut être plus compliqué pour les personnnes n'ayant aucune connaissance en docker)

## Installation Classic



### Prérequis

- PHP 8.4
- Composer
- Symfony CLI
- Docker (pour PostgreSQL)

### 1. Cloner le projet

```bash
git clone <repo-url>
cd altered-core
```

### 2. Installer les dépendances

```bash
composer install
```

### 3. Configurer l'environnement

```bash
cp .env .env.local
```

Éditer `.env.local` avec tes credentials PostgreSQL :

```env
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/altered_core?serverVersion=16&charset=utf8"
```

### 4. Démarrer la base de données

```bash
symfony server:start
```

Le Symfony CLI détecte automatiquement le `compose.yaml` et démarre PostgreSQL via Docker.

### 5. Créer la base et jouer les migrations

```bash
symfony console doctrine:database:create
symfony console doctrine:migrations:migrate
```

### 6. Générer les clés JWT

```bash
symfony console lexik:jwt:generate-keypair
```

---

## Import des données

### Sets

Un fichier CSV est disponible dans `datas/card_set.csv`.

```bash
symfony console app:import:sets
```

### Cartes

Les cartes proviennent du dépôt communautaire [AlteredCommunity/databases](https://github.com/AlteredCommunity/databases).
Créer un lien symbolique vers le répertoire local contenant les JSON :

```bash
ln -s /chemin/vers/community_database datas/databases
```

Puis lancer l'import :

```bash
# Toutes les cartes
symfony console app:import:cards

# Un set spécifique
symfony console app:import:cards --set CORE
symfony console app:import:cards --set ALIZE
```

L'import est idempotent : il peut être rejoué sans créer de doublons.

---

## API

La documentation interactive (Swagger UI) est disponible à :

```
http://localhost/api/docs
```

### Endpoints publics

| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/api/cards` | Liste paginée des cartes |
| GET | `/api/cards/{id}` | Carte par ID |
| GET | `/api/cards/reference/{reference}` | Carte par référence (ex: `ALT_CORE_B_AX_01_C`) |
| GET | `/api/sets` | Liste des sets |
| GET | `/api/sets/{id}` | Set par ID |

### Filtres disponibles sur `/api/cards`

```
?set.reference=CORE
?faction.code=AX
?rarityInt=3
?typeInt=8
?name=Sierra
?promo=true
?kickstarter=false
?order[cardNumber]=asc
?order[rarityInt]=desc
```

### Authentification

Les routes protégées nécessitent un token JWT.

```bash
POST /api/auth/login
Content-Type: application/json

{ "username": "email@example.com", "password": "..." }
```

---

## Contribuer

Le projet est ouvert aux contributions de la communauté Altered.

### Avant de commencer

**Rejoins le canal `#dev` du Discord Altered** et présente ce sur quoi tu veux travailler.
La coordination est essentielle pour éviter que plusieurs personnes travaillent sur la même chose en parallèle.

### Règles de contribution

- Tout le travail se fait via **Pull Request** — aucun push direct sur `main`
- Une PR = une fonctionnalité ou un correctif
- Discuter de la PR sur Discord avant de la soumettre si le changement est conséquent
- Les PRs doivent passer la review d'au moins un autre contributeur avant d'être mergées

### Workflow

```
1. Fork / branche depuis main
2. Développe ta feature
3. Ouvre une Pull Request avec une description claire
4. Discussion & review
5. Merge
```

### Idées de contributions

- Nouvelles routes API (collections joueurs, decks, etc.)
- Filtres supplémentaires sur les cartes
- Amélioration de l'import
- Tests automatisés
- Documentation

---

## Licence

Projet communautaire — les données des cartes appartiennent à Equinox.
