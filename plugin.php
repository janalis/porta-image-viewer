<?php

declare(strict_types=1);

use Porta\Image\ImageDecoder;
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
 * on the pane and its one knob is the settings row below.
 */
return new class('image-viewer') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $api->registerSetting('maxBytes', 'Skip images larger than (bytes)', 'int', ImageDecoder::MAX_BYTES);

        $extensions = array_map(strtolower(...), ImageDecoder::EXTENSIONS);
        $api->registerViewer('image', static function (string $path) use ($api, $extensions): bool {
            if (!in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), $extensions, strict: true)) {
                return false;
            }

            // Over the configured cap the file falls to the text path's
            // refusal instead of the viewer's — the routing IS the limit.
            return (int) filesize($path) <= max(1, (int) $api->setting('maxBytes'));
        });
    }
};
