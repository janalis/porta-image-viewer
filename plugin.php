<?php

declare(strict_types=1);

use Porta\Image\ImageDecoder;
use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;

/**
 * The inline image viewer as a bundled plugin: the whole plugin is the ROUTING
 * CLAIM — image extensions open in the built-in image pane. The viewer
 * implementation (decoder, rasterizer, zoom/pan, the pane widget) stays in
 * `src/`, covered and container-built; disabling this plugin simply stops
 * routing, so images fall through to the text path's not-UTF-8 refusal.
 * Enabled by default ({@see \Porta\Config\IdeConfig::DEFAULT_ENABLED_PLUGINS}).
 */
return new class implements PortaPlugin {
    public function name(): string
    {
        return 'image-viewer';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerViewer('image', ImageDecoder::EXTENSIONS);
    }
};
