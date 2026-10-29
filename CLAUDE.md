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
- Budget de tokens, pour la migration seule : au plus 50 % de la limite sur 5 h, et 50 % de la
  limite hebdomadaire réparti sur les 7 jours de la fenêtre : au jour N, au plus N × 7,1 points du
  compteur hebdomadaire en tout, ce qui n'a pas servi se reporte. La status line ne donne que les
  chiffres du compte : `.claude/tmp/budget/` (hors git) n'additionne leurs hausses que pendant que
  la migration tourne (la session principale ou un de ses agents), une autre session ouverte en
  même temps compte donc aussi. À 85 % de la limite sur 5 h, ou à moins d'un point de la part de
  la semaine, plus de nouvel agent ; à la limite, tout s'arrête jusqu'à la nouvelle fenêtre de 5 h
  ou jusqu'à la part du jour suivant (à l'heure où la fenêtre hebdomadaire a commencé).
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
- On garde toutes les features, y compris Twitter, Facebook, Pinterest et le temps réel de l'éditeur
  de processus.
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
   - 4.4 : `transchoice` → `trans`.
   - 5.4 : FOSUserBundle → sécurité native, Gos WebSocket → Mercure, RabbitMqBundle → Messenger,
     Swiftmailer → Mailer (`DkimSigner` de Mime 5.2, spool par Messenger, #19).
   - 6.4 : annotations → attributs, fin de SensioFrameworkExtraBundle.
   - Elasticsearch 5 → 9 (#31), réindexation depuis la base : 7.17 en Docker et en CI dès la 4.4
     (FOSElasticaBundle 6.3), 9 après le saut en 6.4 (FOSElasticaBundle 7.2, Elastica 9, #161),
     seul livré en prod. Aucune version de FOSElasticaBundle ne parle ES 8 ou 9 avant Symfony 6.4.

## Environnement

- PHP 8.5 dès le départ, dans Docker (`jolicode/docker-starter`, voir la section suivante). Vérifié
  le 2026-10-23 : le code actuel démarre, compile le container, passe `lint:twig` et
  `doctrine:schema:validate` et rend les pages en 8.5.
- `E_DEPRECATED` est coupé en dev, sinon PHP 8.4 et 8.5 noient chaque commande de deprecations :
  dans le `php.ini` des images et dans `bin/console`, qui fait ce que fait `Debug::enable()` sans
  son `error_reporting(-1)`. Les dépréciations Symfony (`E_USER_DEPRECATED`) restent.

## Docker

Issu de l'`AGENTS.md` de docker-starter. Jamais PHP, Node ni Composer sur l'hôte : tout passe par
le conteneur `builder`, via Castor. Prérequis de l'hôte : Docker et Castor.

```bash
castor start                      # build, composer, assets, up, schéma, fixtures si la base est vide, index, workers
castor about                      # URL de la copie et de Mailpit
castor docker:ports               # ports du routeur de la copie
castor stop
castor builder                    # shell dans le builder
castor builder -- bin/console …   # une commande dans le builder
castor logs [--service=frontend]
castor app:install                # composer install, clés DKIM et VAPID jetables si absentes
castor app:db:schema              # base, schéma (doctrine:schema:update) et table sessions
castor app:db:fixtures           # recrée la base avec les fixtures, notifications, index
castor app:elasticsearch:populate # fos:elastica:populate
castor app:assets:build           # CSS et JS minifiés dans web/build/ (npm ci si besoin, Vite)
castor app:assets:watch           # les reconstruit à chaque modification, sans minifier
castor docker:build [--service=…]
castor docker:worker:start        # et docker:worker:stop
castor docker:destroy --force     # conteneurs, volumes, réseau, images et certificats de la copie
castor docker:generate-certificates [--force]
```

Contextes : `--context=test` (`SYMFONY_ENV=test`), `--context=ci` (le même, sans couleurs).

- Application à la racine, docroot `web/`, front controller `app_dev.php`. Service `frontend` :
  nginx et php-fpm. Node 24 dans le builder seulement, pour Vite : les autres images servent les
  fichiers de `web/build/`. Pas de Java.
- Assets (CSS et JS) : Vite et le plugin npm de Symfony Reprise (`package.json`, `vite.config.js`),
  dans `web/build/` avec `manifest.json`, hors git. Une entrée par groupe des anciennes balises
  `{% stylesheets %}` et `{% javascripts %}` d'Assetic, mêmes fichiers dans le même ordre, et des
  balises écrites à la main : `{{ asset('build/base.css', 'build') }}` (package `build` de
  `config.yml`, `json_manifest_path`). Un fichier LESS ou JS ajouté à un groupe va dans
  `vite.config.js`. Le dev sert le build : `castor app:assets:watch` pendant qu'on modifie un
  LESS ou un JS, pas de serveur de Vite.
- MariaDB 10.11 (`root`/`app`), Elasticsearch 9.5 (un nœud, sans sécurité, un volume par version
  majeure), RabbitMQ 3.13 (`app`/`app`), Memcached. Workers
  (profil `worker`) : les trois de Messenger (`messenger:consume mailer`, `messenger:consume view`,
  `messenger:consume webpush_notification`). Extension PHP `amqp` dans les images, pour le transport
  AMQP de Messenger.
- Hub Mercure (service `mercure`, profil `default`, #21) : l'image `dunglas/mercure` 1.x
  (protocole 1.0, #154), en HTTP (`SERVER_NAME=:80`), routé sur `/.well-known/mercure` du domaine
  et du port du site. Une clé JWT de dev fixe (`MERCURE_JWT_SECRET` de `docker-compose.yml`) signe
  la publication et l'abonnement ; pas d'abonné anonyme : sans JWT, 401. Paramètres `mercure_url`
  (interne, pour publier), `mercure_public_url` (celle du navigateur) et `mercure_jwt_secret`.
  Les JWT sont des access tokens OAuth 2.0 : `iss` fixe (`https://lairdubois.fr`,
  `jwt.claims` de `config.yml`, que le hub reconnaît par `MERCURE_TRUSTED_ISSUERS`), `aud` =
  l'URL publique du hub, que `resource_identifier` épingle (derrière le routeur, en HTTP, le hub
  la déduirait en `http://`). `write_timeout 0s` (`MERCURE_EXTRA_DIRECTIVES`) : sinon le hub ferme
  chaque abonnement au bout de 10 minutes, et l'espace de travail recharge ses tâches à chaque
  reconnexion. Healthcheck sur l'API d'admin de Caddy (`127.0.0.1:2019/mercure/health/ready`,
  qui n'écoute que dans le conteneur) : la 1.x ne sert plus `/healthz`.
- Mailpit (service `mailpit`, profil `default`, #19) : l'image `axllent/mailpit`, qui reçoit les
  mails en SMTP sur `mailpit:1025` et les affiche dans son interface, routée sur `/mailpit/` du
  domaine et du port du site (`MP_WEBROOT`) : `castor about` donne son URL. Base temporaire, sans
  volume : les mails sont perdus quand il redémarre. `MAILER_DSN` (`smtp://mailpit:1025`) est dans
  l'environnement du builder, pour `mailer_dsn`. Tous les mails du site y arrivent, par le worker
  `worker_consumer_mailer`, envoyés à `dev@lairdubois.fr` (`envelope.recipients` de
  `config_dev.yml`) : seule l'enveloppe SMTP change, l'en-tête `To` garde le destinataire d'origine.
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
  docker:ports`. Les URL des assets et du hub Mercure portent ce port (`parameters.yml`).
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
castor -c ci qa:lint         # lint:twig, lint:yaml, doctrine:schema:validate, cache:warmup et lint:container --env=prod, composer validate
castor qa:phpstan [-b]       # niveau 0, phpstan-baseline.neon ; -b la régénère
castor qa:phpunit [-- --filter …]
castor qa:deprecations       # var/deprecations/report.md, et le résumé du job en CI
castor qa:install            # outils de tools/ (PHPStan, PHPUnit), installés à la volée sinon
castor qa:e2e [-- --grep …]  # tests E2E (Playwright) : recharge la base du dev, hors qa:all, job E2E en CI
castor qa:rector <chemin>… [--dry-run]  # Rector (tools/rector) : get() et getDoctrine() des actions → arguments (#16, #177), des utils, managers, listeners et validateurs → constructeur (#118), dépréciations de 4.x (#133), routes, contraintes, `@required` et mapping de Doctrine et Gedmo en attributs (#25), commandes en `#[AsCommand]` (#28), `@Template` et `@Security` de Sensio en attributs natifs (#27), types de retour natifs de 5.4 et 6.4 (#177, #205), handlers de Messenger en `#[AsMessageHandler]` (#205)
```

- PHPUnit 9.6, dans `tools/phpunit` et pas dans le `composer.json` de l'application : c'est la
  dernière version qui marche avec `Tests\DeprecationCollector` (`TestResult` et `ErrorHandler`
  de PHPUnit, supprimés en 10). `symfony/phpunit-bridge`, qui n'est pas un des composants en
  `6.4.*` de `composer.json`, est en 8.1 : il ne dépend d'aucun composant de Symfony, n'exige que
  PHP 8.1 et marche encore avec PHPUnit 9.6, rien à y changer aux sauts de version. Le collecteur ne
  l'appelle plus : il construit lui-même le gestionnaire d'erreurs de PHPUnit du test en cours,
  comme le `getPhpUnitErrorHandler()` du bridge, privé depuis 4.4.
- `qa:phpunit` lance PHP avec OPcache (`opcache.enable_cli=1`, `file_update_protection=0`, #113) :
  chaque requête d'un test démarre un nouveau container, qui recharge ses fichiers de services, et
  sans OPcache la compilation de celui d'`argument_resolver.service` (une closure par action) garde
  143 Ko jusqu'à la fin du process. La suite monte alors à plus de 700 Mo, contre 107 Mo.
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
- `lint:yaml` compte les dépréciations comme des erreurs. Sans chemin, `lint:twig` lit tous les
  namespaces de Twig, bundles de `vendor/` compris : `qa:lint` lui donne les nôtres.
- Cache GitHub Actions : couches Docker (`type=gha`, un scope par cible du Dockerfile, via un
  `docker-compose.override.yml` écrit par le workflow), cache Composer (`.home/.cache/composer`)
  et cache npm (`.home/.npm`).
- Les deux jobs construisent les assets (`castor app:assets:build`) : `asset()` lit leur manifest
  sur chaque page. `qa:phpunit` et `qa:e2e` les construisent aussi s'il manque.
- Rector 2 dans `tools/rector`, hors `qa:install` (la CI n'en a pas besoin), avec les règles du
  projet (`tools/rector/src/`). `ControllerGetToActionArgumentRector` laisse
  `__RECTOR_REMOVED_LINE__;` à la place des lignes qu'il supprime, que `qa:rector` retire ensuite :
  php-parser réimprimerait les commentaires d'avant sans leur indentation.
  `ContainerToConstructorRector` écrit le constructeur et les propriétés qu'il ajoute dans une
  constante `__RECTOR_INSERTED_CODE__` (le code en base64), que `qa:rector` remplace par le code :
  php-parser les indenterait mal. Il lit sur le disque les propriétés des classes parentes : sur
  une hiérarchie, une passe par niveau, les parentes d'abord. Le cache de Rector
  (`tools/rector/var/`) ignore les modifications d'une règle et des classes parentes : le vider
  avant chaque passe.
- php-parser compte une tabulation du code d'origine pour 4 colonnes, et Rector écrit un caractère
  d'indentation par colonne : un nœud qu'il réimprime (une méthode qui reçoit un attribut, son
  docblock) aurait 4 tabulations par niveau. `rector.php` ramène la tabulation à une colonne
  (`PrettyPrinterAbstract::$tabWidth`). Une méthode indentée en espaces est alors réimprimée avec
  une tabulation par espace (`OpencutlistController`) : la remettre en espaces à la main.
- Annotations → attributs (#25) : une `AnnotationToAttribute` par sorte et par PR dans `rector.php`.
  Rector 2.7 n'applique plus `withAttributesSets(symfonyRoute: true)` ni `symfonyValidator` seuls,
  et `symfony: true` convertit d'un coup les routes, les contraintes et `@required`. Les routes sont
  des `#[Route]` depuis #25 ; `routing.yml` les lit avec `type: attribute` depuis #27 (`annotation`,
  le seul type de la 5.4 pour un controller, attributs compris, est déprécié en 6.4). Les
  contraintes de validation (`UniqueEntity` comprise) et les setters `#[Required]` aussi
  (`enable_attributes` du validateur depuis #27, `enable_annotations` lisait les deux). Le mapping
  de Doctrine et de Gedmo aussi, avec le driver `attribute` (`LadbCoreBundle`, `gedmo_loggable`,
  `gedmo_tree` dans `config.yml`, un seul driver par mapping) : `NestedAnnotationToAttributeRector`
  (rector-doctrine) sort les colonnes d'une `JoinTable`, les index et contraintes d'unicité d'une
  `Table` en attributs de la propriété ou de la classe. Il passe avant les autres : un
  `#[ORM\JoinColumn]` précède son `#[ORM\ManyToOne]`, et les attributs convertis suivent ceux
  qui étaient déjà là. Rector indente l'attribut comme le `/**` du docblock qu'il remplace (des
  espaces, deux fois dans `Creation`).
- Rector ne convertit une contrainte qu'en attribut existant : les nôtres ont un
  `#[\Attribute(...)]`, écrit à la main, aux cibles de leur `getTargets()` (classe, ou propriété et
  getter), répétables comme celles de Symfony. Il saute une annotation dont l'alias n'a pas la
  casse du `use` (`@ladbAssert\…`, que Doctrine acceptait), recopie le nom complet d'une annotation
  (`@Ladb\CoreBundle\…`) sans `\` de tête (relatif au namespace : une autre classe), écrit
  `Required` en nom complet (importé à la main ensuite) et retire la ligne vide entre un
  commentaire et le docblock qu'il supprime.
  Une annotation était construite une fois, puis relue dans le cache d'annotations (fichiers de
  `var/cache/`) : le rapport ne voyait pas les dépréciations de son constructeur. Un attribut est
  construit à chaque chargement des métadonnées, elles sortent en test (`Range` de `Find`, à `min`,
  `max` et `minMessage`, passé en `notInRangeMessage`).
- Annotations de SensioFrameworkExtraBundle (#27) : deux passes. La première fait de `@Template` et
  `@Security` les attributs de Sensio, et renomme déjà `#[Template]` en celui de TwigBridge ; la
  seconde fait d'un `#[Security]` un `#[IsGranted]` (`SecurityAttributeToIsGrantedAttributeRector`,
  une `Expression` quand ce n'est pas un seul `is_granted()`). Rector écrit ces attributs en nom
  complet et laisse le `use` de Sensio : remplacés à la main.
- Handlers de Messenger (#205) : `MessageSubscriberInterface` étend `MessageHandlerInterface`, et
  `MessageHandlerInterfaceToAttributeRector` met aussi un `#[AsMessageHandler]` sur la classe d'un
  subscriber, sans `__invoke()` (le container refuse le handler). Rector réimprime la classe dont il
  retire l'interface (lignes vides entre les membres, accolade) : la conversion de `ViewConsumer` et
  `WebpushNotificationConsumer` est celle de Rector, réécrite à la main dans le style des fichiers.
- Les règles de rector-symfony (les sets de 4.x ne sont plus dans Rector 2 : une règle à la fois
  dans `rector.php`) ne s'appliquent qu'à partir d'une version d'un paquet de Symfony
  (`symfony/console`…), et l'ignorent sans rien dire avant : `rector.php` donne à Rector les
  versions installées dans l'application (`vendor/composer/installed.json`), pas celles de
  `tools/rector`. Elles typent par PHPStan :
  `MakeDispatchFirstArgumentEventRector` ne reconnaît pas le dispatcher d'une propriété sans type
  (`$this->dispatcher` des managers).
- Types de retour natifs annoncés par le DebugClassLoader (« might add … as a native return type
  declaration ») : le rapport ne voit que les classes que la suite charge. La liste complète vient du
  script `patch-type-declarations` d'ErrorHandler en mode rapport, qui parcourt le classmap
  (`composer dump-autoload -o` avant, `composer dump-autoload` après) et charge `tests/`, donc
  PHPUnit : `castor builder -- env 'SYMFONY_PATCH_TYPE_DECLARATIONS=deprecations=1&php=8.5' php -d
  auto_prepend_file=tools/phpunit/vendor/autoload.php
  vendor/symfony/error-handler/Resources/bin/patch-type-declarations`. Les types s'écrivent par
  `AddReturnTypeDeclarationRector` (`rector.php`), pas par le script (`force=2`) : il n'ajoute un
  type que si la signature finit la ligne, et en ajoute à nos propres méthodes d'après leur
  `@return` (#182).

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

Comptes (mot de passe = identifiant, hashé par le hasher `native` de `security.yml`, connexion par le
formulaire `/login`) :

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
  et les index `ladb_test_<type>`, chargés avec les fixtures. `qa:phpunit` les charge s'ils sont
  vides, et `qa:lint` en contexte `test` ou `ci`, avant `doctrine:schema:validate` ; `castor -c
  test app:db:fixtures` les recharge. Les données de dev ne bougent pas.
- Chaque test tourne dans une transaction annulée à sa fin (`dama/doctrine-test-bundle`, extension
  PHPUnit de `phpunit.xml.dist`), y compris à travers les reboots du kernel entre deux requêtes :
  un test crée, modifie et supprime sans nettoyer derrière lui. Les ids créés par un test ne
  reviennent pas (l'`AUTO_INCREMENT` d'InnoDB n'est pas annulé).
- Ce qui n'est pas en base n'est pas annulé, `Tests\WebTestCase` s'en charge :
  - les index : quand les tests d'une classe en ont modifié un (publication, suppression…), il est
    rempli à nouveau depuis la base après le dernier (`fos:elastica:populate --index`, une
    seconde). Entre deux tests de la classe, ils sont seulement rafraîchis (`refresh`,
    `static::getIndexes()` : tous les index en une requête) : un test voit les
    écritures des précédents, toutes, quel que soit le temps écoulé. Un résultat de recherche peut
    donc dépendre de l'ordre des tests de la classe ;
  - `uploads/`, partagé avec le dev : un fichier qu'un test supprime (images d'un contenu supprimé,
    `#[ORM\PostRemove]` de `Picture` et `Resource`) est remis après le test, depuis des liens durs
    pris au début de la classe (`var/tests/uploads/`). Les fichiers qu'un test crée (stickers,
    avatars…) restent.
- `Tests\WebTestCase` :
  - `static::createClient()` : anonyme, en HTTPS sur `lairdubois.test` (`trusted_hosts`) ;
  - `static::createClientAs(UserFixtures::USER)` : connecté (`ADMIN`, `USER`, `USER2`) sans passer
    par le formulaire de connexion, par `loginUser()` du client ;
  - `static::getEntityManager()`, `static::findUser()` : les données des fixtures. Le client
    reboote le kernel avant chaque requête sauf la première, et l'entity manager avec : relire une
    entité après une requête plutôt que de garder celle d'avant. L'entity manager d'une requête
    peut garder une entité qu'elle a supprimée (article d'un pas à pas) : `clear()` avant de
    vérifier une suppression par `find()`.
  - `static::getContainer()` : le container de test, qui lit aussi les services privés (ceux de
    `services.yml`), dans le kernel du dernier `createClient()` ou `bootKernel()`, reboots du
    client compris ;
  - `static::getFieldErrors($crawler, 'ladb_usersettings[fullname]')` : les messages d'erreur d'un
    champ, dans son `.form-group`.
- Pas de RabbitMQ en test : les transports de Messenger sont `in-memory://` (`config_test.yml`). Ils
  gardent les messages (`$client->getContainer()->get('tests.view_transport')->getSent()`, avant le
  reboot suivant). `static::consumeMessage($message, 'view')` passe un message à ses handlers, comme
  le worker. `LADB_TEST_MESSENGER_TRANSPORT_DSN` change de transport : `UnreachableRabbitMqTest` les
  met sur un port fermé, `RabbitMqTest` sur le RabbitMQ de la stack (`amqp://`, qui doit tourner),
  dans les échanges et files `test_mailer`, `test_view` et `test_webpush_notification` que les
  workers du dev ne lisent pas, et les consomme par `messenger:consume` (#168).
- Pas de hub Mercure en test : `mercure.hub.default` est décoré par `Tests\Double\InMemoryHub`, qui
  garde les `Update` publiées (`$client->getContainer()->get('tests.mercure_hub')->getUpdates()`,
  avant le reboot suivant) ; ses URL et son JWT restent ceux de la configuration.
- Envoi des notifications web push (`tests/Webpush/`) : le test passe le message au handler
  (`consumeMessage()`), le client Guzzle de l'envoi passe par
  `Tests\Double\InMemoryPushHandler` (`tests.webpush_push_handler`), qui garde les requêtes et
  leurs options (timeout) et répond 201, 410 (`expire()`) ou rien (`makeUnreachable()`).
  `NotificationTest` vérifie le JWT VAPID et déchiffre le contenu avec les clés de l'abonnement.
- Le profiler ne collecte qu'à la demande : `$client->enableProfiler()` avant la requête, puis
  `$client->getProfile()` (mails : collector `mailer`, transport `null://` en test).
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
- Une `\Error` PHP donne une 500, comme une exception (`framework.handle_all_throwables`, #205) :
  `$client->request()` rend la page d'erreur au lieu de la lever (`Tests\Error\PhpErrorTest`). Un
  warning PHP devient une exception (gestionnaire d'erreurs de PHPUnit) : 500.
- Smoke test (`tests/Smoke/RouteSmokeTest.php`) : chaque route GET en anonyme, `user` et `admin`,
  rien en 5xx ni en `\Error`. Une action qui refuse
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
- Requêtes du JS du site (`Tests\XhrTrait`) : `static::xhr($client, $method, $uri, $parameters)`,
  écrit quand le client (3.4) n'avait pas encore `xmlHttpRequest()` ;
  `static::generatePath($route, $parameters)`.
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
- Ce que trouve chaque recherche (`Tests\Search\FacetTest`) : chaque facette de chaque liste, la
  recherche sans facette, les typeaheads et les recherches des pages de contenu (contenus
  similaires, compteurs), relevés sur Elasticsearch 5.6 avant #31. Pour chaque recherche d'une
  requête : type, tri (`score` : par pertinence), ids de la page dans leur ordre, total, que garde
  `tests.search_utils` (`Tests\Double\RecordingSearchUtils`, `getSearches()`). Une différence est
  un changement de comportement de la recherche : à expliquer, jamais à recopier. Le tri par défaut
  des listes du bois et de la FAQ dépend du compteur de nouveautés du compte : chaque test repart de
  celui des fixtures, des requêtes précédentes dans le même test le changent.
- Une entité lue avant la première requête d'un client est celle que la requête modifie (même
  entity manager) : lire avant la requête les valeurs à comparer ; une entité supprimée n'a plus
  d'id.
- Logs de test au niveau `notice` (`var/logs/test.log`) : le niveau `debug` du dev écrit 200 Mo
  par passage du smoke test.
- Comptes et profils (`tests/User/`) : connexion par le vrai formulaire (`form[action$="/login_check"]`),
  dont un compte au hash sha512 de FOSUserBundle écrit tel quel en base, rehashé à sa connexion, et
  des sessions ouvertes avant #24, au token sérialisé en dur (`LoginTest`).
- Mails : `static::getSentEmails($client)` (après `$client->enableProfiler()`) et
  `static::getEmailRecipients($message)` (l'en-tête `To`). Ils lisent les `Email` que Mailer met dans
  la file `mailer` (`isQueued()` de `mailer.message_logger_listener`), sans signature. Rien ne part
  pendant la requête : le `SendEmailMessage` reste dans `tests.mailer_transport` (`getSent()`), et
  `static::consumeMessage($message, 'mailer')` l'envoie comme le worker, par le transport
  (`null://` en test, rien ne part), qui signe une copie, un `Message`, que
  `Tests\Mail\DkimSignatureTest` vérifie. Dans le worker, `reset_on_message` vide
  `mailer.message_logger_listener` après chaque message : `RabbitMqTest` écoute le `MessageEvent`.
- Commandes (`tests/Command/`) : `static::runCommand('ladb:cron:offers --force')` lance une ligne
  de commande dans la transaction du test et rend son code de sortie et sa sortie ; le kernel
  reste celui de la commande (`static::getEntityManager()`), ses mails sont dans
  `static::getSentEmailsByCommand()`. Sans gestionnaire de signaux : la console de 5.2+ en
  enregistre un par exécution (pcntl), qui garde l'application, le kernel et la commande jusqu'à
  la fin du process (`setSignalsToDispatchEvent()`). `CommandTest` lance chaque commande `ladb:` en verbeux avec
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
  résultat. Le vrai `LocalisableUtils` est testé dans `tests/Geocoding/`, sur des réponses de
  l'API de Google (`responses/`) servies par un handler Guzzle, et `ADDRESSES` y est comparé à ce
  qu'il donne.
- `AbstractController::createLock()` pose un verrou Memcached de 3 secondes par action et par
  compte, contre le double envoi : la deuxième création d'un même compte dans un test répond 404.
  `Tests\WebTestCase` vide Memcached après chaque test (il ne sert qu'à ces verrous et aux dates de
  recalcul des compteurs de `UserUtils`, par compte).
- Bug connu d'un test fonctionnel : vérifier le mauvais comportement avec le message « passes now:
  remove the known failure (#N) », puis `self::markTestIncomplete('Known failure, see #N.')`. Le
  test échoue quand le bug est corrigé.

Tests E2E (`tests/e2e/`, Playwright en TypeScript, son `package.json` et son lock) : seulement ce
que `WebTestCase` ne peut pas tester (JS, uploads, hub Mercure), un parcours par feature. Rien de
Symfony ni de PHP : le site par HTTPS, la même suite jusqu'en 8.2.

- `castor qa:e2e [-- --grep …]` recharge les fixtures dans la base et l'index du dev (le site sert
  `app_dev.php`, quel que soit le contexte : les données de dev sont perdues), puis lance la suite
  dans le service `playwright` (`npm ci` si `node_modules` manque ou date d'avant le lock). Aucun
  worker de Messenger n'est nécessaire.
- Traces, captures et contexte des tests en échec dans `var/e2e/test-results/` (artifact
  `e2e-test-results` en CI) : `npx playwright show-trace`, ou trace.playwright.dev.
- Le navigateur joint le site par le réseau de la stack : `--host-resolver-rules` de Chromium
  envoie le domaine, port du worktree compris, vers `router:443` (`E2E_BASE_URL`, `E2E_ROUTER`).
- Aucun appel aux CDN (#143) : ce que le site charge de cdnjs.cloudflare.com et cdn.jsdelivr.net
  (bibliothèques, MathJax, emojione) est servi par `context.route()` (`support/test.ts`) depuis
  une copie identique au fichier du CDN, sha256 comparé à l'ajout : le fichier du paquet npm à la
  même version (`package.json`, un alias par version quand le site en charge deux : Chart.js,
  jsPlumb), sinon une copie dans `cdn/<host>/<chemin>` (paquet sans le fichier, fichier différent
  ou paquet trop gros). Une URL de ces CDN sans copie fait échouer le test. Aucun autre host n'est
  appelé : Google Analytics est coupé en debug, Stripe, Google Maps et Facebook sont sur des pages
  qu'aucun test n'ouvre.
- `auth.setup.ts` connecte chaque compte une fois par le formulaire et garde ses cookies
  (`var/e2e/auth/`) ; un test les prend avec `test.use({ storageState: storageStateOf(USER) })`.
  Importer `test` de `support/test.ts`, qui cache la barre de debug de Symfony.
- Un seul worker, une seule base pour toute la suite : un test ne suppose rien des autres, et
  prend un contenu ou un compte que les autres ne touchent pas (un like surveille le contenu).
- Bootstrap 3 laisse `aria-hidden` sur ses fenêtres modales : pas de `getByRole()` dedans.
- Les sessions n'ont pas de verrou (`lock_mode: 0`) : une requête qui réécrit la session peut
  effacer le jeton CSRF d'un formulaire chargé en XHR entre-temps, refusé sans message.
  `/@me/counters.json`, lancé au chargement, ne l'écrit plus (#88 : `metadata_update_threshold`,
  dates de recalcul des compteurs dans Memcached, `Tests\User\CountersTest`) ; `gotoAndSettle()`
  reste avant d'ouvrir un tel formulaire, contre les autres requêtes qui écrivent la session.
- Le verrou de `createLock()` est pris même quand le formulaire est refusé : le renvoyer dans les
  3 secondes répond 404, et le JS ne dit rien.
- Bug connu : `test.fail()`, avec l'issue en commentaire. Le test casse quand le bug est corrigé.

## Pièges connus

- La plupart des paquets du lock déclarent PHP 7 (`"php": "^7.1"`…) : toute commande composer qui
  résout les dépendances (`install`, `require`, `update`) prend `--ignore-platform-req=php+`, qui
  ignore la borne haute et garde la basse. `castor app:install` le fait.
- Composer 2.2 LTS dans le builder. Il avait été choisi pour le `ScriptHandler` de
  `sensio/distribution-bundle`, parti depuis : un `composer install` sur un clone neuf passe aussi
  avec Composer 2.10, rien n'empêche plus de monter. Composer 2.2 ne lit pas
  `COMPOSER_IGNORE_PLATFORM_REQ` et n'a pas `composer audit` (2.4 et plus).
- Les paquets de type `component` (jQuery, jQuery UI, Masonry, Moment…), que `vite.config.js`
  concatène, s'installent dans `vendor/` par `oomphinc/composer-installers-extender`
  (`installer-types` et `installer-paths` de `composer.json`). `robloach/component-installer`, qui
  les copiait aussi dans `components/`, est remplacé par le projet (`replace` : il est exigé par
  `eonasdan/bootstrap-datetimepicker`) : toutes ses versions tirent `kriswallsmith/assetic` 1.x,
  qui refuse `symfony/process` 4. Il reste à `false` dans `allow-plugins` : sinon, sur une copie
  où il est encore installé, `composer install` s'arrête avant de le retirer.
- Le bundle `symfony/reprise` exige Symfony `^7.4` et PHP 8.4 : avant le saut en 7.4, seul le côté
  JS (Vite et le plugin npm `@symfony/reprise`) est utilisable. Ce plugin demande Node 22.18 ou plus.
- Sans `web/build/manifest.json`, `asset(…, 'build')` lève une exception : toute page répond 500.
  Après un `git pull` qui touche aux assets, `castor app:assets:build`.
- Les scripts du site sont écrits pour la portée globale (`var` et `function` en tête de fichier,
  jQuery et ses plugins en globales) : Vite en ferait des modules. `vite.config.js` les concatène
  tels quels et les écrit comme fichiers du build, que le plugin de Reprise met dans le manifest ;
  terser les minifie (le minifieur de Vite écrit des template literals et `**`). Le bundle de
  Reprise (#29) rend des `<script type="module">` : ces groupes garderont `asset()`.
- Vite 8 déclare `less ^4` en dépendance optionnelle : l'`overrides` de `package.json` lui donne
  `less@3.13.1`, celle d'Assetic, pour un CSS identique.
- Le CSS n'est minifié que de ses espaces et commentaires (esbuild, sans cible ni `minifySyntax`).
  Lightning CSS, le minifieur par défaut de Vite, arrondit les nombres (`1.42857143` → `1.42857`,
  le texte bouge d'une fraction de pixel), retire des préfixes et réécrit les couleurs selon les
  navigateurs visés ; esbuild avec une cible ajoute des préfixes (`-webkit-hyphens` : Safari
  couperait les mots).
- Les `url()` absolues des CSS (`/bundles/ladbcore/…`, `assets:install`) restent telles quelles.
  Deux relatives ne résolvaient déjà pas avec Assetic et ne résolvent toujours pas (404) : les
  glyphicons de Bootstrap (`../fonts/`, aucune page n'en affiche) et `images/layers.png` de
  Leaflet (contrôle non utilisé). Vite les signale à chaque build.
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
- Sans index Elasticsearch, la page d'accueil répond 500 (`no such index [ladb_…]`) :
  `castor app:elasticsearch:populate`.
- Éditeur de processus et Mercure (#21) : chaque modification est publiée sur le hub
  (`_push()` d'`AbstractWorkflowBasedController`), en update privée sur deux topics fixes,
  indépendants de l'hôte, écrits en URL Pattern : `https://lairdubois.fr/workflows/:id`, celui de
  l'espace de travail, qui s'y abonne par `match=` (topic exact), et
  `…/users/:userId/workflows/:id`, par propriétaire. La page de l'espace de travail, hors lecture seule, pose le cookie
  `__Secure-mercure_access_token` (chemin `/.well-known/mercure`, `cookie_name` de `config.yml` :
  en debug, le bundle en prendrait un autre), dont le JWT vise les processus du compte (tous pour
  un admin) par `match_type: urlpattern` (`…/users/2/workflows/:id`) : deux onglets sur deux processus ne
  l'écrasent pas. Le hub ferme un abonnement quand son JWT expire, et le navigateur se
  reconnecte avec le même cookie : cookie et JWT durent un an, comme `remember_me`, et la
  déconnexion supprime le cookie (`delete_cookies` de `security.yml`). Hub injoignable : 500
  après le flush ; le client HTTP du hub (`framework.http_client`, qu'aucun autre service
  n'utilise) abandonne au bout de 3 secondes sans réponse, 10 au plus.
- `bin/console` lit `SYMFONY_ENV`, pas `APP_ENV`.
- Les sessions sont en base (`PdoSessionHandler`) : `doctrine:schema:create` ne crée pas la table
  `sessions`. Depuis Symfony 4.4, `sess_lifetime` porte la date d'expiration, trop grande pour
  l'ancien `MEDIUMINT` (500 sur toute page qui écrit la session) :
  `docs/database/schema-sessions.sql` passe aussi une table plus ancienne en `INTEGER UNSIGNED`, et
  `castor app:db:schema` le rejoue.
- Le FPM affiche les erreurs natives de PHP (`display_errors` du php.ini des images), la prod non.
  La 4.4 les coupait (#128) : son Routing ne connaissait pas le message de PCRE2 10.43+ (PHP 8.5)
  pour un lookbehind, et chaque construction du cache du routeur par une requête donnait quelque
  350 warnings avant les en-têtes (500). Le Routing de 5.4 le connaît.
- `framework.handle_all_throwables: true` (le défaut de 7.0, #205) : `HttpKernel` attrape aussi les
  `\Error` PHP (appel sur `null`, `TypeError`), rendues comme une exception par le contrôleur
  d'erreur (500). Avant, elles sortaient de `handle()`, et `DebugHandlersListener` les rendait par
  `terminateWithException()`, avec la même page d'erreur.
- Pages d'erreur : contrôleur d'erreur de FrameworkBundle (`twig.exception_controller: null`).
  `TwigErrorRenderer` ne rend que le HTML (`@Twig/Exception/error.html.twig`) ; les routes au
  `_format` json (`.json`, `.geojson`) gardent leur erreur en JSON par `RequestFormatErrorRenderer`
  (`@Twig/Exception/error.json.twig`). En debug, la page d'exception de Symfony, en HTML.
- Les comptes de la prod ont le hash de FOSUserBundle (sha512, 5000 itérations, base64, sel du
  compte) : ils doivent continuer à se connecter sans reset, d'un bout à l'autre de la migration.
  `security.yml` le garde en `migrate_from` du hasher `native` (`auto` l'ignore, en 4.4 comme en
  5.4) : la première connexion par le formulaire refait le hash (`UserRepository::upgradePassword()`,
  sel à `null`), ce qui déconnecte les autres sessions du compte et invalide ses cookies `remember_me`.
- Sécurité sans FOSUserBundle (#24) : provider `entity` (`UserRepository::loadUserByIdentifier()`,
  nom d'utilisateur ou e-mail), `Security\UserChecker` (compte désactivé refusé à la connexion),
  `Event\LastLoginListener`, `Manager\Core\UserManager` (canonisation, hash du mot de passe). Une
  session est déconnectée quand `User::isEqualTo()` voit changer le mot de passe, le sel, les rôles,
  le nom ou `enabled` : ce que comparait `hasUserChanged()` de 4.4. Les sessions ouvertes avant #24
  ont un `User` sérialisé par FOSUserBundle (format `C:` de `\Serializable`, sans les rôles) :
  `User::unserialize()` les lit, et `isEqualTo()` ne compare pas leurs rôles. Authenticator manager
  depuis #177 : un anonyme n'a plus de token (`null`), et le cookie remember_me est reposé à chaque
  reconnexion automatique (un an après la dernière visite).
- Controllers : services autowirés (prototype `Ladb\CoreBundle\Controller\`), une action reçoit ses
  services en arguments, un helper de la classe de l'action qui l'appelle (en dernier argument),
  un trait ou un controller abstrait par un setter `#[Required]`. L'entity manager est un service
  comme les autres (`EntityManagerInterface $om`, plus de `getDoctrine()`, #177). Dans les traits et
  les controllers abstraits, sa propriété `$om` est protégée, pas privée : un controller qui
  utilise `PublicationControllerTrait` sous un parent qui l'utilise aussi ou qui a son propre
  `setOm()` (`HowtoController`, `TaskController`…) remplace le setter du parent, dont le `$om`
  privé resterait `null`. Les paramètres sont lus par
  `$this->getParameter()` : en 3.4, un `bind` ne s'appliquait à un argument d'action que s'il
  désignait un service ; en 4.4, il s'applique aussi à une valeur (`%param%`). Un service de
  bundle sans alias par type reçoit un alias de son id dans `services.yml`.
- `TypableUtils::getManagerByType()` (`ladb:change:owner`) lit les managers dans un service locator
  (`ladb_core.typable_utils.manager_locator` de `services.yml`), par le nom que donne
  `getStrippedNameByType()` : un manager n'a pas à être public pour lui. `Tests\Command\ManagerByTypeTest`
  vérifie le manager de chaque type.
- Utils : dépendances et paramètres par le constructeur (`Utils/`, sans container). `ViewableUtils`
  et `WebpushNotificationUtils` envoient leurs messages par le bus de Messenger et journalisent un
  échec d'envoi (`TransportException`, RabbitMQ injoignable) au lieu de le lever ; `MailerUtils`
  envoie alors le mail en direct. `SearchUtils` reçoit un index par l'id de son service
  (`fos_elastica.index.<type>`) : un index par type de contenu (#31), `ladb_<type>` dans
  Elasticsearch (`elasticsearch_index_prefix`).
- Managers : dépendances par le constructeur (`Manager/`, sans container). Les classes abstraites
  (`AbstractManager`, `AbstractPublicationManager`…) les reçoivent par des setters `#[Required]`,
  dans des propriétés protégées que leurs sous-classes lisent sans les redemander : le
  constructeur reste aux sous-classes. `Qa\AnswerManager` reçoit `Qa\QuestionManager` par un
  setter, qui le reçoit dans son constructeur (le container refuse un cycle de constructeurs).
- Listeners (`Event/`), validateurs, `WorkflowTopic` et `ViewConsumer` : dépendances et paramètres
  par le constructeur. Plus rien ne lit le container hors des controllers : les services de
  `services.yml` sont privés. `LadbExtension`, que Twig crée avec lui-même à chaque requête, lit
  `TypableUtils` et `UserManager` (le gestionnaire d'entités) et `VideoHostingUtils` (qui reçoit
  Twig) dans `ladb.twig.ladb_extension.service_locator`, au moment de s'en servir.
- PHPStan lit la version de PHP dans `composer.json` (`>=7.3`) : la syntaxe PHP 8 (propriétés
  promues, `readonly`, virgule finale dans une signature) y est une erreur, même si tout tourne en
  8.5.
- `ruflin/elastica` déclare `Elastica\Query\Match` jusqu'en 6.1 : `match` est un mot réservé depuis
  PHP 8, d'où une erreur de parsing (500) dès qu'une page avec des tags cherche des contenus
  similaires. Utiliser `Elastica\Query\MatchQuery`.
- Elasticsearch 7 (#31) :
  - il ne compte exactement les résultats que jusqu'à 10 000 : `SearchUtils` demande le total exact
    (`track_total_hits`), dont dépendent les compteurs et la pagination ;
  - Elastica 7 type ses paramètres : `FunctionScore::setRandomScore()` n'accepte qu'un entier, alors
    que la graine du tri aléatoire du site est une chaîne (`Math.random().toString(36)`) :
    `SearchUtils` écrit le `random_score` lui-même ;
  - le filtre `nGram` s'écrit `ngram`, et un écart de plus de 1 entre `min_gram` et `max_gram`
    demande `index.max_ngram_diff`.
- Elasticsearch 9 (#161) :
  - ES 8 refuse le `boost` d'un champ dans le mapping : les requêtes le donnent au champ
    (`title^300` = `title^100` × 3, `tags.label^2`, `boost` d'un `match`). ES 7 ne l'appliquait
    qu'aux mots seuls, ni aux jokers, ni aux mots flous (`~`), ni aux phrases : les typeaheads, tout
    en jokers, n'en ont pas. Dans une recherche tapée avec des guillemets, `*` ou `~`, ces mots ont
    maintenant aussi le poids du champ : l'ordre par pertinence peut y changer ;
  - le `random_score` hache `_seq_no` avec la graine (`field`, sa valeur par défaut en 9 ; ES 7
    hachait `_id`), qui change quand le document est réindexé ;
  - la liste de mots vides `_french_` de Lucene 10 a 10 mots de moins que celle de Lucene 8 :
    `fr_stop_elasticsearch_7` les remet, pour les mêmes tokens ;
  - le client passe par PSR-18 (`hosts` dans `fos_elastica.clients`), découvert par
    `php-http/discovery` : le `Psr18Client` de HttpClient, avec le `timeout` de 30 s du bundle. Le
    profiler garde le corps des requêtes décodé en tableau : un `match_all` y apparaît en `[]`,
    envoyé `{}`.
- Un index par type (#31) : ES ne trie pas sur un champ absent du mapping de l'index (500), alors
  que l'index unique d'avant partageait le mapping de tous les types. Un champ de tri va dans le
  mapping de chaque type qui le propose.
- `--context=test` et `--context=ci` visent la base et l'index de test (`config_test.yml`), pas ceux
  du dev : la CI charge les fixtures avec `castor app:db:fixtures` dans son contexte.
- Une action qui finit par `exit(0)` (`PictureController::uploadAction()`,
  `ResourceController::uploadAction()`) arrête PHPUnit, sans erreur : ne pas l'appeler dans un test.
- Une entité se désigne par sa classe : `X::class` en PHP, le nom complet dans le DQL (`FROM
  Ladb\CoreBundle\Entity\Core\Member e`), jamais par l'alias `LadbCoreBundle:X`, que
  `doctrine/persistence` 3 ne connaît plus (#178). Les constantes `CLASS_NAME` des entités valent
  `self::class`.
- `doctrine:fixtures:load` sans `--append` vide les tables par `DELETE`, qui ne remet pas les
  `AUTO_INCREMENT` à zéro : `castor app:db:fixtures` supprime la base à la place.
- Hors requête HTTP (fixtures, commandes), les listeners et utils qui lisent l'utilisateur ou la
  session de la requête (`GlobalUtils`) échouent : `FixtureFactory::actAs()` connecte l'auteur dans
  une requête avec une session en mémoire.
- Les managers (`publish()`…) parcourent les collections inverses (créations d'un plan…), qui ne sont
  initialisées que sur une entité chargée depuis la base : `FixtureFactory::create()` fait un
  `refresh()` après le premier `flush()`.
- Doctrine (#177, #178) : ORM 2.20, DBAL 3, DoctrineBundle 2.13 (la dernière pour 5.4),
  `doctrine/persistence` 3.4, qu'exige le DoctrineBridge de 7.4 (la 4 attend DoctrineBundle 3).
  Les proxys sont des lazy ghost objects de `symfony/var-exporter` (`enable_lazy_ghost_objects`,
  les seuls de l'ORM 3) : même nom de classe (`Proxies\__CG__\…`, que `ClassUtils::getRealClass()`
  retire), mais un proxy se charge au premier accès à une propriété, plus à l'appel d'une méthode,
  et son `__serialize()` le charge.
- Bornes de `composer.json` : `gedmo/doctrine-extensions` `~3.20.1` (depuis la 3.21, Sluggable et
  `Urlizer` passent par `symfony/string` : les slugs et les labels de tags peuvent changer) ;
  `dama/doctrine-test-bundle` `~8.2.2` (la 8.3 veut PHPUnit 10, que Composer ne voit pas, dans
  `tools/phpunit`) ; `doctrine/data-fixtures` `^1.8` (la 2.0 impose les types natifs et la classe
  dans `getReference()`).
- doctrine/migrations 3 lit la table des 1.x et 2.x (`storage.table_storage.table_name:
  migration_versions`) : la sienne par défaut, `doctrine_migration_versions`, lui ferait rejouer
  toutes les migrations de la prod. Le premier `doctrine:migrations:migrate` (ou
  `sync-metadata-storage`) la convertit : `version` en `VARCHAR(191)`, qui porte le nom de la
  classe, et les colonnes `executed_at` et `execution_time` ; avant, `status` échoue (« The
  metadata storage is not up to date »). En 3.9 (#26), il n'ajoute à une migration le diff de DBAL
  entre le schéma de la base et celui qu'elle décrit que si elle touche à `$schema` (un objet lazy
  de `symfony/var-exporter` depuis la 3.6, `proxy-manager-lts` avant) : la 3.5 rejouait l'`ALTER`
  de `Version20261028235655`, qui n'a que des `addSql()`.
- DBAL 3 déclare une colonne `json` en `JSON` sur MariaDB (`LONGTEXT … utf8mb4_bin CHECK
  (json_valid(…))`) et voit celle de DBAL 2, un `LONGTEXT`, comme différente
  (`declarationMismatch`) : `Version20261028235655` convertit
  `tbl_webpush_user_subscription.subscription`.
- `use_savepoints: true` en test (`config_test.yml`) : dama 8 l'exige, sa transaction contient
  celles des flush. Le site n'imbrique jamais de transaction.
- Les attributs de l'ORM ont un constructeur typé : un tableau ne prend pas une valeur seule
  (`cascade: ['persist']`, pas `cascade: 'persist'`), ce que DocParser acceptait avant l'ORM 2.20.
- Depuis l'ORM 2.16, un flush ordonne ses INSERT entité par entité (persist et clés étrangères), plus
  classe par classe : les lignes de classes différentes créées ensemble (blocs texte et galerie
  d'un contenu, valeurs d'une fiche de la xylothèque, avatar et bannière d'un compte) prennent leurs
  ids dans un autre ordre, fixtures comprises. Les contenus d'id 1 ne bougent pas.
- `doctrine:schema:update` sans `--complete` : un avertissement de l'ORM 2.20, l'option sera
  obligatoire avec DBAL 4. Avec elle, il supprimerait les tables hors mapping (`sessions`) : un
  `schema_filter` avant DBAL 4. `doctrine:query:sql` est déprécié, `dbal:run-sql` le remplace.
- Symfony Mailer (#19) : `MailerUtils` envoie des `Email`, que Mailer passe au bus de Messenger ;
  `SendEmailMessage` va dans la file `mailer` de RabbitMQ, et son worker (`messenger:consume
  mailer`) les envoie. Un envoi en échec (SMTP injoignable) est rejoué 5 fois, après 1, 3, 9, 27 et
  81 minutes, puis le message est supprimé (log critical). Quand RabbitMQ ne répond pas,
  `MailerUtils` attrape la `TransportException` de Messenger, journalise un warning et envoie le
  mail en direct, dans la requête ou la commande (`TransportInterface` de Mailer, le transport de
  `mailer_dsn`) : le spool fichier de Swiftmailer ne tombait jamais. Les messages de la file sont
  sérialisés en PHP (`PhpSerializer`) : la vider avant un saut de Symfony. Un seul paramètre,
  `mailer_dsn` (`framework.mailer.dsn`). Mailer lance STARTTLS dès que le serveur SMTP l'annonce et
  vérifie son certificat, ce que Swiftmailer ne faisait pas sans `encryption` : `?verify_peer=0`
  pour un Postfix au certificat autosigné (l'option `auto_tls` n'arrive qu'en 7.1).
- `Event\DkimSignedMessageListener` signe chaque mail avec la clé DKIM de la prod
  (`keys/private.pem`, hors git, `relaxed/relaxed`) quand le transport l'envoie (`MessageEvent` pas
  `isQueued()`, dans le worker), pas quand Mailer le met sur le bus : le message en file reste un
  `Email`, que les tests et le profiler lisent. Symfony 7.3 a le sien
  (`framework.mailer.dkim_signer`), qui signe aussi le message mis en file. Sans la clé, tout mail du site (confirmation d'adresse,
  notifications…) donne une 500 : `castor app:install` en génère une jetable si elle manque. Le
  mail du mot de passe oublié (`sendResettingEmailMessage()`) est construit comme FOSUserBundle le
  faisait (texte seul, le sujet sur la première ligne de son template), et signé comme les autres.
- Connexion, inscription, mot de passe oublié et changement de mot de passe : nos controllers
  (`SecurityController`, `RegistrationController`, `ResettingController`,
  `ChangePasswordController`), qui gardent les routes, les noms de formulaires et les templates
  (`@FOSUser/…`) de FOSUserBundle (#24). `LoginUtils` connecte un compte après son inscription ou
  un nouveau mot de passe, comme le `LoginManager` de FOSUserBundle, par `Security::login()` avec
  l'authenticator du formulaire (#26), qui appelle aussi le user checker (`LastLoginListener` pose
  la dernière connexion). Le namespace
  `@FOSUser` est un chemin Twig de `config.yml` (`templates/bundles/FOSUserBundle/`), les traductions
  utilisées sont dans les domaines `FOSUserBundle` et `validators` du bundle.
- Symfony 6.4 en composants séparés (#26) : plus de `symfony/symfony`, que Symfony 7 refuse.
  `composer.json` exige en `6.4.*` les bundles (framework, security, twig ; debug et web-profiler
  en dev) et les composants dont le site se sert lui-même : les `use` de `src/`, `app/`, `tests/`
  et `bin/`, et ce que la config demande (`yaml`, `translation`, `asset`, `http-client`,
  `expression-language` pour `@Security`). En dev, ceux des tests (`browser-kit`, `css-selector`,
  `dom-crawler`, `finder`) et `stopwatch` (services de debug et timeline du profiler, comme le
  `profiler-pack` de Symfony). Le reste vient des dépendances. Celles qui acceptent aussi la 7.x
  (`cache`, `clock`, `process`, `property-info`, `string`, `twig-bridge`, `var-dumper`,
  `var-exporter`, `web-link`) sont bornées par le `conflict` de `composer.json`, à monter avec
  les composants. Les contrats sont en 3.x, séparés (`service-contracts`…).
- Sans `symfony/symfony` (sa classe `FullStack`), FrameworkBundle active d'office chaque fonction
  dont le composant est installé : `lock`, `property_info` et `web_link`, que tirent des
  dépendances, sont coupés dans `config.yml`, comme avant. Un paquet ajouté peut en allumer une
  autre : comparer `debug:container` et `debug:config` avant et après.
- Services lazy (l'entity manager de DoctrineBundle) : la 6.4 fait ses proxys elle-même
  (`symfony/var-exporter`, `EntityManagerGhost…` dans le container), plus de
  `symfony/proxy-manager-bridge` (déprécié en 6.3). `Registry::reset()` (le worker,
  `reset_on_message`) remet à zéro un entity manager fermé par une erreur.
- `symfony/messenger` 6 n'exige plus ses ponts : `amqp-messenger` porte nos transports,
  `doctrine-messenger` et `redis-messenger` sont partis.
- La table `sessions` reste hors du schéma de Doctrine (`docs/database/schema-sessions.sql`) :
  `RemoveSessionSchemaListenerPass` retire le `PdoSessionHandlerSchemaListener` de DoctrineBridge
  6.3+, que DoctrineBundle active pour un `PdoSessionHandler`. Avec lui, chaque commande de schéma
  (`schema:update`, `schema:validate`, `migrations:diff`) proposerait de modifier la table, et
  créerait puis supprimerait une table pour vérifier qu'elle est dans la même base : MariaDB
  valide la transaction sur ce DDL, celle de dama disparaît, et PHPUnit s'arrête (« There is no
  active transaction », `ladb:init`). À revoir avec DBAL 4 (#178) : avec le listener, la table
  ferait partie du schéma, et `--complete` ne la supprimerait pas.
- Les sessions ouvertes en 3.4 et 4.4 ont des objets `Security\Core\Role\Role` dans leur token :
  Symfony 5.4 et 6.4 gardent une classe `Role` pour les relire (vide en 6.4, « Allows migrating
  session payloads from v4 »), et elles restent connectées (`LoginTest`, avec un token capturé
  sur la vraie 4.4 ; le cookie remember_me capturé avec lui est refusé et supprimé depuis
  l'authenticator manager, qui signe autrement, #177). À revérifier en 7.4 et en 8.2.
- Messenger (#22) : trois transports AMQP (`config.yml`), chacun avec son échange et sa file du
  même nom, `mailer` (#19), `view` et `webpush_notification`. Les anciennes files de RabbitMqBundle
  (`view_queue`…) ne servent plus : le `PhpSerializer` lit un de leurs messages comme un array, et
  le worker s'arrêterait sans le retirer. La connexion est en options : dans le DSN, le mot de passe
  et le vhost `/` seraient à encoder. Un handler en échec est rejoué 3 fois (échange `delays`), un
  mail 5 fois, puis le message est supprimé (log critical). Après chaque message, le worker vide
  l'entity manager et remet à zéro les services (`reset_on_message`, toujours depuis 6.0 : l'option, qui ne fait plus rien, est retirée de `config.yml`) : un handler flush ce qu'il
  persiste, sinon c'est perdu, et ne garde rien d'un message à l'autre.
- Les notifications web push sont signées avec les clés VAPID de la prod (`bentools_webpush.*` de
  `parameters.yml`) : avec celles de `parameters.yml.dist`, le worker `webpush_notification`
  échoue sur chaque message. `castor app:install` y écrit une paire jetable, que les `composer
  install` suivants gardent.
- Le géocodage (`geocoder-php/common-http`) trouve sa fabrique PSR-17 par `php-http/discovery`, à
  l'exécution : `GuzzleHttp\Psr7\HttpFactory` de `guzzlehttp/psr7` 2. Le plugin Composer de
  discovery est coupé dans `allow-plugins`.
- `bentools/webpush-bundle` est en 0.14 (#177), avec `minishlink/web-push` 6 et `web-token/jwt-*`
  2.2 (abandonné) : web-push 9, qui passe à `web-token/jwt-library`, monterait aussi `psr/cache`,
  `brick/math` et quatre polyfills de Symfony. Jusqu'à la 0.10, le bundle appelait `GuzzleHttp\Promise\settle()`, supprimée de `guzzlehttp/promises` 2,
  qu'exige Guzzle depuis la 7.10. Son `PushMessageSender` envoie avec un TTL de 0 : `services.yml`
  le redéfinit avec celui de web-push 2.0 (4 semaines). Le timeout est le sien, 30 s par requête
  (`DEFAULT_TIMEOUT`), qui passe avant les options du client Guzzle : ce client n'en a pas, il
  n'est un service que pour le handler du test. Son `RequestBuilder`, créé par le sender et non
  remplaçable, passe le `TTL` et le `Content-Length` en entiers à `withHeader()` : une dépréciation
  de `guzzlehttp/psr7` 2.11 à chaque envoi (une erreur en psr7 3), jusqu'à la 0.14 comprise. Sa
  route d'abonnement lit `{"subscription": …}`, alors que le JS du site envoie la
  `PushSubscription` telle quelle : `WebpushController` la remplace (même nom, même chemin).
- Liip Imagine 2.18 et Imagine 1.5 (#177). Les variantes passent par les post-processors `jpegoptim`
  et `pngquant` du bundle (`config.yml`, une ancre YAML pour tous les filter sets), avec les options
  de ps/image-optimizer, parti (il s'arrêtait à Symfony 5) : `jpegoptim --strip-all --max=95
  --all-progressive` et `pngquant --quality 0-100` (`quality: 100`, le défaut de pngquant ; celui de
  Liip, 80-100, changerait les PNG). ps/image-optimizer ne lançait que le premier optimiseur PNG qui
  réussissait, pngquant : optipng ne sert plus. Liip attend les binaires dans `/usr/bin` et lève une
  exception quand l'un manque ou échoue (500 sur `/media/cache/resolve/…`), là où ps/image-optimizer
  servait l'image sans l'optimiser.
  - Imagine 1.3+ ajoute un canal alpha à chaque image qu'il charge (`Imagick\Image::setColorspace()`) :
    avec ImageMagick 7, le redimensionnement calcule un peu autrement, et les variantes filtrées des
    images des fixtures ont changé d'octets à la montée (invisible : RMSE au pire de 3,1/255 en JPEG,
    de 0,8/255 en PNG). Sans ce canal, elles étaient identiques octet pour octet.
  - Un PNG sans post-processor (filter set `cache`) porte les dates d'ImageMagick (chunks `tIME` et
    `date:*`) : deux générations de la même variante ne sont pas identiques octet pour octet.
  - Le contrôleur redirige en 301 (`controller.redirect_response_code`), le défaut d'avant Liip 2.7
    (302 depuis). `twig.mode: lazy` (le mode `legacy` est déprécié) : `imagine_filter` exige une
    chaîne (un chemin `null` donne une `TypeError`) et ne retire plus la query string du chemin.
- Plus de composant `templating` : les templates se nomment dans la notation de Twig
  (`@LadbCore/Dir/file.html.twig`, `@FOSUser/…`, `base.html.twig` pour `templates/`), la
  notation de bundle (`LadbCoreBundle:Dir:file.html.twig`, `::base.html.twig`) ne les trouve plus.
  Un service reçoit `Twig\Environment`.
- Twig reste en 3.27 (`~3.27.1`) : depuis la 3.28 (twigphp/Twig#4292), un template qui en étend
  un autre affiche les espaces des `{% if %}` qu'il a hors de ses blocs (l'indentation de leurs
  `{% set %}`), avant le doctype ; Twig 3.27 les retire, comme Twig 2.
- Twig 3 échappe les templates `*.json.twig` pour JS (`FileExtensionEscapingStrategy`), Twig 2
  pour HTML : ceux qui affichent des valeurs sont dans un `{% autoescape 'html' %}`, le JS du site
  les insère dans la page.
- Twig 3 donne à un filtre sans arguments un `EmptyNode`, auquel le
  `TranslationDefaultDomainNodeVisitor` du TwigBridge de 4.4 ne savait pas ajouter le domaine de
  `{% trans_default_domain %}` (`'x'|trans` des templates de FOSUserBundle) : le template ne
  compilait pas, et le domaine restait aux templates compilés ensuite par `cache:warmup` (#138).
  Celui de 5.4 le sait. `Tests\Twig\TemplateCacheWarmerTest` compile dans un process tous les
  templates du warmer, puis vérifie une page et leurs erreurs.
- Actions d'admin : `#[IsGranted('ROLE_ADMIN', statusCode: 404, message: …)]` (#27), une
  `Expression` pour un rôle de plus (`ROLE_BLOG`…). Un anonyme n'a pas de token depuis
  l'authenticator manager (#177) : l'`AuthorizationChecker` vote alors avec un `NullToken`, et
  l'attribut répond 404, comme le `@Security` de Sensio avec `Security\NullTokenStorage` (retiré).
- `#[Template]` de TwigBridge (#27) : une action qui rend `null` donne à son template ses
  arguments, services compris (Sensio prenait les attributs de la requête du même nom) ; les trois
  qui le font (`delete-xhr` des étiquettes et des pièces du workflow, des charges du financement)
  ont un template vide. Un `FormInterface` passé tel quel (et non sa vue) répondrait 422 quand il
  est soumis et invalide : les actions passent `createView()`.
- Plus d'annotations (#27) : ni SensioFrameworkExtraBundle ni `doctrine/annotations`, qu'il était
  seul à exiger. FrameworkBundle coupe alors `framework.annotations` (plus d'`annotation_reader`),
  et le `ReaderPass` de StofDoctrineExtensionsBundle donne aux listeners de Gedmo son
  `AttributeReader`. Une annotation oubliée (`@Assert`, `@ORM`, `@Route`…) est ignorée sans rien
  dire.
- symfony/monolog-bundle 3.10 (#177) passe au container les niveaux des handlers tels qu'écrits
  (`notice`, que Monolog convertit) et le `action_level` du `fingers_crossed` en
  `ErrorLevelActivationStrategy` : mêmes niveaux et mêmes fichiers que la 3.6.
- Depuis Symfony 6 (`framework.form.legacy_error_messages`, sans effet), une valeur qu'un type de
  formulaire ne peut pas transformer (langue inconnue, nombre mal écrit, choix hors liste) donne le
  message du type (« Cette langue n'est pas valide. ») au lieu de « Cette valeur n'est pas
  valide. ». Les messages traduits du validateur suivent ceux de Symfony 6.4 (« Elle doit contenir
  au minimum 4 caractères. »). Un `NumberType` sans `html5` a un `inputmode` (`decimal`, ou
  `numeric` avec `scale` à 0) : le clavier numérique sur mobile.
- `Length` n'a plus `allowEmptyString` (#177, retiré en 6.0) : elle refuse `''` avec `min`, mais
  ignore `null`, ce que donne un champ de formulaire vide (`TextType` sans `empty_data`). Une valeur
  validée qui vaudrait `''` (`empty_data: ''`, écrite par le code) serait « trop courte », en plus
  du message de `NotBlank`.
