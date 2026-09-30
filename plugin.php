<?php

declare(strict_types=1);

use Porta\Editor\PaneServices;
use Porta\ImageViewer\ImageDecoder;
use Porta\ImageViewer\ImageViewerWidget;
use Porta\ImageViewer\SvgConverter;
use Porta\ImageViewer\SvgRasterizer;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\LegacyCoreSettings;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\ProjectPaths;
use Porta\Widget\EditorPaneWidget;

/**
 * The inline image viewer as a bundled plugin: the routing claim AND the pane
 * behind it. Everything the viewer is — the decoder, the half-block rasterizer,
 * the zoom/pan model, the SVG converter and the pane widget — is this plugin's
 * own code (`src/`, namespace `Porta\ImageViewer`), graded by the same gates as
 * core. Disabling the plugin stops the routing, so images fall through to the
 * text path's not-UTF-8 refusal.
 *
 * The graph viewer DEPENDS on this one (`extra.porta.requires` in its
 * composer.json): a graph
 * is painted with the same rasterizer at the same half-block resolution, and
 * two copies of a measured sampler would be two things to keep in step.
 * Enabled by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}).
 * No menu entries on purpose: a viewer has nothing to invoke — its keys live
 * on the pane and its knobs are the settings rows below.
 *
 * SVG is claimed too, but only when the IDE can actually render one: it is
 * rasterized by an external converter ({@see SvgConverter}), so with none
 * installed — or with the `svg` setting off — the file stays on the text path
 * where it has always been. Claimed as a live PREVIEW: the markup opens in the
 * editor with the picture beside it, redrawn as it is edited. The
 * converter path is this plugin's own `svgConverter` setting (a core one
 * until the plugin owned it; pre-move values adopt once).
 */
return new class('image-viewer') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        // The hint names the decoder backstop because this setting is about
        // ROUTING, not decoding: raising it past 20 MB claims a bigger bitmap
        // so the refusal comes from the viewer ("Image too large") instead of
        // the text path's "Not a UTF-8 text file", which is the point — but it
        // does not make one displayable, and without saying so the row reads
        // like a cap that simply does not work.
        // Stepping a byte cap ONE BYTE at a time is unusable: 20 MB is
        // 20,971,520 keypresses from zero. A mebibyte per press, floored at 0
        // (claim nothing) and capped at 256 MiB.
        $api->registerSetting(
            'maxBytes',
            'Skip images larger than (bytes)',
            'int',
            ImageDecoder::MAX_BYTES,
            hint: 'routing only; bitmaps over 20 MB are refused by the decoder either way',
            min: 0,
            max: 268_435_456,
            step: 1_048_576,
        );
        $api->registerSetting('svg', 'Render SVG as an image', 'bool', true);
        // A CORE setting until this plugin owned it (the git-keys precedent).
        // The rasterizer resolves it per conversion, so an edit applies live.
        $api->registerSetting('svgConverter', 'SVG converter path', hint: 'empty = discover on PATH');
        $legacy = new LegacyCoreSettings($api->projectRoot(), 'image-viewer', ['svgConverter' => 'svgConverter']);
        $api->onReady(static function () use ($api, $legacy): void {
            foreach ($legacy->adoptions() as $key => $value) {
                $api->changeSetting($key, $value);
            }
        });
        // What a pre-move core key still answers, dropped the moment the
        // plugin's own key is written — the adoption is a boot snapshot of the
        // file, so keeping it would shadow a later edit forever.
        $adopted = $legacy->adoptions();
        // By REFERENCE, and never an arrow fn: a by-value capture would keep
        // answering with the adopted value after the hook below cleared it.
        $api->onSettingChanged(static function (string $key) use (&$adopted): void {
            unset($adopted[$key]);
        });

        $decoder = new ImageDecoder();
        $svg = new SvgRasterizer($api->projectRoot(), static function () use (&$adopted, $api): ?string {
            return self::path($adopted['svgConverter'] ?? $api->setting('svgConverter'));
        });
        // The keys this pane binds — declared so Settings → Keymap lists them
        // and .porta.json may rebind them. The DEFAULTS live in the pane's own
        // getDefaultKeybindings(); what core needs is the id and the label.
        $api->registerActions([
            'image_zoom_in' => 'Image: Zoom In',
            'image_zoom_out' => 'Image: Zoom Out',
            'image_zoom_fit' => 'Image: Fit to Window',
            'image_zoom_actual' => 'Image: Actual Size',
            'image_pan_left' => 'Image: Pan Left',
            'image_pan_right' => 'Image: Pan Right',
            'image_pan_up' => 'Image: Pan Up',
            'image_pan_down' => 'Image: Pan Down',
        ]);
        $api->registerPane(
            'image',
            static fn (PaneServices $pane): EditorPaneWidget => new ImageViewerWidget($svg, $pane->translator, $pane->scheduler, $pane->styles, $pane->graphics),
            // A bitmap rides as decoded pixels, an SVG as its SOURCE — the pane
            // rasterizes that per zoom level, so which pixels depends on the zoom.
            // No size guard on the vector branch: there is no decoder to protect,
            // and the claim's own maxBytes IS the limit.
            static function (string $path) use ($api, $decoder): mixed {
                if (SvgConverter::isSvgPath($path)) {
                    $source = (string) file_get_contents($path);
                    if ('' !== $source && !mb_check_encoding($source, encoding: 'UTF-8')) {
                        throw new \RuntimeException($api->trans('Not a UTF-8 text file'));
                    }

                    return $source;
                }
                if ((int) filesize($path) > ImageDecoder::MAX_BYTES) {
                    throw new \RuntimeException($api->trans('Image too large (> 20 MB)'));
                }

                return $decoder->decode($path) ?? throw new \RuntimeException($api->trans('Cannot display image %file%', ['%file%' => basename($path)]));
            },
        );

        $extensions = array_map(strtolower(...), ImageDecoder::EXTENSIONS);
        $api->registerViewer('image', function (string $path) use ($api, $extensions): bool {
            $isBitmap = ProjectPaths::hasExtension($path, $extensions);
            if (!$isBitmap && !$this->claimsSvg($api, $path)) {
                return false;
            }

            // Over the configured cap the file falls to the text path's
            // refusal instead of the viewer's — the routing IS the limit.
            return (int) filesize($path) <= max(1, (int) $api->setting('maxBytes'));
        });
        // An SVG is TEXT as much as it is a picture, so it opens in the editor
        // with the rendering beside it, re-rasterized as the markup changes.
        // Asked before the viewer claim above, which still takes an SVG too
        // large for the editor to hold.
        $api->registerPreview('image', fn (string $path): bool => $this->claimsSvg($api, $path));
    }

    /** An SVG is the viewer's only if the user wants it AND something can rasterize it. */
    private function claimsSvg(PluginApi $api, string $path): bool
    {
        return SvgConverter::isSvgPath($path) && (bool) $api->setting('svg') && (null !== self::path($api->setting('svgConverter')) || null !== SvgConverter::discover());
    }

    /** A path setting's '' -to-null: empty means "discover", which the rasterizer spells as null. */
    private static function path(bool|int|string $value): ?string
    {
        return '' !== (string) $value ? (string) $value : null;
    }
};
