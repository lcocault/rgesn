# Outil de conformité RGESN

Application web permettant d'évaluer la conformité d'un ou plusieurs projets numériques
au [référentiel général de l'écoconception des services numériques (RGESN, version 2024)](referentiel/referentiel_2024.pdf),
en posant le moins de questions possible, et de produire un document de déclaration
de conformité pour chaque évaluation.

## Principe

- Le référentiel comporte 78 critères, répartis en 9 thématiques, chacun assorti d'un niveau
  de priorité (Prioritaire / Recommandé / Modéré) et de conditions d'applicabilité.
- Chaque **projet** peut faire l'objet de plusieurs **évaluations** dans le temps (une par
  campagne de diagnostic), afin de suivre l'évolution du score d'avancement.
- Une évaluation commence par une courte série de **questions de cadrage** (le service
  traite-t-il de la vidéo ? repose-t-il sur un modèle d'apprentissage automatique ? etc.)
  qui permettent d'écarter automatiquement les critères non pertinents pour ce type de
  service, sans avoir à répondre à chacun individuellement.
- Les critères restants sont ensuite présentés un par un (fiche complète : objectif, mise
  en œuvre, moyen de test) avec un statut (Validé / Non validé / Non applicable) et une
  justification libre.
- Le score d'avancement est calculé automatiquement selon la formule officielle du
  référentiel (pondération 1,5 / 1,25 / 1,0 selon la priorité).
- Une déclaration de conformité (HTML imprimable + export PDF) est générée à partir des
  réponses, suivant la structure du modèle officiel Arcep/Arcom.

## Stack technique

- PHP 8.1+ natif (pas de framework), organisation simple en Contrôleurs / Dépôts / Services.
- [Twig](https://twig.symfony.com/) pour les vues.
- [Dompdf](https://github.com/dompdf/dompdf) pour l'export PDF.
- PostgreSQL comme base de données.

## Installation locale

```bash
composer install
cp .env.example .env
# éditer .env avec les identifiants de votre base PostgreSQL locale
php database/migrate.php
php database/seed_criteria.php
php -S localhost:8000 -t public
```

Puis ouvrir http://localhost:8000.

## Déploiement sur AlwaysData

1. **Base de données** : depuis l'admin AlwaysData, créer une base PostgreSQL et un
   utilisateur associé. Noter l'hôte (`postgresql-<compte>.alwaysdata.net`), le nom de
   la base et les identifiants.
2. **Code** : déployer le contenu du dépôt (Git ou upload) dans un répertoire de votre
   compte, par exemple `www/rgesn`.
3. **Dépendances** : via SSH sur le compte AlwaysData :
   ```bash
   cd www/rgesn
   composer install --no-dev --optimize-autoloader
   ```
4. **Configuration** : copier `.env.example` en `.env` et renseigner les identifiants
   PostgreSQL fournis par AlwaysData, ainsi que `APP_ACCESS_PASSWORD` (mot de passe
   d'accès unique à l'outil, celui-ci étant à usage interne sans gestion multi-comptes).
5. **Site web** : dans l'admin AlwaysData, créer un site pointant vers le sous-répertoire
   `www/rgesn/public` (c'est le *document root*, il ne faut jamais exposer le reste du
   code). Choisir PHP 8.1 ou supérieur.
6. **Initialisation de la base** :
   ```bash
   php database/migrate.php
   php database/seed_criteria.php
   ```
7. Se rendre sur l'URL du site : l'application demande le mot de passe d'accès puis
   affiche la liste des projets.

Pour mettre à jour l'application par la suite : redéployer le code, relancer
`composer install --no-dev` si les dépendances ont changé, puis `php database/migrate.php`
pour appliquer les éventuelles nouvelles migrations (les migrations déjà appliquées sont
ignorées automatiquement).

## Structure du projet

```
public/            Racine web (front controller index.php, assets CSS/JS)
src/
  Controllers/      Logique de traitement des requêtes HTTP
  Repositories/      Accès aux données (PDO / PostgreSQL)
  Services/          Règles métier : applicabilité, score, génération de déclaration
  Support/           Utilitaires transverses (config, vues, session, CSRF...)
templates/          Gabarits Twig
database/
  migrations/        Schéma SQL, appliqué par migrate.php
  seeds/             Données de référence du RGESN (78 critères + questions de cadrage)
referentiel/        Documents officiels source (PDF du référentiel, exemple de déclaration)
```

## Mise à jour du référentiel

Les 78 critères et leurs textes (objectif, mise en œuvre, moyen de test, conditions
d'applicabilité) sont chargés depuis `database/seeds/criteria.json` par le script
`database/seed_criteria.php`. En cas d'évolution du référentiel RGESN, mettre à jour ce
fichier JSON puis relancer le script (il fait un *upsert*, sans dupliquer les critères
existants).
