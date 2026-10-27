<?php

namespace Tests\Opencutlist;

use Ladb\CoreBundle\DataFixtures\UserFixtures;
use Ladb\CoreBundle\Entity\Opencutlist\Access;
use Tests\WebTestCase;
use Tests\XhrTrait;

/**
 * The URLs the OpenCutList extension of SketchUp calls: each one logs an access (stats of the
 * admins), then redirects to the files of the extension on GitHub, or gives the URL of a page of
 * its documentation. The fixtures have no access.
 */
final class OpencutlistTest extends WebTestCase
{
    use XhrTrait;

    private const GITHUB = 'https://raw.githubusercontent.com/lairdubois/lairdubois-opencutlist-sketchup-extension/';

    /**
     * The "lab" (and "dev") environment of the extension gets the files of the branch of the
     * next version.
     *
     * @dataProvider provideRedirects
     */
    public function testRedirect(string $route, array $parameters, int $kind, int $env, string $location): void
    {
        $client = static::createClient([], ['HTTP_USER_AGENT' => 'SketchUp Make/17.2.2555 (Windows)']);
        $client->request('GET', static::generatePath($route, $parameters + ['v' => '7.0.0', 'build' => '202401011200', 'language' => 'fr', 'locale' => 'fr-FR']));

        self::assertTrue($client->getResponse()->isRedirect($location));
        $access = $this->findLastAccess();
        self::assertSame([$kind, $env], [$access->getKind(), $access->getEnv()]);
        self::assertSame('127.0.0.1', $access->getClientIp4());
        self::assertSame('SketchUp Make/17.2.2555 (Windows)', $access->getClientUserAgent());
        self::assertSame(['7.0.0', '202401011200', 'fr', 'fr-FR'], [$access->getClientOclVersion(), $access->getClientOclBuild(), $access->getClientOclLanguage(), $access->getClientSketchupLocale()]);
        self::assertFalse($access->getAnalyzed());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int, int, string}>
     */
    public static function provideRedirects(): iterable
    {
        yield 'manifest' => ['core_opencutlist_manifest', [], Access::KIND_MANIFEST, Access::ENV_PROD, self::GITHUB.'master/dist/manifest.json'];
        yield 'manifest, lab' => ['core_opencutlist_manifest_env', ['env' => 'lab'], Access::KIND_MANIFEST, Access::ENV_DEV, self::GITHUB.'8.0.0/dist/manifest.json'];
        yield 'download' => ['core_opencutlist_download', [], Access::KIND_DOWNLOAD, Access::ENV_PROD, self::GITHUB.'master/dist/ladb_opencutlist.rbz'];
        yield 'download, dev' => ['core_opencutlist_download_env', ['env' => 'dev'], Access::KIND_DOWNLOAD, Access::ENV_DEV, self::GITHUB.'8.0.0/dist/ladb_opencutlist.rbz'];
        yield 'tutorials' => ['core_opencutlist_tutorials', [], Access::KIND_TUTORIALS, Access::ENV_PROD, self::GITHUB.'master/docs/json/tutorials.json'];
        yield 'tutorials, lab' => ['core_opencutlist_tutorials_env', ['env' => 'lab'], Access::KIND_TUTORIALS, Access::ENV_DEV, self::GITHUB.'8.0.0/docs/json/tutorials.json'];
        yield 'changelog' => ['core_opencutlist_changelog', [], Access::KIND_CHANGELOG, Access::ENV_PROD, self::GITHUB.'master/CHANGELOG.md'];
        yield 'changelog, prod' => ['core_opencutlist_changelog_env', ['env' => 'prod'], Access::KIND_CHANGELOG, Access::ENV_PROD, self::GITHUB.'master/CHANGELOG.md'];
        yield 'docs, redirect' => ['core_opencutlist_docs', ['page' => 'cutlist', 'redirect' => '1'], Access::KIND_DOCS, Access::ENV_PROD, 'https://docs.opencutlist.org/features/parts'];
    }

    /**
     * The values of the extension it cannot store are dropped: a language of 2 letters, a locale
     * of 5 characters at most. The setters keep an empty string (substr() of null), as for a
     * missing value.
     */
    public function testAccessWithInvalidValues(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_opencutlist_manifest', ['language' => 'french', 'locale' => 'french-FR']));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $access = $this->findLastAccess();
        self::assertSame(['', '', '', ''], [$access->getClientOclVersion(), $access->getClientOclBuild(), $access->getClientOclLanguage(), $access->getClientSketchupLocale()]);
    }

    /**
     * The URL of the page of the documentation for a part of the extension, in JSON.
     *
     * @dataProvider provideDocs
     */
    public function testDocs(string $page, string $path): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_opencutlist_docs_env', ['env' => 'prod', 'page' => $page]));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('{ "url": "https://docs.opencutlist.org'.$path.'" }', $client->getResponse()->getContent());
        self::assertSame(Access::KIND_DOCS, $this->findLastAccess()->getKind());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDocs(): iterable
    {
        yield 'no page' => ['', ''];
        yield 'unknown page' => ['unknown', ''];
        yield 'core.upgrade' => ['core.upgrade', '/getting-started/updating'];
        yield 'tool.smart-draw' => ['tool.smart-draw', '/features/smart-draw-tool'];
        yield 'tool.smart-handle' => ['tool.smart-handle', '/features/smart-handle-tool'];
        yield 'tool.smart-paint' => ['tool.smart-paint', '/features/smart-paint-tool'];
        yield 'tool.smart-axes' => ['tool.smart-axes', '/features/smart-axes-tool'];
        yield 'tool.smart-export' => ['tool.smart-export', '/features/smart-export-tool'];
        yield 'settings' => ['settings', '/getting-started/installing/preferences'];
        yield 'settings.presets' => ['settings.presets', '/getting-started/installing/presets'];
        yield 'settings.presets.export' => ['settings.presets.export', '/getting-started/installing/presets'];
        yield 'settings.presets.import' => ['settings.presets.import', '/getting-started/installing/presets'];
        yield 'materials' => ['materials', '/features/applying-materials'];
        yield 'materials.options' => ['materials.options', '/features/applying-materials'];
        yield 'materials.new' => ['materials.new', '/features/applying-materials'];
        yield 'materials.edit' => ['materials.edit', '/features/applying-materials'];
        yield 'cutlist' => ['cutlist', '/features/parts'];
        yield 'features.parts.export' => ['features.parts.export', '/features/parts/export'];
        yield 'cutlist.export' => ['cutlist.export', '/features/parts/export'];
        yield 'features.parts.report' => ['features.parts.report', '/features/parts/report'];
        yield 'cutlist.report' => ['cutlist.report', '/features/parts/report'];
        yield 'cutlist.options' => ['cutlist.options', '/features/parts/options'];
        yield 'cutlist.part' => ['cutlist.part', '/features/parts/parts-list/edit-part'];
        yield 'cutlist.cuttingdiagram1d' => ['cutlist.cuttingdiagram1d', '/features/parts/parts-list/cutting-diagrams/dimensional'];
        yield 'cutlist.cuttingdiagram2d' => ['cutlist.cuttingdiagram2d', '/features/parts/parts-list/cutting-diagrams/sheet-goods'];
        yield 'cutlist.cuttingdiagram1d.write' => ['cutlist.cuttingdiagram1d.write', '/features/parts/parts-list/cutting-diagrams/export'];
        yield 'cutlist.cuttingdiagram2d.write' => ['cutlist.cuttingdiagram2d.write', '/features/parts/parts-list/cutting-diagrams/export'];
        yield 'cutlist.packing' => ['cutlist.packing', '/features/parts/parts-list/packing'];
        yield 'cutlist.packing.write' => ['cutlist.packing.write', '/features/parts/parts-list/packing/export'];
        yield 'features.parts.parts-list.labels' => ['features.parts.parts-list.labels', '/features/parts/parts-list/printing-labels'];
        yield 'cutlist.labels' => ['cutlist.labels', '/features/parts/parts-list/printing-labels'];
        yield 'cutlist.layout' => ['cutlist.layout', '/features/parts/layout'];
        yield 'cutlist.write2d' => ['cutlist.write2d', '/features/parts/export-part-drawing/2d-projection'];
        yield 'cutlist.write3d' => ['cutlist.write3d', '/features/parts/export-part-drawing/3d-geometry'];
        yield 'outliner' => ['outliner', '/features/outliner'];
        yield 'outliner.options' => ['outliner.options', '/features/outliner'];
        yield 'outliner.edit' => ['outliner.edit', '/features/outliner'];
        yield 'importer' => ['importer', '/features/import'];
        yield 'importer.load' => ['importer.load', '/features/import'];
        yield 'importer.import' => ['importer.import', '/features/import'];
        yield 'i18n' => ['i18n', '/for-translators/general'];
    }

    /**
     * Links of the site and of the extension, without access.
     *
     * @dataProvider provideLinks
     */
    public function testLink(string $route, string $location): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath($route));

        self::assertTrue($client->getResponse()->isRedirect($location));
        self::assertNull($this->findLastAccess());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLinks(): iterable
    {
        yield 'Extension Warehouse' => ['core_opencutlist_ew', 'https://extensions.sketchup.com/extension/00f0bf69-7a42-4295-9e1c-226080814e3e/open-cut-list'];
        yield 'beta' => ['core_opencutlist_go_beta', 'https://www.opencutlist.org/lab'];
    }

    /**
     * @dataProvider provideStatsUsers
     */
    public function testStatsRights(?string $username, int $status): void
    {
        $client = null === $username ? static::createClient() : static::createClientAs($username);
        $client->request('GET', static::generatePath('core_opencutlist_stats'));

        self::assertSame($status, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function provideStatsUsers(): iterable
    {
        yield 'anonymous' => [null, 404];
        yield 'user' => [UserFixtures::USER, 404];
        yield 'admin' => [UserFixtures::ADMIN, 200];
    }

    /**
     * The users by day and by country, from the accesses the cron located (ladb:cron:opencutlist:access:analyze).
     */
    public function testStats(): void
    {
        $client = static::createClient();
        $client->request('GET', static::generatePath('core_opencutlist_download', ['v' => '7.0.0']));
        $access = $this->findLastAccess();
        $access->setAnalyzed(true);
        $access->setContinentCode('EU');
        $access->setCountryCode('FR');
        static::getEntityManager()->flush();

        $client = static::createClientAs(UserFixtures::ADMIN);
        $crawler = $client->request('GET', static::generatePath('core_opencutlist_stats'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('1 requêtes', $crawler->filter('h2')->text(null, false));
        $panels = $crawler->filter('.panel')->each(fn ($panel) => preg_replace('/\s+/', ' ', trim($panel->text(null, false))));
        self::assertContains('Upgrades - Utilisateurs / pays 1 utilisateurs | 1 pays France 1', $panels);
        self::assertContains('Manifests - Utilisateurs / pays 0 utilisateurs | 0 pays', $panels);

        // The next pages of the accesses, in XHR
        static::xhr($client, 'GET', static::generatePath('core_opencutlist_stats_page', ['page' => 1]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    private function findLastAccess(): ?Access
    {
        return static::getEntityManager()->getRepository(Access::class)->findOneBy([], ['id' => 'DESC']);
    }
}
