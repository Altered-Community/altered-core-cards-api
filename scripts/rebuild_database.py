#!/usr/bin/env python3
"""Héberge l'API Altered Core en local (Docker) et reconstruit la base de données
à partir des dépôts git de données AlteredEquinox.

Procédure « from scratch », idempotente, rejouable, et cross-platform (Linux/macOS/Windows).

Étapes
------
1. Clone (sparse-checkout du seul dossier ``json/``) les dépôts de données de l'org
   GitHub AlteredEquinox dans un dossier local :
       - cards-nonunique     -> cartes COMMON / RARE / EXALTED (tous les sets)
       - cards-unique-<SET>  -> cartes UNIQUE, un dépôt par set
   Seul ``json/`` est récupéré (les ``assets/`` JPG, plusieurs Go, sont inutiles
   pour reconstruire la base).
2. Build de l'image Docker dev (FrankenPHP + PHP).
3. Démarrage des conteneurs (``php`` + ``database`` PostgreSQL).
   L'entrypoint crée la base et joue automatiquement les migrations Doctrine.
4. Reconstruction des données, dans l'ordre des dépendances :
       clés JWT -> admin -> factions -> sets -> abilities -> cartes.

Modes d'import
--------------
- ``full`` (défaut) : clone TOUS les dépôts (non-uniques + uniques) et importe tout.
                      Les dépôts uniques pèsent ~5 millions de fichiers : clone et import
                      très longs, base volumineuse. Réservé à une reconstruction exhaustive.
- ``test``          : importe toutes les non-uniques + un échantillon de N uniques.
                      NE clone PAS les dépôts uniques. Il lit une liste statique
                      pré-générée (``--build-index``, voir ci-dessous), en tire N au hasard,
                      et télécharge UNIQUEMENT ces N fichiers depuis raw.githubusercontent.com.
                      N est paramétrable (--sample-size, défaut 1000) ; tirage reproductible
                      avec --seed. Idéal pour une base de test légère et portable (CI, autres
                      machines) — aucun clone des dépôts uniques requis.

Liste statique (--build-index)
------------------------------
Les dépôts cards-unique-* sont figés (quasi archivés). On génère donc UNE FOIS, depuis
des clones locaux complets, la liste de tous les fichiers uniques (``--build-index``),
stockée compressée dans ``datas/unique_index.tsv.gz`` puis versionnée. Le mode test s'appuie
sur cette liste : il ne dépend plus des clones locaux et peut tourner partout.

Le dossier de données est monté en lecture seule dans le conteneur sur
``/app/datas/databases`` via la variable d'environnement CARDS_IMPORT_DATABASE
(consommée par compose.override.yaml). Les imports lisent donc ``datas/databases``.

Exemples
--------
    python scripts/rebuild_database.py --fresh                          # base complète, propre
    python scripts/rebuild_database.py --build-index                    # (une fois) génère la liste statique
    python scripts/rebuild_database.py --mode test --sample-size 500 --seed 42
    python scripts/rebuild_database.py --skip-clone --skip-build        # reconstruit juste les données
"""

from __future__ import annotations

import argparse
import concurrent.futures as cf
import gzip
import os
import random
import shutil
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

# Dépôts de données AlteredEquinox.
NONUNIQUE_REPO = "cards-nonunique"
UNIQUE_REPOS = [
    "cards-unique-CORE",
    "cards-unique-COREKS",
    "cards-unique-ALIZE",
    "cards-unique-BISE",
    "cards-unique-CYCLONE",
    "cards-unique-DUSTER",
    "cards-unique-EOLE",
]
ALL_REPOS = [NONUNIQUE_REPO] + UNIQUE_REPOS

# Codes factions (sous-dossiers <SET>/<FACTION>/…). Sert à découper l'import cartes
# des dépôts uniques par faction pour borner la mémoire (iterator_to_array).
FACTIONS = ["AX", "BR", "LY", "MU", "OR", "YZ", "NE"]

# Point de montage du dossier de données dans le conteneur (cf. compose.override.yaml).
CONTAINER_DATA_DIR = "datas/databases"
# Sous-dossier (dans le dossier de données) où l'on copie l'échantillon en mode test.
SAMPLE_DIRNAME = "_sample"

PROJECT_ROOT = Path(__file__).resolve().parent.parent

# Liste statique des fichiers de cartes uniques, générée une fois depuis des clones
# locaux (--build-index) puis versionnée. Les dépôts cards-unique-* étant figés
# (~5M fichiers), le mode test lit cette liste au lieu de cloner : il en tire N au
# hasard et télécharge UNIQUEMENT ces N fichiers depuis GitHub.
# Format : une ligne par fichier, "<repo>\t<chemin dans le repo>". gzip.
UNIQUE_INDEX_FILE = PROJECT_ROOT / "datas" / "unique_index.tsv.gz"

GITHUB_BRANCH = "main"
DOWNLOAD_WORKERS = 16  # téléchargements parallèles en mode test

# Réglages git appliqués à chaque appel (perf sur Windows + gros volumes de fichiers).
GIT_FLAGS = [
    "-c", "core.fscache=true",        # cache fs Windows (gros gain au checkout)
    "-c", "core.longpaths=true",      # chemins > 260 car.
    "-c", "feature.manyFiles=true",   # index v4 + untracked cache : repos à 100k+ fichiers
    "-c", "gc.auto=0",                # pas de gc pendant le clone
    "-c", "merge.conflictstyle=merge",  # indépendant de la config globale de l'utilisateur
]

# Nombre de tentatives par dépôt et délai entre tentatives.
CLONE_RETRIES = 3

# Coupe un transfert qui stagne (< 1 Ko/s pendant 120 s) au lieu de pendre indéfiniment.
GIT_NET_ENV = {
    "GIT_HTTP_LOW_SPEED_LIMIT": "1000",
    "GIT_HTTP_LOW_SPEED_TIME": "120",
}


# --------------------------------------------------------------------------- #
# Utilitaires
# --------------------------------------------------------------------------- #
def step(msg: str) -> None:
    print(f"\n=== {msg} ===", flush=True)


def git(args: list[str]) -> None:
    """Exécute une commande git avec les réglages perf + timeout réseau."""
    env = os.environ.copy()
    env.update(GIT_NET_ENV)
    subprocess.run(["git", *GIT_FLAGS, *args], check=True, env=env)


def run(cmd: list[str], *, env: dict | None = None) -> None:
    """Exécute une commande, affiche la ligne, et lève en cas d'échec."""
    print("  > " + " ".join(cmd), flush=True)
    subprocess.run(cmd, cwd=PROJECT_ROOT, env=env, check=True)


# memory_limit des imports : la limite dev (128M) est trop basse (lots de milliers de
# cartes + leur JSON). On borne (au lieu de -1) pour éviter qu'un pic fasse tomber la VM
# Docker. 4G suffit largement par lot ; ajustable via la variable d'env PHP_MEMORY_LIMIT.
PHP_MEMORY_LIMIT = os.environ.get("PHP_MEMORY_LIMIT", "4G")


def console(*args: str, env: dict) -> None:
    """Exécute une commande Symfony console dans le conteneur php (-T = non interactif)."""
    run(["docker", "compose", "exec", "-T", "php",
         "php", "-d", f"memory_limit={PHP_MEMORY_LIMIT}", "bin/console", *args], env=env)


# --------------------------------------------------------------------------- #
# 1. Clonage des dépôts de données
# --------------------------------------------------------------------------- #
def clone_one(repo: str, dest: Path) -> None:
    """Clone un dépôt (shallow). Reprenable : un dossier sans json/ (clone raté) est
    purgé avant de recloner. Réessaie en cas de hang réseau (coupé par GIT_HTTP_LOW_SPEED_*)."""
    url = f"https://github.com/AlteredEquinox/{repo}.git"
    for attempt in range(1, CLONE_RETRIES + 1):
        # Un dossier existant sans json/ = clone précédent incomplet → on repart propre.
        if dest.exists() and not (dest / "json").is_dir():
            shutil.rmtree(dest, ignore_errors=True)
        try:
            if repo == NONUNIQUE_REPO:
                # Ce dépôt contient aussi assets/ (plusieurs Go) et lore/ : on ne checkout
                # que json/ via un clone partiel + sparse-checkout.
                git(["clone", "--no-checkout", "--depth", "1", "--filter=blob:none", url, str(dest)])
                git(["-C", str(dest), "sparse-checkout", "set", "json"])
                git(["-C", str(dest), "checkout"])
            else:
                # Les dépôts cards-unique-* ne contiennent QUE json/ → clone shallow simple
                # (plus fiable que blob:none+sparse sur des centaines de milliers de fichiers).
                git(["clone", "--depth", "1", "--single-branch", url, str(dest)])
            return
        except subprocess.CalledProcessError as exc:
            print(f"    tentative {attempt}/{CLONE_RETRIES} échouée (code {exc.returncode})", flush=True)
            shutil.rmtree(dest, ignore_errors=True)
            if attempt == CLONE_RETRIES:
                raise
            time.sleep(5)


def clone_repos(data_dir: Path) -> None:
    step("1/4  Clonage des dépôts de données AlteredEquinox")
    for repo in ALL_REPOS:
        dest = data_dir / repo
        if (dest / "json").is_dir():
            # Déjà cloné : on saute (les exports Equinox sont versionnés, pas besoin de pull
            # à chaque run ; pour rafraîchir, supprimer le dossier ou utiliser git pull).
            print(f"  - {repo} : déjà présent, ignoré", flush=True)
            continue
        print(f"  - {repo} : clonage…", flush=True)
        clone_one(repo, dest)
        print(f"    -> OK", flush=True)


# --------------------------------------------------------------------------- #
# Index statique des cartes uniques (généré une fois depuis les clones locaux)
# --------------------------------------------------------------------------- #
def build_index(data_dir: Path, index_file: Path) -> None:
    """Génère la liste statique des fichiers de cartes uniques à partir des clones
    locaux présents dans data_dir, et l'écrit (gzip) dans index_file.
    Format : une ligne "<repo>\\t<chemin dans le repo>" par fichier JSON."""
    step("Génération de l'index statique des uniques (depuis les clones locaux)")
    index_file.parent.mkdir(parents=True, exist_ok=True)

    total = 0
    missing: list[str] = []
    with gzip.open(index_file, "wt", encoding="utf-8", newline="\n") as out:
        for repo in UNIQUE_REPOS:
            json_dir = data_dir / repo / "json"
            if not json_dir.is_dir():
                missing.append(repo)
                print(f"  - {repo} : absent localement, ignoré", flush=True)
                continue
            count = 0
            for f in json_dir.rglob("*.json"):
                rel = f.relative_to(data_dir / repo).as_posix()  # ex: json/CORE/.../x.json
                out.write(f"{repo}\t{rel}\n")
                count += 1
            total += count
            print(f"  - {repo} : {count} fichiers", flush=True)

    print(f"  Index écrit : {index_file} — {total} fichiers", flush=True)
    if missing:
        print(f"  ATTENTION : dépôts absents localement (non indexés) : {', '.join(missing)}\n"
              f"  Clone-les puis relance --build-index pour un index complet.", flush=True)


def iter_index(index_file: Path):
    """Génère les (repo, path) depuis l'index gzip, en streaming."""
    with gzip.open(index_file, "rt", encoding="utf-8") as fh:
        for line in fh:
            line = line.rstrip("\n")
            if not line:
                continue
            repo, _, path = line.partition("\t")
            if path:
                yield repo, path


# --------------------------------------------------------------------------- #
# Échantillonnage des uniques (mode test) — lit l'index + télécharge depuis GitHub
# --------------------------------------------------------------------------- #
def _download(repo: str, path: str, dst: Path) -> bool:
    """Télécharge un fichier depuis raw.githubusercontent.com vers dst (2 essais)."""
    quoted = "/".join(urllib.parse.quote(seg) for seg in path.split("/"))
    url = f"https://raw.githubusercontent.com/AlteredEquinox/{repo}/{GITHUB_BRANCH}/{quoted}"
    for attempt in range(2):
        try:
            with urllib.request.urlopen(url, timeout=30) as resp:
                data = resp.read()
            dst.parent.mkdir(parents=True, exist_ok=True)
            dst.write_bytes(data)
            return True
        except Exception as exc:  # noqa: BLE001 — on retente puis on signale
            if attempt == 1:
                print(f"    échec téléchargement {repo}/{path} : {exc}", flush=True)
            else:
                time.sleep(1)
    return False


def build_sample(data_dir: Path, index_file: Path, sample_size: int, seed: int | None) -> None:
    """Tire N fichiers uniques au hasard dans l'index statique (reservoir sampling,
    mémoire O(N)), puis télécharge UNIQUEMENT ces N fichiers depuis GitHub dans
    <data_dir>/_sample/<repo>/<chemin>."""
    if not index_file.exists():
        raise SystemExit(
            f"Index introuvable : {index_file}\n"
            f"Génère-le d'abord depuis des clones locaux :\n"
            f"    python scripts/rebuild_database.py --build-index"
        )

    sample_root = data_dir / SAMPLE_DIRNAME
    if sample_root.exists():
        shutil.rmtree(sample_root)

    # 1. Reservoir sampling sur l'index (mémoire O(N), pas de liste intégrale en RAM).
    print(f"  Lecture de l'index {index_file} et tirage de {sample_size}…", flush=True)
    rng = random.Random(seed)
    reservoir: list[tuple[str, str]] = []
    seen = 0
    for item in iter_index(index_file):
        seen += 1
        if len(reservoir) < sample_size:
            reservoir.append(item)
        else:
            j = rng.randint(0, seen - 1)
            if j < sample_size:
                reservoir[j] = item

    n = len(reservoir)
    print(f"  {n} uniques tirées sur {seen} (seed={seed}) — téléchargement depuis GitHub…",
          flush=True)

    # 2. Téléchargement parallèle des seuls fichiers tirés.
    def task(item: tuple[str, str]) -> bool:
        repo, path = item
        return _download(repo, path, sample_root / repo / path)

    ok = 0
    with cf.ThreadPoolExecutor(max_workers=DOWNLOAD_WORKERS) as pool:
        for success in pool.map(task, reservoir):
            ok += 1 if success else 0

    print(f"  Échantillon : {ok}/{n} fichiers téléchargés dans {sample_root}", flush=True)
    if ok == 0:
        raise SystemExit("Aucun fichier d'échantillon téléchargé — vérifie la connexion réseau.")


# --------------------------------------------------------------------------- #
# 4. Reconstruction des données
# --------------------------------------------------------------------------- #
def rebuild_data(args, data_dir: Path, env: dict) -> None:
    step("4/4  Reconstruction des données")

    print("-- Clés JWT")
    console("lexik:jwt:generate-keypair", "--skip-if-exists", "--no-interaction", env=env)

    print("-- Utilisateur admin")
    try:
        console("app:admin:create", args.admin_email, args.admin_password, env=env)
    except subprocess.CalledProcessError:
        # L'email est UNIQUE : au re-run (reprise) l'admin existe déjà → on ignore.
        print(f"   (admin {args.admin_email} déjà présent, ignoré)")

    print("-- Factions")
    console("app:import:factions", env=env)

    print("-- Sets (datas/card_set.csv)")
    console("app:import:sets", env=env)

    if args.mode == "full":
        # Import PAR DÉPÔT (pas une passe récursive sur ~5M fichiers d'un coup) :
        # borne la mémoire, donne une progression par dépôt, et permet la REPRISE.
        # Un marqueur (<data-dir>/.imported_repos) liste les dépôts déjà importés ;
        # relancer le script après un crash Docker saute ces dépôts (l'import reste
        # idempotent au cas où un dépôt aurait été interrompu en cours).
        marker = data_dir / ".imported_repos"
        done = set(marker.read_text(encoding="utf-8").split()) if marker.exists() else set()
        for repo in [NONUNIQUE_REPO] + UNIQUE_REPOS:
            if repo in done:
                print(f"-- {repo} : déjà importé, ignoré (marqueur)")
                continue
            root = f"{CONTAINER_DATA_DIR}/{repo}/json"
            # abilities streame les fichiers → OK même à ~1M fichiers.
            print(f"-- {repo} : abilities")
            console("app:import:abilities:equinox", root, env=env)
            # L'import cartes fait iterator_to_array() de TOUS les fichiers : à ~1M
            # fichiers ça dépasse la RAM. On découpe PAR FACTION (les dépôts uniques
            # sont json/<SET>/<FACTION>/…) pour borner chaque appel (~1/7 des fichiers).
            # Le non-unique (3398 fichiers) passe en une fois.
            print(f"-- {repo} : cartes")
            if repo == NONUNIQUE_REPO:
                console("app:import:equinox", root, env=env)
            else:
                for fac in FACTIONS:
                    print(f"   · faction {fac}")
                    console("app:import:equinox", root, "--faction", fac, env=env)
            with marker.open("a", encoding="utf-8") as fh:
                fh.write(repo + "\n")
    else:  # test : toutes les non-uniques + l'échantillon d'uniques
        for root in (f"{CONTAINER_DATA_DIR}/{NONUNIQUE_REPO}/json",
                     f"{CONTAINER_DATA_DIR}/{SAMPLE_DIRNAME}"):
            print(f"-- abilities {root}")
            console("app:import:abilities:equinox", root, env=env)
            print(f"-- cartes {root}")
            console("app:import:equinox", root, env=env)


# --------------------------------------------------------------------------- #
# Programme principal
# --------------------------------------------------------------------------- #
def parse_args(argv: list[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="Reconstruit la base Altered Core depuis les dépôts AlteredEquinox.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    p.add_argument("--mode", choices=["full", "test"], default="full",
                   help="full = tout ; test = non-uniques + N uniques aléatoires")
    p.add_argument("--sample-size", type=int, default=1000,
                   help="Nombre d'uniques aléatoires en mode test")
    p.add_argument("--seed", type=int, default=None,
                   help="Graine du tirage aléatoire (pour un échantillon reproductible)")
    p.add_argument("--index-file", default=str(UNIQUE_INDEX_FILE),
                   help="Liste statique des fichiers uniques (lue en mode test, écrite par --build-index)")
    p.add_argument("--build-index", action="store_true",
                   help="Génère la liste statique des uniques depuis les clones locaux (--data-dir) puis quitte")
    p.add_argument("--data-dir", default=str(PROJECT_ROOT.parent / "databases"),
                   help="Dossier hôte où cloner les dépôts de données")
    p.add_argument("--admin-email", default="admin@altered.local")
    p.add_argument("--admin-password", default="admin")
    # Ports hôte. Sous Windows, 80/443 sont souvent réservés (WinNAT) ou occupés :
    # passer p.ex. --https-port 8443 --http-port 8080.
    p.add_argument("--http-port", default=os.environ.get("HTTP_PORT", "80"),
                   help="Port HTTP hôte (défaut 80 ; sous Windows essayer 8080)")
    p.add_argument("--https-port", default=os.environ.get("HTTPS_PORT", "443"),
                   help="Port HTTPS hôte (défaut 443 ; sous Windows essayer 8443)")
    p.add_argument("--http3-port", default=os.environ.get("HTTP3_PORT", None),
                   help="Port HTTP/3 UDP hôte (défaut = valeur de --https-port)")
    p.add_argument("--fresh", action="store_true",
                   help="docker compose down -v avant de commencer (SUPPRIME le volume PostgreSQL)")
    p.add_argument("--skip-clone", action="store_true", help="Ne pas (re)cloner les dépôts de données")
    p.add_argument("--skip-build", action="store_true", help="Ne pas rebuilder l'image Docker")
    return p.parse_args(argv)


def main(argv: list[str]) -> int:
    args = parse_args(argv)

    data_dir = Path(args.data_dir).expanduser().resolve()
    data_dir.mkdir(parents=True, exist_ok=True)
    index_file = Path(args.index_file).expanduser().resolve()

    # --build-index : génère la liste statique depuis les clones locaux puis quitte.
    if args.build_index:
        build_index(data_dir, index_file)
        return 0

    # CARDS_IMPORT_DATABASE est consommée par compose.override.yaml pour le bind-mount
    # du dossier de données vers /app/datas/databases (lecture seule).
    env = os.environ.copy()
    env["CARDS_IMPORT_DATABASE"] = str(data_dir)
    # Ports hôte transmis à compose.yaml (${HTTP_PORT}/${HTTPS_PORT}/${HTTP3_PORT}).
    env["HTTP_PORT"] = str(args.http_port)
    env["HTTPS_PORT"] = str(args.https_port)
    env["HTTP3_PORT"] = str(args.http3_port or args.https_port)

    print(f"Projet  : {PROJECT_ROOT}")
    print(f"Données : {data_dir} (monté sur /app/{CONTAINER_DATA_DIR}:ro)")
    print(f"URL     : https://localhost:{args.https_port}")
    print(f"Mode    : {args.mode}"
          + (f" (échantillon {args.sample_size}, seed={args.seed})" if args.mode == "test" else ""))

    # 1. Récupération des données sources.
    sample_root = data_dir / SAMPLE_DIRNAME
    if args.mode == "full":
        # Tout est cloné localement (non-uniques + tous les dépôts uniques).
        if args.skip_clone:
            step("1/4  Clonage ignoré (--skip-clone)")
        else:
            clone_repos(data_dir)
        if sample_root.exists():
            shutil.rmtree(sample_root)  # pas d'échantillon résiduel à ré-importer
    else:  # test : non-uniques clonées + échantillon d'uniques téléchargé via l'index
        if args.skip_clone:
            step("1/4  Clonage ignoré (--skip-clone)")
        else:
            step("1/4  Clonage des non-uniques (les uniques sont échantillonnées via l'index)")
            dest = data_dir / NONUNIQUE_REPO
            if (dest / "json").is_dir():
                print(f"  - {NONUNIQUE_REPO} : déjà présent, ignoré", flush=True)
            else:
                print(f"  - {NONUNIQUE_REPO} : clonage…", flush=True)
                clone_one(NONUNIQUE_REPO, dest)
                print("    -> OK", flush=True)
        step("Échantillon d'uniques (lecture de l'index + téléchargement depuis GitHub)")
        build_sample(data_dir, index_file, args.sample_size, args.seed)

    # 2. Build
    if args.skip_build:
        step("2/4  Build ignoré (--skip-build)")
    else:
        step("2/4  Build de l'image Docker dev")
        run(["docker", "compose", "build", "--pull"], env=env)

    # 3. Démarrage des conteneurs
    if args.fresh:
        step("Reset complet (--fresh) : suppression des volumes (PostgreSQL inclus)")
        run(["docker", "compose", "down", "-v", "--remove-orphans"], env=env)
    step("3/4  Démarrage des conteneurs (création BDD + migrations automatiques)")
    run(["docker", "compose", "up", "--detach", "--wait"], env=env)

    # 4. Données
    rebuild_data(args, data_dir, env)

    base = f"https://localhost:{args.https_port}" if str(args.https_port) != "443" else "https://localhost"
    step("Terminé")
    print(f"API           : {base}")
    print(f"Documentation : {base}/api/docs")
    print(f"Cartes        : {base}/api/cards")
    print(f"Admin         : {args.admin_email} / {args.admin_password}")
    print("\nArrêt des conteneurs : docker compose down")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1:]))
    except subprocess.CalledProcessError as exc:
        print(f"\nÉCHEC : la commande a retourné le code {exc.returncode}.", file=sys.stderr)
        sys.exit(exc.returncode)
    except KeyboardInterrupt:
        print("\nInterrompu.", file=sys.stderr)
        sys.exit(130)
