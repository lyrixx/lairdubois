Running L'Air du Bois with the Docker images
===========================================

The site also comes as two production images, built and checked by the CI on each push to `next`,
then pushed to the registry of the repository:

- `ghcr.io/lyrixx/lairdubois/php:<tag>`: the code, `vendor/` without the dev packages, the CSS and
  JS, the warmed cache, PHP 8.5 with its extensions, Chromium, ImageMagick, Ghostscript,
  `rsvg-convert`, `jpegoptim` and `pngquant`. It runs php-fpm as the user 1000, and, with another
  command, each worker, the scheduler of the crons among them;
- `ghcr.io/lyrixx/lairdubois/nginx:<tag>`: nginx and `web/`, in HTTP on the port 8080, from
  [`www.lairdubois.fr.conf`](nginx/conf/www.lairdubois.fr.conf).

Tags: `next` (the last push) and the full SHA of the commit. The images, the Dockerfile and a
stack that runs them are in `infrastructure/docker/` (`docker-compose.prod.yml`, for the tests:
throwaway secrets, Mailpit instead of a SMTP server).

The system packages of the images (Debian in `php`, Alpine in `nginx`) are upgraded once a week:
the first build of each week installs them again, the next ones of the week reuse those layers.
An image has the packages of the week of its push: deploy the last `next` to get the security
updates of Debian and Alpine.

## Containers

All of them from the image `php`, but `nginx`, in `/var/www`:

| Container | Command |
|-----------|---------|
| php-fpm | the default one |
| nginx | the default one of the image `nginx` |
| The mailer consumer (step 14.3) | `bin/console messenger:consume mailer` |
| The view consumer (step 14.1) | `bin/console messenger:consume view` |
| The webpush notification consumer (step 14.2) | `bin/console messenger:consume webpush_notification` |
| The scheduler of the crons (step 13.1), one at a time | `bin/console messenger:consume scheduler_cron` |
| The cron consumer (step 13.2), one at a time | `bin/console messenger:consume cron --memory-limit=256M` |

The image has a `HEALTHCHECK` for php-fpm (its ping): disable it for the consumers and the
scheduler. They need MariaDB, Elasticsearch, RabbitMQ, Memcached and the Mercure hub (step 0), and
a SMTP server. A second scheduler waits for the lock of the first one (Memcached), and does nothing
in the meantime. The cron consumer runs the crons one after the other, in a process that lives for
days: past 256 MB, it stops after the cron it runs, never during it, and must be restarted (a
restart policy). Stopped, it finishes the one
it runs first: give it a grace period of an hour (`stop_grace_period` with Compose,
`terminationGracePeriodSeconds` with Kubernetes), or a killed cron waits for its next time.

## Front

TLS ends in front of the containers, on a reverse proxy that sends `/.well-known/mercure` to the
hub (long-lived responses, without buffering, see Step 6) and the rest to `nginx:8080`, with the
`X-Forwarded-For` header: nginx trusts it from the private networks (10/8, 172.16/12,
192.168/16) for the address of the client. The domain of the site must also lead to that proxy
from inside the containers, with a certificate that Chromium trusts: the thumbnails of the
workflows (`ladb:cron:workflow:thumbnails`) are screenshots of a page of the site,
`/processus/<id>/internal/diagram`, open without an account only to the addresses of
`INTERNAL_IPS` (the server itself, `127.0.0.1`, on the production server). Give it the address
that these requests have for nginx (the one of the containers, or of the host if they go through
it), never one that a visitor may have: the diagram of any workflow would be public. Extra flags
for Chromium go in `CHROMIUM_EXTRA_FLAGS`.

## The sandbox of Chromium

Chromium (the thumbnails of the workflows in the cron consumer, the videos of the finds without a
thumbnail from their host in php-fpm) runs without its sandbox (`--no-sandbox`) unless
`CHROMIUM_SANDBOX=true`. Its sandbox creates user, PID and network namespaces, which the default
seccomp profile of Docker, and of Kubernetes (`RuntimeDefault`), only allows with `CAP_SYS_ADMIN`.
Give the containers of php-fpm and of the cron consumer
[`chromium-seccomp.json`](../infrastructure/docker/services/php/chromium-seccomp.json) instead: the
default profile of Docker 29, plus `clone` and `unshare`. Neither `--privileged` nor
`CAP_SYS_ADMIN`. The kernel of the host must allow the user namespaces (Debian:
`kernel.unprivileged_userns_clone`, `1` by default since Debian 11). Without them or without the
profile, Chromium does not start (`No usable sandbox!`): the cron of the thumbnails fails, and the
creation of such a find answers 500.

- Docker: `--security-opt seccomp=chromium-seccomp.json` (`security_opt` with Compose, as in
  `docker-compose.prod.yml`).
- Kubernetes (K3s): the file on each node, in the seccomp directory of the kubelet
  (`/var/lib/kubelet/seccomp/`), and in the containers of php-fpm and of the cron consumer:

  ```yaml
  securityContext:
      seccompProfile:
          type: Localhost
          localhostProfile: chromium-seccomp.json
  ```

  A container without a `seccompProfile` runs `Unconfined` unless the kubelet has
  `seccompDefault`: no filter on the system calls at all, give it the profile anyway.

## Variables

`app/config/parameters.yml` of the image
([`parameters.yml`](../infrastructure/docker/services/php/prod/parameters.yml)) reads them at
runtime, in every container of the image `php`. The ones with a default can be left out.

| Variable | Parameter | Default |
|----------|-----------|---------|
| `DATABASE_HOST`, `DATABASE_USER`, `DATABASE_PASSWORD` | `database_*` | |
| `DATABASE_PORT`, `DATABASE_NAME` | | `3306`, `db_fr_lairdubois_www` |
| `DATABASE_SERVER_VERSION` | `database_server_version` (`10.11.19-MariaDB`) | none: Doctrine connects to find it |
| `ELASTICSEARCH_HOST`, `ELASTICSEARCH_PORT` | `elasticsearch_*` | port `9200` |
| `MEMCACHED_HOST`, `MEMCACHED_PORT` | `memcached_*` | port `11211` |
| `RABBITMQ_HOST`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD` | `rabbitmq_*` | |
| `RABBITMQ_PORT`, `RABBITMQ_VHOST` | | `5672`, `/` |
| `MAILER_DSN` | `mailer_dsn` | |
| `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET` | `mercure_*` | |
| `SECRET` | `secret` | |
| `ROUTER_DEFAULT_URI` | `router_default_uri`: `https://` and the host of the site | |
| `TRUSTED_HOSTS` | `framework.trusted_hosts`, a regex | `^(dev\|www)\.lairdubois\.fr$` |
| `INTERNAL_IPS` | the addresses allowed on the `/internal/` pages without an account (`security.yml`) | `127.0.0.1, ::1` |
| `CHROMIUM_SANDBOX` | `chromium_sandbox`: Chromium in its sandbox, with the seccomp profile of [The sandbox of Chromium](#the-sandbox-of-chromium) | `false`: `--no-sandbox` |
| `WEBPUSH_PUBLIC_KEY`, `WEBPUSH_PRIVATE_KEY` | `bentools_webpush.*` (VAPID) | |
| `ADMIN_USERNAME` | `admin_username` | `admin` |
| `GA_MEASUREMENT_ID` | `ga_measurement_id`: Google Analytics (`G-…`) | empty: none |
| `GOOGLE_API_KEY`, `TWITTER_*`, `FACEBOOK_*`, `PINTEREST_*`, `MASTODON_*`, `STRIPE_*` | the accounts of the site on these services | the placeholders of `parameters.yml.dist` |

Give the accounts of the site on other services only to a server that may use them: with them,
`ladb:cron:spotlight` publishes on Twitter, Facebook and Mastodon, and the funding takes real
payments. `MASTODON_INSTANCE=offline://mastodon` keeps the cron from calling Mastodon. Google
Analytics likewise: give `GA_MEASUREMENT_ID` (`G-HZ1ZEPHSFS`, the one of `parameters.yml.dist`)
to the production server only, a preprod would count its visits in those of the site. Every page
has it, but for the admins.

## Files

| Path in the containers | What | Containers |
|------------------------|------|------------|
| `/var/www/keys/private.pem` | the DKIM key (Step 7), read only | all of the image `php` |
| `/var/www/uploads` | pictures and resources | all of the image `php` |
| `/var/www/downloads` | archives of the plans, graphics and textures | all of the image `php` |
| `/var/www/web/media` | the thumbnails of Liip Imagine | all, read only for nginx |
| `/var/www/sitemaps` | the sitemaps (`SITEMAPS_DIR`), served at the root of the site | all, read only for nginx |
| `/var/www/var/logs` | `prod.log` | all of the image `php` |
| `/var/run/php` | the socket of php-fpm | php-fpm and nginx |

Each one is a volume that a new version of the images keeps, owned by the user 1000 (the image
creates them so, empty). The sessions are in the database.

## Deploying a version

1. Pull the new images, stop the consumers and the scheduler.
2. Update the database, in a container of the new image `php`: `bin/console
   doctrine:migrations:migrate --no-interaction` (on a new database: `doctrine:database:create`,
   `doctrine:schema:update --force`, then the SQL of
   [`schema-sessions.sql`](database/schema-sessions.sql) with `bin/console dbal:run-sql`).
3. Start php-fpm, then nginx, then the consumers and the scheduler.
4. On a new index (a new database, Elasticsearch upgraded): `bin/console fos:elastica:populate`.
