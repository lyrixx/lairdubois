Mise en production de la migration
==================================

Ce guide fait passer le serveur de production du code de `master` (Symfony 3.4) au code livré
(Symfony 8.2, PHP 8.5). Il se suit dans l'ordre, une étape après l'autre. Chaque étape fait une
seule chose : la commande à copier, la vérification qui confirme qu'elle a marché, et quoi faire
sinon. Pendant la coupure, chaque étape dit aussi si on peut la relancer telle quelle.

Il est répété de bout en bout par `castor qa:delivery-rehearsal` (voir `CLAUDE.md`), qui part
d'une copie en 3.4 avec des données comme celles de la prod et lance les commandes telles qu'elles
sont écrites ici. Chaque étape dit ce que la répétition en fait :

- **jouée** : la commande a tourné dans la répétition, sa vérification avec ;
- **vérifiée autrement** : dans un conteneur Debian jetable (paquets, systemd, nginx), ou par
  comparaison de fichiers ;
- **non jouée** : rien dans Docker ne lui ressemble (crontab, Postfix).

Les numéros entre parenthèses en fin d'étape renvoient aux issues et aux pull requests qui
l'expliquent.

## Avant de commencer

### Ce qui change sur le serveur

- PHP 8.5 à la place du PHP de la 3.4, pour le site, la ligne de commande et les workers.
- Elasticsearch 9 à la place de 5.6 : l'index est reconstruit depuis la base.
- Un hub Mercure (`ladb_mercure`) à la place du serveur websocket de l'éditeur de processus.
- Messenger à la place de RabbitMqBundle : les consumers `view` et `webpush` changent de commande,
  un consumer `mailer` envoie les mails à la place du spool de Swiftmailer, au même débit (100 mails
  toutes les 2 minutes au plus).
- Les crons passent de la crontab de www-data à deux services, `ladb_scheduler` et
  `ladb_consumer_cron`.
- Node.js 24 et Vite construisent le CSS et le JS, à la place d'Assetic.
- Tous les membres sont déconnectés une fois : la table des sessions repart de zéro, et les
  cookies « se souvenir de moi » d'avant la livraison ne sont plus lus. À sa première connexion,
  chaque compte refait son mot de passe en interne, sans rien demander au membre.
- La page de maintenance répond en 503, avec un `Retry-After` : les robots gardent les pages du
  site et reviennent plus tard.

### Ce qu'il faut avoir

- Un accès `sudo` au serveur, et `/var/www/www.lairdubois.fr` comme dossier courant : toutes les
  commandes partent de là (`cd /var/www/www.lairdubois.fr`).
- Le code livré dans la branche `master` du dépôt du serveur (`origin`), et le hash de son dernier
  commit (étape 33), sur des versions stables (P7). Les fichiers du code livré cités ici se lisent
  sur GitHub, ou sur le serveur après `sudo --user=www-data git fetch origin` :
  `sudo --user=www-data git show origin/master:docs/01-installation.md`.
- La place disque, dans `~/ladb-backup` et sur les disques de MariaDB et d'Elasticsearch : une
  sauvegarde de la base et du code (`vendor/` compris), une copie de la base le temps de l'étape 20,
  les données d'Elasticsearch 5.6 gardées pour le retour arrière à côté de celles de la 9, et les
  paquets d'Elasticsearch 9 (650 Mo) et 5.6 (35 Mo). La file `mailer` de RabbitMQ garde aussi sur
  disque la lettre de la semaine, le temps de l'envoyer. La mémoire de deux PHP-FPM à la fois
  pendant la phase 1 (étape 12) et du hub Mercure. Pour le voir :

  ```bash
  df -h / /var /home
  sudo du -sh /var/lib/mysql /var/lib/elasticsearch
  free -m
  ```

- Une fenêtre de maintenance, en dehors du vendredi matin : la lettre de la semaine part le
  vendredi à 4 h et remplit le spool de Swiftmailer, qui ne se vide qu'à 100 mails toutes les
  2 minutes (étape 23).

### Durée de la coupure

Tout ce qui peut se faire site ouvert est dans la phase 1, les jours d'avant. La coupure va de
l'étape 21 à l'étape 45. Dans la répétition (fixtures, une machine de développement), ses
commandes prennent 1 min 30 s en tout, dont 10 s pour `composer install`, 8 s pour le CSS et le JS
et 40 s pour installer et démarrer Elasticsearch 9 : le reste de la coupure est le temps de copier
les commandes et de lire les vérifications, une demi-heure environ. Sur le serveur, quatre étapes
dépendent du volume :

- le spool (étape 23), selon le nombre de mails en attente : le vider site ouvert juste avant, pour
  qu'il n'en reste que quelques-uns, par `sudo --user=www-data bin/console --env=prod swiftmailer:spool:send`
  (sans `--recover-timeout=0` : le cron envoie en même temps, un mail en cours d'envoi partirait
  deux fois) ;
- les téléchargements de `composer install` et `npm ci` (étapes 35 et 36), selon le réseau ;
- les migrations (étape 39), sur la base de la prod : l'étape 20 les chronomètre ;
- la reconstruction de l'index (étape 41) : 89 s pour 210 000 documents sur une machine de
  24 cœurs (jeu de volume de #282), à multiplier selon le nombre de contenus de la prod (étape 2)
  et les cœurs du serveur. Tant qu'elle tourne, la recherche et les listes ne marchent pas : elle
  se fait site fermé.

### Prérequis

Le guide part d'un serveur déjà monté en Debian 13 (Debian 12 acceptée), avec PHP 8.5 et ses
extensions installés à côté du PHP qui sert la 3.4. Il ne décrit pas la montée de Debian.

P1. Debian 13 ou 12 :

```bash
cat /etc/debian_version
```

Vérification : `13.x` ou `12.x`. Répétition : vérifiée autrement (conteneurs Debian 13 et 12).

P2. PHP 8.5 et ses extensions :

```bash
php8.5 -v
for m in amqp bcmath bz2 curl gd gmp iconv imagick intl mbstring memcached pdo_mysql xml zip; do php8.5 -m | grep -qix "$m" || echo "manque : $m"; done
ls /etc/php/8.5/fpm/php-fpm.conf
```

Vérification : `PHP 8.5.x`, la boucle n'affiche rien, et le fichier de PHP-FPM existe. Sinon :
installer ce qui manque, par le dépôt de Sury (`docs/01-installation.md` du code livré, « Install
PHP 8.5 »). Répétition : vérifiée autrement (conteneurs Debian 13 et 12).

P3. La commande `php` est toujours celle de l'ancien PHP :

```bash
update-alternatives --display php
```

Vérification : `link currently points to /usr/bin/php7.3` (7.3, ou la version de l'ancien PHP :
dans tout le guide, remplacer `7.3` par elle). Installer `php8.5-cli` fait passer `php` en 8.5
quand l'alternative est en mode `auto`, et la 3.4 tournerait alors en 8.5 (crons, workers,
`composer`). Sinon :

```bash
sudo update-alternatives --set php /usr/bin/php7.3
```

Répétition : vérifiée autrement (conteneurs Debian 13 et 12 : installer `php8.5-cli` à côté de
PHP 7.3 fait passer `php` en 8.5, `--set` le remet en 7.3, et une réinstallation de PHP 8.5 garde
ce choix).

P4. Composer 2.2 ou plus :

```bash
composer --version
```

Vérification : `Composer version 2.x`, avec x ≥ 2. Sinon :

```bash
sudo composer self-update --2
```

Répétition : vérifiée autrement (conteneurs Debian 13 et 12).

P5. Les services de la 3.4 tournent sur la Debian montée :

```bash
curl -s http://127.0.0.1:9200 | grep '"number"'
sudo rabbitmqctl list_queues name messages
```

Vérification : `"number" : "5.6.x"`, puis `view_queue` et `webpush_notification_queue`. Sur une
Debian montée, Elasticsearch 5.6 demande un Java 8 jusqu'à la livraison : Debian 12 et 13 n'en ont
plus dans leurs paquets. RabbitMQ 4.0 (Debian 13) ne se monte que depuis une 3.13, pas depuis la
3.10 de Debian 12 : s'il ne démarre pas après la montée, le repartir de données vides
(RabbitMqBundle recrée ses files au démarrage de ses consumers). Répétition : non jouée (la
répétition tourne sur RabbitMQ 4.0 dès la 3.4).

P6. La version de nginx, qui décide d'une modification du vhost (étape 14) :

```bash
nginx -v
```

Répétition : vérifiée autrement (conteneurs Debian 13 : 1.26, et 12 : 1.22).

P7. Le code livré n'a que des versions stables :

```bash
sudo --user=www-data git fetch origin
sudo --user=www-data git show origin/master:composer.lock | grep -iE '"version": "[^"]*(dev|alpha|beta|rc)'
```

Vérification : la seconde commande n'affiche rien. Sinon : ne pas livrer. Une version de
développement (`v8.2.0-BETA2`) n'a pas de suivi de sécurité et peut encore changer : le code livré
passe d'abord aux versions stables (à la sortie de Symfony 8.2.0 : `minimum-stability` et
`prefer-stable` retirés de `composer.json`, `composer update` des paquets en bêta, CI et
répétition), et le hash du code livré change. Répétition : vérifiée autrement (le `composer.lock`
du code livré, dont elle dit combien de paquets sont encore en version instable).

## Phase 1 : avant la livraison, site ouvert

Ces étapes laissent le site en 3.4 en marche. Elles peuvent se faire les jours d'avant.

### 1. État du code

```bash
sudo --user=www-data git status --short | grep -v '^?? bin/'
sudo --user=www-data git log -1 --format=%H
```

Vérification : la première commande n'affiche rien, la seconde affiche
`b8848c0c05c3ec80ee1968db9f66ea28e38a5b1f`, le `master` d'où part la migration. Les binaires que
Composer installe dans `bin/` (`bin/doctrine`, `bin/simple-phpunit`…) sont attendus : le
`.gitignore` de `master` ne les ignore pas, la commande les retire. Sinon : un fichier modifié sur
le serveur, ou un commit plus récent, est à reporter dans le code livré avant la livraison.
Répétition : jouée (sur le commit en 3.4 de départ, qui a les mêmes données que `master`).

### 2. État de la base

```bash
sudo --user=www-data bin/console --env=prod doctrine:schema:update --dump-sql
sudo --user=www-data bin/console --env=prod doctrine:schema:update --complete --dump-sql
sudo mariadb db_fr_lairdubois_www -e "SELECT c.table_name, c.column_name, c.collation_name, t.table_collation FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name WHERE c.table_schema = DATABASE() AND c.collation_name IS NOT NULL AND c.collation_name <> t.table_collation"
sudo --user=www-data bin/console --env=prod doctrine:migrations:status
sudo mariadb db_fr_lairdubois_www -e "SELECT COUNT(*), MIN(version), MAX(version) FROM migration_versions"
```

La 3.4 ne propose jamais de supprimer une table sans `--complete`, et ne compare pas la collation
d'une colonne. Le code livré fait les deux à l'étape 39 : la deuxième et la troisième commande
voient la base comme il la verra.

Vérification :

- `Nothing to update` ;
- puis cette ligne, la table de doctrine/migrations, que le code livré laisse de côté (la 3.4
  laisse aussi de côté celle des sessions) :

  ```
  DROP TABLE migration_versions;
  ```

  et, pour une table de la liste de `app/DoctrineMigrations/Version20261115140000.php` du code
  livré, son `DROP TABLE` et les `ALTER TABLE … DROP FOREIGN KEY` qui la visent : des tables
  d'entités retirées de `master`, que cette migration supprime à l'étape 39 ;
- aucune ligne pour la requête des collations ;
- `Executed Migrations: 51` et `New Migrations: 0`, puis `51`, `20200323183209` et
  `20210515091743`. Les migrations de la livraison partent de cet état.

Sinon (une autre table, une colonne, une collation) : ne pas livrer, envoyer la sortie des
commandes. Le code livré arrêterait la coupure à l'étape 39.

Pour estimer la sauvegarde et l'index :

```bash
sudo mariadb db_fr_lairdubois_www -e "SELECT table_name, table_rows, ROUND((data_length + index_length) / 1048576) AS mo FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY mo DESC LIMIT 15"
```

Répétition : jouée, sur une base qui a des tables d'entités retirées de `master`, créées comme à
l'époque, avec leurs clés étrangères. (#149, #190, #312)

### 3. Sauvegarder la configuration

```bash
mkdir -p ~/ladb-backup
sudo cp -a app/config/parameters.yml /etc/nginx/sites-available/www.lairdubois.fr.conf /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf /etc/systemd/system/ladb_*.service ~/ladb-backup/
sudo crontab -u www-data -l > ~/ladb-backup/crontab-www-data
sudo grep -rls 'bin/console' /etc/crontab /etc/cron.d/ /var/spool/cron/crontabs/
grep -vE '^\s*(#|$)' ~/ladb-backup/crontab-www-data | grep -v 'bin/console'
timedatectl show -p Timezone
```

Vérification :

- `ls ~/ladb-backup` liste ces fichiers, et `crontab-www-data` a les lignes des crons
  (`swiftmailer:spool:send`, `ladb:cron:…`) ;
- la quatrième commande n'affiche que `/var/spool/cron/crontabs/www-data`. Un autre fichier (la
  crontab de root, un fichier de `/etc/cron.d/`) a aussi des crons du site : le copier dans
  `~/ladb-backup` (`sudo cp -a LE_FICHIER ~/ladb-backup/`). L'étape 22 en retire les lignes, sinon
  ces crons tourneraient en plus de ceux du code livré ;
- la cinquième affiche les lignes de la crontab de www-data qui ne sont pas des crons du site (une
  sauvegarde…) : l'étape 22 les garde ;
- la dernière affiche `Timezone=Europe/Paris`. La crontab tourne à l'heure du serveur, et le code
  livré lance les crons à l'heure de Paris (étape 43) : sur un serveur à une autre heure
  (`Etc/UTC`), la lettre de la semaine et les mails de notifications partiraient une ou deux heures
  plus tôt ou plus tard. Sinon : ne pas livrer, envoyer la sortie.

Répétition : jouée pour `parameters.yml` ; Docker n'a ni vhost, ni unité, ni crontab, ni
`timedatectl`. (#300, #312)

### 4. Node.js 24

```bash
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo bash -
sudo apt-get install nodejs
```

Vérification : `node -v` affiche `v24.x`. L'ancien code ne se sert de Node que pour `assetic:dump`,
au déploiement. Répétition : vérifiée autrement (conteneurs Debian 13 et 12). (#17, #112)

### 5. Optimiseurs d'images et Chromium

```bash
sudo apt-get install jpegoptim pngquant chromium
```

Vérification : `ls /usr/bin/jpegoptim /usr/bin/pngquant` liste les deux fichiers, et
`chromium --version` affiche 65 ou plus. Sans eux, les vignettes (`/media/cache/resolve/…`)
répondent 500. `optipng` ne sert plus. Répétition : vérifiée autrement (conteneurs Debian 13
et 12). (#18, #135, #177, #193)

### 6. Hub Mercure : le binaire

L'archive de la release et ses empreintes, puis le binaire, si l'empreinte est la bonne :

```bash
curl -fsSL -o ~/ladb-backup/mercure_Linux_x86_64.tar.gz https://github.com/dunglas/mercure/releases/download/v1.1.5/mercure_Linux_x86_64.tar.gz
curl -fsSL -o ~/ladb-backup/mercure-checksums.txt https://github.com/dunglas/mercure/releases/download/v1.1.5/checksums.txt
(cd ~/ladb-backup && grep ' mercure_Linux_x86_64.tar.gz$' mercure-checksums.txt | sha256sum -c)
sudo mkdir -p /opt/mercure
sudo tar -xzf ~/ladb-backup/mercure_Linux_x86_64.tar.gz -C /opt/mercure mercure Caddyfile
```

Vérification : `mercure_Linux_x86_64.tar.gz: OK` (l'empreinte de la v1.1.5 est
`a39416791da133775d1c81d1ec069f376cd938112a40a9bd7158a63771c7343b`), et `ls /opt/mercure` liste
`Caddyfile` et `mercure`. Sinon : ne pas décompresser l'archive, la télécharger à nouveau.
Répétition : vérifiée autrement (conteneurs Debian 13 et 12). (#21, #154, #203, #312)

### 7. Hub Mercure : la configuration

Un secret, à garder pour l'étape 11 (`mercure_jwt_secret`) :

```bash
openssl rand -base64 48
```

Puis le fichier `/etc/mercure.env`, lisible par root seulement : le contenu de
`docs/01-installation.md` du code livré, « Install Mercure », avec le secret à la place de
`THE_SECRET` (deux fois).

```bash
sudo nano /etc/mercure.env
sudo chmod 600 /etc/mercure.env
```

Vérification : `sudo grep -c THE_SECRET /etc/mercure.env` affiche `0`. Répétition : vérifiée
autrement (conteneur Debian 13 : le parseur de systemd lit `MERCURE_EXTRA_DIRECTIVES` sur ses deux
lignes, et le hub démarre avec ce fichier). (#21, #156, #157)

### 8. Hub Mercure : le service

Les ports du hub (3000) et de son API d'admin (2019) doivent être libres :

```bash
sudo ss -ltnp | grep -E ':(3000|2019)\b'
```

n'affiche rien. Sinon : un autre service les prend, ne pas aller plus loin et en parler. Puis le
fichier `/etc/systemd/system/ladb_mercure.service` de `docs/01-installation.md` du code livré,
étape 14.4 :

```bash
sudo nano /etc/systemd/system/ladb_mercure.service
sudo systemctl daemon-reload
sudo systemctl enable --now ladb_mercure
```

Vérification :

```bash
curl -i http://127.0.0.1:2019/mercure/health/ready
```

répond `200`. Sinon : `sudo journalctl -u ladb_mercure`. La 3.4 ne se sert pas du hub : il peut
tourner des jours avant la livraison. Répétition : vérifiée autrement (conteneur Debian 13 : les
ports libres, `systemd-analyze verify` de l'unité, puis le hub répond 200 à cette URL, et 401 à un
abonnement sans JWT ; dans la répétition, le hub est le conteneur `mercure`). (#21, #156)

### 9. Vérifier les données

```bash
sudo mariadb db_fr_lairdubois_www -e "SELECT COUNT(*) FROM tbl_webpush_user_subscription WHERE NOT JSON_VALID(subscription)"
grep -E '^\s*secret:' app/config/parameters.yml | LC_ALL=C grep -c -P '[^\x00-\x7F]'
```

Vérification : `0`, puis `0`.

- Le premier : sinon, la migration de la colonne JSON (étape 39) échoue. Supprimer ces lignes
  (des abonnements web push illisibles) avec la même requête en `DELETE`. (#177, #190)
- Le second : le `secret` est en ASCII. Sinon, les numéros des dons (reçus envoyés par mail) et
  les identifiants de groupe des notifications changeraient pour les nouveaux : ne pas livrer, en
  parler. (#255, #258)

Répétition : jouée.

### 10. Préparer le DSN des mails

Les quatre paramètres `mailer_*` de Swiftmailer deviennent un seul, `mailer_dsn` (étape 11). Les
valeurs d'aujourd'hui :

```bash
grep -E '^\s*mailer_' app/config/parameters.yml
```

- `mailer_transport: smtp`, `mailer_host: localhost`, `mailer_user` et `mailer_password` à
  `null` : `smtp://localhost:25?auto_tls=false`. Le Postfix du serveur, sans TLS entre le site et
  lui : Symfony Mailer lance STARTTLS dès que le serveur l'annonce, et vérifie que le certificat
  porte le nom de l'hôte du DSN, `localhost`, ce qu'aucun certificat ne fait ; Swiftmailer ne le
  vérifiait pas. Postfix garde son TLS vers le relais.
- Avec un utilisateur et un mot de passe (un serveur SMTP distant) :
  `smtp://UTILISATEUR:MOT_DE_PASSE@HOTE:25`, chacun encodé pour une URL par
  `php -r 'echo rawurlencode($argv[1]), "\n";' -- 'LA_VALEUR'` (entre guillemets simples : un `$`
  de la valeur reste tel quel). Sans `auto_tls=false` : le mot de passe ne passe qu'en TLS, sur un
  certificat au nom de `HOTE`.

Le relais voit passer les mails au même débit qu'avant : 100 toutes les 2 minutes au plus, comme le
spool (étape 52). Répétition : jouée pour les `mailer_*` (un SMTP local sur le port 25 : Mailpit à
la place de Postfix) ; Postfix non joué. Le vrai envoi est vérifié à l'étape 44. (#19, #180, #312)

### 11. Paramètres du code livré

Ajouter ces lignes sous `parameters:` dans `app/config/parameters.yml`. La 3.4 ne lit pas les
paramètres qu'elle ne connaît pas : elle marche avec.

```bash
sudo --user=www-data nano app/config/parameters.yml
```

```yaml
    database_server_version:        LA_VERSION_DE_MARIADB
    mailer_dsn:                     LE_DSN_DES_MAILS
    mercure_url:                    http://127.0.0.1:3000/.well-known/mercure
    mercure_public_url:             https://www.lairdubois.fr/.well-known/mercure
    mercure_jwt_secret:             LE_SECRET_DE_MERCURE
    ga_measurement_id:              G-HZ1ZEPHSFS
    router_default_uri:             https://www.lairdubois.fr
    chromium_sandbox:               false
```

- `LA_VERSION_DE_MARIADB` : ce qu'affiche `sudo mariadb -N -e "SELECT VERSION()"`
  (`10.11.x-MariaDB-…`, « MariaDB » doit y être).
- `LE_DSN_DES_MAILS` : celui de l'étape 10. `LE_SECRET_DE_MERCURE` : celui de l'étape 7.
- Un `%` s'écrit `%%` (un caractère encodé du DSN, `%40` : `%%40`) : Symfony lit `%…%` comme le
  nom d'un autre paramètre, et la compilation du container du code livré échouerait à l'étape 35.
  Le cache de la 3.4 est déjà construit : le site n'en dit rien d'ici là.
- `chromium_sandbox` passe à `true` après la livraison (étape 50).

Les anciens paramètres (`mailer_*`, `websocket_*`, `ga_tracking_id`, `router.request_context.*`,
`node`, `node_paths`) restent jusqu'à la livraison : `composer install` les retire (étape 35). Un
`composer install` de l'ancien code retirerait au contraire les nouveaux : refaire cette étape
s'il y en a un d'ici la livraison. Coller deux fois le bloc doublerait ses clés, ce que
`composer install` refuse : chacune n'apparaît qu'une fois.

Vérification :
`grep -c -E '^\s+(database_server_version|mailer_dsn|mercure_url|mercure_public_url|mercure_jwt_secret|ga_measurement_id|router_default_uri|chromium_sandbox):' app/config/parameters.yml`
affiche `8`, puis

```bash
sed 's/%%//g' app/config/parameters.yml | grep -n '%' | cut -d: -f1,2
```

n'affiche rien (sinon : le numéro de ligne et le nom de chaque paramètre qui a un `%` seul), et le
site marche. Répétition : jouée (avec les adresses des conteneurs), puis la 3.4 connecte un compte
par le formulaire et écrit sa session ; vérifiée autrement (conteneur Debian 13 : avec un `%` seul
dans `mailer_dsn`, la vérification le cite et le code livré ne compile pas son container ; avec
`%%`, elle n'affiche rien et le code livré lit le DSN avec un `%`). (#19, #21, #178, #240, #266,
#291, #312)

### 12. PHP-FPM 8.5

Les réglages du pool et du `php.ini` de l'ancien PHP, repris pour la 8.5, plus ceux d'OPcache des
images Docker (le code livré a plus de fichiers que les 10 000 par défaut). PHP-FPM 8.5 a son
propre socket : rien ne passe par lui avant la réouverture (étape 45).

D'abord, ce que l'ancien PHP règle d'autre :

```bash
diff <(grep -vE '^\s*(;|$)' /usr/lib/php/7.3/php.ini-production) <(grep -vE '^\s*(;|$)' /etc/php/7.3/fpm/php.ini)
ls /etc/php/7.3/fpm/conf.d/ /etc/php/7.3/fpm/pool.d/
grep -vE '^\s*(;|$)' /etc/php/7.3/fpm/pool.d/*.conf
```

Le `diff` ne doit montrer que les cinq clés reprises plus bas (`date.timezone`,
`upload_max_filesize`, `post_max_size`, `memory_limit`, `cgi.fix_pathinfo`), `conf.d/` que les
fichiers des extensions (`10-opcache.ini`, `20-curl.ini`…), et `pool.d/` que `www.conf`, avec ses
lignes par défaut (`[www]`, `user`, `group`, `listen…`) et les `pm…`. Sinon : ajouter chaque autre
réglage aux fichiers de la 8.5 ci-dessous (`max_execution_time`, `max_input_vars` dans
`90-lairdubois.ini` ; `request_terminate_timeout`, `php_admin_value[…]` dans `zz-lairdubois.conf`),
sans quoi il se perd sans erreur. Puis :

```bash
( echo '[www]'; grep -E '^pm(\.[a-z_]+)? *=' /etc/php/7.3/fpm/pool.d/www.conf ) | sudo tee /etc/php/8.5/fpm/pool.d/zz-lairdubois.conf
( grep -E '^(date\.timezone|upload_max_filesize|post_max_size|memory_limit|cgi\.fix_pathinfo) *=' /etc/php/7.3/fpm/php.ini; printf 'opcache.memory_consumption = 256\nopcache.max_accelerated_files = 20000\n' ) | sudo tee /etc/php/8.5/fpm/conf.d/90-lairdubois.ini
grep -E '^date\.timezone *=' /etc/php/7.3/cli/php.ini | sudo tee /etc/php/8.5/cli/conf.d/90-lairdubois.ini
sudo systemctl enable php8.5-fpm
sudo systemctl restart php8.5-fpm
```

Vérification : `sudo php-fpm8.5 -tt 2>&1 | grep -E 'pm.max_children|test is successful'` affiche
la valeur de l'ancien pool et `test is successful`,

```bash
sudo php-fpm8.5 -i | grep -E '^(date.timezone|upload_max_filesize|post_max_size|memory_limit|max_execution_time) '
```

les valeurs de l'ancien PHP (sans `date.timezone`, PHP-FPM 8.5 tournerait en UTC, et les dates du
site décaleraient d'une ou deux heures), et `ls -l /run/php/php8.5-fpm.sock` trouve le socket.
Répétition : vérifiée autrement (conteneurs Debian 13 et 12 : le `diff` ne montre que les clés
changées d'un `php.ini` de PHP 7.3, et `php-fpm8.5 -tt` et `-i` lisent les réglages repris de son
pool et de son `php.ini`). (#312)

### 13. Unités des nouveaux services

Les fichiers de `docs/01-installation.md` du code livré : `ladb_consumer_mailer` (étape 14.3),
`ladb_scheduler` et `ladb_consumer_cron` (étapes 13.1 et 13.2). Ils ne sont ni activés ni
démarrés : ils le sont aux étapes 42 et 43.

```bash
sudo nano /etc/systemd/system/ladb_consumer_mailer.service
sudo nano /etc/systemd/system/ladb_scheduler.service
sudo nano /etc/systemd/system/ladb_consumer_cron.service
```

Les unités existantes et le compte du site sur RabbitMQ, dont l'étape 42 dépend :

```bash
grep ExecStart ~/ladb-backup/ladb_consumer_view.service ~/ladb-backup/ladb_consumer_webpush.service
sudo rabbitmqctl list_permissions
```

Vérification : `sudo systemd-analyze verify /etc/systemd/system/ladb_*.service` n'affiche rien ;
les deux `ExecStart` finissent par `rabbitmq:consumer view` et
`rabbitmq:consumer webpush_notification`, que l'étape 42 remplace par `sed` ; le compte
`rabbitmq_user` de `parameters.yml` a `.*` dans les trois colonnes (`configure`, `write`, `read`) :
Messenger crée ses échanges et ses files (`mailer`, `view`, `webpush_notification`, `cron`,
`delays`) à leur premier message. Sinon : noter la ligne `ExecStart`, l'étape 42 se fait alors à la
main, ou donner les droits au compte (`sudo rabbitmqctl set_permissions -p / LE_COMPTE '.*' '.*' '.*'`).
Répétition : vérifiée autrement (conteneurs Debian 13 et 12 : `systemd-analyze verify` de toutes
les unités) ; les droits sont ceux du compte de la stack. (#19, #185, #300, #312)

### 14. Préparer le vhost du code livré

Le vhost livré est `docs/nginx/conf/www.lairdubois.fr.conf`. Celui du serveur a peut-être des
lignes à lui (adresses bannies) : il est modifié, pas remplacé. Les modifications se font sur une
copie, mise en place à l'étape 40.

```bash
sudo cp -a /etc/nginx/sites-available/www.lairdubois.fr.conf /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison
sudo nano /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison
```

Relancer le `cp -a` écraserait les modifications déjà faites dans la copie.

1\. L'upstream du websocket devient la `map` de la visionneuse PDF et l'upstream du hub. Avant :

```nginx
upstream websocket {
  server 127.0.0.1:8080;
}
```

Après :

```nginx
# The PDF viewer (Resources/public/ladb/pdfjs/) may not run strings as code. A map and not a
# location: the add_header of a location would drop those of the server, and an empty value sends
# no header. Keep in sync with infrastructure/docker/services/php/prod/nginx.conf.
map $uri $pdf_viewer_csp {
    default                                         "";
    /bundles/ladbcore/ladb/pdfjs/web/viewer.html    "script-src 'self'";
}

upstream mercure {
  server 127.0.0.1:3000;
}
```

2\. L'en-tête de la visionneuse PDF, sous `Service-Worker-Allowed`. Avant :

```nginx
    add_header                  Service-Worker-Allowed "/";
```

Après :

```nginx
    add_header                  Service-Worker-Allowed "/";
    add_header                  Content-Security-Policy $pdf_viewer_csp;
```

3\. La `location` du websocket devient celle du hub. Avant :

```nginx
    # WebSocket location
    location /ws {
        proxy_pass http://websocket;
        proxy_http_version 1.1;
        proxy_set_header Upgrade websocket;
        proxy_set_header Connection upgrade;
        proxy_read_timeout 4h;
    }
```

Après :

```nginx
    # Mercure hub location (ladb_mercure service): long-lived responses (Server-Sent Events),
    # each event sent as it comes. The hub writes a heartbeat every 40s, under the read timeout.
    # "^~": no regex location below may catch it
    location ^~ /.well-known/mercure {
        proxy_pass http://mercure;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 4h;
    }
```

4\. Le socket de PHP-FPM, dans les deux `fastcgi_pass`. Avant :

```nginx
unix:/run/php/php7.3-fpm.sock;
```

Après :

```nginx
unix:/run/php/php8.5-fpm.sock;
```

5\. Avec nginx 1.25.1 ou plus (P6 : Debian 13), `http2` sur `listen` est déprécié, et `nginx -t`
l'écrit à chaque fois : dans les deux blocs `server` en 443 de la copie, et dans ceux de
`/etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf`. Avant :

```nginx
    listen                      443 ssl http2;
    listen                      [::]:443 http2;
```

Après :

```nginx
    listen                      443 ssl;
    listen                      [::]:443 ssl;
    http2                       on;
```

L'écoute en IPv6 gagne `ssl`, qu'elle n'avait pas. Avec nginx 1.22 (Debian 12), ne pas faire cette
modification : `http2 on` n'existe pas avant la 1.25.1.

6\. La page de maintenance répond en 503, dans
`/etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf` seulement : sinon, elle répond 200
à toutes les URL pendant la coupure, et les robots peuvent la prendre pour les pages du site. Une
URL en `.html` (une création) y répond même 404 aujourd'hui : la `location` des `.html` passe
avant celle de `/`. Avant :

```nginx
    location / {
        try_files $uri @rewriteapp;
    }

    location @rewriteapp {
        rewrite ^(.*)$ /maintenance.html last;
    }

    # Html
    location ~* \.(?:html)$ {
      expires -1;
    }

    # Media: images, icons
    location ~* \.(?:jpg|jpeg|png|ico|cur|svg|svgz)$ {
      expires 1y;
      access_log off;
      add_header Cache-Control "public";
    }

    # CSS and Javascript
    location ~* \.(?:css|js)$ {
      expires 1y;
      access_log off;
      add_header Cache-Control "public";
    }
```

Après :

```nginx
    # The files of web/ as they are (the page and what it loads), any other URL in 503 with the
    # page: the robots keep the pages of the site and come back after Retry-After (seconds). Each
    # location has its try_files: a regex location comes before "/"
    add_header                  Retry-After 3600 always;
    error_page                  503 /maintenance.html;

    location / {
        try_files $uri =503;
    }

    # Html
    location ~* \.(?:html)$ {
      try_files $uri =503;
      expires -1;
    }

    # Media: images, icons
    location ~* \.(?:jpg|jpeg|png|ico|cur|svg|svgz)$ {
      try_files $uri =503;
      expires 1y;
      access_log off;
      add_header Cache-Control "public";
    }

    # CSS and Javascript
    location ~* \.(?:css|js)$ {
      try_files $uri =503;
      expires 1y;
      access_log off;
      add_header Cache-Control "public";
    }
```

Ce qui reste des différences avec le vhost livré doit être les lignes propres au serveur, plus,
avec nginx 1.22, les lignes `listen` de la cinquième modification :

```bash
sudo --user=www-data git fetch origin
sudo --user=www-data git show origin/master:docs/nginx/conf/www.lairdubois.fr.conf | diff /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison -
sudo --user=www-data git show origin/master:docs/nginx/conf/www.lairdubois.fr-maintenance.conf | diff /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf -
```

La copie n'est pas lue par nginx : elle se teste à part, comme la page de maintenance.

```bash
for f in www.lairdubois.fr.conf.livraison www.lairdubois.fr-maintenance.conf; do printf 'events {}\nhttp {\n    include mime.types;\n    include sites-available/%s;\n}\n' "$f" | sudo tee /etc/nginx/ladb-test.conf > /dev/null && sudo nginx -t -c /etc/nginx/ladb-test.conf; done
sudo rm /etc/nginx/ladb-test.conf
```

Vérification : deux fois `syntax is ok` et `test is successful`. Répétition : vérifiée autrement
(ces modifications, appliquées au vhost et à la page de maintenance de `master`, donnent exactement
ceux du code livré ; ces commandes et `nginx -t` dans les conteneurs Debian 13 et 12, sans la
cinquième modification pour Debian 12 ; dans Docker, le site est servi par la configuration nginx
de l'image de prod, tirée de ce vhost). (#21, #158, #276, #312)

### 15. Télécharger Elasticsearch 9

Le paquet, téléchargé site ouvert : à l'étape 29, il s'installe sans attendre.

```bash
curl -fsSL -o ~/ladb-backup/elasticsearch-9.5.5-amd64.deb https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-9.5.5-amd64.deb
curl -fsSL -o ~/ladb-backup/elasticsearch-9.5.5-amd64.deb.sha512 https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-9.5.5-amd64.deb.sha512
(cd ~/ladb-backup && sha512sum -c elasticsearch-9.5.5-amd64.deb.sha512)
```

Vérification : `elasticsearch-9.5.5-amd64.deb: OK`. La 9.5.5 est celle des images Docker et de la
CI. Répétition : vérifiée autrement (conteneur Debian 13). (#31, #161, #204)

### 16. Télécharger Elasticsearch 5.6, pour le retour arrière

L'étape 28 supprime le dépôt de la 5.6 : son paquet, gardé ici, permet de la réinstaller (R5).
D'abord la version installée :

```bash
dpkg-query -W -f='${Version}\n' elasticsearch
```

Puis son paquet (`5.6.16` dans les commandes : la remplacer par cette version) :

```bash
curl -fsSL -o ~/ladb-backup/elasticsearch-5.6.16.deb https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-5.6.16.deb
curl -fsSL -o ~/ladb-backup/elasticsearch-5.6.16.deb.sha512 https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-5.6.16.deb.sha512
(cd ~/ladb-backup && echo "$(cat elasticsearch-5.6.16.deb.sha512)  elasticsearch-5.6.16.deb" | sha512sum -c)
```

Le fichier `.sha512` de la 5.6 n'a que l'empreinte, sans le nom du fichier : la troisième commande
le lui ajoute. Et où est réglé le Java 8 de la 5.6 :

```bash
grep -rs JAVA_HOME /etc/default/elasticsearch /etc/systemd/system/elasticsearch.service.d/
```

Vérification : `elasticsearch-5.6.16.deb: OK`, puis la ligne de `JAVA_HOME`, ou rien : la 5.6
prend alors le `java` du `PATH` (`readlink -f "$(command -v java)"` le montre). La purge de
l'étape 28 supprime `/etc/default/elasticsearch`, et un réglage de systemd pour la 5.6
s'appliquerait à la 9 : l'étape 27 met les deux de côté. Répétition : vérifiée autrement
(conteneur Debian 13, avec un Java 8 réglé dans `/etc/default/elasticsearch`). (#312)

### 17. Durée du cron le plus long

Le code livré lance les crons l'un après l'autre, dans un seul worker (étape 43), qui garde le
message d'un cron sans l'acquitter tant qu'il tourne. RabbitMQ ferme le canal d'un worker qui
garde un message plus de 30 minutes (`consumer_timeout`, de 3.10 à 4.0) : le cron va au bout, mais
le worker s'arrête en erreur en acquittant son message, que systemd relance ; le message, rendu à
la file, est supprimé sans être rejoué. Les sitemaps sont le cron le plus long : 1 min 41 s pour
210 000 contenus (jeu de volume de #282), contre 38 s pour 20 000 notifications à envoyer, et 17 s
pour la lettre de 10 000 comptes, que le code livré met dans la file `mailer` sans attendre leur
envoi. Leur durée sur le serveur, avec la 3.4, à une heure creuse (le cron de 3 h fait la même
chose) :

```bash
time sudo --user=www-data bin/console --env=prod ladb:cron:sitemaps --force
```

Vérification : `real` à moins de 15 minutes. Sinon : 4 heures de délai, dans la configuration de
RabbitMQ (RabbitMQ 3.10 ne connaît pas de délai par file, et refuse la politique
`consumer-timeout` des versions suivantes) :

```bash
echo 'consumer_timeout = 14400000' | sudo tee -a /etc/rabbitmq/rabbitmq.conf
```

RabbitMQ le lit à son prochain démarrage : l'étape 25 le redémarre, quand plus rien ne s'y
connecte. Le délai vaut pour toutes les files : un worker bloqué sur un message le garde 4 heures
avant que RabbitMQ le rende. Répétition : jouée (les sitemaps de la 3.4, puis ce réglage, quelle
que soit leur durée) ; vérifiée autrement (RabbitMQ 3.10 et 4.0 jetables : un message pris comme
le fait Messenger, gardé au-delà du délai, ferme le canal à l'acquittement ; la politique marche en
4.0, pas en 3.10). (#300, #312)

### 18. Sauvegarder la base

```bash
sudo mariadb-dump --single-transaction --quick --routines db_fr_lairdubois_www | gzip > ~/ladb-backup/db.sql.gz
```

Vérification : `zcat ~/ladb-backup/db.sql.gz | tail -1` affiche `-- Dump completed on …`. Une
sauvegarde complète, site ouvert (`--single-transaction` : une image cohérente de la base, sans la
bloquer). Pendant la coupure, seules les tables que les migrations modifient sont sauvegardées
(étape 26) : le site fermé, rien d'autre ne change dans la base. Répétition : jouée.

### 19. Sauvegarder le code de la 3.4

Pour le retour arrière : ce que git ne garde pas.

```bash
sudo tar -czf ~/ladb-backup/code-3.4.tar.gz --ignore-failed-read vendor web/bundles web/css web/js components app/config/parameters.yml var/bootstrap.php.cache
```

Vérification : `tar -tzf ~/ladb-backup/code-3.4.tar.gz | grep -c '^vendor/autoload.php$'` affiche
`1`. Répétition : jouée.

### 20. Rejouer les migrations sur une copie de la base

Plusieurs jours avant : la sauvegarde de l'étape 18, restaurée dans une base à part, migrée par le
code livré, installé dans un dossier à part avec PHP 8.5. Elle dit ce que l'étape 39 trouvera sur
les vraies données, et combien de temps elle prendra. Rien de la prod n'est touché : sa base, son
code et ses services restent tels quels (les migrations et le contrôle du schéma ne parlent qu'à
MariaDB). La restauration charge MariaDB : à une heure creuse.

La base à part :

```bash
sudo mariadb -e "CREATE DATABASE db_fr_lairdubois_www_livraison"
zcat ~/ladb-backup/db.sql.gz | sudo mariadb db_fr_lairdubois_www_livraison
```

Le site s'y connecte avec le compte de `parameters.yml` : s'il n'a de droits que sur
`db_fr_lairdubois_www` (`sudo mariadb -e "SHOW GRANTS FOR 'LE_COMPTE'@'localhost'"`, avec le
`database_user` de `parameters.yml`), lui donner les mêmes sur la copie, le temps de l'étape :
`sudo mariadb -e "GRANT ALL PRIVILEGES ON db_fr_lairdubois_www_livraison.* TO 'LE_COMPTE'@'localhost'"`.

Le code livré, sans son historique, et ses paramètres : ceux du site, sur la base à part.

```bash
sudo mkdir /var/www/ladb-livraison
sudo chown www-data: /var/www/ladb-livraison
sudo --user=www-data git archive origin/master | sudo --user=www-data tar -x -C /var/www/ladb-livraison
sudo sed 's/^\(\s*database_name:\s*\).*/\1db_fr_lairdubois_www_livraison/' app/config/parameters.yml | sudo --user=www-data tee /var/www/ladb-livraison/app/config/parameters.yml > /dev/null
sudo --user=www-data env SYMFONY_ENV=prod php8.5 "$(command -v composer)" --working-dir=/var/www/ladb-livraison install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=php+
```

Les migrations et le contrôle du schéma, comme à l'étape 39 :

```bash
time sudo --user=www-data php8.5 /var/www/ladb-livraison/bin/console --env=prod doctrine:migrations:migrate --no-interaction
sudo --user=www-data php8.5 /var/www/ladb-livraison/bin/console --env=prod doctrine:migrations:up-to-date
sudo --user=www-data php8.5 /var/www/ladb-livraison/bin/console --env=prod doctrine:schema:update --dump-sql
```

Vérification : la première joue les quatre migrations de la livraison (`real` : la durée de
l'étape 39), puis `Up-to-date!` et `Nothing to update`. Sinon : ne pas livrer, envoyer la sortie
des trois commandes. Ensuite, la base et le dossier à part s'en vont (et le `GRANT`, s'il y en a
eu un : `REVOKE ALL PRIVILEGES ON db_fr_lairdubois_www_livraison.* FROM 'LE_COMPTE'@'localhost'`) :

```bash
sudo mariadb -e "DROP DATABASE db_fr_lairdubois_www_livraison"
sudo rm -rf /var/www/ladb-livraison
```

Vérification : `sudo mariadb -e "SHOW DATABASES LIKE 'db_fr_lairdubois_www%'"` ne liste que
`db_fr_lairdubois_www`. Répétition : jouée (sur la sauvegarde de la base de la copie en 3.4, qui a
des tables d'entités retirées de `master` : la migration les supprime ; puis la base de la 3.4 et
son site n'ont pas changé). (#312)

## Phase 2 : pendant la livraison, site en maintenance

Le site est fermé de l'étape 21 à l'étape 45.

### 21. Passer en maintenance

```bash
sudo ln -sfn /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf /etc/nginx/sites-enabled/www.lairdubois.fr.conf
sudo nginx -t
sudo systemctl reload nginx
```

Ne pas lancer `scripts/maintenanceStart.sh` : celui du serveur est encore celui de la 3.4, qui
arrête les consumers avant qu'ils aient vidé leurs files (étape 24).

Vérification : `curl -s https://www.lairdubois.fr/ | grep -c 'Maintenance en cours'` affiche `1`,
et `curl -sI https://www.lairdubois.fr/ | grep -iE '^(HTTP|retry-after)'` affiche `HTTP/2 503` et
`retry-after: 3600`. Relance : oui. Répétition : vérifiée autrement (conteneurs Debian 13 et 12 :
ces commandes, puis `nginx -t`, et la page de maintenance servie par nginx : 503 et `Retry-After`
sur `/` et sur une URL en `.html`). (#312)

### 22. Arrêter les crons

Les lignes des crons du site (`bin/console`) s'en vont de la crontab de www-data, les autres
restent :

```bash
sudo crontab -u www-data -l | grep -v 'bin/console' | sudo crontab -u www-data -
```

Si l'étape 3 a trouvé des crons du site dans un autre fichier, retirer aussi leurs lignes
(`sudo crontab -e` pour la crontab de root, `sudo nano` pour un fichier de `/etc/cron.d/`).

Vérification : `sudo grep -rs 'bin/console' /etc/crontab /etc/cron.d/ /var/spool/cron/crontabs/`
n'affiche rien, et `sudo crontab -u www-data -l` n'a plus que les lignes de l'étape 3 qui ne sont
pas des crons du site. Puis attendre la fin des crons en cours :
`pgrep -a -u www-data -f 'bin/console --env=prod (ladb|swiftmailer):'` n'affiche plus rien. Les
crons reviennent à l'étape 43, en services. Relance : oui. Répétition : non jouée (pas de crontab
dans Docker). (#300, #312)

### 23. Vider le spool de Swiftmailer

Le code livré ne lit plus `app/spool/` : les mails qui y restent sont à envoyer par l'ancien code.

```bash
sudo --user=www-data bin/console --env=prod swiftmailer:spool:send --recover-timeout=0
```

`--recover-timeout=0` renvoie aussi les mails qu'un envoi interrompu a laissés en `.sending`.

Vérification : `find app/spool -type f | wc -l` affiche `0`. Sinon : relancer la commande. Durée :
4 s pour 3 mails. Relance : oui. Répétition : jouée, les mails arrivent dans Mailpit. (#19, #180)

### 24. Laisser les anciens consumers vider leurs files

```bash
sudo rabbitmqctl list_queues name messages
```

Vérification : `view_queue` et `webpush_notification_queue` à `0`. Sinon : attendre quelques
secondes et relancer la commande. Le site est fermé, plus rien ne les remplit. Relance : oui.
Répétition : jouée, l'ancien consumer envoie la notification web push qui attendait. (#22, #152)

### 25. Arrêter les anciens consumers et le serveur websocket

```bash
sudo systemctl stop ladb_consumer_view ladb_consumer_webpush
sudo systemctl disable --now ladb_websocket
```

Vérification : `systemctl is-active ladb_consumer_view ladb_consumer_webpush ladb_websocket`
affiche trois fois `inactive`. Sans `disable`, systemd relancerait `ladb_websocket` toutes les 3 s
après le déploiement, sur une commande qui n'existe plus.

Si l'étape 17 a réglé `consumer_timeout`, redémarrer RabbitMQ : plus rien ne s'y connecte, et ses
files sont vides (étape 24).

```bash
sudo systemctl restart rabbitmq-server
sudo rabbitmq-diagnostics environment | grep consumer_timeout
```

Vérification : `{consumer_timeout,14400000}`. Relance : oui. Répétition : jouée (services
Docker). (#22, #158, #312)

### 26. Sauvegarder les tables que les migrations modifient

```bash
sudo mariadb-dump --single-transaction db_fr_lairdubois_www migration_versions ext_log_entries tbl_webpush_user_subscription | gzip > ~/ladb-backup/db-migrations.sql.gz
```

Les trois premières migrations ne modifient que ces tables, plus les commentaires de six colonnes ;
la quatrième supprime des tables que le site n'utilise plus, qui sont dans la sauvegarde de
l'étape 18. Vérification : `zcat ~/ladb-backup/db-migrations.sql.gz | tail -1` affiche
`-- Dump completed on …`. Relance : oui jusqu'à l'étape 39, jamais après : la sauvegarde aurait les
tables migrées, et le retour arrière (R6) ne rendrait pas à la 3.4 sa `migration_versions`.
Répétition : jouée.

### 27. Elasticsearch 5.6 : l'arrêter et le mettre de côté

Elasticsearch 9 ne lit pas les données de la 5.6 : l'index est reconstruit à l'étape 41.

```bash
sudo systemctl stop elasticsearch
sudo mv /var/lib/elasticsearch /var/lib/elasticsearch-5.6
sudo cp -a /etc/elasticsearch /etc/elasticsearch-5.6
sudo cp -a /etc/default/elasticsearch ~/ladb-backup/elasticsearch-5.6.default
ls -d /etc/systemd/system/elasticsearch.service.d
```

Si la dernière commande trouve le dossier (des réglages de systemd pour la 5.6, comme son Java,
étape 16), le mettre de côté aussi : il s'appliquerait à la 9.

```bash
sudo mv /etc/systemd/system/elasticsearch.service.d ~/ladb-backup/elasticsearch-5.6.service.d
```

Vérification : `ls -d /var/lib/elasticsearch-5.6 /etc/elasticsearch-5.6 ~/ladb-backup/elasticsearch-5.6.default`
liste les trois, et `curl -s http://127.0.0.1:9200` ne répond plus. Relance : non. Le `mv` et le
`cp -a` relancés rangeraient le dossier déjà déplacé, ou celui de la 9 après l'étape 29, dans la
copie de la 5.6 : ne relancer que les commandes qui n'ont pas marché. Répétition : jouée (conteneur
arrêté, son volume gardé) ; vérifiée autrement (conteneur Debian 13 : la 5.6 de son paquet, avec un
Java 8 réglé dans `/etc/default/elasticsearch`). (#31, #204, #312)

### 28. Elasticsearch 5.6 : le désinstaller

```bash
sudo apt-get purge elasticsearch
sudo rm -rf /etc/elasticsearch
grep -rls 'packages/5.x' /etc/apt/sources.list /etc/apt/sources.list.d/
```

La purge laisse dans `/etc/elasticsearch` ce qui n'est pas un fichier du paquet (un fichier ajouté à la main, un keystore) :
le `rm -rf` le retire, la copie est faite, et le paquet 9 part d'un dossier vide. La purge
supprime aussi l'utilisateur `elasticsearch`. Supprimer le fichier que donne la dernière commande
(`/etc/apt/sources.list.d/elastic-5.x.list` d'après l'ancienne doc d'installation).
Vérification : `ls -d /etc/elasticsearch` ne le trouve plus, et la dernière commande n'affiche plus
rien. Relance : oui jusqu'à l'étape 29, jamais après : elle désinstallerait la 9. Répétition :
vérifiée autrement (conteneur Debian 13). (#204, #312)

### 29. Elasticsearch 9 : l'installer

```bash
sudo apt-get install ~/ladb-backup/elasticsearch-9.5.5-amd64.deb
```

Vérification : `/usr/share/elasticsearch/bin/elasticsearch --version` affiche `Version: 9.5.5`. Le
paquet a son propre Java. Durée : 14 s (conteneur Debian 13). Relance : oui. Répétition : vérifiée
autrement (conteneur Debian 13). (#31, #161, #204)

### 30. Elasticsearch 9 : le configurer

Le paquet active la sécurité (TLS, mot de passe) et écoute sur toutes les interfaces. Le site s'y
connecte sans TLS ni mot de passe, comme à la 5.6 : le bloc de sécurité du paquet
(`#--- BEGIN SECURITY AUTO CONFIGURATION ---` à `#--- END SECURITY AUTO CONFIGURATION ---`) est
remplacé par une écoute sur 127.0.0.1, un seul nœud, sans sécurité. Le paquet a aussi mis dans le
keystore les mots de passe de ses certificats : tant qu'ils y sont, Elasticsearch refuse de
démarrer sans TLS (`invalid configuration for xpack.security.transport.ssl`).

```bash
sudo sed -i '/BEGIN SECURITY AUTO CONFIGURATION/,/END SECURITY AUTO CONFIGURATION/d' /etc/elasticsearch/elasticsearch.yml
printf 'network.host: 127.0.0.1\ndiscovery.type: single-node\nxpack.security.enabled: false\ningest.geoip.downloader.enabled: false\n' | sudo tee -a /etc/elasticsearch/elasticsearch.yml
sudo /usr/share/elasticsearch/bin/elasticsearch-keystore remove xpack.security.http.ssl.keystore.secure_password xpack.security.transport.ssl.keystore.secure_password xpack.security.transport.ssl.truststore.secure_password
```

La mémoire (heap), la même que celle de la 5.6 :

```bash
grep -E '^-Xm[sx]' /etc/elasticsearch-5.6/jvm.options
printf -- '-Xms1g\n-Xmx1g\n' | sudo tee /etc/elasticsearch/jvm.options.d/heap.options
```

(`1g` remplacé par la valeur de la 5.6.) Vérification :
`grep -c -E '^(xpack\.security\.enabled: false|network\.host: 127\.0\.0\.1)$' /etc/elasticsearch/elasticsearch.yml`
affiche `2`, `grep -c 'xpack.security' /etc/elasticsearch/elasticsearch.yml` affiche `1`, et
`sudo /usr/share/elasticsearch/bin/elasticsearch-keystore list | grep -c xpack.security` affiche
`0`. Relance : non. Un second `tee -a` doublerait les lignes de `elasticsearch.yml` (la
vérification affiche alors `4` et `2` : retirer les lignes en double), et le second `remove`
échoue sur des clés déjà retirées ; le `sed` et le fichier de la mémoire se relancent. Répétition :
vérifiée autrement (conteneur Debian 13 : sans ce `remove`, Elasticsearch ne démarre pas). (#31,
#204)

### 31. Elasticsearch 9 : le démarrer

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now elasticsearch
```

Vérification : `curl -s http://127.0.0.1:9200 | grep '"number"'` affiche `"number" : "9.5.5"`,
sans demander de mot de passe. Sinon : `sudo journalctl -u elasticsearch` et
`/var/log/elasticsearch/elasticsearch.log`. Durée : 21 s (conteneur Debian 13). Relance : oui.
Répétition : vérifiée autrement (conteneur Debian 13 : Elasticsearch 9 démarre avec cette
configuration et répond sans mot de passe ; dans la répétition, c'est le conteneur
`elasticsearch` en 9). (#204)

### 32. Supprimer ce que le code livré n'utilise plus

```bash
sudo rm -rf var/bootstrap.php.cache var/SymfonyRequirements.php web/css web/js components var/cache/prod var/cache/dev
```

Le cache de la 3.4 ne se reconstruit pas avec le code livré : `bin/console` chargerait l'ancien
container et échouerait dès `composer install` (étape 35). Les autres fichiers sont ceux de
`sensio/distribution-bundle`, d'Assetic et de `robloach/component-installer`. Ils sont dans la
sauvegarde de l'étape 19.

Vérification : `ls -d var/cache/prod web/css components` ne trouve aucun des trois. Relance : oui ;
après l'étape 37, elle supprime aussi le cache du code livré : refaire alors l'étape 37.
Répétition : jouée. (#17, #101, #112, #126)

### 33. Déployer le code

```bash
sudo --user=www-data git pull origin master
```

Vérification : `sudo --user=www-data git log -1 --format=%H` affiche le hash du code livré (voir
« Ce qu'il faut avoir »). Relance : oui. Répétition : jouée (`git checkout` du commit livré, le
dépôt du serveur n'étant pas joignable ; les paquets du serveur sont ceux des images de `next`).

### 34. PHP en ligne de commande

```bash
sudo update-alternatives --set php /usr/bin/php8.5
```

Vérification : `php -v` affiche `PHP 8.5.x`. Les unités de systemd et Composer appellent `php`.
Relance : oui. Répétition : vérifiée autrement (conteneurs Debian 13 et 12).

### 35. Dépendances PHP

```bash
sudo --user=www-data env SYMFONY_ENV=prod composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=php+
```

- `--ignore-platform-req=php+` : deux dépendances (`facebook/graph-sdk`, `spomky-labs/base64url`)
  ne déclarent que PHP 7, et le code tourne en 8.5 ; Composer refuse sinon.
- `--no-dev` et `SYMFONY_ENV=prod`, comme les images Docker : sans les paquets de développement
  (fixtures, profiler), et les scripts de Composer (`cache:clear`, `assets:install`) en prod.
- `composer install` retire de `parameters.yml` les anciens paramètres.

Vérification : la commande finit sans erreur, et
`grep -c -E '^\s+(mailer_host|websocket_uri|ga_tracking_id|node):' app/config/parameters.yml`
affiche `0`. Durée : 10 s. Relance : oui. Répétition : jouée ; aussi dans un conteneur Debian 13,
en www-data avec un `/var/www` à root, sur une copie du code livré. (#101, #190)

### 36. CSS et JS

```bash
sudo --user=www-data env npm_config_cache=var/npm npm ci --no-audit --no-fund
sudo --user=www-data env npm_config_cache=var/npm npx vite build
```

Vérification : `ls web/build/manifest.json web/build/entrypoints.json` liste les deux fichiers.
Sans eux, toutes les pages répondent 500. Durée : 8 s. Relance : oui. Répétition : jouée ; aussi
dans un conteneur Debian 13, comme l'étape 35. (#17, #112)

### 37. Cache

```bash
sudo --user=www-data bin/console --env=prod cache:warmup
```

Vérification : `sudo --user=www-data bin/console --env=prod about | grep -E 'Environment|Debug'`
affiche `prod` et `false`. Le cache du routeur est construit avant la première requête. Relance :
oui. Répétition : jouée. (#18, #128)

### 38. Table des sessions

La table des sessions de la 3.4 est remplacée par une table neuve : toutes les sessions sont
fermées de toute façon (le cookie change de nom, #301).

```bash
sudo mariadb db_fr_lairdubois_www -e "DROP TABLE IF EXISTS sessions"
sudo mariadb db_fr_lairdubois_www < docs/database/schema-sessions.sql
```

Vérification : `sudo mariadb db_fr_lairdubois_www -e "SHOW CREATE TABLE sessions\G"` affiche
`sess_lifetime` en `int(10) unsigned` et la clé `sessions_sess_lifetime_idx`. Avec l'ancienne
colonne (`MEDIUMINT`), toute page qui écrit la session répondrait 500. Relance : oui, la table
repart vide. Répétition : jouée, puis le code livré connecte un compte et écrit sa session dans la
nouvelle table. (#18, #128, #301)

### 39. Migrations

```bash
sudo --user=www-data bin/console --env=prod doctrine:migrations:migrate --no-interaction
```

Elle convertit d'abord `migration_versions` au format de doctrine/migrations 3 (avant,
`doctrine:migrations:status` échoue), puis joue les quatre migrations de la livraison :

- `Version20261028193655` : `object_class` et `username` de `ext_log_entries` en `VARCHAR(191)`
  (#172) ;
- `Version20261028235655` : `tbl_webpush_user_subscription.subscription` en `JSON`, table recopiée
  (#190) ;
- `Version20261031093745` : retire les commentaires `(DC2Type:…)` de six colonnes, sans recopier de
  table (#178, #218) ;
- `Version20261115140000` : supprime les tables d'entités retirées de `master` que la base aurait
  encore (`DROP TABLE IF EXISTS`), celles qu'a vues l'étape 2 (#312).

Vérification :

```bash
sudo --user=www-data bin/console --env=prod doctrine:migrations:up-to-date
sudo mariadb db_fr_lairdubois_www -e "SELECT COUNT(*), SUM(executed_at IS NOT NULL) FROM migration_versions"
sudo --user=www-data bin/console --env=prod doctrine:schema:update --dump-sql
```

`Up-to-date!`, puis `55` et `4` (les 51 de la 3.4 gardent leur ligne, aucune n'est rejouée), puis
`Nothing to update`. Sinon : ne pas rouvrir, envoyer la sortie. Ne jamais corriger le schéma par
`doctrine:schema:update --force` (étape 52). Durée : 5 s, celle de l'étape 20 sur le serveur.
Relance : oui, seules les migrations qui manquent sont jouées. Répétition : jouée, sur la
`migration_versions` de doctrine/migrations 1.8 avec ses 51 lignes. (#149, #190, #200, #312)

### 40. Mettre en place le vhost du code livré

```bash
sudo cp -a /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison /etc/nginx/sites-available/www.lairdubois.fr.conf
```

Il ne sert qu'à la réouverture (étape 45). Vérification :
`diff /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison /etc/nginx/sites-available/www.lairdubois.fr.conf`
n'affiche rien. Relance : oui. Répétition : jouée pour le site (la configuration nginx de l'image
de prod, tirée de ce vhost, sert alors `web/app.php` en prod) ; le vhost lui-même est vérifié à
l'étape 14.

### 41. Index Elasticsearch

```bash
sudo --user=www-data bin/console --env=prod fos:elastica:populate
```

Tant qu'elle tourne, les listes et la recherche sont vides ou en erreur. Vérification :
`curl -s 'http://127.0.0.1:9200/_cat/indices?v'` liste les index `ladb_…`, avec leurs documents.
Durée : 6 s sur les fixtures ; voir « Durée de la coupure » pour la prod. Relance : oui, les index
sont recréés. Répétition : jouée. (#31, #204)

### 42. Workers

Les deux consumers existants changent de commande, et celui des mails (étape 13) démarre :

```bash
sudo sed -i 's#rabbitmq:consumer view#messenger:consume view#' /etc/systemd/system/ladb_consumer_view.service
sudo sed -i 's#rabbitmq:consumer webpush_notification#messenger:consume webpush_notification#' /etc/systemd/system/ladb_consumer_webpush.service
sudo systemctl daemon-reload
sudo systemctl enable --now ladb_consumer_mailer
sudo systemctl start ladb_consumer_view ladb_consumer_webpush
```

Vérification : `grep ExecStart /etc/systemd/system/ladb_consumer_*.service` montre les trois
`messenger:consume`, et `systemctl is-active ladb_consumer_mailer ladb_consumer_view ladb_consumer_webpush`
affiche trois fois `active`. Sans le consumer `mailer`, les mails attendent dans la file `mailer`,
sans se perdre. Relance : oui, un `sed` relancé ne trouve plus rien à remplacer. Répétition :
jouée (services Docker) ; les deux `sed` appliqués aux unités de `master` donnent celles de
`docs/01-installation.md`, vérifiées par `systemd-analyze verify` (conteneurs Debian 13 et 12).
(#19, #22, #152, #185)

### 43. Crons

```bash
sudo systemctl enable --now ladb_scheduler ladb_consumer_cron
```

Vérification : `sudo --user=www-data bin/console --env=prod debug:scheduler cron` liste les huit
crons avec leur prochain passage, et `systemctl is-active ladb_scheduler ladb_consumer_cron` affiche
deux fois `active`. Ce sont ceux de l'ancienne crontab, aux mêmes heures (Paris, étape 3), sauf
`swiftmailer:spool:send`, qui disparaît, et `ladb:cron:opencutlist:download:analyze`, devenue
`ladb:cron:opencutlist:access:analyze` : le retard d'analyse des accès se rattrape à 40 accès par
passage (limite d'ip-api.com). Relance : oui. Répétition : jouée (services Docker, puis un
`ladb:cron:notification:populate` mis dans la file par le scheduler et lancé par le worker).
(#71, #94, #300, #303)

### 44. Envoyer un mail de test

Un mail par le transport de `mailer_dsn`, comme le worker `mailer` les envoie, à une adresse de
celui qui livre (à la place de `UNE_ADRESSE`) :

```bash
sudo --user=www-data bin/console --env=prod mailer:test --from=noreply@lairdubois.fr --subject='Livraison : mail de test' UNE_ADRESSE
```

Vérification : la commande finit sans erreur, le mail arrive dans la boîte de cette adresse, son
original (« Afficher l'original » dans Gmail) a `DKIM: 'PASS'` pour `lairdubois.fr`, et
`sudo grep 'status=sent' /var/log/mail.log | tail -n 3` (`sudo journalctl -t postfix/smtp -n 20`
sans rsyslog) a la ligne de son envoi au relais. Sinon : ne pas rouvrir, lire
`/var/log/mail.log` et `var/logs/prod.log`. Un `mailer_dsn` à corriger (étapes 10 et 11) se
prend en compte par `sudo --user=www-data bin/console --env=prod cache:clear`, puis
`sudo systemctl restart ladb_consumer_mailer`, avant de relancer le test : un mail qui n'arrive
pas au relais est rejoué 5 fois en 2 heures, puis perdu. Relance : oui. Répétition : jouée (le mail
arrive dans Mailpit, signé DKIM) ; Postfix et le relais non joués. (#19, #180, #312)

### 45. Rouvrir le site

C'est le dernier moment pour revenir en arrière sans perte (voir « Retour arrière »).

```bash
sudo scripts/maintenanceStop.sh
```

Le script, celui du code livré, remet le vhost du site (et revient à la page de maintenance si
`nginx -t` échoue), redémarre PHP-FPM 8.5 et nginx, et démarre les workers et les crons.

Vérification : `curl -s -o /dev/null -w '%{http_code}\n' https://www.lairdubois.fr/` affiche `200`,
puis se connecter, ouvrir une création, chercher un mot. Sinon : `tail -n 50 var/logs/prod.log` et
`sudo tail -n 50 /var/log/nginx/www.lairdubois.fr_error.log`. Relance : oui. Répétition : jouée
pour les vérifications (pages en anonyme et connecté, connexion, recherche) ; le script ne l'est
pas (systemd, nginx du serveur).

### 46. Arrêter l'ancien PHP-FPM

```bash
sudo systemctl disable --now php7.3-fpm
```

Vérification : `systemctl is-active php7.3-fpm` affiche `inactive`. Relance : oui. Répétition :
non jouée.

## Phase 3 : après la livraison

### 47. Messages non lus

```bash
sudo --user=www-data bin/console --env=prod ladb:reset:unreadmessagecount --force
```

Chaque fil reçu depuis fin 2020 a laissé un message non lu fantôme. La commande compte maintenant
comme la lecture d'un fil (réponses et fils de collectif compris). Vérification : elle finit sans
erreur. Répétition : jouée. (#69, #93, #94, #98)

### 48. Anciennes files et échanges de RabbitMQ

Les files sont vides depuis l'étape 24. Les supprimer :

```bash
sudo rabbitmqctl delete_queue view_queue
sudo rabbitmqctl delete_queue webpush_notification_queue
```

Les échanges se suppriment par l'API de management. D'abord vérifier que son plugin est actif :

```bash
sudo rabbitmq-plugins list '^rabbitmq_management$'
```

`[E*] rabbitmq_management` : il l'est. `[  ] rabbitmq_management` : l'activer,
`sudo rabbitmq-plugins enable rabbitmq_management`, le temps de cette étape. Puis, avec un compte
qui a le tag `administrator` (`sudo rabbitmqctl list_users`) :

```bash
curl -i -u UTILISATEUR:MOT_DE_PASSE -X DELETE http://127.0.0.1:15672/api/exchanges/%2F/view_exchange
curl -i -u UTILISATEUR:MOT_DE_PASSE -X DELETE http://127.0.0.1:15672/api/exchanges/%2F/webpush_notification_exchange
```

S'il n'était pas actif avant, couper le plugin : il écoute sur le port 15672 de toutes les
interfaces.

```bash
sudo rabbitmq-plugins disable rabbitmq_management
```

Vérification : chaque `curl` répond `204`, `sudo rabbitmqctl list_queues name` comme
`sudo rabbitmqctl list_exchanges name` ne listent plus ces noms, et le plugin est revenu à son état
d'avant (`[  ]` s'il ne l'était pas). Messenger crée aussi l'échange `delays` et des files `delay_*`
au premier nouvel essai d'un message. Répétition : jouée (RabbitMQ 4.0). (#22, #152, #312)

### 49. Dépôt d'Elasticsearch 9

Pour ses mises à jour de sécurité, comme le reste du serveur :

```bash
wget -qO - https://artifacts.elastic.co/GPG-KEY-elasticsearch | sudo gpg --dearmor -o /usr/share/keyrings/elasticsearch-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/elasticsearch-keyring.gpg] https://artifacts.elastic.co/packages/9.x/apt stable main" | sudo tee /etc/apt/sources.list.d/elastic-9.x.list
sudo apt-get update
```

Vérification : `apt-cache policy elasticsearch` cite `artifacts.elastic.co/packages/9.x`.
Répétition : vérifiée autrement (conteneur Debian 13). (#204)

### 50. Chromium dans son bac à sable

```bash
sysctl kernel.unprivileged_userns_clone
```

Si la valeur est `1` (le défaut depuis Debian 11) : `chromium_sandbox: true` dans
`app/config/parameters.yml`, puis :

```bash
sudo --user=www-data bin/console --env=prod cache:clear
sudo --user=www-data bin/console --env=prod ladb:cron:workflow:thumbnails --force
```

Vérification : la commande finit sans `No usable sandbox!`. Sinon : remettre `false`, puis
`cache:clear`. Répétition : non jouée (le bac à sable est vérifié par `castor qa:prod-smoke`).
(#291, #295)

### 51. Ménage, quand le retour arrière n'est plus envisagé

```bash
sudo rm -rf /var/lib/elasticsearch-5.6 /etc/elasticsearch-5.6 /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison
```

Puis les paquets de l'ancien PHP, le Java 8 d'Elasticsearch 5.6 et `~/ladb-backup`. Répétition :
non jouée.

### 52. À savoir pour la suite

- Déploiement : `scripts/maintenanceStart.sh` et `maintenanceStop.sh` sont à jour (PHP-FPM 8.5,
  workers et crons), comme les alias de `docs/01-installation.md` (`ladb-install`, `ladb-assets`).
  Le consumer `mailer` est à redémarrer à chaque déploiement : `maintenanceStop.sh` le fait.
- Schéma : ne jamais lancer `doctrine:schema:update --force` sur la prod. Le code livré (ORM 3)
  compare tout le schéma : il supprimerait les tables hors du mapping et ramènerait les colonnes à
  la collation de leur table, au risque de perdre des caractères. Le schéma change par une
  migration (`doctrine:migrations:migrate`), et `doctrine:schema:update --dump-sql` doit répondre
  `Nothing to update`.
- Mails : 100 toutes les 2 minutes au plus, comme le spool d'avant (`rate_limiter` du transport
  `mailer`, son état dans Memcached) : la lettre de la semaine met une demi-heure par tranche de
  1 500 comptes, et les autres mails attendent leur tour derrière elle. Un envoi en échec est
  rejoué 5 fois en environ 2 h (files `delay_mailer_*`), puis supprimé avec un log critical ; si
  RabbitMQ est injoignable, le mail part en direct, sans attendre, avec un warning. Surveiller la
  place disque de RabbitMQ le vendredi (la lettre y met tous ses mails d'un coup), et laisser le
  worker vider la file avant chaque saut de Symfony. `mailer:test` (étape 44) vérifie l'envoi.
  (#19, #180, #312)
- `doctrine:query:sql` est dépréciée : `dbal:run-sql` la remplace. (#190)

## Retour arrière

### Jusqu'à la réouverture (étape 45)

On peut revenir à la 3.4 sans perte : la base migrée reste lisible par la 3.4. Le site reste en
maintenance pendant ce temps.

R1. Arrêter les services du code livré :

```bash
sudo systemctl disable --now ladb_scheduler ladb_consumer_cron ladb_consumer_mailer
sudo systemctl stop ladb_consumer_view ladb_consumer_webpush
```

R2. Le code de la 3.4 :

```bash
sudo --user=www-data git reset --hard b8848c0c05c3ec80ee1968db9f66ea28e38a5b1f
sudo rm -rf vendor web/bundles var/cache/prod var/cache/dev
sudo tar -xzf ~/ladb-backup/code-3.4.tar.gz
```

R3. L'ancien PHP :

```bash
sudo update-alternatives --set php /usr/bin/php7.3
```

R4. Les anciennes unités et l'ancien vhost :

```bash
sudo cp -a ~/ladb-backup/ladb_*.service /etc/systemd/system/
sudo cp -a ~/ladb-backup/www.lairdubois.fr.conf ~/ladb-backup/www.lairdubois.fr-maintenance.conf /etc/nginx/sites-available/
sudo systemctl daemon-reload
```

R5. Elasticsearch 5.6 :

Arrêter la 9 et la désinstaller, puis réinstaller la 5.6 depuis le paquet de l'étape 16 (`5.6.16`
remplacé par sa version), et lui rendre ses dossiers et ses réglages :

```bash
sudo systemctl stop elasticsearch
sudo apt-get purge elasticsearch
sudo rm -rf /var/lib/elasticsearch /etc/elasticsearch
sudo apt-get install ~/ladb-backup/elasticsearch-5.6.16.deb
sudo rm -rf /var/lib/elasticsearch /etc/elasticsearch
sudo mv /var/lib/elasticsearch-5.6 /var/lib/elasticsearch
sudo mv /etc/elasticsearch-5.6 /etc/elasticsearch
sudo chown -R elasticsearch:elasticsearch /var/lib/elasticsearch
sudo chown -R root:elasticsearch /etc/elasticsearch
sudo cp -a ~/ladb-backup/elasticsearch-5.6.default /etc/default/elasticsearch
```

Les purges ont supprimé l'utilisateur `elasticsearch`, que le paquet recrée, peut-être avec un
autre numéro : les `chown` lui rendent les dossiers. Si l'étape 27 a mis de côté
`elasticsearch.service.d`, le remettre :
`sudo mv ~/ladb-backup/elasticsearch-5.6.service.d /etc/systemd/system/elasticsearch.service.d`.
Puis :

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now elasticsearch
```

Vérification : `curl -s http://127.0.0.1:9200 | grep '"number"'` affiche la version de la 5.6, et
`curl -s 'http://127.0.0.1:9200/_cat/indices?v'` liste ses index, avec leurs documents.

R6. La base :

```bash
zcat ~/ladb-backup/db-migrations.sql.gz | sudo mariadb db_fr_lairdubois_www
```

La 3.4 lit la base migrée, sauf `migration_versions`, que l'étape 39 a convertie : sans la
sauvegarde de l'étape 26, `doctrine:migrations:status` de la 3.4 compterait 55 migrations
inconnues et 51 nouvelles, et un `migrate` rejouerait celles de 2020. La sauvegarde rend aussi
`ext_log_entries` et `tbl_webpush_user_subscription` d'avant les migrations, que le site fermé n'a
pas changées. Les tables que la quatrième migration a supprimées ne servaient plus : elles restent
dans la sauvegarde de l'étape 18. Vérification :
`sudo --user=www-data bin/console --env=prod doctrine:migrations:status` affiche
`Executed Migrations: 51`, `Executed Unavailable Migrations: 0` et `New Migrations: 0`.

R7. Les services et les crons de la 3.4 :

```bash
sudo systemctl enable --now ladb_websocket
sudo systemctl start ladb_consumer_view ladb_consumer_webpush
sudo crontab -u www-data ~/ladb-backup/crontab-www-data
sudo scripts/maintenanceStop.sh
```

Et les crons retirés d'un autre fichier à l'étape 22 : le remettre depuis `~/ladb-backup`. Le
`maintenanceStop.sh` est alors celui de la 3.4. Vérification : le site répond, on se connecte.
Un compte qui s'est connecté au code livré pendant la maintenance (pour tester) ne se connecte
plus : son mot de passe est à refaire par « mot de passe oublié ».

Répétition : jouée de R2 à R6 juste avant la réouverture, après les migrations et un premier
démarrage des workers et des crons, puis le code livré est déployé à nouveau. La 3.4 connecte un
compte qui ne s'est pas encore connecté au code livré, affiche ses pages, écrit sa session et
trouve ses contenus dans l'index 5.6, et `doctrine:migrations:status` retrouve ses 51 migrations ;
un compte qui s'est connecté au code livré est refusé ; au second déploiement,
`doctrine:migrations:migrate` rejoue les quatre migrations sur les tables que R6 a remises. R5 est
vérifiée autrement (conteneur Debian 13 : la 5.6 réinstallée depuis son paquet, alors qu'un autre
compte a pris le numéro de l'utilisateur `elasticsearch`, retrouve son Java, ses données et son
index). R1, R3, R4 et R7 (systemd, crontab) ne sont pas joués tels quels.

### Après la réouverture

Le retour arrière n'est plus sans perte :

- chaque compte qui se connecte au code livré voit son mot de passe refait en interne (le hash de
  FOSUserBundle, sha512, devient un hash bcrypt). La 3.4 ne sait pas le lire : après un retour, ces
  membres ne peuvent plus se connecter sans passer par « mot de passe oublié » ;
- les mails en attente dans la file `mailer`, et les vues et notifications des files `view` et
  `webpush_notification`, ne sont pas lus par la 3.4 ;
- les contenus créés depuis manquent dans l'index 5.6, jusqu'à un `fos:elastica:populate` de la
  3.4 ;
- tous les membres sont déconnectés une seconde fois.

Plus le site reste ouvert, plus il y a de comptes concernés : un retour arrière après la
réouverture se décide vite.
