# Reconstruire la base de données « from scratch »

Le script [`scripts/rebuild_database.py`](../scripts/rebuild_database.py) héberge l'API en
local via Docker et reconstruit **entièrement** la base de données à partir des dépôts
git de données de l'org GitHub [AlteredEquinox](https://github.com/AlteredEquinox).

Il est **idempotent**, **rejouable** et **cross-platform** (Linux, macOS, Windows).

## Prérequis

- Docker + Docker Compose v2 (`docker compose`)
- Python 3.10+
- git 2.25+ (pour le sparse-checkout)

## Ce que fait le script

1. **Clone** (sparse-checkout du seul dossier `json/`) les dépôts de données :
   - `cards-nonunique` → cartes COMMON / RARE / EXALTED (tous les sets)
   - `cards-unique-<SET>` → cartes UNIQUE (un dépôt par set : CORE, COREKS, ALIZE, BISE, CYCLONE, DUSTER, EOLE)

   Seul `json/` est récupéré — les `assets/` (images JPG, plusieurs Go) sont inutiles
   pour reconstruire la base.
2. **Build** de l'image Docker dev.
3. **Démarre** les conteneurs (`php` + PostgreSQL). La base et les migrations Doctrine
   sont créées/jouées automatiquement par l'entrypoint.
4. **Reconstruit les données** dans l'ordre des dépendances :
   clés JWT → admin → factions → sets → abilities → cartes.

Le dossier de données est monté en lecture seule sur `/app/datas/databases` dans le
conteneur (via la variable `CARDS_IMPORT_DATABASE` que le script positionne et que
`compose.override.yaml` consomme).

## Utilisation

```bash
# Base complète : toutes les non-uniques + toutes les uniques (~5M fichiers, très long)
python scripts/rebuild_database.py

# Reconstruction propre (supprime d'abord le volume PostgreSQL)
python scripts/rebuild_database.py --fresh

# Base de test : toutes les non-uniques + 1000 uniques aléatoires (sans cloner les uniques)
python scripts/rebuild_database.py --mode test

# Base de test reproductible : 500 uniques, tirage fixé par la graine
python scripts/rebuild_database.py --mode test --sample-size 500 --seed 42

# Reconstruire uniquement les données (dépôts déjà clonés, image déjà buildée)
python scripts/rebuild_database.py --skip-clone --skip-build
```

> Sous Windows, utiliser `python` ; sous Linux/macOS, `python3` si nécessaire.

## Modes d'import

| Mode | Contenu | Dépôts uniques clonés ? |
|---|---|---|
| `full` (défaut) | toutes les non-uniques **+** toutes les uniques (~5M fichiers) | Oui (long, volumineux) |
| `test` | toutes les non-uniques **+** N uniques aléatoires (`--sample-size`, défaut 1000) | **Non** |

### Mode test : liste statique + téléchargement direct

Les dépôts `cards-unique-*` contiennent ~5 millions de fichiers : les cloner pour n'en
garder que 1000 serait absurde. Le mode `test` s'appuie donc sur une **liste statique
pré-générée** de tous les fichiers uniques, versionnée dans le repo
(`datas/unique_index.tsv.gz`). Il :

1. lit cette liste (en streaming) et tire `N` fichiers au hasard (*reservoir sampling*,
   mémoire constante, reproductible via `--seed`) ;
2. télécharge **uniquement** ces `N` fichiers depuis `raw.githubusercontent.com` vers
   `<data-dir>/_sample/`, puis les importe.

Aucun clone des dépôts uniques n'est nécessaire → le mode test est rapide et portable
(CI, autre machine).

### Générer / régénérer la liste statique (`--build-index`)

Les dépôts uniques sont figés (quasi archivés), donc la liste ne change pas. On la génère
**une fois**, depuis des clones locaux complets :

```bash
# Nécessite d'avoir cloné localement les dépôts cards-unique-* (ex: après un run full)
python scripts/rebuild_database.py --build-index
# -> écrit datas/unique_index.tsv.gz, puis quitte
```

À committer dans le repo pour que le mode test fonctionne partout.

## Options principales

| Option | Défaut | Description |
|---|---|---|
| `--mode {full,test}` | `full` | Périmètre des cartes importées |
| `--sample-size N` | `1000` | Nombre d'uniques aléatoires en mode `test` |
| `--seed S` | — | Graine du tirage aléatoire (échantillon reproductible) |
| `--build-index` | off | Génère la liste statique des uniques depuis les clones locaux, puis quitte |
| `--index-file PATH` | `datas/unique_index.tsv.gz` | Emplacement de la liste statique (lue en `test`, écrite par `--build-index`) |
| `--data-dir PATH` | `../databases` | Dossier hôte où cloner les dépôts de données |
| `--admin-email` | `admin@altered.local` | Email de l'admin créé |
| `--admin-password` | `admin` | Mot de passe de l'admin créé |
| `--fresh` | off | `docker compose down -v` avant de commencer (**supprime** la base) |
| `--skip-clone` | off | Ne pas (re)cloner les dépôts de données |
| `--skip-build` | off | Ne pas rebuilder l'image Docker |

## Après exécution

| Ressource | URL |
|---|---|
| API | https://localhost |
| Documentation (Swagger UI) | https://localhost/api/docs |
| Cartes | https://localhost/api/cards |

Arrêter les conteneurs : `docker compose down`.
