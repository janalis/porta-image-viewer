<?php

declare(strict_types=1);

use Porta\Image\ImageDecoder;
use Porta\Image\SvgConverter;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;

/**
 * The inline image viewer as a bundled plugin: the whole plugin is the ROUTING
 * CLAIM — image extensions open in the built-in image pane. The viewer
 * implementation (decoder, rasterizer, zoom/pan, the pane widget) stays in
 * `src/`, covered and container-built; disabling this plugin simply stops
 * routing, so images fall through to the text path's not-UTF-8 refusal.
 * Enabled by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}).
 * No menu entries on purpose: a viewer has nothing to invoke — its keys live
 * on the pane and its knobs are the settings rows below.
 *
 * SVG is claimed too, but only when the IDE can actually render one: it is
 * rasterized by an external converter ({@see SvgConverter}), so with none
 * installed — or with the `svg` setting off, for someone who would rather EDIT
 * their SVGs — the file stays on the text path where it has always been. The
 * converter itself is a CORE setting, read here through coreSetting(): a plugin
 * setting can only be read by the plugin that registered it, and the rasterizer
 * is a core service.
 */
return new class('image-viewer') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerSetting('maxBytes', 'Skip images larger than (bytes)', 'int', ImageDecoder::MAX_BYTES);
        $api->registerSetting('svg', 'Render SVG as an image', 'bool', true);

        $extensions = array_map(strtolower(...), ImageDecoder::EXTENSIONS);
        $api->registerViewer('image', function (string $path) use ($api, $extensions): bool {
            $isBitmap = in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), $extensions, strict: true);
            if (!$isBitmap && !$this->claimsSvg($api, $path)) {
                return false;
            }

            // Over the configured cap the file falls to the text path's
            // refusal instead of the viewer's — the routing IS the limit.
            return (int) filesize($path) <= max(1, (int) $api->setting('maxBytes'));
        });
    }

    /** An SVG is the viewer's only if the user wants it AND something can rasterize it. */
    private function claimsSvg(PluginApi $api, string $path): bool
    {
        return SvgConverter::isSvgPath($path)
            && (bool) $api->setting('svg')
            && (null !== $this->coreSetting('svgConverter') || null !== SvgConverter::discover());
    }
};
