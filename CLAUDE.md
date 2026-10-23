# L'Air du Bois : migration Symfony

Hard fork de [lairdubois/lairdubois](https://github.com/lairdubois/lairdubois) (Boris Beaulant) pour
migrer de Symfony 3.4 à Symfony 8.2 et PHP 8.5, en isolation. Tout sera livré à Boris en une fois, à
la fin.

## Git et publication

Exception à mes règles globales, valable uniquement sur ce repo (`lyrixx/lairdubois`) : commits,
push, ouverture, mise à jour et merge de PR, issues, commentaires, sans me demander.

- Une branche par PR, PR vers `next`. On merge dans `next` quand la CI est verte et le diff relu.
- `master` est l'état du repo de Boris au moment du fork : on n'y touche pas.
- Jamais rien sur `lairdubois/lairdubois` : pas de remote `upstream`, pas de `gh -R
  lairdubois/lairdubois`. La synchro avec Boris se fera à la fin.
- Le backlog vit dans les issues du fork, rangées par milestone.

## Organisation du travail

Une session pilote, des agents codent.

- La session principale tient le backlog, lance un agent par issue, relit le diff de chaque PR
  (pas le résumé de l'agent), vérifie la CI et merge. Elle ne code pas elle-même.
- Un agent = une issue = une PR. Chaque agent travaille dans son propre worktree git. S'il bute
  sur un choix qui n'est pas tranché ici ou dans l'issue, il s'arrête et le remonte.
- L'état fait foi sur GitHub (issues, PR), pas dans la mémoire de la session : une nouvelle
  session reprend depuis là.
- Point d'avancement toutes les heures : mergé, en cours, bloqué, prochaine étape.
- Au plus 3 ou 4 agents en parallèle : au-delà, les conflits (`composer.lock`, fixtures) et la
  relecture deviennent le goulot.
- Chaque worktree lance sa propre stack Docker, sans conflit de conteneurs, de ports ni de
  domaines avec les autres : c'est ce qui permet le parallélisme.

Ordre :

1. #1 Docker, seul : tant qu'il n'est pas mergé, les agents partageraient la base et
   Elasticsearch de l'hôte.
2. #2 CI et #3 fixtures en parallèle.
3. #4 smoke test, seul : il pose l'outillage de test commun (classe de base, connexion en anonyme,
   membre et admin) que les tests suivants réutilisent.
4. En parallèle : #5 à #12 et les bugs #32 à #34.
5. Ménage : #13 et #17 en parallèle, #14 avant #15 et #16.
6. Sauts de version en série, un agent par saut. Les chantiers d'un milestone qui ne dépendent pas
   du saut se font en parallèle.

## Principes de la migration

- On change la plomberie, pas le site. Le front (Bootstrap 3, jQuery, LESS) est gelé, seul le build
  des assets change. Aucune différence visible pour les utilisateurs.
- On garde toutes les features, y compris Twitter, Facebook, Pinterest et le websocket.
- Diff minimal : on garde le namespace `Ladb\CoreBundle`, `app/` et `web/` tant que rien n'oblige à
  bouger. Un déplacement de fichiers = une PR qui ne contient que des `git mv`.
- Rector pour les réécritures mécaniques : ne pas faire à la main ce qu'il sait faire.
- Mes règles globales (`php.md`…) s'appliquent au code qu'on écrit. On ne réécrit pas le code
  existant juste pour s'y conformer, et on suit le style du fichier (tabulations, commentaires en
  anglais).

## Plan

1. Filet de sécurité, en Symfony 3.4 : Docker (`jolicode/docker-starter`), fixtures, smoke test sur
   toutes les routes (anonyme, connecté, admin), tests fonctionnels sur toutes les features du site,
   crons, CI GitHub Actions. Tests Symfony (WebTestCase) dès que c'est possible, ils sont beaucoup
   plus rapides ; Playwright (l'officiel, en Node) seulement pour ce qui a besoin de JS.
2. Ménage, toujours en 3.4 : dépendances mortes, injection de dépendances à la place de
   `$this->get()`, Assetic → Symfony Reprise à rendu identique (voir les pièges).
3. Sauts de version : 4.4 → 5.4 → 6.4 → 7.4 → 8.2. Avant chaque saut, zéro dépréciation Symfony en CI.
   - 4.4 : Swiftmailer → Mailer, `transchoice` → `trans`.
   - 5.4 : FOSUserBundle → sécurité native, Gos WebSocket → Mercure, RabbitMqBundle → Messenger.
   - 6.4 : annotations → attributs, fin de SensioFrameworkExtraBundle.
   - Elasticsearch 5 → version supportée, à part, quand ça arrange (réindexation depuis la base).

## Environnement

- PHP 8.5 dès le départ. Vérifié le 2026-10-23 : le code actuel démarre, compile le container,
  passe `lint:twig` et `doctrine:schema:validate` et rend les pages en 8.5.
- `E_DEPRECATED` est coupé en dev, sinon PHP 8.4 et 8.5 noient chaque commande de deprecations.
- Docker n'est pas encore en place. Une fois docker-starter installé : merger son `AGENTS.md` ici
  et ne plus lancer PHP ni Composer sur l'hôte.

## Pièges connus

- `composer install` échoue tant que `composer.json` ne déclare pas `config.allow-plugins` (le lock
  est antérieur à cette option), et `beberlei/doctrineextensions` exige `php ^7.1`.
- Le bundle `symfony/reprise` exige Symfony `^7.4` et PHP 8.4 : avant le saut en 7.4, seul le côté
  JS (Vite et le plugin npm `@symfony/reprise`) est utilisable. Ce plugin demande Node 22.18 ou plus.
- `trusted_hosts` vaut `^(dev|www)\.lairdubois\.fr$` : une requête sur un autre host répond 400.
- Les sessions sont en base (`PdoSessionHandler`) : `doctrine:schema:create` ne crée pas la table
  `sessions`.
- En 3.4, `HttpKernel` n'attrape que les `\Exception` : une `\Error` PHP (appel sur `null`,
  `TypeError`) sort du kernel au lieu de donner une 500.
- Les mots de passe sont hashés en sha512 par FOSUserBundle : les comptes existants doivent continuer
  à se connecter sans reset, d'un bout à l'autre de la migration.
