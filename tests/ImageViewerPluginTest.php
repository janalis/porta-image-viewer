<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPluginRuntime;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** What the plugin claims, and the knob that bounds it — asked of the plugin itself, no IDE booted. */
final class ImageViewerPluginTest extends TestCase
{
    use PreservesPath;

    private PluginManager $manager;
    private PluginSettings $settings;

    /** @param array<string, bool|int|string> $values */
    private function activate(TempProject $project, array $values = []): void
    {
        \putenv('PATH=/nonexistent'); // no converter to discover: only the setting names one
        $this->settings = new PluginSettings($project->dir, ['image-viewer' => $values]);
        $this->manager = new PluginManager(new EventDispatcher(), $this->settings);
        $this->manager->wireRuntime(TestPluginRuntime::create(new CodeEditorWidget(), new StatusBarWidget()));
        $api = $this->manager->api('image-viewer', $project->dir, $this->settings);
        $plugin = require \dirname(__DIR__).'/porta.php';
        $plugin->activate($api);
        $this->manager->commit($api, $plugin);
    }

    /** A mebibyte a press, from nothing to 256 MiB: a byte a press would be unusable. */
    public function testTheSizeCapStepsByTheMebibyteWithinItsBounds(): void
    {
        $this->activate(new TempProject([]));

        $row = \array_find($this->settings->rows('image-viewer'), static fn (object $row): bool => 'pluginSetting:maxBytes' === $row->key);

        $this->assertSame([0, 268_435_456, 1_048_576], [$row?->min, $row?->max, $row?->step]);
    }

    /** The cap is the largest size claimed, not the first refused; set to nothing, a byte still opens. */
    public function testAnImageExactlyAtTheCapIsClaimed(): void
    {
        $project = new TempProject(['ten.png' => \str_repeat('x', times: 10), 'eleven.png' => \str_repeat('x', times: 11), 'one.png' => 'x', 'two.png' => 'xx']);
        $this->activate($project, ['maxBytes' => 10]);

        $this->assertSame('image', $this->manager->viewerFor($project->path('ten.png')));
        $this->assertNull($this->manager->viewerFor($project->path('eleven.png')));

        $this->activate($project, ['maxBytes' => 1]); // seeded, never persisted: a write would land in the suite's shared home
        $this->assertSame('image', $this->manager->viewerFor($project->path('one.png')));
        $this->assertNull($this->manager->viewerFor($project->path('two.png')));

        $this->activate($project, ['maxBytes' => 0]);
        $this->assertSame('image', $this->manager->viewerFor($project->path('one.png')), 'a cap of nothing still claims a byte');
    }

    /** Only an SVG is previewed beside its text — whatever converter there is — and one named in the setting is enough. */
    public function testOnlyAnSvgIsPreviewedAndANamedConverterIsEnough(): void
    {
        $project = new TempProject(['logo.svg' => '<svg/>', 'notes.txt' => 'notes']);
        $this->activate($project, ['svgConverter' => '/opt/bin/rsvg-convert']);

        $this->assertSame('image', $this->manager->previewFor($project->path('logo.svg')), 'named, not discovered');
        $this->assertNull($this->manager->previewFor($project->path('notes.txt')), 'a converter makes no picture of text');
    }
}
