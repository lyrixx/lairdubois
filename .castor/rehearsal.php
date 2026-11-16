<?php

namespace rehearsal;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function Castor\context;
use function Castor\fs;
use function Castor\http_client;
use function Castor\io;
use function Castor\run;
use function Castor\with;
use function context\create_default_context;
use function docker\build;
use function docker\docker_compose;
use function docker\docker_compose_run;
use function worktree\get_worktree_name;
use function worktree\get_worktree_ports;

// The code of the production server: master, as it was at the fork (its vhost, its units)
const MASTER_COMMIT = 'b8848c0c05c3ec80ee1968db9f66ea28e38a5b1f';

// Where the rehearsal starts: the last commit of next in Symfony 3.4, before the jump to 4.4
// (552ee45f). The same versions as master of Symfony, FOSUserBundle, Doctrine,
// doctrine/migrations, Swiftmailer, RabbitMqBundle and FOSElasticaBundle, the same mapping,
// migrations, sessions, queues and spool, plus Docker
const OLD_COMMIT = '479b63edd9e0b8e2d8d2ec8cc4e8dc335f519bb4';

const GUIDE = 'docs/04-delivery.md';
const INSTALLATION = 'docs/01-installation.md';

// The files of the rehearsal in the copy that runs it: the copy in 3.4 (a git worktree, with a
// Compose project and ports of its own), what the throwaway containers read, the state of a
// rehearsal kept by --keep, the report
const WORK_DIR = 'var/delivery-rehearsal';

// The parts of a rehearsal, in their order
const PARTS = ['server', 'setup', 'before', 'during', 'deploy', 'rollback', 'redeploy', 'after', 'late-rollback'];

// The hub of the stack signs with it (MERCURE_JWT_SECRET of docker-compose.yml)
const MERCURE_SECRET = 'lairdubois-dev-mercure-jwt-secret-not-secret';

// A browser, not a crawler (CrawlerDetect): its views are counted
const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0';

// Tables of entities that master removed from its mapping, as its migrations created them then
// (Version20160830143152, Version20180425123105, Version20210222141757, removed since), with their
// foreign keys: a database of the production may still have them. The migration of the delivery
// drops them (Version20261115140000), the join tables first
const ORPHAN_TABLES = [
    'tbl_find_content_event' => [
        'CREATE TABLE tbl_find_content_event (id INT NOT NULL, location VARCHAR(100) NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, start_at DATETIME NOT NULL, start_date DATE NOT NULL, start_time TIME DEFAULT NULL, end_at DATETIME NOT NULL, end_date DATE DEFAULT NULL, end_time TIME DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB',
        'ALTER TABLE tbl_find_content_event ADD CONSTRAINT FK_FD47A7DDBF396750 FOREIGN KEY (id) REFERENCES tbl_find_content (id) ON DELETE CASCADE',
    ],
    'tbl_find_content_event_picture' => [
        'CREATE TABLE tbl_find_content_event_picture (event_id INT NOT NULL, picture_id INT NOT NULL, INDEX IDX_DF7E8EE171F7E88B (event_id), INDEX IDX_DF7E8EE1EE45BDBF (picture_id), PRIMARY KEY(event_id, picture_id)) DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE = InnoDB',
        'ALTER TABLE tbl_find_content_event_picture ADD CONSTRAINT FK_DF7E8EE171F7E88B FOREIGN KEY (event_id) REFERENCES tbl_find_content_event (id) ON DELETE CASCADE',
        'ALTER TABLE tbl_find_content_event_picture ADD CONSTRAINT FK_DF7E8EE1EE45BDBF FOREIGN KEY (picture_id) REFERENCES tbl_core_picture (id) ON DELETE CASCADE',
    ],
    'tbl_knowledge2_book_review' => [
        'CREATE TABLE tbl_knowledge2_book_review (id INT AUTO_INCREMENT NOT NULL, book_id INT NOT NULL, user_id INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, score INT DEFAULT NULL, body LONGTEXT DEFAULT NULL, html_body LONGTEXT DEFAULT NULL, INDEX IDX_AF0F87E316A2B381 (book_id), INDEX IDX_AF0F87E3A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE UTF8_unicode_ci ENGINE = InnoDB',
        'ALTER TABLE tbl_knowledge2_book_review ADD CONSTRAINT FK_AF0F87E316A2B381 FOREIGN KEY (book_id) REFERENCES tbl_knowledge2_book (id)',
        'ALTER TABLE tbl_knowledge2_book_review ADD CONSTRAINT FK_AF0F87E3A76ED395 FOREIGN KEY (user_id) REFERENCES tbl_core_user (id)',
    ],
    'tbl_knowledge2_value_tool_identity' => [
        'CREATE TABLE tbl_knowledge2_value_tool_identity (id INT NOT NULL, data VARCHAR(100) NOT NULL, name VARCHAR(100) NOT NULL, is_product TINYINT(1) NOT NULL, productName VARCHAR(100) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB',
        'ALTER TABLE tbl_knowledge2_value_tool_identity ADD CONSTRAINT FK_5BFDAF3CBF396750 FOREIGN KEY (id) REFERENCES tbl_knowledge2_value (id) ON DELETE CASCADE',
    ],
    'tbl_knowledge2_tool_value_identity' => [
        'CREATE TABLE tbl_knowledge2_tool_value_identity (tool_id INT NOT NULL, toolidentity_id INT NOT NULL, INDEX IDX_F8C2FB638F7B22CC (tool_id), INDEX IDX_F8C2FB6311D8CC51 (toolidentity_id), PRIMARY KEY(tool_id, toolidentity_id)) DEFAULT CHARACTER SET UTF8 COLLATE `UTF8_unicode_ci` ENGINE = InnoDB',
        'ALTER TABLE tbl_knowledge2_tool_value_identity ADD CONSTRAINT FK_F8C2FB638F7B22CC FOREIGN KEY (tool_id) REFERENCES tbl_knowledge2_tool (id) ON DELETE CASCADE',
        'ALTER TABLE tbl_knowledge2_tool_value_identity ADD CONSTRAINT FK_F8C2FB6311D8CC51 FOREIGN KEY (toolidentity_id) REFERENCES tbl_knowledge2_value_tool_identity (id) ON DELETE CASCADE',
    ],
];

// The throwaway database and the folder of the copy of the code delivered (steps 20 and 21 of the
// guide)
const COPY_DATABASE = 'db_fr_lairdubois_www_livraison';
const COPY_DIR = 'ladb-livraison';

// The checks of parameters.yml of steps 9 and 11, by sudo: the file may be readable by www-data
// only (rehearsal_server.php plays them so)
const SECRET_CHECK = "sudo grep -E '^\\s*secret:' app/config/parameters.yml | LC_ALL=C grep -c -v -P '[^\\x00-\\x7F]'";
const PERCENT_CHECK = "sudo sed 's/%%//g' app/config/parameters.yml | grep -n '%' | cut -d: -f1,2";

// Step 37, with the cache of Composer that step 20 fills
const COMPOSER_INSTALL = 'sudo --user=www-data env COMPOSER_CACHE_DIR=var/composer SYMFONY_ENV=prod composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=php+';

// The workers and the crons, restarted after a cache:clear (steps 46 and 52)
const RESTART_WORKERS = 'sudo systemctl restart ladb_scheduler ladb_consumer_cron ladb_consumer_mailer ladb_consumer_view ladb_consumer_webpush';
const WORKER_SERVICES = ['scheduler', 'worker_cron', 'worker_consumer_mailer', 'worker_consumer_view', 'worker_consumer_webpush_notification'];

// The p256dh and auth keys of a browser (tests/Webpush/SubscriptionTest.php), for the
// subscriptions of the accounts, to the double "push"
const PUSH_SUBSCRIPTION_KEYS = [
    'p256dh' => 'BHRokM_VvfQj55aPbnoh2J8uvpHQs4SNdajH5XUTqOjf_rsMfUHHy5iAQOpnkcOJaUGdqc8EhgNvnsu3I2DNXcM',
    'auth' => 'PbPBY0VewTVZ-S6wnkqrOA',
];

/**
 * The delivery guide played from a copy in Symfony 3.4, with data like those of the production
 * server, up to the HEAD of this copy. Each command of the guide that runs here is written as in
 * the guide, which must have it: in the builder (the files of the site, bin/console as www-data),
 * or in the containers of MariaDB and RabbitMQ. What Docker does not play (packages, systemd,
 * nginx of the server) is checked in throwaway Debian containers (rehearsal_server.php).
 */
#[AsTask(description: 'Rehearses the delivery guide (docs/04-delivery.md), from a copy in Symfony 3.4 with data like the production ones up to the HEAD of this copy, then a table of the steps', namespace: 'qa', name: 'delivery-rehearsal')]
function rehearsal(
    #[AsOption(description: 'Keeps the stack and the copy in 3.4 at the end (castor qa:delivery-rehearsal-destroy removes them)')]
    bool $keep = false,
    #[AsOption(description: 'Starts from this part, on a stack kept by --keep: server, setup, before, during, deploy, rollback, redeploy, after or late-rollback')]
    ?string $from = null,
    #[AsOption(description: 'Stops after this part, and keeps the stack (server alone: the packages, systemd and nginx in the throwaway containers)')]
    ?string $to = null,
    #[AsOption(description: 'The version of MariaDB of the server: 11.8 on Debian 13, 10.11 on Debian 12')]
    string $mariadb = '11.8',
    #[AsOption(description: 'The version of RabbitMQ of the server: 4.0 on Debian 13, 3.10 on Debian 12')]
    string $rabbitmq = '4.0',
): int {
    $start = array_search($from ?? PARTS[0], PARTS, true);
    $end = array_search($to ?? PARTS[\count(PARTS) - 1], PARTS, true);
    if (false === $start || false === $end || $end < $start) {
        throw new \RuntimeException(\sprintf('Unknown parts "%s" to "%s": %s.', $from, $to, implode(', ', PARTS)));
    }
    $keep = $keep || null !== $to;

    // The commit delivered: the HEAD of this copy, its uncommitted changes aside
    $delivered = trim(run(['git', 'rev-parse', 'HEAD'], context: context()->withQuiet())->getOutput());

    if (0 === $start) {
        // Nothing of a former rehearsal
        destroy();
    }
    $report = Report::load($start > 0);
    // The one of the start for the next parts (--from)
    $report->state['mariadb'] ??= $mariadb;
    $report->state['rabbitmq'] ??= $rabbitmq;
    $report->save();
    $failed = false;
    try {
        foreach (\array_slice(PARTS, (int) $start, $end - $start + 1) as $part) {
            io()->title("Delivery rehearsal: {$part}");
            match ($part) {
                'server' => part_server($report),
                'setup' => part_setup($report),
                'before' => part_before($report, $delivered),
                'during' => part_during($report),
                'deploy' => part_deploy($report, $delivered),
                'rollback' => part_rollback($report),
                'redeploy' => part_redeploy($report, $delivered),
                'after' => part_after($report),
                'late-rollback' => part_late_rollback($report),
            };
            $report->save();
        }
    } catch (StopRehearsal) {
        $failed = true;
    } finally {
        $report->save();
        $report->render();
    }

    // A failed step keeps them too, to look at them
    $failed = $failed || $report->hasFailures();
    if ($failed || $keep) {
        io()->note('The stack and the copy in 3.4 are kept (' . tree_dir() . '): castor qa:delivery-rehearsal-destroy removes them.');
    } else {
        destroy();
    }

    return $failed ? 1 : 0;
}

#[AsTask(description: 'Removes the stack, the copy in 3.4 and the throwaway containers of the delivery rehearsal', namespace: 'qa', name: 'delivery-rehearsal-destroy')]
function destroy(): void
{
    io()->title('Removing the delivery rehearsal');

    $tree = tree_dir();
    if (is_dir($tree . '/infrastructure/docker')) {
        // The volumes the compose files of the copy do not declare any more (Elasticsearch 5.6
        // after the deployment) go with the project
        docker_compose(['down', '--remove-orphans', '--volumes', '--rmi=local'], c: stack()->withAllowFailure(), profiles: ['*']);
    }
    $project = project_name();
    foreach (['volume', 'network'] as $kind) {
        $names = array_filter(explode("\n", trim(run(['docker', $kind, 'ls', '-q', '--filter', "label=com.docker.compose.project={$project}"], context: context()->withQuiet())->getOutput())));
        if ($names) {
            run(['docker', $kind, 'rm', ...$names], context: context()->withQuiet()->withAllowFailure());
        }
    }
    remove_check_containers();

    if (is_dir($tree)) {
        run(['git', 'worktree', 'remove', '--force', $tree], context: context()->withAllowFailure());
        // Files written by the root of a container (backups of MariaDB) stay: git leaves the folder
        fs()->remove($tree);
    }
    fs()->remove(work_dir() . '/state.json');
}

/**
 * The stack in 3.4 and its data: a copy of the code at OLD_COMMIT, its own castor start
 * (fixtures, index of Elasticsearch 5.6, workers), then what the production server has and the
 * fixtures do not.
 */
function part_setup(Report $r): void
{
    $r->check('Copie en 3.4 (worktree git à ' . substr(OLD_COMMIT, 0, 8) . ', projet Compose ' . project_name() . ')', create_copy(...));
    // Its own tasks: those of the 3.4 (composer, schema, fixtures, index, workers)
    $r->check('Stack en 3.4 : castor start de la copie (fixtures, index Elasticsearch 5.6, workers)', static fn () => run(['castor', 'start'], context: context()->withWorkingDirectory(tree_dir())));
    $r->check('Paramètres de prod : secret, mails vers Mailpit (port 25, comme Postfix), Mastodon hors ligne', setup_parameters(...));
    $r->check('migration_versions de doctrine/migrations 1.8 : 51 lignes', setup_migration_versions(...));
    $r->check('Tables d\'entités retirées de master, créées comme à l\'époque, avec leurs clés étrangères', setup_orphan_tables(...));
    $r->check('Abonnements web push (format du JS, vers le service de push factice)', setup_subscriptions(...));
    $r->check('Comptes au hash sha512 de FOSUserBundle', static fn () => expect('4' === sql("SELECT COUNT(*) FROM tbl_core_user WHERE salt IS NOT NULL AND password NOT LIKE '\$2y\$%'"), 'The accounts of the fixtures are not hashed by FOSUserBundle.'));
    $r->check('Session et cookie « se souvenir de moi » ouverts par le formulaire', setup_session(...));
    $r->check('Spool de Swiftmailer non vide', setup_spool(...));
    $r->check('Messages dans view_queue et webpush_notification_queue (workers arrêtés)', setup_queues(...));
    $r->check('Fichiers dans uploads/ et web/media/', setup_media(...));
    $r->check('La 3.4 marche : connexion, pages, session écrite', check_old_site(...));
}

function part_before(Report $r, string $delivered): void
{
    $r->step('État du code', static function (): void {
        // On the host: the .git of the copy is a path of the host. The files of Docker (.home,
        // certificates) are not on the server; the binaries of Composer in bin/ are, that the
        // .gitignore of master does not ignore, as the files of master written below (a
        // rehearsal taken again with --from)
        guide_has("sudo --user=www-data git status --short | grep -v '^?? bin/'");
        $status = array_filter(
            explode("\n", trim(run(['git', 'status', '--short'], context: context()->withWorkingDirectory(tree_dir())->withQuiet())->getOutput())),
            static fn (string $line) => '' !== $line && !preg_match('{^\?\? (\.home/|infrastructure/docker/services/router/certs/|bin/|web/css/|web/js/|components/)}', $line),
        );
        expect([] === $status, "The copy has changes:\n" . implode("\n", $status));
        guide_has('sudo --user=www-data git log -1 --format=%H');
        $head = trim(run(['git', 'log', '-1', '--format=%H'], context: context()->withWorkingDirectory(tree_dir())->withQuiet())->getOutput());
        expect(OLD_COMMIT === $head, "The copy is at {$head}.");
        // The files of master that the commit in 3.4 does not write (sensio/distribution-bundle,
        // Assetic, robloach/component-installer), ignored by the .gitignore of master: step 34
        // removes them
        in_builder('mkdir -p web/css web/js components/jquery && touch var/bootstrap.php.cache var/SymfonyRequirements.php web/css/base.css web/js/base.js components/jquery/jquery.js');
    });
    $r->step('État de la base', static function (): void {
        $output = play('sudo --user=www-data bin/console --env=prod doctrine:schema:update --dump-sql');
        expect(str_contains($output, 'Nothing to update'), "The schema of the database is not the one of the mapping:\n{$output}");
        check_complete_schema(play('sudo --user=www-data bin/console --env=prod doctrine:schema:update --complete --dump-sql'));
        $collations = play('sudo mariadb db_fr_lairdubois_www -e "SELECT c.table_name, c.column_name, c.collation_name, t.table_collation FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name WHERE c.table_schema = DATABASE() AND c.collation_name IS NOT NULL AND c.collation_name <> t.table_collation"', 'mariadb');
        expect('' === trim($collations), "Columns have another collation than their table:\n{$collations}");
        $output = play('sudo --user=www-data bin/console --env=prod doctrine:migrations:status');
        expect(1 === preg_match('{Executed Migrations:\s+51\b}', $output) && 1 === preg_match('{New Migrations:\s+0\b}', $output), "doctrine:migrations:status:\n{$output}");
        $output = play('sudo mariadb db_fr_lairdubois_www -e "SELECT COUNT(*), MIN(version), MAX(version) FROM migration_versions"', 'mariadb');
        expect(1 === preg_match('{51\s+20200323183209\s+20210515091743}', $output), "migration_versions:\n{$output}");
        play('sudo mariadb db_fr_lairdubois_www -e "SELECT table_name, table_rows, ROUND((data_length + index_length) / 1048576) AS mo FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY mo DESC LIMIT 15"', 'mariadb');
    });
    $r->step('Sauvegarder la configuration', static function (): void {
        // The files of the server that Docker has: parameters.yml. Neither crontab nor timedatectl
        play('mkdir -p ~/ladb-backup');
        guide_has('sudo cp -a app/config/parameters.yml /etc/nginx/sites-available/www.lairdubois.fr.conf /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf /etc/systemd/system/ladb_*.service ~/ladb-backup/');
        in_builder('cp -a app/config/parameters.yml ~/ladb-backup/');
        expect(is_file(backup_dir() . '/parameters.yml'), 'No parameters.yml in the backup.');
        foreach (['sudo crontab -u www-data -l > ~/ladb-backup/crontab-www-data', "sudo grep -rls 'bin/console' /etc/crontab /etc/cron.d/ /var/spool/cron/crontabs/", "grep -vE '^\\s*(#|$)' ~/ladb-backup/crontab-www-data | grep -v 'bin/console'", 'timedatectl show -p Timezone'] as $command) {
            guide_has($command);
        }
    });
    $r->step('Vérifier les données', static function (): void {
        $invalid = play('sudo mariadb db_fr_lairdubois_www -e "SELECT COUNT(*) FROM tbl_webpush_user_subscription WHERE NOT JSON_VALID(subscription)"', 'mariadb');
        expect(1 === preg_match('{^\D*0\s*$}', $invalid), "Invalid JSON in tbl_webpush_user_subscription:\n{$invalid}");
        $ascii = play(SECRET_CHECK, allowFailure: true);
        expect('1' === trim($ascii), "The secret is not in ASCII: {$ascii}");
    });
    $r->step('Préparer le DSN des mails', static function (): void {
        $output = play("sudo grep -E '^\\s*mailer_' app/config/parameters.yml");
        // smtp://localhost:25 in production, Mailpit instead of Postfix here
        foreach (['mailer_transport:\s+smtp', 'mailer_host:\s+mailpit', 'mailer_user:\s+null', 'mailer_password:\s+null'] as $expected) {
            expect(1 === preg_match("{{$expected}}", $output), "The mailer_* parameters are not those of a local SMTP:\n{$output}");
        }
        guide_has('`smtp://localhost:25?auto_tls=false`');
    });
    $r->step('Paramètres du code livré', static function (): void {
        add_delivery_parameters();
        $percents = play(PERCENT_CHECK, allowFailure: true);
        expect('' === trim($percents), "A parameter has a single %:\n{$percents}");
        check_old_site();
    });
    $r->step('Durée du cron le plus long', static function (): void {
        // The sitemaps of the 3.4, on the fixtures: a few seconds
        $output = play('time sudo --user=www-data bin/console --env=prod ladb:cron:sitemaps --force');
        expect(1 === preg_match('{real\s+(\d+)m([\d.]+)s}', $output, $matches), "No duration:\n{$output}");
        io()->text("The sitemaps of the 3.4: {$matches[1]} min {$matches[2]} s.");
        // The setting anyway, read when RabbitMQ starts again (step 27). /etc/rabbitmq/rabbitmq.conf
        // is a file of the copy (write_override()): it stays when Compose creates the container
        // again. It belongs to rabbitmq, as its folder: in a sticky folder open to all, the kernel
        // refuses to root to write in a file of another user (fs.protected_regular). Once only, for
        // a rehearsal taken again with --from
        in_service('rabbitmq', 'chown rabbitmq:rabbitmq /etc/rabbitmq/rabbitmq.conf', quiet: true);
        if (!str_contains(file_get_contents(tree_dir() . '/var/rabbitmq.conf'), 'consumer_timeout')) {
            play("echo 'consumer_timeout = 14400000' | sudo tee -a /etc/rabbitmq/rabbitmq.conf", 'rabbitmq');
        }
        expect(str_contains(file_get_contents(tree_dir() . '/var/rabbitmq.conf'), 'consumer_timeout = 14400000'), 'The setting is not in rabbitmq.conf.');
    });
    $r->step('Sauvegarder la base', static function (): void {
        play('sudo mariadb-dump --single-transaction --quick --routines db_fr_lairdubois_www | gzip > ~/ladb-backup/db.sql.gz', 'mariadb');
        expect(str_contains(play('zcat ~/ladb-backup/db.sql.gz | tail -1'), '-- Dump completed on'), 'The dump of the database is not complete.');
    });
    $r->step('Sauvegarder le code de la 3.4', static function (): void {
        play('sudo tar -czf ~/ladb-backup/code-3.4.tar.gz --ignore-failed-read vendor web/bundles web/css web/js components app/config/parameters.yml var/bootstrap.php.cache');
        expect('1' === trim(play("tar -tzf ~/ladb-backup/code-3.4.tar.gz | grep -c '^vendor/autoload.php$'")), 'vendor/ is not in the backup of the code.');
    });
    $r->step('Installer le code livré dans un dossier à part', static fn () => install_a_copy($r, $delivered));
    $r->step('Rejouer les migrations sur une copie de la base', rehearse_migrations_on_a_copy(...));
    $r->check('La 3.4 marche après la phase 1 : connexion, pages, session écrite', check_old_site(...));
    // The last one before the maintenance, site open
    $r->step('Sauvegarder la base, le jour même', static function (): void {
        play('sudo mariadb-dump --single-transaction --quick --routines db_fr_lairdubois_www | gzip > ~/ladb-backup/db-avant-maintenance.sql.gz', 'mariadb');
        expect(str_contains(play('zcat ~/ladb-backup/db-avant-maintenance.sql.gz | tail -1'), '-- Dump completed on'), 'The dump of the database is not complete.');
    });
}

/**
 * The output of doctrine:schema:update --complete --dump-sql of the 3.4: migration_versions, out
 * of its mapping (DoctrineBundle leaves aside the table of PdoSessionHandler), and the tables of
 * the entities removed by master that the migration of the delivery drops, with the foreign keys
 * that reference them. Nothing else.
 */
function check_complete_schema(string $output): void
{
    $dropped = [];
    $unexpected = [];
    foreach (array_filter(array_map('trim', explode("\n", $output)), static fn (string $line) => str_ends_with($line, ';')) as $statement) {
        if (preg_match('{^DROP TABLE (\w+);$}', $statement, $matches)) {
            $dropped[] = $matches[1];
            if (!\in_array($matches[1], ['migration_versions', ...delivery_dropped_tables()], true)) {
                $unexpected[] = $statement;
            }
        } elseif (!preg_match('{^ALTER TABLE (\w+) DROP FOREIGN KEY \w+;$}', $statement, $matches) || !\in_array($matches[1], delivery_dropped_tables(), true)) {
            $unexpected[] = $statement;
        }
    }
    expect([] === $unexpected, "doctrine:schema:update --complete has more than the tables out of the mapping:\n" . implode("\n", $unexpected) . "\n\n{$output}");
    sort($dropped);
    $expected = ['migration_versions', ...orphan_tables()];
    sort($expected);
    expect($expected === $dropped, "doctrine:schema:update --complete drops:\n" . implode("\n", $dropped));
}

/**
 * The tables that the migration of the delivery drops, if the database has them.
 *
 * @return list<string>
 */
function delivery_dropped_tables(): array
{
    $migration = file_get_contents(\dirname(__DIR__) . '/app/DoctrineMigrations/Version20261115140000.php');
    preg_match('{const TABLES = \[(.*?)\];}s', $migration, $matches) or throw new \LogicException('No TABLES in Version20261115140000.');
    preg_match_all("{'(\w+)'}", $matches[1], $tables);

    return $tables[1];
}

/**
 * Step 20 of the guide: a copy of the code delivered (git archive), installed by its own composer
 * and npm, with the caches of var/ of the site that steps 37 and 38 take again. They start empty:
 * the timings are those of a cutover without them.
 */
function install_a_copy(Report $r, string $delivered): void
{
    // /var/www/ladb-livraison in the builder: the commit delivered, by git archive on the host
    foreach (['sudo mkdir /var/www/ladb-livraison', 'sudo chown www-data: /var/www/ladb-livraison', 'sudo --user=www-data git archive origin/master | sudo --user=www-data tar -x -C /var/www/ladb-livraison'] as $command) {
        guide_has($command);
    }
    $copy = tree_dir() . '/' . COPY_DIR;
    fs()->remove([$copy, tree_dir() . '/var/composer', tree_dir() . '/var/npm']);
    fs()->mkdir($copy);
    run(['sh', '-c', 'git archive ' . escapeshellarg($delivered) . ' | tar -x -C ' . escapeshellarg($copy)], context: context()->withQuiet());
    play("sudo sed 's/^\\(\\s*database_name:\\s*\\).*/\\1db_fr_lairdubois_www_livraison/' app/config/parameters.yml | sudo --user=www-data tee /var/www/ladb-livraison/app/config/parameters.yml > /dev/null");
    // The variables of the builder for parameters.yml go, those of the code delivered: its
    // DATABASE_NAME would point the copy to the database of the 3.4. The builder of the 3.4 has
    // not the extension amqp (php-amqplib), that the server has (P2), nor the migrations need
    $r->state['composer_cold'] = timed(static fn () => play('sudo --user=www-data env COMPOSER_CACHE_DIR=/var/www/www.lairdubois.fr/var/composer SYMFONY_ENV=prod php8.5 "$(command -v composer)" --working-dir=/var/www/ladb-livraison install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=php+', prefix: php85() . unset_parameter_variables($copy . '/composer.json'), suffix: ' --ignore-platform-req=ext-amqp'));
    expect(1 === preg_match('{^\s+database_name:\s+db_fr_lairdubois_www_livraison$}m', file_get_contents($copy . '/app/config/parameters.yml')), 'The copy does not use the throwaway database.');
    $r->state['npm_cold'] = timed(static fn () => play('(cd /var/www/ladb-livraison && sudo --user=www-data env npm_config_cache=/var/www/www.lairdubois.fr/var/npm npm ci --no-audit --no-fund)'));
    play('(cd /var/www/ladb-livraison && sudo --user=www-data env npm_config_cache=/var/www/www.lairdubois.fr/var/npm npx vite build)');
    guide_has('ls /var/www/ladb-livraison/web/build/manifest.json');
    expect(is_file($copy . '/web/build/manifest.json'), 'The copy has no web/build/manifest.json.');
    $r->state['cache_sizes'] = trim(play('sudo du -sh var/composer var/npm'));
    io()->text(\sprintf('composer install in %s, npm ci in %s, with empty caches. Caches: %s', format_duration($r->state['composer_cold']), format_duration($r->state['npm_cold']), str_replace("\n", ', ', $r->state['cache_sizes'])));
}

/**
 * Step 21 of the guide: the backup of the database in a throwaway one, migrated by the copy of
 * step 20, then both removed. The database of the 3.4 and its site do not change.
 */
function rehearse_migrations_on_a_copy(): void
{
    // Nothing of a rehearsal taken again with --from
    sql('DROP DATABASE IF EXISTS ' . COPY_DATABASE);
    play('sudo mariadb -e "CREATE DATABASE db_fr_lairdubois_www_livraison"', 'mariadb');
    play('zcat ~/ladb-backup/db.sql.gz | sudo mariadb db_fr_lairdubois_www_livraison', 'mariadb');
    expect(\count(ORPHAN_TABLES) === \count(orphan_tables(COPY_DATABASE)), 'The throwaway database has not the tables of the backup.');
    $copy = tree_dir() . '/' . COPY_DIR;
    $prefix = php85();

    $output = play('time sudo --user=www-data php8.5 /var/www/ladb-livraison/bin/console --env=prod doctrine:migrations:migrate --no-interaction', prefix: $prefix);
    preg_match_all('{Migrating up to (\S+)}', $output, $matches);
    preg_match('{real\s+(\d+)m([\d.]+)s}', $output, $duration);
    io()->text('Migrated up to ' . implode(', ', $matches[1]) . ' in ' . ($duration[1] ?? '?') . ' min ' . ($duration[2] ?? '?') . ' s.');
    expect(str_contains(play('sudo --user=www-data php8.5 /var/www/ladb-livraison/bin/console --env=prod doctrine:migrations:up-to-date', prefix: $prefix), 'Up-to-date'), 'The migrations of the copy are not up to date.');
    $output = play('sudo --user=www-data php8.5 /var/www/ladb-livraison/bin/console --env=prod doctrine:schema:update --dump-sql', prefix: $prefix);
    expect(str_contains($output, 'Nothing to update'), "The schema of the copy is not the one of the mapping:\n{$output}");
    expect([] === orphan_tables(COPY_DATABASE), 'The migration does not drop the tables of the entities removed by master: ' . implode(', ', orphan_tables(COPY_DATABASE)));
    $counts = sql('SELECT COUNT(*), SUM(executed_at IS NOT NULL) FROM ' . COPY_DATABASE . '.migration_versions');
    expect(1 === preg_match('{^55\s+4$}', $counts), "The copy has not the 4 migrations of the delivery: {$counts}");

    play('sudo mariadb -e "DROP DATABASE db_fr_lairdubois_www_livraison"', 'mariadb');
    guide_has('sudo rm -rf /var/www/ladb-livraison');
    fs()->remove($copy);
    $databases = play('sudo mariadb -e "SHOW DATABASES LIKE \'db_fr_lairdubois_www%\'"', 'mariadb');
    expect(!str_contains($databases, COPY_DATABASE), "The throwaway database is still there:\n{$databases}");

    // Nothing of the 3.4 changed: its migration_versions of doctrine/migrations 1.8, its tables
    expect('51' === sql('SELECT COUNT(*) FROM migration_versions') && '' === sql("SHOW COLUMNS FROM migration_versions LIKE 'executed_at'"), 'The migration_versions of the 3.4 changed.');
    expect(\count(ORPHAN_TABLES) === \count(orphan_tables()), 'The database of the 3.4 lost tables.');
}

/**
 * A php8.5 for the commands of the guide that name it: the PHP of the builder.
 */
function php85(): string
{
    return 'mkdir -p /tmp/rehearsal-bin && ln -sf "$(command -v php)" /tmp/rehearsal-bin/php8.5 && export PATH="/tmp/rehearsal-bin:$PATH" && ';
}

function part_during(Report $r): void
{
    $r->step('Vider le spool de Swiftmailer', static function (): void {
        $spooled = count_spool();
        $before = mailpit_total();
        play('sudo --user=www-data bin/console --env=prod swiftmailer:spool:send --recover-timeout=0');
        expect('0' === trim(play('find app/spool -type f | wc -l')), 'The spool is not empty.');
        wait_for(static fn () => mailpit_total() >= $before + $spooled, "Mailpit did not get the {$spooled} mails of the spool.");
        io()->text("{$spooled} mails sent to Mailpit.");
    });
    $r->step('Laisser les anciens consumers vider leurs files', static function (): void {
        // They run on the server: here they were stopped to fill their queues
        compose(['up', '--detach', '--no-build', 'worker_consumer_view', 'worker_consumer_webpush_notification']);
        wait_for(static function (): bool {
            $output = play('sudo rabbitmqctl list_queues name messages', 'rabbitmq', quiet: true);

            return 1 === preg_match('{^view_queue\s+0$}m', $output) && 1 === preg_match('{^webpush_notification_queue\s+0$}m', $output);
        }, 'The old consumers do not empty their queues.');
        // The notification of the like, sent by the old consumer to the service of the browser
        wait_for(static fn () => str_contains(compose(['logs', '--no-log-prefix', 'push']), 'POST /user'), 'The old consumer did not send the web push notification.');
    });
    $r->step('Arrêter les anciens consumers et le serveur websocket', static function (): void {
        guide_has('sudo systemctl stop ladb_consumer_view ladb_consumer_webpush');
        guide_has('sudo systemctl disable --now ladb_websocket');
        compose(['stop', 'worker_consumer_view', 'worker_consumer_webpush_notification', 'websocket']);
        expect('' === trim(compose(['ps', '--quiet', 'worker_consumer_view', 'worker_consumer_webpush_notification', 'websocket'])), 'An old consumer still runs.');
        // The consumer_timeout of step 17, read by RabbitMQ when it starts again
        guide_has('sudo systemctl restart rabbitmq-server');
        compose(['restart', 'rabbitmq']);
        wait_for(static fn () => in_service('rabbitmq', 'rabbitmq-diagnostics -q check_port_connectivity', quiet: true)->isSuccessful(), 'RabbitMQ does not start again.');
        $environment = play('sudo rabbitmq-diagnostics environment | grep consumer_timeout', 'rabbitmq');
        expect(str_contains($environment, '{consumer_timeout,14400000}'), "RabbitMQ has not the consumer_timeout of step 17:\n{$environment}");
    });
    $r->step('Sauvegarder les tables que les migrations modifient', static function (): void {
        play('sudo mariadb-dump --single-transaction db_fr_lairdubois_www migration_versions ext_log_entries tbl_webpush_user_subscription | gzip > ~/ladb-backup/db-migrations.sql.gz', 'mariadb');
        expect(str_contains(play('zcat ~/ladb-backup/db-migrations.sql.gz | tail -1'), '-- Dump completed on'), 'The dump of the tables is not complete.');
    });
    $r->step('Elasticsearch 5.6 : l\'arrêter et le mettre de côté', static function (): void {
        guide_has('sudo systemctl stop elasticsearch');
        // Its volume stays aside, as /var/lib/elasticsearch-5.6
        compose(['stop', 'elasticsearch']);
        expect(str_contains(run(['docker', 'volume', 'ls', '-q'], context: context()->withQuiet())->getOutput(), project_name() . '_elasticsearch-data'), 'The volume of Elasticsearch 5.6 is gone.');
    });
    $r->step('Supprimer ce que le code livré n\'utilise plus', static function (): void {
        play('sudo rm -rf var/bootstrap.php.cache var/SymfonyRequirements.php web/css web/js components var/cache/prod var/cache/dev');
        expect(!file_exists(tree_dir() . '/var/cache/prod') && !file_exists(tree_dir() . '/web/css') && !file_exists(tree_dir() . '/components'), 'A file of the 3.4 is still there.');
    });
}

/**
 * The code delivered, from the deployment to the reopening, then a login before the reopening.
 */
function part_deploy(Report $r, string $delivered): void
{
    deploy($r, $delivered);
    $s = $r->state;
    $time = static fn (string $key) => isset($s[$key]) ? format_duration($s[$key]) : '?';
    $label = \sprintf("Caches de l'étape 20 : composer install en %s au lieu de %s, npm ci en %s au lieu de %s (caches vides) ; caches : %s", $time('composer_warm'), $time('composer_cold'), $time('npm_warm'), $time('npm_cold'), str_replace("\n", ', ', $s['cache_sizes'] ?? '?'));
    $r->check($label, static function () use ($s): void {
        expect(isset($s['composer_cold'], $s['composer_warm'], $s['npm_cold'], $s['npm_warm']), 'No timings of composer install and npm ci (steps 20, 37 and 38).');
    }, strict: false);
    $r->check('Avant la réouverture : connexion au code livré d\'un compte au hash sha512 (user2), refait en bcrypt', static function (): void {
        $browser = new Browser();
        expect($browser->login('user2'), 'user2 does not log in.');
        expect(1 === preg_match('{^\$2y\$\S+\s+NULL$}', sql("SELECT password, IFNULL(salt, 'NULL') FROM tbl_core_user WHERE username = 'user2'")), 'The password of user2 is not hashed again by the hasher native.');
    });
}

/**
 * From the end of the maintenance back to the 3.4 (R2 to R6 of the guide), on the migrated
 * database: the copy at OLD_COMMIT, the code of the backup, the stack in 3.4 with its index.
 */
function part_rollback(Report $r): void
{
    rollback_code_and_index($r);
    $r->step('La base', static function (): void {
        rollback_database();
        // The 3.4 on the migrated database, its consumers and its websocket
        compose(['up', '--detach', '--no-build', 'worker_consumer_view', 'worker_consumer_webpush_notification', 'websocket']);
        check_old_site('user');
        check_search();
    });
    $r->check('La 3.4 refuse le compte refait en bcrypt par le code livré (user2) : le point de non-retour', static function (): void {
        expect(!(new Browser())->login('user2'), 'user2 logs in to the 3.4 with a password hashed by the code delivered.');
    });
    $r->check('Retour à l\'état de la maintenance : anciens consumers arrêtés, Elasticsearch 5.6 arrêté', static function (): void {
        compose(['stop', 'worker_consumer_view', 'worker_consumer_webpush_notification', 'websocket', 'elasticsearch']);
        play('sudo rm -rf var/bootstrap.php.cache var/SymfonyRequirements.php web/css web/js components var/cache/prod var/cache/dev');
    });
}

/**
 * The rollback after the reopening ("Après la réouverture" of the guide), on the stack after the
 * checks of phase 3: the old queues and exchanges deleted (step 50), the caches of the code
 * delivered cleared (step 52), every account of the fixtures logged in to the code delivered. The
 * maintenance, R2 to R6, the index rebuilt by the 3.4, then its consumers and a password reset.
 */
function part_late_rollback(Report $r): void
{
    $r->check('Après la réouverture : repasser en maintenance (maintenanceStart.sh : arrêt des workers et des crons)', static function (): void {
        guide_has('sudo scripts/maintenanceStart.sh');
        compose(['stop', ...WORKER_SERVICES]);
    });
    rollback_code_and_index($r);
    $r->step('La base', rollback_database(...));
    $r->check('Après la réouverture : l\'index de la 5.6 reconstruit par la 3.4 (fos:elastica:populate)', static function (): void {
        play('sudo --user=www-data bin/console --env=prod fos:elastica:populate');
        $indices = in_builder("curl -s 'http://elasticsearch:9200/_cat/indices?v'", quiet: true)->getOutput();
        io()->text($indices);
        check_search();
    });
    $r->check('Après la réouverture : les anciens consumers recréent les files et les échanges supprimés à l\'étape 50 ; une vue comptée', static function (): void {
        compose(['up', '--detach', '--no-build', 'worker_consumer_view', 'worker_consumer_webpush_notification', 'websocket']);
        // Each queue with its consumer
        wait_for(static function (): bool {
            $queues = in_service('rabbitmq', 'rabbitmqctl -q list_queues name consumers', quiet: true)->getOutput();

            return 1 === preg_match('{^view_queue\s+1$}m', $queues) && 1 === preg_match('{^webpush_notification_queue\s+1$}m', $queues);
        }, 'The old consumers do not create their queues again.');
        $queues = play('sudo rabbitmqctl list_queues name', 'rabbitmq', quiet: true);
        expect(1 === preg_match('{^view_queue$}m', $queues) && 1 === preg_match('{^webpush_notification_queue$}m', $queues), "The old queues are not listed:\n{$queues}");
        $exchanges = play('sudo rabbitmqctl list_exchanges name', 'rabbitmq', quiet: true);
        expect(1 === preg_match('{^view_exchange$}m', $exchanges) && 1 === preg_match('{^webpush_notification_exchange$}m', $exchanges), "The old exchanges are not created again:\n{$exchanges}");
        // A new anonymous session: its first view of the creation is counted by the old consumer
        $before = (int) sql('SELECT view_count FROM tbl_wonder_creation WHERE id = 1');
        expect(200 === (new Browser())->request('GET', '/creations/1.html')->getStatusCode(), 'The creation does not answer 200 in the 3.4.');
        wait_for(static fn () => (int) sql('SELECT view_count FROM tbl_wonder_creation WHERE id = 1') > $before, 'The view is not counted by the old consumer.');
    });
    $r->check('Après la réouverture : un compte connecté au code livré (user) refusé par la 3.4, puis « mot de passe oublié »', static function (): void {
        expect(!(new Browser())->login('user'), 'user logs in to the 3.4 with a password hashed by the code delivered.');
        reset_old_password('user');
        check_old_site('user');
    });
}

/**
 * R2 and R5 of the guide in Docker: the copy at OLD_COMMIT, the code of the backup, the stack in
 * 3.4 with the index of Elasticsearch 5.6 that its volume kept.
 */
function rollback_code_and_index(Report $r): void
{
    $r->step('Le code de la 3.4', static function (): void {
        // R1, the services of the code delivered stopped (a rehearsal taken again may be past it)
        if (OLD_COMMIT !== tree_head()) {
            compose(['stop', ...WORKER_SERVICES]);
        }
        // git reset --hard on the branch of the server, a commit of the copy here
        guide_has('sudo --user=www-data git reset --hard b8848c0c05c3ec80ee1968db9f66ea28e38a5b1f');
        run(['git', 'checkout', '--detach', OLD_COMMIT], context: context()->withWorkingDirectory(tree_dir()));
        // The compose files of the 3.4: its additions, before any command of Compose
        write_override(delivered: false);
        play('sudo rm -rf vendor web/bundles var/cache/prod var/cache/dev');
        play('sudo tar -xzf ~/ladb-backup/code-3.4.tar.gz');
        // The packages of the server stay those of the delivery: the images are not built again
        compose(['up', '--detach', '--no-build', '--remove-orphans', '--wait', ...default_services()]);
    });
    $r->step('Elasticsearch 5.6', static function (): void {
        // The service elasticsearch of the copy in 3.4: the image 5.6 and its volume, kept aside
        $output = in_builder('curl -s http://elasticsearch:9200', quiet: true)->getOutput();
        expect(str_contains($output, '"number" : "5.6.'), "Elasticsearch is not the 5.6 with its index:\n{$output}");
    });
}

/**
 * R6 of the guide: migration_versions as the 3.4 reads it, and the two other tables of step 28.
 */
function rollback_database(): void
{
    play('zcat ~/ladb-backup/db-migrations.sql.gz | sudo mariadb db_fr_lairdubois_www', 'mariadb');
    $status = play('sudo --user=www-data bin/console --env=prod doctrine:migrations:status');
    foreach (['Executed Migrations:\s+51\b', 'Executed Unavailable Migrations:\s+0\b', 'New Migrations:\s+0\b'] as $expected) {
        expect(1 === preg_match("{{$expected}}", $status), "doctrine:migrations:status of the 3.4:\n{$status}");
    }
}

/**
 * The search finds the creation 1 in the index, of the 3.4 or of the code delivered.
 */
function check_search(): void
{
    $page = (new Browser())->request('GET', '/creations/?q=' . rawurlencode(search_word()));
    expect(200 === $page->getStatusCode() && str_contains($page->getContent(false), '/creations/1'), 'The search does not find the creation 1.');
}

/**
 * "Mot de passe oublié" of the 3.4 (FOSUserBundle): the request, then the form of the token, that
 * the mail of the spool would give, with the username as password again (fixtures).
 */
function reset_old_password(string $username): void
{
    $browser = new Browser();
    $browser->request('GET', '/resetting/request');
    $response = $browser->request('POST', '/resetting/send-email', form: ['username' => $username]);
    expect(\in_array($response->getStatusCode(), [200, 302], true), "/resetting/send-email answers {$response->getStatusCode()}.");
    $token = sql(\sprintf("SELECT IFNULL(confirmation_token, '') FROM tbl_core_user WHERE username = '%s'", addslashes($username)));
    expect('' !== $token, "No token of password reset for {$username}.");
    $page = $browser->request('GET', '/resetting/reset/' . $token);
    expect(200 === $page->getStatusCode(), "/resetting/reset answers {$page->getStatusCode()}.");
    preg_match('{<form[^>]*action="([^"]*/resetting/reset/[^"]*)"[^>]*>(.*?)</form>}s', $page->getContent(false), $form) or throw new \RuntimeException('No form of password reset.');
    $fields = [];
    preg_match_all('{<input\b[^>]*>}', $form[2], $inputs);
    foreach ($inputs[0] as $input) {
        if (preg_match('{name="([^"]+)"}', $input, $name)) {
            preg_match('{value="([^"]*)"}', $input, $value);
            $fields[html_entity_decode($name[1])] = str_contains($name[1], 'plainPassword') ? $username : html_entity_decode($value[1] ?? '');
        }
    }
    $response = $browser->request('POST', parse_url(html_entity_decode($form[1]), \PHP_URL_PATH), form: $fields);
    expect(302 === $response->getStatusCode(), "The reset of the password answers {$response->getStatusCode()}: " . implode(', ', array_keys($fields)));
}

/**
 * The code delivered again, after the rollback: the steps of the maintenance from the
 * deployment, on the database that the rollback gave back to the 3.4.
 */
function part_redeploy(Report $r, string $delivered): void
{
    deploy($r, $delivered);
}

/**
 * The steps of the maintenance from the deployment of the code to the reopening of the site.
 */
function deploy(Report $r, string $delivered): void
{
    $r->step('Déployer le code', static function () use ($delivered): void {
        guide_has('sudo --user=www-data git pull origin master');
        run(['git', 'checkout', '--detach', $delivered], context: context()->withWorkingDirectory(tree_dir()));
        $head = trim(run(['git', 'log', '-1', '--format=%H'], context: context()->withWorkingDirectory(tree_dir())->withQuiet())->getOutput());
        expect($delivered === $head, "The copy is at {$head}.");
        // The packages of the server: the images of the code delivered, Elasticsearch 9, the hub
        write_override(delivered: true);
        with(static fn () => build(), context: stack());
        compose(['up', '--detach', '--no-build', '--remove-orphans', '--wait', ...default_services()]);
    });
    $r->step('Dépendances PHP', static function () use ($r): void {
        // The variables of the builder for parameters.yml go: the server has none
        $output = '';
        $r->state['composer_warm'] = timed(static function () use (&$output): void {
            $output = play(COMPOSER_INSTALL, prefix: unset_parameter_variables());
        });
        expect('0' === trim(play("sudo grep -c -E '^\\s+(mailer_host|websocket_uri|ga_tracking_id|node):' app/config/parameters.yml", allowFailure: true)), 'An old parameter is still in parameters.yml.');
        // Everything from the cache of step 20
        preg_match_all('{^\s*- Downloading .*$}m', $output, $downloads);
        expect([] === $downloads[0], \count($downloads[0]) . " packages downloaded, not taken from the cache of step 20:\n" . implode("\n", \array_slice($downloads[0], 0, 10)));
        io()->text(substr_count($output, 'Installing') . ' packages installed from the cache in ' . format_duration($r->state['composer_warm']) . '.');
    });
    $r->step('CSS et JS', static function () use ($r): void {
        $output = '';
        // The requests of npm: each package from the cache of step 20, none to the registry
        $r->state['npm_warm'] = timed(static function () use (&$output): void {
            $output = play('sudo --user=www-data env npm_config_cache=var/npm npm ci --no-audit --no-fund', suffix: ' --loglevel=http');
        });
        // "npm http cache … (cache hit)" for a package of the cache, "npm http fetch …" for a request
        preg_match_all('{^npm http (?:cache|fetch) .*$}m', $output, $fetches);
        $network = preg_grep('{^npm http cache .*\(cache hit\)$}', $fetches[0], \PREG_GREP_INVERT);
        expect([] === $network, \count($network) . " requests of npm not answered by its cache:\n" . implode("\n", \array_slice($network, 0, 10)));
        $r->state['assets_warm'] = $r->state['npm_warm'] + timed(static fn () => play('sudo --user=www-data env npm_config_cache=var/npm npx vite build'));
        expect(is_file(tree_dir() . '/web/build/manifest.json') && is_file(tree_dir() . '/web/build/entrypoints.json'), 'No manifest.json or entrypoints.json in web/build.');
        io()->text(\count($fetches[0]) . ' packages of npm from the cache, npm ci in ' . format_duration($r->state['npm_warm']) . '.');
    });
    $r->step('Cache', static function (): void {
        play('sudo --user=www-data bin/console --env=prod cache:warmup');
        $output = play("sudo --user=www-data bin/console --env=prod about | grep -E 'Environment|Debug'");
        expect(1 === preg_match('{Environment\s+prod.*Debug\s+false}s', $output), "The kernel is not in prod without debug:\n{$output}");
    });
    $r->step('Table des sessions', static function (): void {
        play('sudo mariadb db_fr_lairdubois_www -e "DROP TABLE IF EXISTS sessions"', 'mariadb');
        play('sudo mariadb db_fr_lairdubois_www < docs/database/schema-sessions.sql', 'mariadb');
        $table = play('sudo mariadb db_fr_lairdubois_www -e "SHOW CREATE TABLE sessions\G"', 'mariadb');
        expect(str_contains($table, '`sess_lifetime` int(10) unsigned NOT NULL') && str_contains($table, 'KEY `sessions_sess_lifetime_idx`'), "The table sessions is not the new one:\n{$table}");
        expect('0' === sql('SELECT COUNT(*) FROM sessions'), 'The table sessions has rows.');
    });
    $r->step('Migrations', static function (): void {
        // The 4 of the delivery, the second time too: the rollback gave back migration_versions
        // to the 3.4 (R6)
        $output = play('sudo --user=www-data bin/console --env=prod doctrine:migrations:migrate --no-interaction');
        preg_match_all('{Migrating up to (\S+)}', $output, $matches);
        io()->text('Migrated up to ' . implode(', ', $matches[1]));
        expect(str_contains(play('sudo --user=www-data bin/console --env=prod doctrine:migrations:up-to-date'), 'Up-to-date'), 'The migrations are not up to date.');
        $counts = play('sudo mariadb db_fr_lairdubois_www -e "SELECT COUNT(*), SUM(executed_at IS NOT NULL) FROM migration_versions"', 'mariadb');
        expect(1 === preg_match('{^55\s+4$}m', $counts), "migration_versions:\n{$counts}");
        $new = sql('SELECT GROUP_CONCAT(version ORDER BY version) FROM migration_versions WHERE executed_at IS NOT NULL');
        expect('Application\Migrations\Version20261028193655,Application\Migrations\Version20261028235655,Application\Migrations\Version20261031093745,Application\Migrations\Version20261115140000' === $new, "The migrations played are not those of the delivery: {$new}");
        expect([] === orphan_tables(), 'The migration does not drop the tables of the entities removed by master: ' . implode(', ', orphan_tables()));
        $output = play('sudo --user=www-data bin/console --env=prod doctrine:schema:update --dump-sql');
        expect(str_contains($output, 'Nothing to update'), "The schema of the database is not the one of the mapping:\n{$output}");
    });
    $r->step('Mettre en place le vhost du code livré', static function (): void {
        guide_has('sudo cp -a /etc/nginx/sites-available/www.lairdubois.fr.conf.livraison /etc/nginx/sites-available/www.lairdubois.fr.conf');
        // The site of the image "nginx" of production (services/php/prod/nginx.conf, from
        // docs/nginx/conf/www.lairdubois.fr.conf) in the frontend: web/app.php, in prod
        write_override(delivered: true, productionVhost: true);
        compose(['up', '--detach', '--no-build', '--wait', 'frontend']);
        wait_for(static fn () => 200 === (new Browser())->request('GET', '/unsupported.html')->getStatusCode(), 'The router does not serve the frontend.');
    });
    $r->step('Index Elasticsearch', static function (): void {
        play('sudo --user=www-data bin/console --env=prod fos:elastica:populate');
        $output = in_builder("curl -s 'http://elasticsearch:9200/_cat/indices?v'", quiet: true)->getOutput();
        guide_has("curl -s 'http://127.0.0.1:9200/_cat/indices?v'");
        io()->text($output);
        expect(1 === preg_match('{ladb_wonder_creation\s+\S+\s+\d+\s+\d+\s+[1-9]\d*}', $output), "No document in the index of the creations:\n{$output}");
    });
    $r->step('Workers', static function (): void {
        guide_has('sudo systemctl enable --now ladb_consumer_mailer');
        guide_has('sudo systemctl start ladb_consumer_view ladb_consumer_webpush');
        compose(['up', '--detach', '--no-build', 'worker_consumer_mailer', 'worker_consumer_view', 'worker_consumer_webpush_notification']);
        expect(3 === \count(array_filter(explode("\n", trim(compose(['ps', '--quiet', '--status', 'running', 'worker_consumer_mailer', 'worker_consumer_view', 'worker_consumer_webpush_notification']))))), 'A worker does not run.');
    });
    $r->step('Crons', static function (): void {
        guide_has('sudo systemctl enable --now ladb_scheduler ladb_consumer_cron');
        compose(['up', '--detach', '--no-build', 'scheduler', 'worker_cron']);
        $output = play('sudo --user=www-data bin/console --env=prod debug:scheduler cron');
        expect(8 === preg_match_all('{ via cron\)}', $output), "debug:scheduler does not list the 8 crons:\n{$output}");
        // The 4 hours of step 17 for the worker of the crons, RabbitMQ created again or not
        $environment = in_service('rabbitmq', 'rabbitmq-diagnostics environment', quiet: true)->getOutput();
        expect(str_contains($environment, '{consumer_timeout,14400000}'), 'RabbitMQ lost the consumer_timeout of step 17.');
    });
    $r->step('Envoyer un mail de test', static function (): void {
        // To Mailpit, by the transport of mailer_dsn: signed with DKIM, as the worker sends them
        $command = "sudo --user=www-data bin/console --env=prod mailer:test --from=noreply@lairdubois.fr --subject='Livraison : mail de test' UNE_ADRESSE";
        guide_has($command);
        $before = mailpit_total();
        $output = in_builder(str_replace(['sudo --user=www-data ', 'UNE_ADRESSE'], ['', 'livraison@lairdubois.test'], $command));
        expect($output->isSuccessful(), "mailer:test failed:\n" . tail($output->getOutput() . $output->getErrorOutput()));
        wait_for(static fn () => mailpit_total() > $before, 'The mail of mailer:test is not in Mailpit.');
        $messages = mailpit('/api/v1/messages?limit=1');
        expect('Livraison : mail de test' === $messages['messages'][0]['Subject'], 'The last mail of Mailpit is not the one of mailer:test.');
        $headers = array_change_key_case(mailpit('/api/v1/message/' . $messages['messages'][0]['ID'] . '/headers'));
        expect(str_contains($headers['dkim-signature'][0] ?? '', 'd=lairdubois.fr'), 'The mail of mailer:test is not signed with DKIM.');
        foreach (["sudo grep 'status=sent' /var/log/mail.log | tail -n 3", 'sudo journalctl -t postfix/smtp -n 20'] as $command) {
            guide_has($command);
        }
    });
}

/**
 * After the reopening, on the migrated data: the checks of the guide, then what a member does.
 * A failed check does not stop the next ones.
 */
function part_after(Report $r): void
{
    $r->check('Ancienne session et ancien cookie « se souvenir de moi » : refusés, sans erreur', static function (): void {
        $old = Report::load(true)->state['cookies'] ?? [];
        expect(isset($old['PHPSESSID'], $old['REMEMBERME']), 'No session nor remember-me cookie of the 3.4 in the state of the rehearsal.');
        $browser = new Browser();
        $browser->withCookies($old);
        $response = $browser->request('GET', '/');
        $html = $response->getContent(false);
        expect(200 === $response->getStatusCode(), "The home page answers {$response->getStatusCode()} with the cookies of the 3.4.");
        expect(!str_contains($html, '/logout'), 'The member of the old session is logged in.');
        expect(!isset($browser->cookies()['REMEMBERME']), 'The old remember-me cookie is not removed.');
    }, strict: false);
    $r->check('Connexion d\'un compte au hash sha512 (user), refait en bcrypt ; session écrite dans la nouvelle table', static function (): void {
        $browser = new Browser();
        expect($browser->login('user'), 'user does not log in.');
        expect(1 === preg_match('{^\$2y\$\S+\s+NULL$}', sql("SELECT password, IFNULL(salt, 'NULL') FROM tbl_core_user WHERE username = 'user'")), 'The password of user is not hashed again.');
        $id = $browser->cookies()['LADBSESSID'] ?? '';
        expect('' !== $id, 'No LADBSESSID cookie.');
        expect('1' === sql(\sprintf("SELECT COUNT(*) FROM sessions WHERE sess_id = '%s' AND sess_lifetime > UNIX_TIMESTAMP()", addslashes($id))), 'The session is not written in the table sessions.');
    }, strict: false);
    $r->check('Pages en anonyme et connecté (prod, web/app.php)', static function (): void {
        $anonymous = new Browser();
        $user = new Browser();
        expect($user->login('admin'), 'admin does not log in.');
        $failures = [];
        foreach (['/', '/creations/', '/creations/1.html', '/plans/1.html', '/ateliers/1.html', '/questions/1.html', '/xylotheque/1.html', '/trouvailles/1.html', '/@user', '/blog/1.html'] as $path) {
            foreach (['anonymous' => $anonymous, 'admin' => $user] as $who => $browser) {
                $status = $browser->request('GET', $path)->getStatusCode();
                if (200 !== $status) {
                    $failures[] = "{$path} ({$who}): {$status}";
                }
            }
        }
        foreach (['/notifications/', '/messagerie/', '/processus/1.html?layout=workspace'] as $path) {
            $status = $user->request('GET', $path)->getStatusCode();
            if (200 !== $status) {
                $failures[] = "{$path} (admin): {$status}";
            }
        }
        expect([] === $failures, 'Pages not in 200: ' . implode(', ', $failures));
    }, strict: false);
    $r->check('Recherche dans l\'index Elasticsearch 9', check_search(...), strict: false);
    $r->step('Anciennes files et échanges de RabbitMQ', static function (): void {
        play('sudo rabbitmqctl delete_queue view_queue', 'rabbitmq');
        play('sudo rabbitmqctl delete_queue webpush_notification_queue', 'rabbitmq');
        $plugins = play("sudo rabbitmq-plugins list '^rabbitmq_management\$'", 'rabbitmq');
        $enabledBefore = 1 === preg_match('{\[E\*\] rabbitmq_management\b}', $plugins);
        if (!$enabledBefore) {
            play('sudo rabbitmq-plugins enable rabbitmq_management', 'rabbitmq');
            wait_for(static fn () => in_builder('curl -s -o /dev/null -w "%{http_code}" -u app:app http://rabbitmq:15672/api/overview', quiet: true)->getOutput() === '200', 'The API of management does not answer.');
        }
        // The account of the stack has the tag administrator (RABBITMQ_DEFAULT_USER)
        foreach (['view_exchange', 'webpush_notification_exchange'] as $exchange) {
            guide_has("curl -i -u UTILISATEUR:MOT_DE_PASSE -X DELETE http://127.0.0.1:15672/api/exchanges/%2F/{$exchange}");
            $status = '';
            // The API answers once the plugin has started its listener
            wait_for(static function () use ($exchange, &$status): bool {
                $status = trim(in_builder("curl -sS -o /dev/null -w '%{http_code}' -u app:app -X DELETE http://rabbitmq:15672/api/exchanges/%2F/{$exchange}", quiet: true)->getOutput());

                return '000' !== $status;
            }, "The API of management does not answer to the deletion of {$exchange}.");
            expect('204' === $status, "The deletion of {$exchange} answers {$status}.");
        }
        $queues = play('sudo rabbitmqctl list_queues name', 'rabbitmq', quiet: true);
        $exchanges = play('sudo rabbitmqctl list_exchanges name', 'rabbitmq', quiet: true);
        expect(0 === preg_match('{^(view_queue|webpush_notification_queue|view_exchange|webpush_notification_exchange)$}m', $queues . "\n" . $exchanges), "An old queue or exchange is still there:\n{$queues}\n{$exchanges}");
        // Back to its state before: off, when it was (the image rabbitmq:*-alpine)
        if (!$enabledBefore) {
            play('sudo rabbitmq-plugins disable rabbitmq_management', 'rabbitmq');
            $plugins = play("sudo rabbitmq-plugins list '^rabbitmq_management\$'", 'rabbitmq');
            expect(1 === preg_match('{\[  \] rabbitmq_management\b}', $plugins), "The plugin rabbitmq_management is still on:\n{$plugins}");
        }
    }, strict: false);
    // Before the checks of the workers: they pass by the workers started again
    $r->step('Chromium dans son bac à sable', check_sandbox_and_restart(...), strict: false);
    $r->check('Une vue comptée par le nouveau worker', static function (): void {
        $before = (int) sql('SELECT view_count FROM tbl_wonder_creation WHERE id = 1');
        $page = (new Browser())->request('GET', '/creations/1.html');
        expect(200 === $page->getStatusCode() && '' !== $page->getContent(false), 'The creation does not answer 200.');
        wait_for(static fn () => (int) sql('SELECT view_count FROM tbl_wonder_creation WHERE id = 1') > $before, 'The view is not counted by the worker "view".');
    }, strict: false);
    $r->check('Une notification web push envoyée à un ancien abonnement par le nouveau worker', static function (): void {
        $browser = new Browser();
        expect($browser->login('admin'), 'admin does not log in.');
        $before = substr_count(compose(['logs', '--no-log-prefix', 'push']), 'POST /user ');
        // A like of a plan of user: a notification to user, who is subscribed since the 3.4
        $like = $browser->request('GET', '/likes/105/1/create', ['X-Requested-With' => 'XMLHttpRequest']);
        expect(200 === $like->getStatusCode(), "The like answers {$like->getStatusCode()}.");
        wait_for(static fn () => substr_count(compose(['logs', '--no-log-prefix', 'push']), 'POST /user ') > $before, 'The worker webpush_notification sent nothing to the subscription of user.');
        // VAPID: "vapid t=…, k=…" (aes128gcm), or "WebPush <JWT>" with Crypto-Key (aesgcm, the encoding of
        // the subscriptions of the site, UserSubscription::getContentEncoding())
        $posts = array_values(preg_grep('{^POST /user }', explode("\n", compose(['logs', '--no-log-prefix', 'push']))));
        $last = $posts[\count($posts) - 1];
        io()->text($last);
        expect(1 === preg_match('{authorization=(vapid t=|WebPush ey)}', $last), 'The notification is not signed with VAPID.');
    }, strict: false);
    $r->check('Un mail envoyé par le nouveau worker mailer, signé DKIM', static function (): void {
        $before = mailpit_total();
        $browser = new Browser();
        $page = $browser->request('GET', '/resetting/request')->getContent(false);
        expect(1 === preg_match('{action="([^"]*/resetting/send-email)"}', $page), 'No form on /resetting/request.');
        // admin: user2 asked for a new password in the 3.4, the site sends one a day at most
        $response = $browser->request('POST', '/resetting/send-email', form: ['username' => 'admin']);
        expect(\in_array($response->getStatusCode(), [200, 302], true), "/resetting/send-email answers {$response->getStatusCode()}.");
        wait_for(static fn () => mailpit_total() > $before, 'No mail in Mailpit from the worker mailer.');
        $messages = mailpit('/api/v1/messages?limit=1');
        $headers = array_change_key_case(mailpit('/api/v1/message/' . $messages['messages'][0]['ID'] . '/headers'));
        expect(str_contains($headers['dkim-signature'][0] ?? '', 'd=lairdubois.fr'), 'The mail is not signed with DKIM.');
    }, strict: false);
    $r->check('Un cron lancé par le scheduler (ladb:cron:notification:populate, à la minute paire)', static function (): void {
        sql('DELETE FROM tbl_core_notification; UPDATE tbl_core_activity SET is_pending_notifications = 1');
        wait_for(static fn () => '0' === sql('SELECT COUNT(*) FROM tbl_core_activity WHERE is_pending_notifications = 1'), 'No notification:populate in 3 minutes: the scheduler or the worker cron does not run.', 180);
        expect('0' !== sql('SELECT COUNT(*) FROM tbl_core_notification'), 'notification:populate gave no notification.');
    }, strict: false);
    $r->check('Une modification dans l\'éditeur de processus publiée sur le hub Mercure', check_mercure(...), strict: false);
    $r->step('Messages non lus', static function (): void {
        play('sudo --user=www-data bin/console --env=prod ladb:reset:unreadmessagecount --force');
    }, strict: false);
    $r->verified('À savoir pour la suite', 'services Docker', check_cron_stop(...));
    $r->check('Aucune erreur 500 dans var/logs/prod.log', static function (): void {
        $log = tree_dir() . '/var/logs/prod.log';
        $lines = is_file($log) ? file($log) : [];
        // The crons of the scheduler may fail here (the thumbnails of the workflows: Chromium does
        // not reach the router of the host): listed, a request in 500 fails the check
        $errors = preg_grep('{\.(CRITICAL|ERROR|ALERT|EMERGENCY): }', $lines);
        io()->text(\count($errors) . ' errors in prod.log' . ($errors ? ":\n" . implode('', \array_slice($errors, 0, 10)) : '.'));
        $requests = preg_grep('{request\.CRITICAL: }', $lines);
        expect([] === $requests, \count($requests) . " requests in 500 in prod.log:\n" . implode('', \array_slice($requests, 0, 5)));
    }, strict: false);
}

/**
 * The copy in 3.4: a git worktree in the work folder, the backup folder of the guide
 * (~/ladb-backup in the builder and in MariaDB), the additions of the rehearsal to its stack.
 */
function create_copy(): void
{
    $tree = tree_dir();
    expect(!is_dir($tree), "{$tree} exists: castor qa:delivery-rehearsal-destroy.");
    fs()->mkdir(\dirname($tree));
    // --force: a copy removed by hand is still registered
    run(['git', 'worktree', 'add', '--force', '--detach', $tree, OLD_COMMIT]);
    expect(basename($tree) === get_worktree_name($tree), 'git named the worktree ' . get_worktree_name($tree) . ': castor qa:delivery-rehearsal-destroy.');
    fs()->mkdir(backup_dir(), 0o777);
    fs()->dumpFile($tree . '/var/ladb-backup.my.cnf', "[client]\nuser=root\npassword=app\n");
    // /etc/rabbitmq/rabbitmq.conf of the server, empty as on Debian (step 17 adds to it)
    fs()->dumpFile($tree . '/var/rabbitmq.conf', '');
    write_override(delivered: false);
}

/**
 * What the production server has in parameters.yml and the fixtures do not: a secret of its own,
 * Swiftmailer to a local SMTP on the port 25 (Mailpit here), and Mastodon offline (the spotlight
 * of the scheduler, every hour).
 */
function setup_parameters(): void
{
    edit_parameters([
        'secret' => bin2hex(random_bytes(20)),
        'mailer_host' => 'mailpit',
        'mastodon_instance' => 'offline://mastodon',
    ]);
    // The cache of prod (the workers) does not read parameters.yml again
    in_builder('rm -rf var/cache/prod && bin/console --env=prod cache:warmup');
    compose(['restart', 'worker_consumer_view', 'worker_consumer_webpush_notification', 'websocket']);
}

function setup_migration_versions(): void
{
    in_builder('bin/console doctrine:migrations:version --add --all --no-interaction');
    expect('51' === sql('SELECT COUNT(*) FROM migration_versions'), 'migration_versions does not have the 51 migrations of master.');
}

function setup_orphan_tables(): void
{
    sql(implode(";\n", array_merge(...array_values(ORPHAN_TABLES))));
    expect(\count(ORPHAN_TABLES) === \count(orphan_tables()), 'The tables of the entities removed by master are not all created.');
}

/**
 * The tables of ORPHAN_TABLES the database has.
 *
 * @return list<string>
 */
function orphan_tables(string $database = 'db_fr_lairdubois_www'): array
{
    $names = implode("', '", array_keys(ORPHAN_TABLES));

    return array_values(array_filter(explode("\n", sql("SELECT table_name FROM information_schema.tables WHERE table_schema = '{$database}' AND table_name IN ('{$names}') ORDER BY table_name"))));
}

function setup_subscriptions(): void
{
    foreach (['admin' => 1, 'user' => 2, 'user2' => 3] as $username => $id) {
        $endpoint = "http://push/{$username}";
        $subscription = json_encode(['endpoint' => $endpoint, 'expirationTime' => null, 'keys' => PUSH_SUBSCRIPTION_KEYS]);
        sql(\sprintf("INSERT INTO tbl_webpush_user_subscription (user_id, subscription_hash, subscription) VALUES (%d, '%s', '%s')", $id, md5($endpoint), $subscription));
    }
}

/**
 * user logs in by the form, with "remember me": the session and the cookie of the 3.4, that the
 * code delivered must refuse without error.
 */
function setup_session(): void
{
    $browser = new Browser();
    expect($browser->login('user'), 'user does not log in to the 3.4.');
    $cookies = $browser->cookies();
    expect(isset($cookies['PHPSESSID'], $cookies['REMEMBERME']), 'No PHPSESSID or REMEMBERME cookie: ' . implode(', ', array_keys($cookies)));
    expect('1' === sql(\sprintf("SELECT COUNT(*) FROM sessions WHERE sess_id = '%s'", addslashes($cookies['PHPSESSID']))), 'The session of user is not in the table sessions.');
    $report = Report::load(true);
    $report->state['cookies'] = ['PHPSESSID' => $cookies['PHPSESSID'], 'REMEMBERME' => $cookies['REMEMBERME']];
    $report->save();
}

/**
 * Mails in the spool, as the site and the crons leave them between two runs of
 * swiftmailer:spool:send: a password reset by the form, and two by the console in prod.
 */
function setup_spool(): void
{
    $browser = new Browser();
    $browser->request('GET', '/resetting/request');
    $response = $browser->request('POST', '/resetting/send-email', form: ['username' => 'user2']);
    expect(\in_array($response->getStatusCode(), [200, 302], true), "/resetting/send-email answers {$response->getStatusCode()}.");
    foreach (['user', 'admin'] as $username) {
        in_builder("bin/console --env=prod swiftmailer:email:send --from=noreply@lairdubois.fr --to={$username}@lairdubois.test --subject='Spool before the delivery' --body='A mail of the spool of Swiftmailer.' --no-interaction");
    }
    expect(count_spool() >= 3, 'The spool has ' . count_spool() . ' mails, not 3.');
}

/**
 * The old consumers stopped, a view and a like: messages wait in view_queue and in
 * webpush_notification_queue, as when the maintenance starts.
 */
function setup_queues(): void
{
    compose(['stop', 'worker_consumer_view', 'worker_consumer_webpush_notification']);
    $anonymous = new Browser();
    foreach (['/creations/1.html', '/plans/1.html'] as $path) {
        expect(200 === $anonymous->request('GET', $path)->getStatusCode(), "{$path} does not answer 200.");
    }
    $admin = new Browser();
    expect($admin->login('admin'), 'admin does not log in to the 3.4.');
    // A like of the creation of user: a web push notification to user
    $like = $admin->request('GET', '/likes/100/1/create', ['X-Requested-With' => 'XMLHttpRequest']);
    expect(200 === $like->getStatusCode(), "The like answers {$like->getStatusCode()}.");
    $output = play('sudo rabbitmqctl list_queues name messages', 'rabbitmq', quiet: true);
    expect(1 === preg_match('{^view_queue\s+[1-9]}m', $output) && 1 === preg_match('{^webpush_notification_queue\s+[1-9]}m', $output), "The old queues are empty:\n{$output}");
}

/**
 * The thumbnails of Liip Imagine resolved by the 3.4: files in web/media/cache, that nginx serves
 * after the delivery.
 */
function setup_media(): void
{
    $browser = new Browser();
    preg_match_all('{/media/cache/resolve/[^"\'\s?]+\.(?:jpe?g|png)}i', $browser->request('GET', '/creations/1.html')->getContent(false), $matches);
    $urls = \array_slice(array_unique($matches[0]), 0, 4);
    expect([] !== $urls, 'No thumbnail to resolve on the creation 1.');
    foreach ($urls as $url) {
        $browser->request('GET', $url)->getStatusCode();
    }
    $files = (int) trim(in_builder('find web/media/cache -type f | wc -l', quiet: true)->getOutput());
    expect($files > 0 && (int) trim(in_builder('find uploads -type f | wc -l', quiet: true)->getOutput()) > 0, 'No file in web/media/cache or in uploads.');
}

/**
 * The 3.4 works: a login by the form (user2 by default: sha512, never logged in to the code
 * delivered), pages, the session written in the table.
 */
function check_old_site(string $username = 'user2'): void
{
    $browser = new Browser();
    expect($browser->login($username), "{$username} does not log in to the 3.4.");
    foreach (['/', '/creations/1.html', '/notifications/'] as $path) {
        $status = $browser->request('GET', $path)->getStatusCode();
        expect(200 === $status, "{$path} answers {$status}.");
    }
    $id = $browser->cookies()['PHPSESSID'] ?? '';
    expect('1' === sql(\sprintf("SELECT COUNT(*) FROM sessions WHERE sess_id = '%s'", addslashes($id))), 'The 3.4 did not write its session.');
}

/**
 * The parameters of the code delivered, from the block of the guide: the addresses of the
 * containers instead of those of the server.
 */
function add_delivery_parameters(): void
{
    preg_match('{```yaml\n(.*?)```}s', guide(), $matches) or throw new \LogicException('No parameters block in the guide.');
    guide_has('sudo mariadb -N -e "SELECT VERSION()"');
    $version = trim(in_service('mariadb', 'mariadb -N -e "SELECT VERSION()"', quiet: true)->getOutput());
    $uri = 'https://' . stack()['root_domain'] . ':' . get_worktree_ports(stack()['ports_name'])['https'];
    $block = strtr($matches[1], [
        'LA_VERSION_DE_MARIADB' => $version,
        // The DSN of step 11: Mailpit instead of the Postfix of the server, on the same port
        'LE_DSN_DES_MAILS' => 'smtp://mailpit:25?auto_tls=false',
        'LE_SECRET_DE_MERCURE' => MERCURE_SECRET,
        'http://127.0.0.1:3000' => 'http://mercure',
        'https://www.lairdubois.fr' => $uri,
    ]);
    $file = tree_dir() . '/app/config/parameters.yml';
    // Once only, for a rehearsal taken again with --from
    if (!str_contains(file_get_contents($file), 'database_server_version:')) {
        fs()->dumpFile($file, rtrim(file_get_contents($file)) . "\n" . $block);
    }
    $count = trim(play("sudo grep -c -E '^\\s+(database_server_version|mailer_dsn|mercure_url|mercure_public_url|mercure_jwt_secret|ga_measurement_id|router_default_uri|chromium_sandbox):' app/config/parameters.yml"));
    expect('8' === $count, "{$count} parameters of the code delivered in parameters.yml, not 8.");
}

/**
 * The workspace of the workflow 1 sets the cookie of the subscription; with it, a subscription to
 * the hub gets the update of a move of a task.
 */
function check_mercure(): void
{
    $browser = new Browser();
    expect($browser->login('user'), 'user does not log in.');
    $workspace = $browser->request('GET', '/processus/1.html?layout=workspace');
    expect(200 === $workspace->getStatusCode(), "The workspace answers {$workspace->getStatusCode()}.");
    $jwt = $browser->cookies()['__Secure-mercure_access_token'] ?? null;
    expect(null !== $jwt, 'The workspace does not set the cookie of the hub.');

    $taskId = sql('SELECT id FROM tbl_workflow_task WHERE workflow_id = 1 ORDER BY id LIMIT 1');
    $left = random_int(100, 900);
    $subscription = http_client()->request('GET', base_url() . '/.well-known/mercure?match=' . rawurlencode('https://lairdubois.fr/workflows/1'), [
        'resolve' => [stack()['root_domain'] => '127.0.0.1'],
        'verify_peer' => false,
        'verify_host' => false,
        'headers' => ['Cookie' => "__Secure-mercure_access_token={$jwt}", 'Accept' => 'text/event-stream'],
        'timeout' => 10,
    ]);
    expect(200 === $subscription->getStatusCode(), "The hub answers {$subscription->getStatusCode()} to the subscription.");
    $move = $browser->request('POST', '/processus/1/task/position/update', ['X-Requested-With' => 'XMLHttpRequest'], ['taskId1' => $taskId, 'positionLeft1' => (string) $left, 'positionTop1' => '50']);
    expect(200 === $move->getStatusCode(), "The move of the task answers {$move->getStatusCode()}.");

    $events = '';
    $end = time() + 10;
    foreach (http_client()->stream($subscription, 2) as $chunk) {
        if (!$chunk->isTimeout()) {
            $events .= $chunk->getContent();
        }
        if (str_contains($events, '"positionLeft":' . $left) || time() > $end) {
            break;
        }
    }
    $subscription->cancel();
    expect(str_contains($events, '"positionLeft":' . $left), "The hub did not send the update of the move:\n{$events}");
}

/**
 * Step 52 of the guide: chromium_sandbox, the cache, then the workers and the crons started again
 * after it (the services of Docker for those of systemd). The builder and the worker of the crons
 * have the seccomp profile of Chromium: the capture of a workflow starts it in its sandbox, then
 * does not reach the router of the host.
 */
function check_sandbox_and_restart(): void
{
    guide_has('sysctl kernel.unprivileged_userns_clone');
    edit_parameters(['chromium_sandbox' => 'true']);
    play('sudo --user=www-data bin/console --env=prod cache:clear');
    $cleared = time();
    guide_has(RESTART_WORKERS);
    compose(['restart', ...WORKER_SERVICES]);
    guide_has('systemctl show -p ActiveEnterTimestamp ladb_scheduler ladb_consumer_cron ladb_consumer_mailer ladb_consumer_view ladb_consumer_webpush');
    foreach (WORKER_SERVICES as $service) {
        expect(started_at($service) >= $cleared, "{$service} did not start again after the cache:clear.");
    }
    // A workflow without its thumbnail: the command captures it
    sql('UPDATE tbl_workflow SET main_picture_id = NULL WHERE id = 1');
    $output = play('sudo --user=www-data bin/console --env=prod ladb:cron:workflow:thumbnails --force', allowFailure: true, suffix: ' -v');
    io()->text(tail($output, 10));
    expect(1 === preg_match('{\[[1-9]\d* workflows\]}', $output) && !str_contains($output, 'No usable sandbox'), "Chromium does not start in its sandbox:\n" . tail($output));
}

/**
 * Following deployments (step 55): a cron in the queue "cron", as the scheduler puts them (the
 * rebuilding of the index, a few seconds), then the worker stopped while it runs it. The stop
 * waits for its end, the message is acknowledged, the worker exits with 0.
 */
function check_cron_stop(): void
{
    guide_has('sudo systemctl stop ladb_scheduler ladb_consumer_cron');
    compose(['stop', 'scheduler']);
    $dispatch = <<<'PHP'
        require 'app/autoload.php';
        $kernel = new AppKernel('prod', false);
        $kernel->boot();
        $kernel->getContainer()->get('messenger.default_bus')->dispatch(new Symfony\Component\Console\Messenger\RunCommandMessage('fos:elastica:populate'), [new Symfony\Component\Messenger\Stamp\TransportNamesStamp(['cron'])]);
        PHP;
    // The indexes and their documents: the cron creates each of them again, one after the other
    $indices = static fn () => array_map(static fn (string $line) => preg_split('{\s+}', $line), array_filter(explode("\n", trim(in_builder("curl -s 'http://elasticsearch:9200/_cat/indices/ladb_*?h=index,creation.date,docs.count&s=index'", quiet: true)->getOutput()))));
    $before = $indices();
    $dispatched = (int) (microtime(true) * 1000);
    $process = in_builder('php -r ' . escapeshellarg($dispatch), quiet: true);
    expect($process->isSuccessful(), "The cron is not put in the queue:\n" . tail($process->getOutput() . $process->getErrorOutput()));
    $queue = static fn () => trim(in_service('rabbitmq', 'rabbitmqctl -q list_queues name messages_ready messages_unacknowledged', quiet: true)->getOutput());
    // Taken by the worker, not acknowledged yet: the cron runs
    $end = time() + 60;
    while (1 !== preg_match('{^cron\s+0\s+1$}m', $queue())) {
        expect(time() < $end, "The worker of the crons does not take the cron:\n" . $queue());
        usleep(200000);
    }
    $stopped = (int) (microtime(true) * 1000);
    $seconds = timed(static fn () => compose(['stop', 'worker_cron']));
    $container = trim(compose(['ps', '--all', '--quiet', 'worker_cron']));
    $exit = trim(run(['docker', 'inspect', '-f', '{{.State.ExitCode}}', $container], context: context()->withQuiet())->getOutput());
    io()->text("The worker of the crons stopped in {$seconds} s, exit code {$exit}.");
    expect(1 === preg_match('{^cron\s+0\s+0$}m', $queue()), "The cron is not acknowledged:\n" . $queue());
    expect('0' === $exit, "The worker of the crons exits with {$exit}.");
    // Every index created again after the cron was put in the queue, with its documents: the cron
    // went to its end
    $after = $indices();
    expect(array_column($before, 0) === array_column($after, 0) && array_column($before, 2) === array_column($after, 2), "The indexes are not those of before the cron:\n" . json_encode([$before, $after]));
    expect([] === array_filter($after, static fn (array $index) => (int) $index[1] < $dispatched), "Indexes not created again by the cron:\n" . json_encode($after));
    // The last ones after the signal: the worker waited for them
    $late = \count(array_filter($after, static fn (array $index) => (int) $index[1] > $stopped));
    io()->text("{$late} of " . \count($after) . ' indexes created by the cron after the signal.');
    expect($late > 0, 'The cron ended before the stop of the worker: nothing to wait for.');
    compose(['up', '--detach', '--no-build', 'scheduler', 'worker_cron']);
    // The index rebuilt by the cron is whole
    check_search();
}

/**
 * When the container of a service started, as a timestamp (systemctl show -p ActiveEnterTimestamp).
 */
function started_at(string $service): int
{
    $container = trim(compose(['ps', '--quiet', $service]));
    expect('' !== $container, "{$service} does not run.");
    $started = trim(run(['docker', 'inspect', '-f', '{{.State.StartedAt}}', $container], context: context()->withQuiet())->getOutput());

    return (int) strtotime(substr($started, 0, 19) . 'Z');
}

/**
 * The words of the title of the creation 1, the longest: the search finds it.
 */
function search_word(): string
{
    $words = preg_split('{[^\p{L}]+}u', sql('SELECT title FROM tbl_wonder_creation WHERE id = 1'), flags: \PREG_SPLIT_NO_EMPTY);
    usort($words, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

    return $words[0];
}

/**
 * The additions of the rehearsal to the stack of the copy, a docker-compose.override.yml that both
 * versions read (gitignored): Mailpit on the port 25 (the Postfix of the server), the service of
 * push of the browsers, the backup folder of the guide, the client of MariaDB without password
 * (sudo mariadb on the server). For the code delivered: the workers in prod (the units of
 * docs/01-installation.md), the scheduler and the worker of the crons, and, from step 42, the site
 * of the image nginx of production in the frontend.
 */
function write_override(bool $delivered, bool $productionVhost = false): void
{
    $common = <<<'YAML'
        # Written by castor qa:delivery-rehearsal (.castor/rehearsal.php)
        services:
            mariadb:
                image: mariadb:MARIADB_VERSION
                volumes:
                    - "../..:/var/www:ro"
                    - "../../var/ladb-backup:/root/ladb-backup"
                    - "../../var/ladb-backup.my.cnf:/root/.my.cnf:ro"
            builder:
                volumes:
                    - "../../var/ladb-backup:/home/app/ladb-backup"
            rabbitmq:
                image: rabbitmq:RABBITMQ_VERSION-alpine
                volumes:
                    - "../../var/rabbitmq.conf:/etc/rabbitmq/rabbitmq.conf"
            mailpit:
                image: axllent/mailpit:v1.32.0
                environment:
                    MP_DISABLE_VERSION_CHECK: "true"
                    MP_WEBROOT: /mailpit
                    MP_SMTP_BIND_ADDR: "0.0.0.0:25"
                profiles:
                    - default
                labels:
                    - "traefik.enable=true"
                    - "project-name=${PROJECT_NAME}"
                    - "traefik.http.routers.${PROJECT_NAME}-mailpit.rule=Host(${PROJECT_DOMAINS}) && PathPrefix(`/mailpit`)"
                    - "traefik.http.routers.${PROJECT_NAME}-mailpit.tls=true"
                    - "traefik.http.services.${PROJECT_NAME}-mailpit.loadbalancer.server.port=8025"
            push:
                image: nginx:1.30-alpine
                command:
                    - sh
                    - -c
                    - |
                      cat > /etc/nginx/conf.d/default.conf <<'EOF'
                      log_format push '$$request_method $$request_uri ttl=$$http_ttl encoding=$$http_content_encoding authorization=$$http_authorization';
                      server { listen 80; access_log /dev/stdout push; location / { return 201; } }
                      EOF
                      exec nginx -g 'daemon off;'
                profiles:
                    - default

        YAML;

    // The MariaDB and the RabbitMQ of the Debian of the server
    $state = Report::load(true)->state;
    $common = strtr($common, ['MARIADB_VERSION' => $state['mariadb'] ?? '11.8', 'RABBITMQ_VERSION' => $state['rabbitmq'] ?? '4.0']);

    if (!$delivered) {
        // The commands of the units of master, in prod
        $workers = <<<'YAML'
                worker_consumer_view:
                    command: php bin/console --env=prod rabbitmq:consumer view
                worker_consumer_webpush_notification:
                    command: php bin/console --env=prod rabbitmq:consumer webpush_notification
                websocket:
                    command: php bin/console --env=prod gos:websocket:server --host=0.0.0.0

            YAML;
        fs()->dumpFile(tree_dir() . '/infrastructure/docker/docker-compose.override.yml', $common . $workers);

        return;
    }

    $vhost = $productionVhost ? <<<'YAML'
                volumes:
                    - "./services/php/prod/nginx.conf:/etc/nginx/conf.d/default.conf:ro"

        YAML : '';
    $delivery = <<<YAML
            frontend:
                environment:
                    TRUSTED_HOSTS: '^lairdubois\\.test\$\$'
        {$vhost}    worker_consumer_mailer:
                command: php bin/console --env=prod messenger:consume mailer
            worker_consumer_view:
                command: php bin/console --env=prod messenger:consume view
            worker_consumer_webpush_notification:
                command: php bin/console --env=prod messenger:consume webpush_notification
            scheduler:
                build:
                    context: services/php
                    target: worker
                init: true
                user: "\${USER_ID}:\${USER_ID}"
                command: php bin/console --env=prod messenger:consume scheduler_cron
                depends_on:
                    - mariadb
                    - memcached
                    - rabbitmq
                volumes:
                    - "../..:/var/www:cached"
                    - "../../.home:/home/app:cached"
                profiles:
                    - cron
            worker_cron:
                build:
                    context: services/php
                    target: worker
                init: true
                user: "\${USER_ID}:\${USER_ID}"
                command: php bin/console --env=prod messenger:consume cron --memory-limit=256M
                stop_grace_period: 1h
                # Chromium in its sandbox (step 52), as in docker-compose.prod.yml
                security_opt:
                    - "seccomp=services/php/chromium-seccomp.json"
                depends_on:
                    - mariadb
                    - memcached
                    - rabbitmq
                volumes:
                    - "../..:/var/www:cached"
                    - "../../.home:/home/app:cached"
                profiles:
                    - cron

        YAML;
    fs()->dumpFile(tree_dir() . '/infrastructure/docker/docker-compose.override.yml', $common . $delivery);
}

/**
 * The services of the profile "default" of the compose files of the copy, as they are.
 *
 * @return list<string>
 */
function default_services(): array
{
    $services = json_decode(docker_compose(['config', '--format', 'json'], c: stack()->withQuiet(), profiles: ['default'])->getOutput(), true, flags: \JSON_THROW_ON_ERROR)['services'];

    return array_keys($services);
}

/**
 * The environment variables of the builder that composer writes in parameters.yml ("env-map" of
 * a composer.json, the one of the copy by default), unset: the server has none, its
 * parameters.yml is the one of the guide.
 */
function unset_parameter_variables(?string $composerJson = null): string
{
    $map = json_decode(file_get_contents($composerJson ?? tree_dir() . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR)['extra']['incenteev-parameters']['env-map'] ?? [];

    return 'env ' . implode(' ', array_map(static fn (string $variable) => "-u {$variable}", array_values($map))) . ' ';
}

/**
 * @param array<string, string> $values
 */
function edit_parameters(array $values): void
{
    $file = tree_dir() . '/app/config/parameters.yml';
    $contents = file_get_contents($file);
    foreach ($values as $name => $value) {
        $contents = preg_replace('{^(\s+' . preg_quote($name, '{') . ':\s+).*$}m', '${1}' . $value, $contents, 1, $count);
        expect(1 === $count, "No {$name} in parameters.yml.");
    }
    fs()->dumpFile($file, $contents);
}

/**
 * Runs a command of the guide, written as in it: the guide must have it. In the builder (the files
 * of the site and bin/console, as www-data), or in the container of MariaDB or RabbitMQ for their
 * clients (as root). sudo goes, after a time, in a pipe and in a subshell: the containers run as
 * the user of the files, or as root. The folder of the site is /var/www in the containers.
 */
function play(string $command, string $service = 'builder', string $prefix = '', bool $quiet = false, bool $allowFailure = false, string $suffix = ''): string
{
    guide_has($command);
    $run = preg_replace('{(^|\| |&& |\()(time )?sudo (--user=www-data )?}', '$1$2', $command);
    $run = str_replace('/var/www/www.lairdubois.fr/', '/var/www/', $run) . $suffix;
    $process = 'builder' === $service ? in_builder($prefix . $run, $quiet) : in_service($service, $run, $quiet);
    if (!$allowFailure && !$process->isSuccessful()) {
        throw new \RuntimeException("`{$command}` failed:\n" . tail($process->getOutput() . $process->getErrorOutput()));
    }

    // The notes of the console (Nothing to update…) are on the error output
    return $process->getOutput() . $process->getErrorOutput();
}

function in_builder(string $command, bool $quiet = false): Process
{
    $c = stack()->withAllowFailure();

    return docker_compose_run(['bash', '-c', $command], c: $quiet ? $c->withQuiet() : $c);
}

function in_service(string $service, string $command, bool $quiet = false): Process
{
    $c = stack()->withAllowFailure();
    // The containers of MariaDB has the code in /var/www, as the server
    $workDir = 'mariadb' === $service ? ['-w', '/var/www'] : [];

    return docker_compose(['exec', '-T', ...$workDir, $service, 'sh', '-c', $command], c: $quiet ? $c->withQuiet() : $c, profiles: ['*']);
}

/**
 * @param list<string> $command
 */
function compose(array $command): string
{
    return docker_compose($command, c: stack()->withQuiet(), profiles: ['*'])->getOutput();
}

/**
 * A query in MariaDB, its rows tab-separated.
 */
function sql(string $sql): string
{
    return trim(docker_compose(['exec', '-T', 'mariadb', 'mariadb', '--skip-column-names', '--batch', '--raw', 'db_fr_lairdubois_www'], c: stack()->withQuiet()->withInput($sql), profiles: ['*'])->getOutput());
}

function count_spool(): int
{
    return (int) trim(in_builder("find app/spool -type f -name '*.message' | wc -l", quiet: true)->getOutput());
}

/**
 * @return array<string, mixed>
 */
function mailpit(string $path): array
{
    return (new Browser())->request('GET', '/mailpit' . $path)->toArray();
}

function mailpit_total(): int
{
    return mailpit('/api/v1/messages?limit=1')['total'];
}

function guide(): string
{
    static $guide;

    return $guide ??= file_get_contents(\dirname(__DIR__) . '/' . GUIDE);
}

function installation(): string
{
    static $installation;

    return $installation ??= file_get_contents(\dirname(__DIR__) . '/' . INSTALLATION);
}

/**
 * The rehearsal plays the guide as it is written: a command it does not have any more is a guide
 * and a rehearsal out of sync.
 */
function guide_has(string $command): void
{
    if (!str_contains(guide(), $command)) {
        throw new \LogicException("The guide (" . GUIDE . ") does not have the command `{$command}`: keep .castor/rehearsal.php in sync with it.");
    }
}

function installation_has(string $command): void
{
    if (!str_contains(installation(), $command)) {
        throw new \LogicException("The installation (" . INSTALLATION . ") does not have the command `{$command}`: keep .castor/rehearsal.php in sync with it.");
    }
}

function tree_head(): string
{
    return trim(run(['git', 'rev-parse', 'HEAD'], context: context()->withWorkingDirectory(tree_dir())->withQuiet())->getOutput());
}

function stack(): Context
{
    $tree = tree_dir();

    return create_default_context()
        ->withData([
            'root_dir' => $tree,
            'project_name' => project_name(),
            'ports_name' => basename($tree),
            'docker_compose_files' => ['docker-compose.yml', 'docker-compose.dev.yml', 'docker-compose.override.yml'],
        ], recursive: false)
        ->withWorkingDirectory($tree)
    ;
}

/**
 * The project of the stack of the copy: that of a worktree named after its folder
 * (.castor/worktree.php).
 */
function project_name(): string
{
    return 'lairdubois-wt-' . basename(tree_dir());
}

function work_dir(): string
{
    return \dirname(__DIR__) . '/' . WORK_DIR;
}

/**
 * The copy in 3.4, named after the copy that runs the rehearsal: two copies rehearse apart.
 */
function tree_dir(): string
{
    return work_dir() . '/delivery-rehearsal-' . (get_worktree_name(\dirname(__DIR__)) ?? 'main');
}

function backup_dir(): string
{
    return tree_dir() . '/var/ladb-backup';
}

function base_url(): string
{
    return 'https://' . stack()['root_domain'] . ':' . get_worktree_ports(stack()['ports_name'])['https'];
}

/**
 * The seconds a callable takes.
 */
function timed(callable $callable): float
{
    $start = microtime(true);
    $callable();

    return round(microtime(true) - $start, 1);
}

function wait_for(callable $condition, string $message, int $timeout = 60): void
{
    $end = time() + $timeout;
    while (!$condition()) {
        if (time() > $end) {
            throw new \RuntimeException($message);
        }
        sleep(2);
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function tail(string $output, int $lines = 30): string
{
    return implode("\n", \array_slice(explode("\n", trim($output)), -$lines));
}

/**
 * A browser on the site of the stack, through its router: its cookies, no redirection followed.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $cookies = [];

    /**
     * @param array<string, string> $cookies
     */
    public function withCookies(array $cookies): void
    {
        $this->cookies = $cookies + $this->cookies;
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @param array<string, string>      $headers
     * @param array<string, string>|null $form
     */
    public function request(string $method, string $path, array $headers = [], ?array $form = null): ResponseInterface
    {
        if ($this->cookies) {
            $headers['Cookie'] = implode('; ', array_map(static fn (string $name, string $value) => "{$name}={$value}", array_keys($this->cookies), $this->cookies));
        }
        $options = [
            'resolve' => [stack()['root_domain'] => '127.0.0.1'],
            'verify_peer' => false,
            'verify_host' => false,
            'max_redirects' => 0,
            'headers' => $headers + ['User-Agent' => USER_AGENT],
            'timeout' => 120,
        ];
        if (null !== $form) {
            $options['body'] = $form;
        }
        $response = http_client()->request($method, base_url() . $path, $options);
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ('' === $value || 'deleted' === $value || preg_match('{max-age=0\b|expires=Thu, 01[ -]Jan[ -]1970}i', $cookie)) {
                unset($this->cookies[trim($name)]);
            } else {
                $this->cookies[trim($name)] = $value;
            }
        }

        return $response;
    }

    /**
     * Logs in by the form of /login, "remember me" checked: the password is the username (fixtures).
     */
    public function login(string $username): bool
    {
        $page = $this->request('GET', '/login')->getContent(false);
        if (!preg_match('{name="_csrf_token" value="([^"]+)"}', $page, $matches)) {
            throw new \RuntimeException('No CSRF token on /login.');
        }
        $response = $this->request('POST', '/login_check', form: ['_username' => $username, '_password' => $username, '_csrf_token' => $matches[1], '_remember_me' => 'on']);
        $location = $response->getHeaders(false)['location'][0] ?? '';

        return 302 === $response->getStatusCode() && !str_contains($location, '/login');
    }
}

final class StopRehearsal extends \RuntimeException
{
}

/**
 * The steps of the rehearsal and their results, by step of the guide: played in Docker, checked
 * in a throwaway container, or not played. Kept with the state of the rehearsal (the cookies of
 * the 3.4) for --from.
 */
final class Report
{
    /** @var list<array{key: string, label: string, kind: string, ok: bool, message: string, seconds: float}> */
    public array $records = [];

    /** @var array<string, mixed> */
    public array $state = [];

    public static function load(bool $keep): self
    {
        $report = new self();
        $file = work_dir() . '/state.json';
        if ($keep && is_file($file)) {
            $data = json_decode(file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
            $report->records = $data['records'];
            $report->state = $data['state'];
        }

        return $report;
    }

    public function save(): void
    {
        // The state of another load (setup_session()) is kept
        $saved = self::load(true);
        $this->state += $saved->state;
        fs()->dumpFile(work_dir() . '/state.json', json_encode(['records' => $this->records, 'state' => $this->state], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    /**
     * A step of the guide, played in Docker: its number comes from the guide.
     */
    public function step(string $title, callable $step, bool $strict = true, string $kind = 'jouée'): void
    {
        $this->record(guide_step($title), $title, $kind, $step, $strict);
    }

    /**
     * A step of the guide checked in a throwaway container.
     */
    public function verified(string $title, string $where, callable $step): void
    {
        $this->record(guide_step($title), $title, "vérifiée autrement ({$where})", $step, false);
    }

    /**
     * What the guide does not say: the data of the start, the checks after the delivery.
     */
    public function check(string $label, callable $check, bool $strict = true): void
    {
        $this->record('', $label, 'vérification', $check, $strict);
    }

    public function hasFailures(): bool
    {
        return \in_array(false, array_column($this->records, 'ok'), true);
    }

    public function render(): void
    {
        $markdown = "## Étapes du guide\n\n| Étape | Résultat | Durée |\n| --- | --- | ---: |\n";
        $rows = [];
        foreach (guide_steps() as $key => $title) {
            $records = array_filter($this->records, static fn (array $record) => $record['key'] === (string) $key);
            if ([] === $records) {
                $rows[] = ["{$key}. {$title}", 'non jouée', ''];
                continue;
            }
            $results = [];
            $durations = [];
            foreach ($records as $record) {
                $results[] = $record['ok'] ? $record['kind'] : "ÉCHEC ({$record['kind']}) : " . mb_strimwidth(strtok($record['message'], "\n"), 0, 90, '…');
                // In a throwaway container, the downloads of apt and curl count
                $durations[] = format_duration($record['seconds']) . (str_starts_with($record['kind'], 'jouée') ? '' : ' (' . substr($record['kind'], \strlen('vérifiée autrement ('), -1) . ')');
            }
            $rows[] = ["{$key}. {$title}", implode(' ; ', array_unique($results)), implode(' + ', $durations)];
        }
        foreach ($rows as [$step, $result, $duration]) {
            $markdown .= "| {$step} | {$result} | {$duration} |\n";
        }

        $markdown .= "\n## Données et vérifications\n\n| Vérification | Résultat | Durée |\n| --- | --- | ---: |\n";
        $checks = [];
        foreach ($this->records as $record) {
            if ('' !== $record['key']) {
                continue;
            }
            $result = $record['ok'] ? 'OK' : 'ÉCHEC : ' . mb_strimwidth(strtok($record['message'], "\n"), 0, 90, '…');
            $checks[] = [$record['label'], $result, format_duration($record['seconds'])];
            $markdown .= "| {$record['label']} | {$result} | " . format_duration($record['seconds']) . " |\n";
        }

        fs()->dumpFile(work_dir() . '/report.md', $markdown);
        io()->section('Steps of the guide');
        io()->table(['Étape', 'Résultat', 'Durée'], $rows);
        io()->section('Data and checks');
        io()->table(['Vérification', 'Résultat', 'Durée'], $checks);
        io()->comment('Also in ' . WORK_DIR . '/report.md.');
    }

    private function record(string $key, string $label, string $kind, callable $step, bool $strict): void
    {
        io()->section(('' !== $key ? "{$key}. " : '') . $label);
        $start = microtime(true);
        $ok = true;
        $message = '';
        try {
            $step();
        } catch (\RuntimeException|\JsonException $e) {
            $ok = false;
            $message = $e->getMessage();
            io()->error($message);
        }
        $this->records[] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'ok' => $ok, 'message' => $message, 'seconds' => round(microtime(true) - $start, 1)];
        $this->save();
        if (!$ok && $strict) {
            throw new StopRehearsal($message);
        }
    }
}

/**
 * The steps of the guide by number: "1" for "### 1. Title", "P1" and "R1" for the prerequisites
 * and the rollback.
 *
 * @return array<string, string>
 */
function guide_steps(): array
{
    preg_match_all('{^(?:### (\d+)\. (.+)|(P\d+|R\d+)\. (.+?) ?:?)$}m', guide(), $matches, \PREG_SET_ORDER);
    $steps = [];
    foreach ($matches as $match) {
        if ('' !== ($match[1] ?? '')) {
            $steps[$match[1]] = trim($match[2]);
        } else {
            $steps[$match[3]] = trim($match[4]);
        }
    }

    return $steps;
}

function guide_step(string $title): string
{
    $key = array_search($title, guide_steps(), true);
    if (false === $key) {
        throw new \LogicException("The guide (" . GUIDE . ") has no step \"{$title}\": keep .castor/rehearsal.php in sync with it.");
    }

    return (string) $key;
}

function format_duration(float $seconds): string
{
    return $seconds < 90 ? \sprintf('%d s', round($seconds)) : \sprintf('%d min %02d s', intdiv((int) $seconds, 60), (int) $seconds % 60);
}
