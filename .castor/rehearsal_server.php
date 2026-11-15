<?php

namespace rehearsal;

use function Castor\context;
use function Castor\fs;
use function Castor\run;

// The Elasticsearch 5.6 of the server (its last version): the guide keeps its package for the
// rollback (step 16, R5)
const ELASTICSEARCH_56 = '5.6.16';

// The digest of the archive of the hub, in the checksums.txt of its release (step 6)
const MERCURE_SHA256 = 'a39416791da133775d1c81d1ec069f376cd938112a40a9bd7158a63771c7343b';

/**
 * What Docker does not play, in throwaway Debian containers named after the project of the
 * rehearsal (removed at the end): the packages, PHP 8.5 next to an older PHP, the units of
 * systemd, the vhost of nginx and the hub of Mercure, with the commands of the guide and of
 * docs/01-installation.md as they are written. The containers run as root, with sudo: the
 * commands keep it.
 */
function part_server(Report $r): void
{
    stable_versions_check($r);
    prepare_server_files();
    try {
        foreach (['13', '12'] as $debian) {
            server_checks($r, $debian);
        }
        elasticsearch_checks($r);
    } finally {
        remove_check_containers();
    }
}

/**
 * P7: the command of the guide on the composer.lock of the code delivered, against the versions
 * of the lock that have a stability flag. Until Symfony 8.2.0, the prerequisite is not met: the
 * table says how many packages are still in an unstable version.
 */
function stable_versions_check(Report $r): void
{
    guide_has('sudo --user=www-data git fetch origin');
    $command = "sudo --user=www-data git show origin/master:composer.lock | grep -iE '\"version\": \"[^\"]*(dev|alpha|beta|rc)'";
    guide_has($command);
    $found = array_filter(explode("\n", trim(run(['sh', '-c', str_replace(['sudo --user=www-data ', 'origin/master'], ['', 'HEAD'], $command)], context: context()->withQuiet()->withAllowFailure())->getOutput())));
    $lock = json_decode(run(['git', 'show', 'HEAD:composer.lock'], context: context()->withQuiet())->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
    $unstable = array_filter(array_merge($lock['packages'], $lock['packages-dev']), static fn (array $package) => 1 === preg_match('{^dev-|-dev$|-(alpha|beta|rc)\d*$}i', $package['version']));
    $where = [] === $unstable ? 'composer.lock du code livré : aucun paquet en version instable' : 'composer.lock du code livré : ' . \count($unstable) . ' paquets en version instable, prérequis non rempli';
    $r->verified('Le code livré n\'a que des versions stables', $where, static function () use ($found, $unstable): void {
        expect(\count($found) === \count($unstable), 'The command of the guide finds ' . \count($found) . ' unstable versions, the lock has ' . \count($unstable) . '.');
    });
}

function server_checks(Report $r, string $debian): void
{
    $c = check_container("debian{$debian}");
    $where = "Debian {$debian}";
    start_container($c, "debian:{$debian}");

    $r->verified('Debian 13 ou 12', $where, static function () use ($c, $debian): void {
        $version = trim(server_run($c, 'cat /etc/debian_version'));
        expect(str_starts_with($version, "{$debian}."), "Debian {$version}.");
    });
    $r->verified('La commande `php` est toujours celle de l\'ancien PHP', $where, static function () use ($c): void {
        // The PHP of the 3.4 on the server, with the settings of docs/01-installation.md of master
        foreach ([
            'sudo apt-get install ca-certificates curl',
            'curl -sSLo ~/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb',
            'sudo dpkg -i ~/debsuryorg-archive-keyring.deb',
            'echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(. /etc/os-release && echo "$VERSION_CODENAME") main" | sudo tee /etc/apt/sources.list.d/php.list',
            'sudo apt-get update',
        ] as $command) {
            server_run($c, $command, INSTALLATION);
        }
        in_container($c, 'apt-get install -qq php7.3-cli php7.3-fpm > /dev/null');
        in_container($c, implode(' && ', [
            "sed -i -E 's/^pm.max_children = .*/pm.max_children = 100/; s/^pm.start_servers = .*/pm.start_servers = 20/; s/^pm.min_spare_servers = .*/pm.min_spare_servers = 10/; s/^pm.max_spare_servers = .*/pm.max_spare_servers = 20/' /etc/php/7.3/fpm/pool.d/www.conf",
            "printf 'date.timezone = Europe/Paris\\nupload_max_filesize = 60M\\npost_max_size = 60M\\nmemory_limit = 256M\\ncgi.fix_pathinfo=0\\n' >> /etc/php/7.3/fpm/php.ini",
            "printf 'date.timezone = Europe/Paris\\n' >> /etc/php/7.3/cli/php.ini",
        ]));
        expect(str_contains(in_container($c, 'update-alternatives --display php'), 'link currently points to /usr/bin/php7.3'), 'php is not PHP 7.3 before PHP 8.5.');

        server_run($c, 'sudo apt-get install php8.5-cli php8.5-fpm php8.5-amqp php8.5-bcmath php8.5-bz2 php8.5-curl php8.5-gd php8.5-gmp php8.5-imagick php8.5-intl php8.5-mbstring php8.5-memcached php8.5-mysql php8.5-xml php8.5-zip', INSTALLATION);
        $display = server_run($c, 'update-alternatives --display php');
        expect(str_contains($display, 'auto mode') && str_contains($display, 'link currently points to /usr/bin/php8.5'), "Installing php8.5-cli does not make php PHP 8.5 in auto mode:\n{$display}");

        server_run($c, 'sudo update-alternatives --set php /usr/bin/php7.3');
        in_container($c, 'apt-get install -qq --reinstall php8.5-cli > /dev/null');
        $display = server_run($c, 'update-alternatives --display php');
        expect(str_contains($display, 'manual mode') && str_contains($display, 'link currently points to /usr/bin/php7.3'), "--set does not keep php on PHP 7.3:\n{$display}");
    });
    $r->verified('PHP 8.5 et ses extensions', $where, static function () use ($c): void {
        expect(str_starts_with(server_run($c, 'php8.5 -v'), 'PHP 8.5.'), 'php8.5 is not PHP 8.5.');
        $missing = server_run($c, 'for m in amqp bcmath bz2 curl gd gmp iconv imagick intl mbstring memcached pdo_mysql xml zip; do php8.5 -m | grep -qix "$m" || echo "manque : $m"; done');
        expect('' === trim($missing), "Extensions missing: {$missing}");
        server_run($c, 'ls /etc/php/8.5/fpm/php-fpm.conf');
    });
    $r->verified('Composer 2.2 ou plus', $where, static function () use ($c): void {
        server_run($c, 'curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer', INSTALLATION);
        $version = server_run($c, 'composer --version');
        expect(1 === preg_match('{Composer version 2\.(\d+)}', $version, $matches) && (int) $matches[1] >= 2, "Composer: {$version}");
    });
    $r->verified('La version de nginx, qui décide d\'une modification du vhost (étape 14)', $where, static function () use ($c): void {
        in_container($c, 'apt-get install -qq nginx > /dev/null');
        server_run($c, 'nginx -v');
    });
    $r->verified('Node.js 24', $where, static function () use ($c): void {
        server_run($c, 'curl -fsSL https://deb.nodesource.com/setup_24.x | sudo bash -');
        server_run($c, 'sudo apt-get install nodejs');
        expect(str_starts_with(trim(in_container($c, 'node -v')), 'v24.'), 'Node.js is not 24.');
    });
    $r->verified('Optimiseurs d\'images et Chromium', $where, static function () use ($c): void {
        server_run($c, 'sudo apt-get install jpegoptim pngquant chromium');
        server_run($c, 'ls /usr/bin/jpegoptim /usr/bin/pngquant');
        $version = server_run($c, 'chromium --version');
        expect(1 === preg_match('{Chromium (\d+)\.}', $version, $matches) && (int) $matches[1] >= 65, "Chromium: {$version}");
    });
    $r->verified('PHP-FPM 8.5', $where, static function () use ($c): void {
        // The settings of the old PHP beyond those the guide takes: only the 5 keys of the php.ini,
        // the files of the extensions, the default lines of the pool and the pm.*
        $diff = server_run($c, "diff <(grep -vE '^\\s*(;|$)' /usr/lib/php/7.3/php.ini-production) <(grep -vE '^\\s*(;|$)' /etc/php/7.3/fpm/php.ini)", allowFailure: true);
        $changed = preg_grep('{^[<>] }', explode("\n", $diff));
        $other = preg_grep('{^[<>] (date\.timezone|upload_max_filesize|post_max_size|memory_limit|cgi\.fix_pathinfo) *=}', $changed, \PREG_GREP_INVERT);
        expect([] !== $changed && [] === $other, "The diff of the php.ini of PHP 7.3 has other settings:\n{$diff}");
        $files = server_run($c, 'ls /etc/php/7.3/fpm/conf.d/ /etc/php/7.3/fpm/pool.d/');
        expect(1 === preg_match('{pool\.d/:\nwww\.conf\s*$}', $files) && 1 === preg_match('{conf\.d/:\n(\d+-\w+\.ini\s+)+}', $files), "conf.d and pool.d of PHP 7.3:\n{$files}");
        $pool = server_run($c, "grep -vE '^\\s*(;|$)' /etc/php/7.3/fpm/pool.d/*.conf");
        $other = preg_grep('{^(\[www\]|(user|group|listen(\.\w+)?|pm(\.\w+)?) *= .*)$}', array_filter(explode("\n", $pool)), \PREG_GREP_INVERT);
        expect([] === $other, "The pool of PHP 7.3 has other settings:\n" . implode("\n", $other));

        foreach ([
            "( echo '[www]'; grep -E '^pm(\\.[a-z_]+)? *=' /etc/php/7.3/fpm/pool.d/www.conf ) | sudo tee /etc/php/8.5/fpm/pool.d/zz-lairdubois.conf",
            "( grep -E '^(date\\.timezone|upload_max_filesize|post_max_size|memory_limit|cgi\\.fix_pathinfo) *=' /etc/php/7.3/fpm/php.ini; printf 'opcache.memory_consumption = 256\\nopcache.max_accelerated_files = 20000\\n' ) | sudo tee /etc/php/8.5/fpm/conf.d/90-lairdubois.ini",
            "grep -E '^date\\.timezone *=' /etc/php/7.3/cli/php.ini | sudo tee /etc/php/8.5/cli/conf.d/90-lairdubois.ini",
        ] as $command) {
            server_run($c, $command);
        }
        // systemctl restart php8.5-fpm: its test of the configuration, without systemd
        guide_has('sudo systemctl restart php8.5-fpm');
        $test = server_run($c, "sudo php-fpm8.5 -tt 2>&1 | grep -E 'pm.max_children|test is successful'");
        expect(str_contains($test, 'pm.max_children = 100') && str_contains($test, 'test is successful'), "php-fpm8.5 -tt:\n{$test}");
        $ini = server_run($c, "sudo php-fpm8.5 -i | grep -E '^(date.timezone|upload_max_filesize|post_max_size|memory_limit|max_execution_time) '");
        foreach (['upload_max_filesize => 60M', 'post_max_size => 60M', 'memory_limit => 256M', 'date.timezone => Europe/Paris'] as $expected) {
            expect(str_contains($ini, $expected), "PHP-FPM 8.5 has not {$expected}:\n{$ini}");
        }
        $ini = in_container($c, "php-fpm8.5 -i | grep -E '^(cgi.fix_pathinfo|opcache.max_accelerated_files) '");
        foreach (['cgi.fix_pathinfo => Off', 'opcache.max_accelerated_files => 20000'] as $expected) {
            expect(str_contains($ini, $expected), "PHP-FPM 8.5 has not {$expected}:\n{$ini}");
        }
    });
    $r->verified('PHP en ligne de commande', $where, static function () use ($c): void {
        server_run($c, 'sudo update-alternatives --set php /usr/bin/php8.5');
        expect(str_starts_with(in_container($c, 'php -v'), 'PHP 8.5.'), 'php is not PHP 8.5.');
        expect(str_contains(in_container($c, 'php -i'), 'date.timezone => Europe/Paris'), 'The command line of PHP 8.5 has not the time zone of the old one.');
    });
    $r->verified('Préparer le vhost du code livré', $where, static function () use ($c, $debian): void {
        check_vhost_edits();
        // The vhost of the server: master's, with the changes of the guide. nginx 1.22 (Debian
        // 12): without the fifth one
        $suffix = '12' === $debian ? '-1.22' : '';
        in_container($c, implode(' && ', [
            'mkdir -p /etc/letsencrypt/live/lairdubois.fr',
            'openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=www.lairdubois.fr -keyout /etc/letsencrypt/live/lairdubois.fr/privkey.pem -out /etc/letsencrypt/live/lairdubois.fr/fullchain.pem 2> /dev/null',
            'cp /rehearsal/vhost-master.conf /etc/nginx/sites-available/www.lairdubois.fr.conf',
            'cp /rehearsal/vhost-maintenance-master.conf /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf',
            'ln -s /etc/nginx/sites-available/www.lairdubois.fr.conf /etc/nginx/sites-enabled/www.lairdubois.fr.conf',
        ]));
        $master = in_container($c, 'nginx -t 2>&1');
        expect(str_contains($master, 'test is successful'), "nginx -t of the vhost of master:\n{$master}");
        server_run($c, 'sudo cp -a /etc/nginx/sites-available/www.lairdubois.fr.conf /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison');
        in_container($c, "cp /rehearsal/vhost-delivery{$suffix}.conf /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison");
        in_container($c, "cp /rehearsal/vhost-maintenance{$suffix}.conf /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf");
        foreach (['sudo --user=www-data git show origin/master:docs/nginx/conf/www.lairdubois.fr.conf | diff /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison -', 'sudo --user=www-data git show origin/master:docs/nginx/conf/www.lairdubois.fr-maintenance.conf | diff /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf -'] as $command) {
            guide_has($command);
        }
        $test = server_run($c, 'for f in www.lairdubois.fr.conf.livraison www.lairdubois.fr-maintenance.conf; do printf \'events {}\nhttp {\n    include mime.types;\n    include sites-available/%s;\n}\n\' "$f" | sudo tee /etc/nginx/ladb-test.conf > /dev/null && sudo nginx -t -c /etc/nginx/ladb-test.conf; done');
        server_run($c, 'sudo rm /etc/nginx/ladb-test.conf');
        expect(2 === substr_count($test, 'test is successful'), "nginx -t of the vhost of the delivery and of the maintenance:\n{$test}");
        expect(!str_contains($test, 'deprecated'), "nginx -t warns:\n{$test}");
        // The vhost of the delivery read with the rest of nginx, as after the reopening
        in_container($c, 'cp /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison /etc/nginx/sites-available/www.lairdubois.fr.conf');
        $test = in_container($c, 'nginx -t 2>&1');
        expect(str_contains($test, 'test is successful') && !str_contains($test, 'deprecated'), "nginx -t of the vhost of the delivery:\n{$test}");
    });
    $r->verified('Passer en maintenance', $where, static function () use ($c): void {
        server_run($c, 'sudo ln -sfn /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf /etc/nginx/sites-enabled/www.lairdubois.fr.conf');
        $test = server_run($c, 'sudo nginx -t');
        expect(str_contains($test, 'test is successful'), "nginx -t in maintenance:\n{$test}");
        expect('/etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf' === trim(in_container($c, 'readlink /etc/nginx/sites-enabled/www.lairdubois.fr.conf')), 'The site is not in maintenance.');
        // nginx serves the page of the code delivered, and its logo, from web/
        in_container($c, 'mkdir -p /var/www/www.lairdubois.fr/web/bundles/ladbcore/ladb/images && cp /rehearsal/code/web/maintenance.html /var/www/www.lairdubois.fr/web/ && cp /rehearsal/code/src/Ladb/CoreBundle/Resources/public/ladb/images/logo_big.png /var/www/www.lairdubois.fr/web/bundles/ladbcore/ladb/images/ && nginx');
        guide_has("curl -s https://www.lairdubois.fr/ | grep -c 'Maintenance en cours'");
        guide_has("curl -sI https://www.lairdubois.fr/ | grep -iE '^(HTTP|retry-after)'");
        $curl = 'curl -sk --resolve www.lairdubois.fr:443:127.0.0.1';
        foreach (['/', '/creations/1.html', '/media/cache/resolve/600x600o/uploads/a.jpg'] as $path) {
            $headers = in_container($c, "{$curl} -o /dev/null -D - https://www.lairdubois.fr{$path} | grep -iE '^(HTTP|retry-after)'");
            expect(1 === preg_match('{^HTTP/\S+ 503}m', $headers) && 1 === preg_match('{^retry-after: 3600}mi', $headers), "{$path} in maintenance:\n{$headers}");
        }
        expect('1' === trim(in_container($c, "{$curl} https://www.lairdubois.fr/creations/1.html | grep -c 'Maintenance en cours'")), 'The page of maintenance is not served in 503.');
        expect('200' === trim(in_container($c, "{$curl} -o /dev/null -w '%{http_code}' https://www.lairdubois.fr/bundles/ladbcore/ladb/images/logo_big.png")), 'The logo of the page of maintenance is not served.');
        in_container($c, 'nginx -s stop');
    });
    $r->verified('Hub Mercure : le binaire', $where, static function () use ($c): void {
        in_container($c, 'mkdir -p ~/ladb-backup');
        server_run($c, 'curl -fsSL -o ~/ladb-backup/mercure_Linux_x86_64.tar.gz https://github.com/dunglas/mercure/releases/download/v1.1.5/mercure_Linux_x86_64.tar.gz');
        server_run($c, 'curl -fsSL -o ~/ladb-backup/mercure-checksums.txt https://github.com/dunglas/mercure/releases/download/v1.1.5/checksums.txt');
        $check = server_run($c, "(cd ~/ladb-backup && grep ' mercure_Linux_x86_64.tar.gz\$' mercure-checksums.txt | sha256sum -c)");
        expect(str_contains($check, 'mercure_Linux_x86_64.tar.gz: OK'), "sha256sum: {$check}");
        guide_has(MERCURE_SHA256);
        expect(str_contains(in_container($c, 'cat ~/ladb-backup/mercure-checksums.txt'), MERCURE_SHA256 . '  mercure_Linux_x86_64.tar.gz'), 'The checksums.txt of the release has not the digest of the guide.');
        server_run($c, 'sudo mkdir -p /opt/mercure');
        server_run($c, 'sudo tar -xzf ~/ladb-backup/mercure_Linux_x86_64.tar.gz -C /opt/mercure mercure Caddyfile');
        $files = server_run($c, 'ls /opt/mercure');
        expect(str_contains($files, 'Caddyfile') && str_contains($files, 'mercure'), "/opt/mercure: {$files}");
    });
    $r->verified('Hub Mercure : la configuration', $where, static function () use ($c): void {
        in_container($c, 'cp /rehearsal/mercure.env /etc/mercure.env');
        server_run($c, 'sudo chmod 600 /etc/mercure.env');
        expect('0' === trim(server_run($c, 'sudo grep -c THE_SECRET /etc/mercure.env', allowFailure: true)), 'THE_SECRET is still in /etc/mercure.env.');
        // The parser of EnvironmentFile= (that of environment.d, without systemd running): the
        // directives on their two lines, without the quotes
        in_container($c, 'apt-get install -qq systemd > /dev/null && mkdir -p /etc/environment.d && cp /etc/mercure.env /etc/environment.d/60-mercure.conf');
        $environment = in_container($c, '/usr/lib/systemd/user-environment-generators/30-systemd-environment-d-generator');
        in_container($c, 'rm /etc/environment.d/60-mercure.conf');
        // The generator writes the value escaped: \n is the newline in it
        expect(str_contains($environment, 'MERCURE_EXTRA_DIRECTIVES="write_timeout 0s\nresource_identifier https://www.lairdubois.fr/.well-known/mercure"'), "systemd does not read MERCURE_EXTRA_DIRECTIVES on two lines:\n{$environment}");
    });
    $r->verified('Hub Mercure : le service', $where, static function () use ($c): void {
        expect('' === trim(server_run($c, "sudo ss -ltnp | grep -E ':(3000|2019)\\b'", allowFailure: true)), 'The ports of the hub are taken.');
        install_units($c);
        $verify = in_container($c, 'systemd-analyze verify /etc/systemd/system/ladb_mercure.service 2>&1', allowFailure: true);
        expect('' === trim($verify), "systemd-analyze verify ladb_mercure.service:\n{$verify}");
        // ExecStart of the unit, with its environment (EnvironmentFile, StateDirectory, User)
        in_container($c, 'mkdir -p /var/lib/ladb_mercure && chown www-data: /var/lib/ladb_mercure');
        // EnvironmentFile is read by root, the hub runs as www-data
        in_container($c, "cd /var/lib/ladb_mercure && bash -c 'set -a && . /etc/mercure.env && exec sudo --preserve-env --user=www-data /opt/mercure/mercure run --config /opt/mercure/Caddyfile' > /var/log/mercure.log 2>&1 &");
        $ready = '';
        for ($i = 0; $i < 30 && '200' !== $ready; ++$i) {
            sleep(1);
            $ready = trim(in_container($c, "curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:2019/mercure/health/ready", allowFailure: true));
        }
        guide_has('curl -i http://127.0.0.1:2019/mercure/health/ready');
        expect('200' === $ready, "The hub is not ready ({$ready}):\n" . in_container($c, 'tail -n 20 /var/log/mercure.log'));
        $anonymous = trim(in_container($c, "curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:3000/.well-known/mercure?match=https%3A%2F%2Flairdubois.fr%2Fworkflows%2F1'"));
        expect('401' === $anonymous, "The hub answers {$anonymous} to a subscription without JWT.");
    });
    $r->verified('Unités des nouveaux services', $where, static function () use ($c): void {
        install_units($c);
        $verify = server_run($c, 'sudo systemd-analyze verify /etc/systemd/system/ladb_*.service', allowFailure: true);
        expect('' === trim($verify), "systemd-analyze verify:\n{$verify}");
        // The units of master, kept by step 3: those the seds of step 42 change
        in_container($c, 'mkdir -p ~/ladb-backup && cp /rehearsal/units-master/ladb_consumer_view.service /rehearsal/units-master/ladb_consumer_webpush.service ~/ladb-backup/');
        $execStart = server_run($c, 'grep ExecStart ~/ladb-backup/ladb_consumer_view.service ~/ladb-backup/ladb_consumer_webpush.service');
        expect(str_contains($execStart, 'rabbitmq:consumer view') && str_contains($execStart, 'rabbitmq:consumer webpush_notification'), "The units of master:\n{$execStart}");
        guide_has('sudo rabbitmqctl list_permissions');
    });
    $r->verified('Workers', $where, static function () use ($c): void {
        // The units of master, changed by the guide: those of docs/01-installation.md
        in_container($c, 'cp /rehearsal/units-master/ladb_consumer_view.service /rehearsal/units-master/ladb_consumer_webpush.service /etc/systemd/system/');
        server_run($c, "sudo sed -i 's#rabbitmq:consumer view#messenger:consume view#' /etc/systemd/system/ladb_consumer_view.service");
        server_run($c, "sudo sed -i 's#rabbitmq:consumer webpush_notification#messenger:consume webpush_notification#' /etc/systemd/system/ladb_consumer_webpush.service");
        foreach (['ladb_consumer_view', 'ladb_consumer_webpush'] as $unit) {
            $diff = in_container($c, "diff /rehearsal/units/{$unit}.service /etc/systemd/system/{$unit}.service", allowFailure: true);
            expect('' === trim($diff), "{$unit}.service of master, changed by the guide, is not the one of docs/01-installation.md:\n{$diff}");
        }
        $verify = in_container($c, 'systemd-analyze verify /etc/systemd/system/ladb_*.service 2>&1', allowFailure: true);
        expect('' === trim($verify), "systemd-analyze verify:\n{$verify}");
    });
    $r->verified('Rouvrir le site', $where, static function () use ($c): void {
        foreach (['maintenanceStart.sh', 'maintenanceStop.sh'] as $script) {
            in_container($c, "bash -n /rehearsal/code/scripts/{$script}");
        }
    });
    if ('13' === $debian) {
        $r->verified('Dépendances PHP', $where, static function () use ($c): void {
            // The code delivered, as git leaves it, in a folder of www-data, with a /var/www of root
            in_container($c, 'rm -rf /var/www/www.lairdubois.fr && cp -a /rehearsal/code /var/www/www.lairdubois.fr && chown -R www-data: /var/www/www.lairdubois.fr && test "$(stat -c %U /var/www)" = root');
            $output = server_run($c, 'sudo --user=www-data env SYMFONY_ENV=prod composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=php+');
            expect(str_contains($output, 'Generating optimized autoload files'), "composer install:\n" . tail($output));
            expect(str_contains(in_container($c, 'ls /var/www/www.lairdubois.fr/vendor/symfony'), 'framework-bundle'), 'No vendor/.');
            expect(!str_contains(in_container($c, 'ls /var/www/www.lairdubois.fr/vendor/symfony'), 'web-profiler-bundle'), 'The dev packages are installed.');
        });
        $r->verified('Paramètres du code livré', $where, static function () use ($c): void {
            percent_checks($c);
        });
        $r->verified('CSS et JS', $where, static function () use ($c): void {
            server_run($c, 'sudo --user=www-data env npm_config_cache=var/npm npm ci --no-audit --no-fund');
            server_run($c, 'sudo --user=www-data env npm_config_cache=var/npm npx vite build');
            server_run($c, 'ls web/build/manifest.json web/build/entrypoints.json');
        });
    }
}

/**
 * Step 11: a DSN with an encoded user and password in parameters.yml, on the code delivered
 * installed by composer. With a single %, the check of the guide names the parameter and the
 * container does not compile; with %%, the check says nothing and the container reads a %.
 */
function percent_checks(string $c): void
{
    $file = '/var/www/www.lairdubois.fr/app/config/parameters.yml';
    $check = "sed 's/%%//g' app/config/parameters.yml | grep -n '%' | cut -d: -f1,2";
    // As at step 35: without cache (step 32), composer install compiles the container
    // (cache:clear without the warmers, that would connect to MariaDB)
    $clear = 'sudo --user=www-data rm -rf var/cache/prod && sudo --user=www-data bin/console --env=prod cache:clear --no-warmup';
    $set = static fn (string $dsn) => in_container($c, "sudo --user=www-data sed -i -E 's#^(\\s+mailer_dsn:\\s+).*#\\1{$dsn}#' {$file}");

    $set('smtp://us%2Fer:p%40ss@localhost:25');
    $found = server_run($c, $check, allowFailure: true);
    expect(1 === preg_match('{^\d+:\s+mailer_dsn$}', trim($found)), "The check does not name mailer_dsn:\n{$found}");
    $output = in_container($c, "cd /var/www/www.lairdubois.fr && {$clear}", allowFailure: true);
    // What is between the first two %: the name of a parameter, that does not exist
    expect(str_contains($output, 'non-existent parameter "2Fer:p"'), "The container compiles with a single %:\n" . tail($output));

    $set('smtp://us%%2Fer:p%%40ss@localhost:25');
    expect('' === trim(server_run($c, $check, allowFailure: true)), 'The check names a parameter written with %%.');
    in_container($c, "cd /var/www/www.lairdubois.fr && {$clear}");
    $dsn = in_container($c, 'cd /var/www/www.lairdubois.fr && sudo --user=www-data bin/console --env=prod debug:container --parameter=mailer_dsn');
    expect(str_contains($dsn, 'smtp://us%2Fer:p%40ss@localhost:25'), "The container does not read the DSN with a single %:\n{$dsn}");
    $set('smtp://localhost:25?auto_tls=false');
}

/**
 * The packages of Elasticsearch, as the guide changes them, from the 5.6 of the server to the 9
 * (steps 15, 16 and 27 to 31), then back to the 5.6 (R5), in a container started as their units
 * would: the 5.6 with its Java 8 in /etc/default/elasticsearch and a setting of systemd, and a
 * system account that takes the number of its user once the purge freed it.
 */
function elasticsearch_checks(Report $r): void
{
    $c = check_container('elasticsearch');
    $where = 'Debian 13';
    start_container($c, 'debian:13');
    $version = ELASTICSEARCH_56;

    $r->verified('Télécharger Elasticsearch 9', $where, static function () use ($c): void {
        in_container($c, 'mkdir -p ~/ladb-backup');
        server_run($c, 'curl -fsSL -o ~/ladb-backup/elasticsearch-9.5.5-amd64.deb https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-9.5.5-amd64.deb');
        server_run($c, 'curl -fsSL -o ~/ladb-backup/elasticsearch-9.5.5-amd64.deb.sha512 https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-9.5.5-amd64.deb.sha512');
        $check = server_run($c, '(cd ~/ladb-backup && sha512sum -c elasticsearch-9.5.5-amd64.deb.sha512)');
        expect(str_contains($check, 'elasticsearch-9.5.5-amd64.deb: OK'), "sha512sum: {$check}");
    });
    $r->verified('Télécharger Elasticsearch 5.6, pour le retour arrière', $where, static function () use ($c, $version): void {
        install_elasticsearch_56($c);
        expect($version === trim(server_run($c, "dpkg-query -W -f='\${Version}\\n' elasticsearch")), 'The 5.6 installed is not ' . $version . '.');
        server_run($c, "curl -fsSL -o ~/ladb-backup/elasticsearch-{$version}.deb https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-{$version}.deb");
        server_run($c, "curl -fsSL -o ~/ladb-backup/elasticsearch-{$version}.deb.sha512 https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-{$version}.deb.sha512");
        $check = server_run($c, "(cd ~/ladb-backup && echo \"\$(cat elasticsearch-{$version}.deb.sha512)  elasticsearch-{$version}.deb\" | sha512sum -c)");
        expect(str_contains($check, "elasticsearch-{$version}.deb: OK"), "sha512sum: {$check}");
        $java = server_run($c, 'grep -rs JAVA_HOME /etc/default/elasticsearch /etc/systemd/system/elasticsearch.service.d/');
        expect(str_contains($java, '/etc/default/elasticsearch:JAVA_HOME=/opt/java8'), "No JAVA_HOME:\n{$java}");
    });
    $r->verified('Elasticsearch 5.6 : l\'arrêter et le mettre de côté', $where, static function () use ($c): void {
        guide_has('sudo systemctl stop elasticsearch');
        stop_elasticsearch($c);
        server_run($c, 'sudo mv /var/lib/elasticsearch /var/lib/elasticsearch-5.6');
        server_run($c, 'sudo cp -a /etc/elasticsearch /etc/elasticsearch-5.6');
        server_run($c, 'sudo cp -a /etc/default/elasticsearch ~/ladb-backup/elasticsearch-5.6.default');
        server_run($c, 'ls -d /etc/systemd/system/elasticsearch.service.d');
        server_run($c, 'sudo mv /etc/systemd/system/elasticsearch.service.d ~/ladb-backup/elasticsearch-5.6.service.d');
        server_run($c, 'ls -d /var/lib/elasticsearch-5.6 /etc/elasticsearch-5.6 ~/ladb-backup/elasticsearch-5.6.default');
    });
    $r->verified('Elasticsearch 5.6 : le désinstaller', $where, static function () use ($c): void {
        $purge = server_run($c, 'sudo apt-get purge elasticsearch');
        // The file added by hand stays: without the rm -rf, the package 9 would start from it
        expect(str_contains(in_container($c, 'ls -A /etc/elasticsearch', allowFailure: true), 'ladb-local.yml'), "The purge removed /etc/elasticsearch:\n" . tail($purge));
        server_run($c, 'sudo rm -rf /etc/elasticsearch');
        expect('' === trim(server_run($c, "grep -rls 'packages/5.x' /etc/apt/sources.list /etc/apt/sources.list.d/", allowFailure: true)), 'A repository of the 5.6 is left.');
        expect('' === trim(in_container($c, 'ls -d /etc/elasticsearch 2> /dev/null; id elasticsearch 2> /dev/null', allowFailure: true)), 'The purge left /etc/elasticsearch or the user elasticsearch.');
        // A system account that takes the number the purge freed (without a group of its own:
        // adduser would look for a number free as a group too): the 5.6 of R5 gets another one
        in_container($c, 'adduser --quiet --system --no-create-home ladb-uid');
    });
    $r->verified('Elasticsearch 9 : l\'installer', $where, static function () use ($c): void {
        server_run($c, 'sudo apt-get install ~/ladb-backup/elasticsearch-9.5.5-amd64.deb');
        $version = in_container($c, '/usr/share/elasticsearch/bin/elasticsearch --version 2>&1');
        expect(str_contains($version, 'Version: 9.5.5'), "elasticsearch --version: {$version}");
    });
    $r->verified('Elasticsearch 9 : le configurer', $where, static function () use ($c): void {
        $yml = in_container($c, 'cat /etc/elasticsearch/elasticsearch.yml');
        expect(str_contains($yml, '#----------------------- BEGIN SECURITY AUTO CONFIGURATION -----------------------') && str_contains($yml, 'xpack.security.enabled: true'), 'The package did not write its block of security.');
        server_run($c, "sudo sed -i '/BEGIN SECURITY AUTO CONFIGURATION/,/END SECURITY AUTO CONFIGURATION/d' /etc/elasticsearch/elasticsearch.yml");
        server_run($c, "printf 'network.host: 127.0.0.1\\ndiscovery.type: single-node\\nxpack.security.enabled: false\\ningest.geoip.downloader.enabled: false\\n' | sudo tee -a /etc/elasticsearch/elasticsearch.yml");
        expect(str_contains(in_container($c, '/usr/share/elasticsearch/bin/elasticsearch-keystore list'), 'xpack.security.transport.ssl.keystore.secure_password'), 'The package did not put the passwords of its certificates in the keystore.');
        server_run($c, 'sudo /usr/share/elasticsearch/bin/elasticsearch-keystore remove xpack.security.http.ssl.keystore.secure_password xpack.security.transport.ssl.keystore.secure_password xpack.security.transport.ssl.truststore.secure_password');
        expect('0' === trim(server_run($c, 'sudo /usr/share/elasticsearch/bin/elasticsearch-keystore list | grep -c xpack.security', allowFailure: true)), 'The keystore still has passwords of xpack.security.');
        // The heap of the 5.6, 512 MB here
        $heap = server_run($c, "grep -E '^-Xm[sx]' /etc/elasticsearch-5.6/jvm.options");
        expect(str_contains($heap, '-Xms512m') && str_contains($heap, '-Xmx512m'), "The heap of the 5.6:\n{$heap}");
        guide_has("printf -- '-Xms1g\\n-Xmx1g\\n' | sudo tee /etc/elasticsearch/jvm.options.d/heap.options");
        in_container($c, "printf -- '-Xms512m\\n-Xmx512m\\n' | sudo tee /etc/elasticsearch/jvm.options.d/heap.options");
        expect('2' === trim(server_run($c, "grep -c -E '^(xpack\\.security\\.enabled: false|network\\.host: 127\\.0\\.0\\.1)$' /etc/elasticsearch/elasticsearch.yml")), 'elasticsearch.yml has not the lines of the guide.');
        expect('1' === trim(server_run($c, "grep -c 'xpack.security' /etc/elasticsearch/elasticsearch.yml")), 'elasticsearch.yml has other xpack.security lines.');
    });
    $r->verified('Elasticsearch 9 : le démarrer', $where, static function () use ($c): void {
        guide_has('sudo systemctl enable --now elasticsearch');
        // As its unit: the user elasticsearch, the environment of the unit
        in_container($c, 'mkdir -p /run/elasticsearch && chown elasticsearch: /run/elasticsearch && cd /usr/share/elasticsearch && sudo --user=elasticsearch env ES_HOME=/usr/share/elasticsearch ES_PATH_CONF=/etc/elasticsearch /usr/share/elasticsearch/bin/elasticsearch --daemonize --pidfile /run/elasticsearch/elasticsearch.pid > /dev/null');
        $number = wait_for_elasticsearch($c);
        expect(str_contains($number, '"number" : "9.5.5"'), 'Elasticsearch 9 does not answer without password: ' . in_container($c, 'tail -n 20 /var/log/elasticsearch/elasticsearch.log', allowFailure: true));
    });
    $r->verified('Dépôt d\'Elasticsearch 9', $where, static function () use ($c): void {
        server_run($c, 'wget -qO - https://artifacts.elastic.co/GPG-KEY-elasticsearch | sudo gpg --dearmor -o /usr/share/keyrings/elasticsearch-keyring.gpg');
        server_run($c, 'echo "deb [signed-by=/usr/share/keyrings/elasticsearch-keyring.gpg] https://artifacts.elastic.co/packages/9.x/apt stable main" | sudo tee /etc/apt/sources.list.d/elastic-9.x.list');
        server_run($c, 'sudo apt-get update');
        expect(str_contains(in_container($c, 'apt-cache policy elasticsearch'), 'artifacts.elastic.co/packages/9.x'), 'apt does not read the repository of Elasticsearch 9.');
    });
    $r->verified('Elasticsearch 5.6', $where, static function () use ($c, $version): void {
        stop_elasticsearch($c, '/run/elasticsearch/elasticsearch.pid');
        server_run($c, 'sudo apt-get purge elasticsearch');
        server_run($c, 'sudo rm -rf /var/lib/elasticsearch /etc/elasticsearch');
        server_run($c, "sudo apt-get install ~/ladb-backup/elasticsearch-{$version}.deb");
        server_run($c, 'sudo rm -rf /var/lib/elasticsearch /etc/elasticsearch');
        server_run($c, 'sudo mv /var/lib/elasticsearch-5.6 /var/lib/elasticsearch');
        server_run($c, 'sudo mv /etc/elasticsearch-5.6 /etc/elasticsearch');
        // The user of the 5.6 again, under another number: the folders are not its own
        $owners = trim(in_container($c, 'echo "$(id -u elasticsearch) $(stat -c %u /var/lib/elasticsearch)"'));
        [$user, $owner] = explode(' ', $owners);
        expect($user !== $owner, "The user elasticsearch has the number of before ({$owners}): the chown is not checked.");
        server_run($c, 'sudo chown -R elasticsearch:elasticsearch /var/lib/elasticsearch');
        server_run($c, 'sudo chown -R root:elasticsearch /etc/elasticsearch');
        server_run($c, 'sudo cp -a ~/ladb-backup/elasticsearch-5.6.default /etc/default/elasticsearch');
        server_run($c, 'sudo mv ~/ladb-backup/elasticsearch-5.6.service.d /etc/systemd/system/elasticsearch.service.d');
        guide_has('sudo systemctl daemon-reload');
        guide_has('sudo systemctl enable --now elasticsearch');
        start_elasticsearch_56($c);
        $number = wait_for_elasticsearch($c);
        expect(str_contains($number, "\"number\" : \"{$version}\""), "The 5.6 does not start again:\n" . in_container($c, 'tail -n 20 /var/log/elasticsearch/elasticsearch.log /var/log/elasticsearch-start.log', allowFailure: true));
        expect(str_contains(in_container($c, 'test -f /etc/systemd/system/elasticsearch.service.d/override.conf && echo kept'), 'kept'), 'The setting of systemd of the 5.6 is not back.');
        $indices = in_container($c, "curl -s 'http://127.0.0.1:9200/_cat/indices?v'");
        expect(1 === preg_match('{ladb_rehearsal\s+\S+\s+\d+\s+\d+\s+1\s}', $indices), "The 5.6 has not its index any more:\n{$indices}");
        guide_has("curl -s 'http://127.0.0.1:9200/_cat/indices?v'");
    });
}

/**
 * The Elasticsearch 5.6 of the server: its package, a Java 8 (Temurin, Debian has none) named by
 * /etc/default/elasticsearch, a setting of systemd, a file added by hand in its configuration,
 * its heap at 512 MB, started with an index of one document.
 */
function install_elasticsearch_56(string $c): void
{
    $version = ELASTICSEARCH_56;
    in_container($c, implode(' && ', [
        'mkdir -p /opt/java8',
        'curl -fsSL https://api.adoptium.net/v3/binary/latest/8/ga/linux/x64/jre/hotspot/normal/eclipse | tar -xz -C /opt/java8 --strip-components=1',
        "curl -fsSL -o /root/elasticsearch-{$version}.deb https://artifacts.elastic.co/downloads/elasticsearch/elasticsearch-{$version}.deb",
        "apt-get install -qq /root/elasticsearch-{$version}.deb > /dev/null",
        'echo JAVA_HOME=/opt/java8 >> /etc/default/elasticsearch',
        "sed -i -E 's/^-Xm([sx]).*/-Xm\\1512m/' /etc/elasticsearch/jvm.options",
        "mkdir -p /etc/systemd/system/elasticsearch.service.d && printf '[Service]\\nLimitMEMLOCK=infinity\\n' > /etc/systemd/system/elasticsearch.service.d/override.conf",
        // A file that is not one of the package, that its purge leaves
        "echo '# Added by hand' > /etc/elasticsearch/ladb-local.yml",
    ]));
    start_elasticsearch_56($c);
    $number = wait_for_elasticsearch($c);
    expect(str_contains($number, "\"number\" : \"{$version}\""), 'Elasticsearch 5.6 does not start: ' . in_container($c, 'tail -n 20 /var/log/elasticsearch-start.log', allowFailure: true));
    in_container($c, "curl -sf -XPUT -H 'Content-Type: application/json' http://127.0.0.1:9200/ladb_rehearsal/doc/1 -d '{\"title\": \"Une création\"}' > /dev/null && curl -sf -XPOST http://127.0.0.1:9200/ladb_rehearsal/_refresh > /dev/null");
}

/**
 * As the unit of the 5.6: its user, its folders, and /etc/default/elasticsearch (its Java).
 */
function start_elasticsearch_56(string $c): void
{
    in_container($c, implode(' && ', [
        'mkdir -p /var/run/elasticsearch && chown elasticsearch: /var/run/elasticsearch',
        'set -a && ES_HOME=/usr/share/elasticsearch CONF_DIR=/etc/elasticsearch DATA_DIR=/var/lib/elasticsearch LOG_DIR=/var/log/elasticsearch PID_DIR=/var/run/elasticsearch && . /etc/default/elasticsearch && set +a',
        'cd /usr/share/elasticsearch',
        'sudo --preserve-env --user=elasticsearch /usr/share/elasticsearch/bin/elasticsearch -d -p /var/run/elasticsearch/elasticsearch.pid -Edefault.path.logs=/var/log/elasticsearch -Edefault.path.data=/var/lib/elasticsearch -Edefault.path.conf=/etc/elasticsearch > /var/log/elasticsearch-start.log 2>&1',
    ]));
}

function stop_elasticsearch(string $c, string $pidFile = '/var/run/elasticsearch/elasticsearch.pid'): void
{
    in_container($c, "kill \$(cat {$pidFile}) && while curl -s -o /dev/null http://127.0.0.1:9200; do sleep 1; done");
}

/**
 * The line "number" of Elasticsearch, once it answers (2 minutes at most).
 */
function wait_for_elasticsearch(string $c): string
{
    $number = '';
    for ($i = 0; $i < 60 && '' === $number; ++$i) {
        sleep(2);
        $number = trim(in_container($c, "curl -s http://127.0.0.1:9200 | grep '\"number\"'", allowFailure: true));
    }

    return $number;
}

/**
 * The files the containers read: the code delivered (git archive of the HEAD), the vhost and the
 * units of master, the vhosts changed by the guide, the units and /etc/mercure.env of
 * docs/01-installation.md.
 */
function prepare_server_files(): void
{
    $dir = work_dir() . '/server';
    fs()->remove($dir);
    fs()->mkdir([$dir . '/code', $dir . '/units', $dir . '/units-master']);
    run(['sh', '-c', 'git archive HEAD | tar -x -C ' . escapeshellarg($dir . '/code')], context: context()->withQuiet());

    $show = static fn (string $path) => run(['git', 'show', MASTER_COMMIT . ':' . $path], context: context()->withQuiet())->getOutput();
    [$site, $http2, $maintenance] = vhost_edits();
    $vhost = $show('docs/nginx/conf/www.lairdubois.fr.conf');
    $maintenanceVhost = $show('docs/nginx/conf/www.lairdubois.fr-maintenance.conf');
    fs()->dumpFile($dir . '/vhost-master.conf', $vhost);
    fs()->dumpFile($dir . '/vhost-maintenance-master.conf', $maintenanceVhost);
    fs()->dumpFile($dir . '/vhost-delivery.conf', apply_edits($vhost, [...$site, $http2]));
    fs()->dumpFile($dir . '/vhost-delivery-1.22.conf', apply_edits($vhost, $site));
    fs()->dumpFile($dir . '/vhost-maintenance.conf', apply_edits($maintenanceVhost, [$http2, $maintenance]));
    fs()->dumpFile($dir . '/vhost-maintenance-1.22.conf', apply_edits($maintenanceVhost, [$maintenance]));

    foreach (units(installation()) as $unit => $contents) {
        fs()->dumpFile("{$dir}/units/{$unit}.service", $contents);
    }
    foreach (units($show(INSTALLATION)) as $unit => $contents) {
        fs()->dumpFile("{$dir}/units-master/{$unit}.service", $contents);
    }

    preg_match('{```\n(\# /etc/mercure\.env\n.*?)```}s', installation(), $matches) or throw new \LogicException('No /etc/mercure.env in ' . INSTALLATION . '.');
    fs()->dumpFile($dir . '/mercure.env', str_replace('THE_SECRET', base64_encode(random_bytes(36)), $matches[1]));
}

/**
 * The vhost and the page of maintenance of master, changed by the guide: those of the code
 * delivered, byte for byte.
 */
function check_vhost_edits(): void
{
    $dir = work_dir() . '/server';
    foreach (['vhost-delivery.conf' => 'www.lairdubois.fr.conf', 'vhost-maintenance.conf' => 'www.lairdubois.fr-maintenance.conf'] as $edited => $delivered) {
        expect(file_get_contents("{$dir}/{$edited}") === file_get_contents(\dirname(__DIR__) . "/docs/nginx/conf/{$delivered}"), "The {$delivered} of master, changed by the guide (step 14), is not the one of docs/nginx/conf/.");
    }
}

/**
 * The changes of the vhost in step 14 of the guide, its blocks "Avant" and "Après" in pairs: the
 * four of the site, the one of http2 (the site and the page of maintenance, nginx 1.25.1 or more),
 * and the one of the page of maintenance.
 *
 * @return array{list<array{string, string}>, array{string, string}, array{string, string}}
 */
function vhost_edits(): array
{
    preg_match_all('{```nginx\n(.*?)```}s', guide(), $matches);
    $blocks = $matches[1];
    expect(12 === \count($blocks), \count($blocks) . ' nginx blocks in the guide, not 12 (6 changes, before and after).');
    $edits = array_chunk($blocks, 2);

    return [\array_slice($edits, 0, 4), $edits[4], $edits[5]];
}

/**
 * @param list<array{string, string}> $edits
 */
function apply_edits(string $vhost, array $edits): string
{
    foreach ($edits as [$before, $after]) {
        $count = substr_count($vhost, $before);
        expect($count > 0, "The vhost of master has not:\n{$before}");
        $vhost = str_replace($before, $after, $vhost);
    }

    return $vhost;
}

/**
 * The units of systemd of an installation guide: the block after the nano of each file.
 *
 * @return array<string, string>
 */
function units(string $installation): array
{
    preg_match_all('{sudo nano /etc/systemd/system/(\w+)\.service\n```\n.*?```\n(.*?)```}s', $installation, $matches, \PREG_SET_ORDER);
    $units = [];
    foreach ($matches as [, $unit, $contents]) {
        $units[$unit] = $contents;
    }

    return $units;
}

function install_units(string $c): void
{
    in_container($c, implode(' && ', [
        'mkdir -p /var/www/www.lairdubois.fr',
        'cp /rehearsal/units/*.service /etc/systemd/system/',
    ]));
}

function check_container(string $what): string
{
    return project_name() . '-check-' . $what;
}

function start_container(string $name, string $image): void
{
    run(['docker', 'rm', '--force', $name], context: context()->withQuiet()->withAllowFailure());
    run(['docker', 'run', '--detach', '--name', $name, '--label', 'lairdubois.delivery-rehearsal=' . project_name(), '--volume', work_dir() . '/server:/rehearsal:ro', $image, 'sleep', 'infinity'], context: context()->withQuiet());
    // apt-get answers yes, as the one who copies the commands: they run as written. IPv4 (the
    // bridge of Docker has no IPv6), and retries: the DNS of the host may miss a few queries
    in_container($name, implode(' && ', [
        "printf 'APT::Get::Assume-Yes \"true\";\\nAcquire::ForceIPv4 \"true\";\\nAcquire::Retries \"5\";\\n' > /etc/apt/apt.conf.d/90-rehearsal",
        'apt-get update -qq',
        'apt-get install -qq --no-install-recommends sudo ca-certificates curl wget gnupg openssl procps iproute2 adduser > /dev/null',
        'mkdir -p /var/www/www.lairdubois.fr',
    ]));
}

function remove_check_containers(): void
{
    $names = array_filter(explode("\n", trim(run(['docker', 'ps', '--all', '--quiet', '--filter', 'label=lairdubois.delivery-rehearsal=' . project_name()], context: context()->withQuiet())->getOutput())));
    if ($names) {
        run(['docker', 'rm', '--force', ...$names], context: context()->withQuiet());
    }
}

function in_container(string $name, string $command, bool $allowFailure = false): string
{
    // A download that fails on the network (the DNS of the host may miss a query) is tried again
    for ($attempt = 1; ; ++$attempt) {
        $process = run(['docker', 'exec', '--env', 'DEBIAN_FRONTEND=noninteractive', $name, 'bash', '-c', $command], context: context()->withQuiet()->withAllowFailure());
        if ($process->isSuccessful() || $attempt >= 3 || !preg_match('{Could not resolve|Temporary failure resolving|Failed to fetch|Unable to fetch|Connection timed out}', $process->getOutput() . $process->getErrorOutput())) {
            break;
        }
        sleep(5);
    }
    if (!$allowFailure && !$process->isSuccessful()) {
        throw new \RuntimeException("`{$command}` failed in {$name}:\n" . tail($process->getOutput() . $process->getErrorOutput()));
    }

    return $process->getOutput() . $process->getErrorOutput();
}

/**
 * A command of the guide (or of docs/01-installation.md), as written, in the folder of the site.
 */
function server_run(string $name, string $command, string $source = GUIDE, bool $allowFailure = false): string
{
    GUIDE === $source ? guide_has($command) : installation_has($command);

    return in_container($name, "cd /var/www/www.lairdubois.fr && {$command}", $allowFailure);
}
