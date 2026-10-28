import fs from 'node:fs';
import path from 'node:path';
import Symfony from '@symfony/reprise/vite';
import { minify } from 'terser';
import { createLogger, defineConfig } from 'vite';

// The CSS and JS of the site, written in web/build/ with their manifest.json, that the "build"
// package of the assets reads (app/config/config.yml): {{ asset('build/<name>.css', 'build') }}.
// One entry per group of files of the former {% stylesheets %} and {% javascripts %} tags of
// Assetic, with the same files in the same order: keep in sync with the templates that use them.
// castor app:assets:build (minified, as on the production server) and app:assets:watch.

const ASSETS = 'src/Ladb/CoreBundle/Resources/assets';

// Each file is compiled on its own (LESS), then the group is concatenated, as Assetic did. Assetic
// also passed the plain CSS files of a group with LESS files through LESS: it only changes their
// formatting, they are left as they are.
const styles = {
    'base': [
        `${ASSETS}/css/jquery-lazyloadxt/jquery.lazyloadxt.fadein.css`,
        `${ASSETS}/css/emojionearea/emojionearea.css`,
        `${ASSETS}/less/bootstrap/bootstrap.less`,
        `${ASSETS}/less/jquery-autocomplete/jquery-autocomplete.less`,
        `${ASSETS}/less/jquery-textcomplete/jquery-textcomplete.less`,
        `${ASSETS}/less/uikit/core/animation.less`,
        `${ASSETS}/less/uikit/core/nav.less`,
        `${ASSETS}/less/uikit/core/offcanvas.less`,
        `${ASSETS}/less/uikit/core/overlay.less`,
        `${ASSETS}/less/uikit/components/notify.less`,
        `${ASSETS}/less/bootstrap-markdown/bootstrap-markdown-v1.less`,
        `${ASSETS}/less/bootstrap-datetimepicker/bootstrap-datetimepicker.less`,
        `${ASSETS}/less/ladb/ladb.less`,
        `${ASSETS}/less/ladb/icons/ladbicons.less`,
    ],
    'blueimp-gallery': [
        `${ASSETS}/css/blueimp-gallery/blueimp-gallery.css`,
    ],
    'funding': [
        `${ASSETS}/less/ladb/icons/ladbcc.less`,
    ],
    'map': [
        `${ASSETS}/css/leaflet/leaflet.css`,
        `${ASSETS}/css/leaflet-markercluster/MarkerCluster.css`,
        `${ASSETS}/less/leaflet-markercluster/MarkerCluster.Ladb.less`,
    ],
    'qa': [
        `${ASSETS}/less/ladb/layout/qa.less`,
    ],
    'school': [
        `${ASSETS}/less/ladb/layout/school.less`,
    ],
    'selectize': [
        `${ASSETS}/less/selectize/selectize.ladb.less`,
    ],
    'stars': [
        `${ASSETS}/less/jquery-barrating/ladb-stars.less`,
    ],
    'tocify': [
        `${ASSETS}/css/jquery-tocify/jquery.tocify.css`,
    ],
    'workflow-diagram': [
        `${ASSETS}/less/ladb/layout/workflow.less`,
    ],
    'workflow-workspace': [
        `${ASSETS}/less/ladb/component/inputcolor.less`,
        `${ASSETS}/less/ladb/component/inputduration.less`,
        `${ASSETS}/less/ladb/layout/workflow.less`,
    ],
};

// Classic scripts, written for the global scope (var and function declarations, jQuery and its
// plugins as globals): concatenated as they are, never bundled as modules
const scripts = {
    'base': [
        'vendor/components/jqueryui/jquery-ui.js',
        'vendor/components/jqueryui/ui/widgets/sortable.js',
        'vendor/components/jqueryui/ui/effect.js',
        'vendor/components/jqueryui/ui/effects/effect-highlight.js',
        'vendor/components/masonry/masonry.pkgd.js',
        'vendor/moment/moment/moment.js',
        'vendor/moment/moment/locale/fr.js',
        'vendor/eonasdan/bootstrap-datetimepicker/src/js/bootstrap-datetimepicker.js',
        `${ASSETS}/js/modernizr/modernizr-custom.js`,
        `${ASSETS}/js/jquery-form/jquery.form.js`,
        `${ASSETS}/js/jquery-ui-touch-punch/jquery.ui.touch-punch.js`,
        `${ASSETS}/js/jquery-textcomplete/jquery.textcomplete.min.js`,
        `${ASSETS}/js/jquery-autocomplete/jquery.autocomplete.js`,
        'vendor/twitter/bootstrap/js/modal.js',
        'vendor/twitter/bootstrap/js/tooltip.js',
        'vendor/twitter/bootstrap/js/popover.js',
        'vendor/twitter/bootstrap/js/button.js',
        'vendor/twitter/bootstrap/js/collapse.js',
        'vendor/twitter/bootstrap/js/dropdown.js',
        'vendor/twitter/bootstrap/js/affix.js',
        'vendor/twitter/bootstrap/js/tab.js',
        'vendor/uikit/uikit/src/js/core/core.js',
        'vendor/uikit/uikit/src/js/core/offcanvas.js',
        'vendor/uikit/uikit/src/js/core/scrollspy.js',
        'vendor/uikit/uikit/src/js/components/notify.js',
        `${ASSETS}/js/bootbox/bootbox.js`,
        `${ASSETS}/js/bootstrap-markdown/bootstrap-markdown-v1.js`,
        `${ASSETS}/js/jquery-scrollto/jquery.scrollto.js`,
        `${ASSETS}/js/jquery-jscroll/jquery.jscroll.js`,
        `${ASSETS}/js/jquery-cornerslider/jquery.cornerslider.js`,
        `${ASSETS}/js/jquery-sticky/jquery.sticky.js`,
        `${ASSETS}/js/jquery-lazy/jquery.lazy.js`,
        `${ASSETS}/js/jquery-lazy/jquery.lazy.ajax.js`,
        `${ASSETS}/js/readmorejs/readmore.js`,
        `${ASSETS}/js/emojionearea/emojionearea.js`,
        `${ASSETS}/js/autosize/autosize.js`,

        `${ASSETS}/js/javascript-load-image/load-image.min.js`,
        `${ASSETS}/js/javascript-canvas-to-blob/canvas-to-blob.min.js`,
        `${ASSETS}/js/infinite/collections.js`,
        'vendor/blueimp/jquery-file-upload/js/jquery.fileupload.js',
        'vendor/blueimp/jquery-file-upload/js/jquery.iframe-transport.js',
        'vendor/blueimp/jquery-file-upload/js/jquery.fileupload-process.js',
        'vendor/blueimp/jquery-file-upload/js/jquery.fileupload-image.js',
        'vendor/blueimp/jquery-file-upload/js/jquery.fileupload-validate.js',

        `${ASSETS}/js/ladb/jquery.ladb.counters.js`,
        `${ASSETS}/js/ladb/jquery.ladb.hrefajax.js`,
        `${ASSETS}/js/ladb/jquery.ladb.textcompletify.js`,
        `${ASSETS}/js/ladb/jquery.ladb.sharebuttonsbuilder.js`,
        `${ASSETS}/js/ladb/jquery.ladb.scrollto.js`,
        `${ASSETS}/js/ladb/jquery.ladb.appendscrolltotopbtn.js`,
        `${ASSETS}/js/ladb/jquery.ladb.autopager.js`,
        `${ASSETS}/js/ladb/jquery.ladb.maparea.js`,
        `${ASSETS}/js/ladb/jquery.ladb.smartsearch.js`,
        `${ASSETS}/js/ladb/jquery.ladb.topbartranslucent.js`,
        `${ASSETS}/js/ladb/jquery.ladb.commentwidget.js`,
        `${ASSETS}/js/ladb/jquery.ladb.votewidget.js`,
        `${ASSETS}/js/ladb/jquery.ladb.reviewwidget.js`,
        `${ASSETS}/js/ladb/jquery.ladb.feedbackwidget.js`,
        `${ASSETS}/js/ladb/jquery.ladb.boxlinkclick.js`,
        `${ASSETS}/js/ladb/ladb-common.js`,
        `${ASSETS}/js/ladb/ladb-reports.js`,
        `${ASSETS}/js/ladb/ladb-pictures.js`,
        `${ASSETS}/js/ladb/ladb-webpush-client.js`,
    ],
    'blueimp-gallery': [
        `${ASSETS}/js/blueimp-gallery/blueimp-gallery.js`,
        `${ASSETS}/js/blueimp-gallery/jquery.blueimp-gallery.js`,
    ],
    'events': [
        `${ASSETS}/js/jquery-clndr/clndr.min.js`,
        `${ASSETS}/js/ladb/jquery.ladb.eventspage.js`,
    ],
    'funding': [
        `${ASSETS}/js/jquery-circle-progress/jquery.circle-progress.js`,
        `${ASSETS}/js/ladb/jquery.ladb.fundingdashboard.js`,
        `${ASSETS}/js/ladb/jquery.ladb.fundingwidget.js`,
    ],
    'funding-charge-infos': [
        `${ASSETS}/js/ladb/jquery.ladb.fundingchargeinfos.js`,
    ],
    // Internet Explorer 8 and before only
    'ie': [
        'vendor/afarkas/html5shiv/dist/html5shiv.js',
        `${ASSETS}/js/respond/respond.js`,
    ],
    'jquery': [
        'vendor/components/jquery/jquery.js',
    ],
    'map': [
        `${ASSETS}/js/leaflet/leaflet-src.js`,
        `${ASSETS}/js/leaflet-markercluster/leaflet.markercluster-src.js`,
    ],
    'plan': [
        `${ASSETS}/js/ladb/jquery.ladb.planpage.js`,
    ],
    'question': [
        `${ASSETS}/js/ladb/jquery.ladb.questionpage.js`,
    ],
    'school': [
        `${ASSETS}/js/ladb/jquery.ladb.schoolpage.js`,
        `${ASSETS}/js/ladb/ladb-values.js`,
    ],
    'settings': [
        `${ASSETS}/js/ladb/jquery.ladb.settingspage.js`,
    ],
    'stars-values': [
        `${ASSETS}/js/jquery-barrating/jquery.barrating.js`,
        `${ASSETS}/js/ladb/ladb-values.js`,
    ],
    'thread': [
        `${ASSETS}/js/ladb/jquery.ladb.threadpage.js`,
    ],
    'tocify': [
        `${ASSETS}/js/jquery-tocify/jquery.tocify.js`,
    ],
    'values': [
        `${ASSETS}/js/ladb/ladb-values.js`,
    ],
    'workflow-diagram': [
        `${ASSETS}/js/jquery-panzoom/jquery.panzoom.min.js`,
        `${ASSETS}/js/ladb/jquery.ladb.workflowdiagram.js`,
    ],
    'workflow-workspace': [
        `${ASSETS}/js/jquery-panzoom/jquery.panzoom.min.js`,
        `${ASSETS}/js/ladb/jquery.ladb.inputcolor.js`,
        `${ASSETS}/js/ladb/jquery.ladb.inputduration.js`,
        `${ASSETS}/js/ladb/jquery.ladb.remotemodal.js`,
        `${ASSETS}/js/ladb/jquery.ladb.workflowworkspace.js`,
    ],
    'youtook': [
        `${ASSETS}/js/ladb/jquery.ladb.youtookwidget.js`,
    ],
};

// Assetic did not minify them (no "?closure" filter)
const UNMINIFIED_SCRIPTS = ['map'];

const STYLE_PREFIX = 'ladb-styles:';

/**
 * A stylesheet entry per group: a virtual stylesheet that imports its files, each one compiled
 * on its own by its preprocessor (Vite inlines the @import of a CSS file).
 */
function styleGroups(groups) {
    return {
        name: 'ladb:style-groups',
        resolveId(id) {
            if (id.startsWith(STYLE_PREFIX)) {
                return `\0${id}`;
            }
        },
        load(id) {
            if (!id.startsWith(`\0${STYLE_PREFIX}`)) {
                return;
            }
            const name = id.slice(STYLE_PREFIX.length + 1, -'.css'.length);

            return groups[name].map((file) => `@import ${JSON.stringify(path.resolve(file))};\n`).join('');
        },
    };
}

/**
 * The scripts of each group concatenated as they are, written as an asset that the plugin of
 * Reprise puts in manifest.json (build/<name>.js): Vite would turn them into modules.
 */
function scriptGroups(groups, { minified }) {
    return {
        name: 'ladb:script-groups',
        async buildStart() {
            for (const [name, files] of Object.entries(groups)) {
                files.forEach((file) => this.addWatchFile(path.resolve(file)));
                // A separator between two files, in case one of them does not end its last statement
                let code = files.map((file) => fs.readFileSync(file, 'utf8')).join('\n;\n');
                if (minified && !UNMINIFIED_SCRIPTS.includes(name)) {
                    // Terser, as the closure compiler of Assetic: a script whose top level
                    // declarations stay globals, and no syntax newer than the one of the sources
                    // (the minifier of Vite writes template literals and "**")
                    code = (await minify(code, { toplevel: false })).code;
                }
                this.emitFile({ type: 'asset', name: `${name}.js`, source: code });
            }
        },
    };
}

/**
 * The absolute URLs of the stylesheets (/bundles/...) are files of web/, installed by
 * assets:install: they stay as they are, without a warning each.
 */
function createQuietLogger() {
    const logger = createLogger();
    const warnOnce = logger.warnOnce;
    logger.warnOnce = (message, options) => {
        if (!/^\s*\/bundles\/\S+ referenced in .* didn't resolve at build time/.test(message)) {
            warnOnce(message, options);
        }
    };

    return logger;
}

export default defineConfig(({ mode }) => {
    // castor app:assets:watch: neither minified CSS nor minified JS
    const minified = 'development' !== mode;

    return {
        customLogger: createQuietLogger(),
        input: Object.fromEntries(Object.keys(styles).map((name) => [name, `${STYLE_PREFIX}${name}.css`])),
        build: {
            minify: minified,
            // The CSS loses its spaces and comments, nothing else, as with yuicompressor. Lightning
            // CSS, the default minifier, rounds the numbers (1.42857143 → 1.42857: the text moves
            // by a fraction of a pixel), and removes the vendor prefixes or rewrites the colors
            // for the browsers it targets; esbuild adds prefixes for them (-webkit-hyphens: Safari
            // would hyphenate). esbuild, without target nor syntax minification, changes neither.
            cssMinify: minified ? 'esbuild' : false,
            cssTarget: 'esnext',
            // Terser takes most of the build, on purpose (scriptGroups())
            rolldownOptions: { checks: { bundlerTimings: false } },
        },
        esbuild: {
            // The licenses (/*! ... */), as yuicompressor
            legalComments: 'inline',
            minifyIdentifiers: false,
            minifySyntax: false,
            minifyWhitespace: true,
        },
        plugins: [
            styleGroups(styles),
            scriptGroups(scripts, { minified }),
            Symfony({ outputPath: 'web/build', publicPath: '/build/' }),
        ],
    };
});
