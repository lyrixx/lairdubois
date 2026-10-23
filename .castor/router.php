<?php

namespace router;

use Castor\Attribute\AsTask;
use Symfony\Component\Process\ExecutableFinder;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;
use function Castor\variable;

/*
 * The global router: a single Caddy for every project of the machine, the only
 * container that publishes ports (80 and 443). It is the router of the
 * castor-php/docker plugin: started from the compose file the plugin generated
 * when it is installed, from infrastructure/docker/services/router/compose.yaml
 * otherwise.
 *
 * Each project keeps its own network: the router joins it, with the domains of
 * the project as aliases, so that the containers of the project reach their own
 * domains through the router too. Two projects never share a network, so their
 * services with the same name ("frontend"...) never collide in the Docker DNS.
 */

const ROUTER_NAME = 'castor-docker-router';

#[AsTask(description: 'Starts the global router shared by all the projects, unless it is already running', namespace: 'docker:router', name: 'start')]
function start(): void
{
    if (is_running()) {
        return;
    }

    io()->section('Starting the global router');

    // The projects of the plugin must run behind the exact router it generated
    // (Caddyfile, labels it checks): its files are left as they are
    $composeFile = get_plugin_compose_file();
    if (!is_file($composeFile)) {
        $composeFile = variable('root_dir') . '/infrastructure/docker/services/router/compose.yaml';

        $certsDirectory = get_certs_directory();
        // Created by Docker, as root, otherwise
        fs()->mkdir($certsDirectory);
        install_certificate_authority($certsDirectory);
    }

    run(['docker', 'compose', '-f', $composeFile, 'up', '--detach', '--wait']);
}

function is_running(): bool
{
    $output = capture(
        ['docker', 'ps', '--filter', 'name=^' . ROUTER_NAME . '$', '--filter', 'status=running', '--format', '{{.Names}}'],
        context: context()->withQuiet(),
    );

    return ROUTER_NAME === trim($output);
}

/**
 * @param list<string> $domains
 */
function connect(string $network, array $domains): void
{
    if (\in_array($network, get_networks(), true)) {
        return;
    }

    $command = ['docker', 'network', 'connect'];
    foreach ($domains as $domain) {
        $command[] = '--alias';
        $command[] = $domain;
    }
    $command[] = $network;
    $command[] = ROUTER_NAME;

    run($command, context: context()->withQuiet());
}

function disconnect(string $network): void
{
    if (!is_running() || !\in_array($network, get_networks(), true)) {
        return;
    }

    run(['docker', 'network', 'disconnect', $network, ROUTER_NAME], context: context()->withQuiet());
}

/**
 * @return list<string>
 */
function get_networks(): array
{
    $output = capture(
        ['docker', 'inspect', '--format', '{{range $name, $network := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}', ROUTER_NAME],
        context: context()->withQuiet(),
    );

    return array_values(array_filter(array_map(trim(...), explode("\n", $output))));
}

/**
 * Written by "castor docker:router:enable" of the castor-php/docker plugin.
 */
function get_plugin_compose_file(): string
{
    return getenv('HOME') . '/.castor/docker/router/compose.yaml';
}

function get_certs_directory(): string
{
    return getenv('HOME') . '/.castor/docker/router/certs';
}

/**
 * Same as castor-php/docker: with mkcert, the certificates minted by Caddy are
 * signed by the mkcert root CA, already trusted by the host and its browsers.
 * Without it, Caddy uses its own local CA, and browsers show a warning.
 */
function install_certificate_authority(string $certsDirectory): void
{
    if (!(new ExecutableFinder())->find('mkcert')) {
        io()->comment('mkcert is not installed: browsers will show a security warning. Install mkcert (https://github.com/FiloSottile/mkcert) for locally-trusted certificates.');

        return;
    }

    $caRoot = trim(capture(['mkcert', '-CAROOT']));
    if (!is_file("{$caRoot}/rootCA.pem") || !is_file("{$caRoot}/rootCA-key.pem")) {
        io()->warning('The mkcert CA is not installed on the host, run "mkcert -install" for locally-trusted certificates.');

        return;
    }

    foreach (['rootCA.pem', 'rootCA-key.pem'] as $file) {
        if (is_file("{$certsDirectory}/{$file}") && md5_file("{$caRoot}/{$file}") === md5_file("{$certsDirectory}/{$file}")) {
            continue;
        }

        // The key is read-only (0400), as in the mkcert directory
        fs()->remove("{$certsDirectory}/{$file}");
        fs()->copy("{$caRoot}/{$file}", "{$certsDirectory}/{$file}");
    }

    // The mkcert root may only sign leaf certificates ("pathlen:0"): it is used as
    // the intermediate CA of Caddy too, so that leaves are signed by it directly
    fs()->dumpFile("{$certsDirectory}/caddy/ca.caddy", <<<'CADDY'
        pki {
            ca local {
                root {
                    cert /certs/rootCA.pem
                    key /certs/rootCA-key.pem
                }
                intermediate {
                    cert /certs/rootCA.pem
                    key /certs/rootCA-key.pem
                }
            }
        }
        CADDY);
}
