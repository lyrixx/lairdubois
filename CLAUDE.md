# L'Air du Bois : migration Symfony

Hard fork de [lairdubois/lairdubois](https://github.com/lairdubois/lairdubois) (Boris Beaulant) pour
migrer de Symfony 3.4 à Symfony 8.2 et PHP 8.5, en isolation. Tout sera livré à Boris en une fois, à
la fin.

## Git et publication

Exception à mes règles globales, valable uniquement sur ce repo (`lyrixx/lairdubois`) : commits,
push, ouverture, mise à jour et merge de PR, issues, commentaires, sans me demander.

- Une branche par PR, PR vers `next`, partie de `origin/next`. On merge dans `next` (squash) quand
  la CI est verte et le diff relu. `Fixes #N` ne ferme pas l'issue, la PR ne visant pas la branche
  par défaut (`master`) : on la ferme à la main après le merge.
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
- Journal (#92, épinglé) : ce qui change pour un utilisateur ou un admin, ce qui est supprimé
  (route, commande), les comportements gardés volontairement, les contournements temporaires et
  les actions à faire en prod. Un agent le liste dans sa PR, section « À consigner » ; la session
  principale met le journal à jour après chaque merge.
- Suivi pour Boris (#95, épinglé) : état par étape, fait, prochaines étapes, à valider, à faire en
  prod. La session principale le met à jour en fin de journée, et poste un commentaire court
  quand une étape (milestone) est terminée : modifier la description ne notifie personne.
- Point d'avancement toutes les heures : mergé, en cours, bloqué, prochaine étape.
- Budget de tokens : au plus 50 % de la limite sur 5 h, et 50 % de la limite hebdomadaire ramenée
  à la journée (7,1 points du compteur hebdomadaire par jour). Les chiffres sont ceux du compte,
  lus dans la status line (`.claude/tmp/budget/`, hors git). À 85 % de l'un des deux, plus de
  nouvel agent ; à 100 %, tout s'arrête jusqu'à la nouvelle fenêtre de 5 h ou jusqu'au lendemain.
- Au plus 3 ou 4 agents en parallèle : au-delà, les conflits (`composer.lock`, fixtures) et la
  relecture deviennent le goulot.
- Chaque worktree lance sa propre stack Docker, sans conflit de conteneurs ni de ports avec les
  autres : c'est ce qui permet le parallélisme.

Ordre :

1. #1 Docker, seul : tant qu'il n'est pas mergé, les agents partageraient la base et
   Elasticsearch de l'hôte.
2. #2 CI et #3 fixtures en parallèle.
3. #4 smoke test, seul : il pose l'outillage de test commun (classe de base, connexion en anonyme,
   membre et admin) que les tests suivants réutilisent.
4. En parallèle : #5 à #12. Ensuite les bugs (label `bug` : #32 à #34, #42 à #51 et ceux que
   trouvent les tests), un agent pour quelques bugs à la fois. Ce sont des bugs du site qui
   existaient déjà : on les corrige quand même, l'échec connu du test est retiré avec le correctif.
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

- PHP 8.5 dès le départ, dans Docker (`jolicode/docker-starter`, voir la section suivante). Vérifié
  le 2026-10-23 : le code actuel démarre, compile le container, passe `lint:twig` et
  `doctrine:schema:validate` et rend les pages en 8.5.
- `E_DEPRECATED` est coupé en dev, sinon PHP 8.4 et 8.5 noient chaque commande de deprecations :
  dans le `php.ini` des images et dans `bin/console` (`Debug::enable()` remet `E_ALL` sinon). Les
  dépréciations Symfony (`E_USER_DEPRECATED`) restent.

## Docker

Issu de l'`AGENTS.md` de docker-starter. Jamais PHP, Node ni Composer sur l'hôte : tout passe par
le conteneur `builder`, via Castor. Prérequis de l'hôte : Docker et Castor.

```bash
castor start                      # build, composer, up, schéma, fixtures si la base est vide, index, workers
castor about                      # URL de la copie
castor docker:ports               # ports du routeur de la copie
castor stop
castor builder                    # shell dans le builder
castor builder -- bin/console …   # une commande dans le builder
castor logs [--service=frontend]
castor app:install                # composer install, clés DKIM et VAPID jetables si absentes
castor app:db:schema              # base, schéma (doctrine:schema:update) et table sessions
castor app:db:fixtures           # recrée la base avec les fixtures, notifications, index
castor app:elasticsearch:populate # fos:elastica:populate
castor app:assets:dump            # CSS et JS de prod (Assetic, closure compiler, yuicompressor)
castor docker:build [--service=…]
castor docker:worker:start        # et docker:worker:stop
castor docker:destroy --force     # conteneurs, volumes, réseau, images et certificats de la copie
castor docker:generate-certificates [--force]
```

Contextes : `--context=test` (`SYMFONY_ENV=test`), `--context=ci` (le même, sans couleurs).

- Application à la racine, docroot `web/`, front controller `app_dev.php`. Service `frontend` :
  nginx et php-fpm. Node 10 et `less@3.13.1` dans toutes les images (en dev, le contrôleur Assetic
  compile le LESS à la demande), Java dans le builder seulement.
- MariaDB 10.11 (`root`/`app`), Elasticsearch 5.6, RabbitMQ 3.13 (`app`/`app`), Memcached. Workers
  (profil `worker`) : les deux consumers RabbitMQ et `websocket` (routé sur `/ws`).
- Playwright (service `playwright`, profil `e2e`, lancé par `castor qa:e2e`) : l'image officielle
  `mcr.microsoft.com/playwright`, avec Node 24 et les navigateurs, à la version de
  `@playwright/test` dans `tests/e2e/package.json`.
- Routeur : un Traefik par copie (service `router`, `docker-compose.dev.yml`), qui ne route que
  les conteneurs portant le label `project-name` de sa copie. Certificats dans
  `infrastructure/docker/services/router/certs/`, générés par `castor docker:build` : mkcert s'il
  est installé, autosigné sinon.
- `app/config/parameters.yml` est écrit par composer (`incenteev/composer-parameter-handler`) : les
  valeurs propres à Docker viennent de l'environnement du builder (`env-map` de `composer.json`,
  `parameters_environment` de `docker-compose.yml`) et sont réécrites à chaque `composer install`.

Plusieurs worktrees git tournent en même temps (`.castor/worktree.php`, celui de docker-starter) :

- Copie principale : projet Compose `lairdubois`, routeur sur les ports 80, 443 et 8080 (admin
  Traefik), `https://lairdubois.test`.
- Worktree : projet `lairdubois-wt-<worktree>` (nom du dossier dans `.git/worktrees/`), donc
  conteneurs, volumes, réseau et images propres. Même domaine, mais ports HTTP, HTTPS et admin
  dérivés de ce nom (crc32, entre 10000 et 60000) : `https://lairdubois.test:<port>`, voir `castor
  docker:ports`. Les URL des assets et du websocket portent ce port (`parameters.yml`).
- Seuls les worktrees sont isolés : deux clones du repo ont le même projet et les mêmes ports.
- Pour lire une page sans toucher à `/etc/hosts` : `curl --resolve lairdubois.test:<port>:127.0.0.1
  https://lairdubois.test:<port>/` (`-k` sans mkcert).
- Avant de supprimer un worktree : `castor docker:destroy --force`, sinon ses volumes restent.

Conventions :

1. Jamais `docker compose` à la main : `docker_compose()` et `docker_compose_run()` de
   `.castor/docker.php` pour écrire une tâche.
2. Jamais de nom de projet, de domaine ni de port en dur : `variable('project_name')`,
   `variable('root_domain')`, `get_worktree_ports()`.
3. Une tâche récurrente est une tâche Castor (`castor.php` ou `.castor/*.php`), pas un script.

## CI et QA

GitHub Actions (`.github/workflows/ci.yml`) sur les PR vers `next` et les push sur `next`. Elle lance
les tâches Castor dans Docker, en contexte `ci`. Les tâches QA sont dans `.castor/qa.php`. Pour
refaire la CI en local, sur une stack démarrée (`castor start`) :

```bash
castor -c ci qa:all          # qa:lint, qa:phpstan, qa:phpunit, puis qa:deprecations
castor -c ci qa:lint         # lint:twig, lint:yaml, doctrine:schema:validate, cache:warmup --env=prod, composer validate
castor qa:phpstan [-b]       # niveau 0, phpstan-baseline.neon ; -b la régénère
castor qa:phpunit [-- --filter …]
castor qa:deprecations       # var/deprecations/report.md, et le résumé du job en CI
castor qa:install            # outils de tools/ (PHPStan, PHPUnit), installés à la volée sinon
castor qa:e2e [-- --grep …]  # tests E2E (Playwright) : recharge la base du dev, hors qa:all, job E2E en CI
```

- PHPUnit 9.6, dans `tools/phpunit` et pas dans le `composer.json` de l'application : c'est la
  dernière version qui marche à la fois avec le `WebTestCase` de Symfony 3.4 (setUp/tearDown
  `void` via `ForwardCompatTestTrait`) et avec `symfony/phpunit-bridge` 3.4 (`TestListener` et
  `ErrorHandler` de PHPUnit, supprimés en 10). Le `simple-phpunit` du bridge 3.4 ne sait pas
  installer PHPUnit 9.6 (il garde le `composer.lock` du paquet et n'installe pas le bridge).
- Tests dans `tests/` (namespace `Tests\`, `autoload-dev`), configuration `phpunit.xml.dist` à la
  racine, `KERNEL_CLASS=AppKernel`. PHPStan n'analyse pas `tests/` (il n'y trouverait pas
  PHPUnit).
- Rapport de dépréciations : celles de la compilation du container (le kernel les écrit dans
  `var/cache/test/*Deprecations.log`, sauf sous PHPUnit) et celles du dernier `qa:phpunit`.
  `qa:phpunit` coupe le gestionnaire du bridge (`SYMFONY_DEPRECATIONS_HELPER=disabled`) et passe
  `PHPUNIT_DEPRECATIONS_FILE` à `tests/bootstrap.php`, qui enregistre `Tests\DeprecationCollector` :
  il compte les dépréciations par message. Le `SYMFONY_DEPRECATIONS_SERIALIZE` du bridge garde
  chaque occurrence en mémoire, des centaines par requête : le smoke test dépasse la limite de
  mémoire. Les dépréciations de PHP lui-même (`E_DEPRECATED`, d'où `error_reporting=-1` dans
  `phpunit.xml.dist`) sont comptées, pas listées. Le rapport ne fait jamais échouer la CI.
- Les dépréciations d'un template Twig ne sortent qu'à sa compilation : `qa:deprecations` vide le
  cache de test sans les warmers optionnels, pour que le `qa:phpunit` suivant les voie.
- En 3.4, `lint:yaml` ne prend qu'un chemin et compte les dépréciations comme des erreurs ;
  `lint:twig` exige des chemins.
- Cache GitHub Actions : couches Docker (`type=gha`, un scope par cible du Dockerfile, via un
  `docker-compose.override.yml` écrit par le workflow) et cache Composer (`.home/.cache/composer`).

## Fixtures

Jeu de données de dev et de test, sans donnée de prod : `src/Ladb/CoreBundle/DataFixtures/`
(`doctrine/doctrine-fixtures-bundle`, en dev et en test), services dans `app/config/fixtures.yml`.

- `castor app:db:fixtures` supprime et recrée la base (les `AUTO_INCREMENT` repartent à 1, les ids
  sont donc stables), charge les fixtures, transforme les activités en notifications
  (`ladb:cron:notification:populate`) et remplit l'index. `castor start` le lance quand la base n'a
  aucun compte (copie neuve) ; sinon il garde la base telle quelle.
- Les fixtures rejouent les create et publish des controllers (`FixtureFactory`, managers, utils,
  événements) pour que compteurs, activités, tags, watches et index soient cohérents. Quand un
  controller change sa façon de créer un contenu, la fixture suit.
- Images : des JPEG et PNG de quelques Ko dans `src/Ladb/CoreBundle/Resources/fixtures/pictures/`,
  copiés dans `uploads/` sous un nom stable (`fixture-<nom>.jpg`), avec les placeholders
  (`empty.png`, `avatar.png`…) que les templates demandent à Liip Imagine.

Comptes (mot de passe = identifiant, hashé en sha512 par FOSUserBundle, connexion par le formulaire
`/login`) :

| Identifiant | Id | Rôle |
|-------------|----|------|
| `admin`     | 1  | `ROLE_ADMIN`, aussi `admin_username` (expéditeur des messages de bienvenue) |
| `user`      | 2  | « Personne », autrice de tous les contenus de référence |
| `user2`     | 3  | « Personne », commente, aime, répond, vote, donne son avis sur les contenus de `user` |
| `team`      | 4  | « Collectif » de `user` et `user2`, qui invite `admin` ; pas de connexion possible |

Retrouver « le » contenu d'un type dans un test : chaque type a un contenu publié d'id 1, de `user`
(sauf blog, FAQ et astuce : `admin`), avec image, tags, commentaire et like de `user2`. Les routes
prennent donc `{id}` = 1 et `{username}` = `user` (`team` pour un collectif). En PHP, les
identifiants des comptes sont des constantes : `UserFixtures::ADMIN`, `USER`, `USER2`, `TEAM`.

| Type | Page |
|------|------|
| Création, atelier, plan | `/creations/1.html`, `/ateliers/1.html`, `/plans/1.html` |
| Pas à pas et son article | `/pas-a-pas/1.html`, `/pas-a-pas/articles/1.html` |
| Trouvaille, événement, annonce | `/trouvailles/1.html`, `/evenements/1.html`, `/annonces/1.html` |
| Question et réponse | `/questions/1.html`, `/questions/reponses/1` |
| Processus, collection, graphisme | `/processus/1.html`, `/collections/1.html`, `/promouvoir/1.html` |
| Xylothèque (et texture), fournisseur, école (et témoignage) | `/xylotheque/1.html`, `/fournisseurs/1.html`, `/ecoles/1.html` |
| Livre, logiciel, outil (avis sur le livre et le logiciel) | `/livres/1.html`, `/logiciels/1.html`, `/outils/1.html` |
| Blog, FAQ, astuce (redirige vers son URL), took (redirige vers YouTube) | `/blog/1.html`, `/faq/1.html`, `/astuces/1.html`, `/yt/1.html` |
| Messagerie : fil de `user2` à `user` | `/messagerie/thread/1` |
| Financement : mois précédent (charge récurrente et autre charge), mois courant (don de `user2`) | `/financement/` |

Les autres tables (commentaires, likes, votes, avis, feedbacks, valeurs de la xylothèque,
notifications…) ont aussi une ligne d'id 1, mais son contenu dépend de l'ordre de chargement :
le retrouver par requête plutôt que de supposer ce qu'elle porte.

## Tests

Tests fonctionnels (`WebTestCase`, PHPUnit 9.6) dans `tests/`, un dossier par feature
(`tests/Smoke/`…), lancés par `castor qa:phpunit [-- --filter …]`.

- Base et index propres à l'environnement `test` (`config_test.yml`) : `db_fr_lairdubois_www_test`
  et `ladb_test`, chargés avec les fixtures. `qa:phpunit` les charge s'ils sont vides, `castor -c
  test app:db:fixtures` les recharge. Les données de dev ne bougent pas.
- Chaque test tourne dans une transaction annulée à sa fin (`dama/doctrine-test-bundle`, extension
  PHPUnit de `phpunit.xml.dist`), y compris à travers les reboots du kernel entre deux requêtes :
  un test crée, modifie et supprime sans nettoyer derrière lui. Les ids créés par un test ne
  reviennent pas (l'`AUTO_INCREMENT` d'InnoDB n'est pas annulé).
- Ce qui n'est pas en base n'est pas annulé, `Tests\WebTestCase` s'en charge :
  - l'index : quand les tests d'une classe l'ont modifié (publication, suppression…), il est
    rempli à nouveau depuis la base après le dernier (`fos:elastica:populate`, quelques secondes).
    Entre deux tests de la classe, il est seulement rafraîchi (`refresh`) : un test voit les
    écritures des précédents, toutes, quel que soit le temps écoulé. Un résultat de recherche peut
    donc dépendre de l'ordre des tests de la classe ;
  - `uploads/`, partagé avec le dev : un fichier qu'un test supprime (images d'un contenu supprimé,
    `@ORM\PostRemove` de `Picture` et `Resource`) est remis après le test, depuis des liens durs
    pris au début de la classe (`var/tests/uploads/`). Les fichiers qu'un test crée (stickers,
    avatars…) restent.
- `Tests\WebTestCase` :
  - `static::createClient()` : anonyme, en HTTPS sur `lairdubois.test` (`trusted_hosts`) ;
  - `static::createClientAs(UserFixtures::USER)` : connecté (`ADMIN`, `USER`, `USER2`) sans passer
    par le formulaire de connexion, en mettant le token dans la session ;
  - `static::getEntityManager()`, `static::findUser()` : les données des fixtures. Le client
    reboote le kernel avant chaque requête sauf la première, et l'entity manager avec : relire une
    entité après une requête plutôt que de garder celle d'avant. L'entity manager d'une requête
    peut garder une entité qu'elle a supprimée (article d'un pas à pas) : `clear()` avant de
    vérifier une suppression par `find()`.
- Pas de RabbitMQ ni de websocket en test : les producers `old_sound_rabbit_mq.view_producer` et
  `webpush_notification_producer`, et le pusher `gos_web_socket.wamp.pusher`, sont décorés par
  `Tests\Double\InMemoryProducer` et `InMemoryPusher`, qui gardent les messages
  (`$client->getContainer()->get('tests.view_producer')->getMessages()`, avant le reboot suivant).
- Le profiler ne collecte qu'à la demande : `$client->enableProfiler()` avant la requête, puis
  `$client->getProfile()` (mails : collector `swiftmailer`, la livraison est coupée en test).
- Aucun appel réseau sortant. Les GET du smoke test n'en font pas : Stripe ne part qu'en XHR, le
  compteur de partages Facebook qu'avec `?url=`, le titre d'un referer qu'avec `?referer=1`, le
  scrape Open Graph de Facebook jamais en debug. Doubles de `config_test.yml`
  (`Tests\Double\Offline*`, des sous-classes, certains services demandant la classe d'origine) :
  `PictureManager::createFromUrl()` copie une image des fixtures et garde les URL
  (`$client->getContainer()->get('tests.picture_manager')->getUrls()`, avant le reboot suivant) ;
  `OpenGraphUtils::fetchMetas()` ne trouve des métas qu'à `OfflineOpenGraphUtils::URL` ;
  `VideoHostingUtils::getThumbnailUrl()` répond sans les API (playlist YouTube, Vimeo), comme
  `getVideoSitemapData()` (`SITEMAP_TITLE`) et `getVideoGwData()` (Youtook) ; la capture
  Chromium de `WebScreenshotUtils` copie une image des fixtures. Stripe, appelé en statique par
  `FundingController` : un listener de `kernel.request` lui donne le client HTTP
  `Tests\Double\OfflineStripeClient`, qui ne connaît que le payment intent qu'il crée
  (`PAYMENT_INTENT_ID`) et garde les requêtes (`tests.stripe_client`, `getRequests()`).
- Graph API de Facebook (`ApiController`, `OpenGraphUtils`) : le SDK est créé dans le code du site,
  sans service à remplacer ; `Tests\Double\OfflineFacebookGraph`, chargé par
  `tests/bootstrap.php`, définit les fonctions `curl_*()` du namespace du SDK, qui passent avant
  celles de PHP (comme `ClockMock` du bridge). Il connaît le compteur de partages de
  `OfflineFacebookGraph::URL`, répond une erreur pour `ERROR_URL` et pour toute autre requête, et
  rend les URL appelées (`popRequests()`).
- Une `\Error` PHP sort du kernel en 3.4 : elle remonte de `$client->request()` au lieu de donner
  une 500. Un warning PHP devient une exception (gestionnaire d'erreurs de PHPUnit) : 500.
- Smoke test (`tests/Smoke/RouteSmokeTest.php`) : chaque route GET en anonyme, `user` et `admin`
  (les assets d'Assetic en anonyme seulement), rien en 5xx ni en `\Error`. Une action qui refuse
  une requête hors XHR (« Only XML request allowed ») est rappelée en XHR. Paramètres des routes :
  `PARAMETERS` (par nom) et `getRouteParameters()` (par route). Échecs connus dans
  `KNOWN_FAILURES` (route, rôle, issue) : marqués incomplets, et le test échoue quand l'un d'eux
  passe, pour qu'on retire la ligne. Routes non appelées dans `UNTESTED`, avec la raison (sorties
  en « skipped »). Une nouvelle route GET sans valeur pour un de ses paramètres fait échouer le
  test. Filtrer sur une route : `castor qa:phpunit -- --filter 'core_creation_show'`.
- `Tests\PublicationTestCase` : les tests communs aux types publiables (show, new, create, edit,
  update, publish, unpublish, delete, lock, chown, avec les droits de l'anonyme, de l'auteur, d'un
  autre compte et de l'admin, et ce que la publication déclenche : compteurs, activité, liste
  issue de l'index), sur le contenu d'id 1. Une sous-classe donne le préfixe des routes, l'entité,
  le nom des compteurs de `UserMeta` et les valeurs du formulaire d'un nouveau contenu ;
  `createPicture()` lui crée une image avec la fabrique des fixtures (`tests.fixture_factory`).
  Échecs connus dans `KNOWN_FAILURES` (nom du test avec son jeu de données, issue), comme le smoke
  test. Exemples : `tests/Wonder/`, `tests/Find/`. Annonce : `KEEPS_ITS_ACTIVITIES_WHEN_UNPUBLISHED`
  (republiable, l'activité ne vient qu'à la première publication) et suppression par l'auteur.
- `Tests\EditorialTestCase` : la même chose pour le blog et la FAQ, que seuls les admins écrivent,
  publient et suppriment (contenu d'id 1 d'`admin`, `user` est l'autre compte) ; ni compteurs ni
  collectifs.
- Requêtes du JS du site (`Tests\XhrTrait`) : `static::xhr($client, $method, $uri, $parameters)`, le
  client de 3.4 n'a pas `xmlHttpRequest()` ; `static::generatePath($route, $parameters)`.
- Connaissances (`tests/Knowledge/`) : ni auteur, ni brouillon, ni formulaire d'édition. Une
  sous-classe de `KnowledgeTestCase` donne ses valeurs de création et une valeur à proposer ; le
  mécanisme commun (proposer, modifier, retirer, déplacer une valeur, voter) est dans `ValueTest`,
  sur le bois, avec les helpers de `ValueTrait` (`proposeValue()`, `vote()`…). Le verrou de
  `createLock()` vaut aussi pour les votes, même après un formulaire invalide : un second vote du
  même compte dans un test répond 404. Les avis exigent un compte de plus de 7 jours :
  `setCreatedAt()` sur le compte dans le test.
- Interactions (`tests/Core/`, sous-classes de `Tests\Core\InteractionTestCase`) : commentaires,
  likes, suivis, signalements, votes, retours d'expérience, tags et notifications, sur la création
  d'id 1 (`findCreation()`, `unpublishCreation()` pour un brouillon) ;
  `getWebpushNotifications($client)` désérialise les notifications webpush de la dernière requête.
  Le premier de deux votes d'un même compte passe par `tests.fixture_factory` (`vote()`), pas par
  le controller et son verrou. Messagerie dans `tests/Message/`, recherche dans `tests/Search/`.
- Une entité lue avant la première requête d'un client est celle que la requête modifie (même
  entity manager) : lire avant la requête les valeurs à comparer ; une entité supprimée n'a plus
  d'id.
- Le `DomCrawler` de Symfony 3.4 lit l'UTF-8 comme de l'ISO-8859-1 depuis PHP 8.2 (`chÃªne`), et
  un formulaire relu par le crawler renverrait ses valeurs accentuées ainsi : le client de test
  (`Tests\Utf8Client`, qui décore `test.client`) lui passe la page en entités HTML.
- Logs de test au niveau `notice` (`var/logs/test.log`) : le niveau `debug` du dev écrit 200 Mo
  par passage du smoke test.
- Comptes et profils (`tests/User/`) : connexion par le vrai formulaire (`form[action$="/login_check"]`),
  dont un compte au hash sha512 de FOSUserBundle écrit tel quel en base (`LoginTest`, il protège
  #24).
- Mails : `static::getSentEmails($client)` (après `$client->enableProfiler()`) et
  `static::getEmailRecipients($message)`. Le `delivery_address` de `config_dev.yml` remplace les
  destinataires de chaque mail par `dev@lairdubois.fr`, ceux d'origine restent dans l'en-tête
  `X-Swift-To`. Spool en mémoire en test : le spool fichier de `config.yml` (`app/spool/`) est
  partagé avec le dev.
- Commandes (`tests/Command/`) : `static::runCommand('ladb:cron:offers --force')` lance une ligne
  de commande dans la transaction du test et rend son code de sortie et sa sortie ; le kernel
  reste celui de la commande (`static::getEntityManager()`), ses mails sont dans
  `static::getSentEmailsByCommand()`. `CommandTest` lance chaque commande `ladb:` en verbeux avec
  ses options (`COMMANDS`, une nouvelle commande sans entrée fait échouer le test), `UNTESTED` et
  `KNOWN_FAILURES` comme le smoke test. `CrontabTest` lance les lignes de la crontab de
  `docs/01-installation.md` (étape 13) et vérifie l'effet de chaque cron ; il remet les
  `web/sitemap-*.xml` qu'écrit `ladb:cron:sitemaps`. Mastodon (curl dans `ladb:cron:spotlight`) est
  hors ligne par `mastodon_instance` (`config_test.yml`), un protocole que curl ne connaît pas ;
  Twitter et Facebook y sont coupés dans le code. Un `file_get_contents()` d'URL sans service à
  doubler (ip-api.com) : `Tests\Double\OfflineHttpStream::register([$url => $body])`, puis
  `unregister()` ; toute autre URL échoue.
- Géocodage (`LocalisableUtils`, profils, collectifs et tous les formulaires avec une
  localisation) : remplacé en test par `Tests\Double\OfflineLocalisableUtils`, qui connaît les
  adresses de sa constante `ADDRESSES` (`Bordeaux, France`) ; toute autre adresse n'a pas de
  résultat.
- `AbstractController::createLock()` pose un verrou Memcached de 3 secondes par action et par
  compte, contre le double envoi : la deuxième création d'un même compte dans un test répond 404.
  `Tests\WebTestCase` vide Memcached après chaque test (il ne sert qu'à ces verrous).
- Bug connu d'un test fonctionnel : vérifier le mauvais comportement avec le message « passes now:
  remove the known failure (#N) », puis `self::markTestIncomplete('Known failure, see #N.')`. Le
  test échoue quand le bug est corrigé.

Tests E2E (`tests/e2e/`, Playwright en TypeScript, son `package.json` et son lock) : seulement ce
que `WebTestCase` ne peut pas tester (JS, uploads, websocket), un parcours par feature. Rien de
Symfony ni de PHP : le site par HTTPS, la même suite jusqu'en 8.2.

- `castor qa:e2e [-- --grep …]` recharge les fixtures dans la base et l'index du dev (le site sert
  `app_dev.php`, quel que soit le contexte : les données de dev sont perdues), redémarre le serveur
  websocket en dev, puis lance la suite dans le service `playwright` (`npm ci` si `node_modules`
  manque ou date d'avant le lock). Aucun consumer RabbitMQ n'est nécessaire.
- Traces, captures et contexte des tests en échec dans `var/e2e/test-results/` (artifact
  `e2e-test-results` en CI) : `npx playwright show-trace`, ou trace.playwright.dev.
- Le navigateur joint le site par le réseau de la stack : `--host-resolver-rules` de Chromium
  envoie le domaine, port du worktree compris, vers `router:443` (`E2E_BASE_URL`, `E2E_ROUTER`).
- `auth.setup.ts` connecte chaque compte une fois par le formulaire et garde ses cookies
  (`var/e2e/auth/`) ; un test les prend avec `test.use({ storageState: storageStateOf(USER) })`.
  Importer `test` de `support/test.ts`, qui cache la barre de debug de Symfony.
- Un seul worker, une seule base pour toute la suite : un test ne suppose rien des autres, et
  prend un contenu ou un compte que les autres ne touchent pas (un like surveille le contenu).
- Bootstrap 3 laisse `aria-hidden` sur ses fenêtres modales : pas de `getByRole()` dedans.
- Les sessions n'ont pas de verrou (`lock_mode: 0`) : une requête encore en cours au chargement
  (`/@me/counters.json`) peut réécrire la session sans le jeton CSRF d'un formulaire chargé en XHR
  entre-temps, refusé sans message (#88). `gotoAndSettle()` avant d'ouvrir un tel formulaire.
- Le verrou de `createLock()` est pris même quand le formulaire est refusé : le renvoyer dans les
  3 secondes répond 404, et le JS ne dit rien.
- Bug connu : `test.fail()`, avec l'issue en commentaire. Le test casse quand le bug est corrigé.

## Pièges connus

- La plupart des paquets du lock déclarent PHP 7 (`"php": "^7.1"`…) : toute commande composer qui
  résout les dépendances (`install`, `require`, `update`) prend `--ignore-platform-req=php+`, qui
  ignore la borne haute et garde la basse. `castor app:install` le fait.
- Composer 2.2 LTS dans le builder : les versions suivantes embarquent `symfony/process` 5 ou plus,
  et le `ScriptHandler` de `sensio/distribution-bundle` y fait `new Process(string)` (`TypeError`).
  Composer 2.2 ne lit pas `COMPOSER_IGNORE_PLATFORM_REQ`.
- `allow-plugins` doit lister `robloach/component-installer` : il installe `components/` (jQuery…),
  que les templates lisent via Assetic.
- Le bundle `symfony/reprise` exige Symfony `^7.4` et PHP 8.4 : avant le saut en 7.4, seul le côté
  JS (Vite et le plugin npm `@symfony/reprise`) est utilisable. Ce plugin demande Node 22.18 ou plus.
- `trusted_hosts` vaut `^(dev|www)\.lairdubois\.fr$`, plus `^lairdubois\.test$` en dev et en
  test : une requête sur un autre host répond 400, y compris `localhost`, le host par défaut du
  client de `WebTestCase`.
- Les URL des assets sont absolues (`base_urls` de `config.yml`, depuis
  `router.request_context.host`) : dans un worktree, ce paramètre vaut `lairdubois.test:<port>`.
  Après un changement de ports (renommage du worktree), `castor app:install` réécrit
  `parameters.yml`.
- Le `fastcgi_params` de Debian passe `HTTP_HOST` sans le port (`$host`) : le nginx du `frontend`
  le remplace par `$http_host`, sinon les URL absolues et les redirections d'un worktree visent
  le port 443, celui de la copie principale.
- Juste après la recréation d'un conteneur (`castor up --service=frontend`…), Traefik répond
  quelques secondes `404 page not found`, le temps de le reprendre.
- La redirection HTTP → HTTPS de Traefik (`redirect-to-https`, comme dans docker-starter) perd le
  port : dans un worktree, passer directement par le port HTTPS.
- `web/app_dev.php` refuse les requêtes non locales ou qui passent par un proxy : le nginx de
  Docker les lui présente comme locales, `getClientIp()` vaut donc toujours `127.0.0.1` en dev.
- Sans index Elasticsearch, la page d'accueil répond 500 (`no such index [ladb]`) :
  `castor app:elasticsearch:populate`.
- Le serveur websocket de Gos n'écoute que sur une IP : il tourne avec `--host=0.0.0.0`, et
  `websocket_host` (`websocket`) ne sert qu'au pusher.
- Gos WebSocket ne marche pas tel quel en PHP 8 : le pusher WAMP (son client lit mal la réponse du
  serveur) et la lecture de la session par le serveur (`unserialize()` de Ratchet, PHP 8.3) sont
  remplacés par les classes de `src/Ladb/CoreBundle/WebSocket/`, à retirer avec Gos (#21).
- `bin/console` lit `SYMFONY_ENV`, pas `APP_ENV`.
- Les sessions sont en base (`PdoSessionHandler`) : `doctrine:schema:create` ne crée pas la table
  `sessions`.
- En 3.4, `HttpKernel` n'attrape que les `\Exception` : une `\Error` PHP (appel sur `null`,
  `TypeError`) sort du kernel au lieu de donner une 500.
- Les mots de passe sont hashés en sha512 par FOSUserBundle : les comptes existants doivent continuer
  à se connecter sans reset, d'un bout à l'autre de la migration.
- PHPStan lit la version de PHP dans `composer.json` (`>=7.3`) : la syntaxe PHP 8 (propriétés
  promues, `readonly`, virgule finale dans une signature) y est une erreur, même si tout tourne en
  8.5.
- `ruflin/elastica` déclare `Elastica\Query\Match` jusqu'en 6.1 : `match` est un mot réservé depuis
  PHP 8, d'où une erreur de parsing (500) dès qu'une page avec des tags cherche des contenus
  similaires. Passé en 6.2, utiliser `Elastica\Query\MatchQuery`.
- `--context=test` et `--context=ci` visent la base et l'index de test (`config_test.yml`), pas ceux
  du dev : la CI charge les fixtures avec `castor app:db:fixtures` dans son contexte.
- Une action qui finit par `exit(0)` (`PictureController::uploadAction()`,
  `ResourceController::uploadAction()`) arrête PHPUnit, sans erreur : ne pas l'appeler dans un test.
- `doctrine:fixtures:load` sans `--append` vide les tables par `DELETE`, qui ne remet pas les
  `AUTO_INCREMENT` à zéro : `castor app:db:fixtures` supprime la base à la place.
- Hors requête HTTP (fixtures, commandes), les listeners et utils qui lisent l'utilisateur ou la
  session de la requête (`GlobalUtils`) échouent : `FixtureFactory::actAs()` connecte l'auteur dans
  une requête avec une session en mémoire.
- Les managers (`publish()`…) parcourent les collections inverses (créations d'un plan…), qui ne sont
  initialisées que sur une entité chargée depuis la base : `FixtureFactory::create()` fait un
  `refresh()` après le premier `flush()`.
- `MailerUtils` signe chaque mail avec la clé DKIM de la prod (`keys/private.pem`, hors git) : sans
  elle, tout mail du site (confirmation d'adresse, notifications…) donne une 500. `castor
  app:install` en génère une jetable si elle manque. Les mails de FOSUserBundle (mot de passe
  oublié) ne passent pas par là.
- Les notifications web push sont signées avec les clés VAPID de la prod (`bentools_webpush.*` de
  `parameters.yml`) : avec celles de `parameters.yml.dist`, le consumer `webpush_notification`
  s'arrête au premier message. `castor app:install` y écrit une paire jetable, que les `composer
  install` suivants gardent.
