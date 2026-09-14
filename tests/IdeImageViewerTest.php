<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferNavigator;
use Porta\Editor\BufferService;
use Porta\Editor\EditorPanes;
use Porta\Editor\OpenFocus;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The inline image viewer: half-block rendering, zoom/pan, tab
 * safety and rejection of oversized or corrupt files.
 * Split out of the oversized IdeEdgeCasesTest.
 */
final class IdeImageViewerTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    /**
     * A project with ONLY this plugin enabled.
     *
     * Booting an IDE loads every default-enabled bundled plugin, and any file
     * they touch lands in the serial plugin-coverage report — so an unrelated
     * plugin's uncovered line would fail this suite. Naming the list keeps each
     * plugin's coverage its own tests' business.
     *
     * @param array<string, string> $files
     */
    private static function project(array $files): TempProject
    {
        /** @var array<string, mixed> $config */
        $config = \json_decode($files['.porta.json'] ?? '{}', associative: true);
        $config['enabledPlugins'] = ['image-viewer'];

        return new TempProject([...$files, '.porta.json' => (string) \json_encode($config)]);
    }

    public function testThePaneIsHandedTheSameTabHandlerCorePanesGet(): void
    {
        // A plugin pane's tab keys — next/prev/close/go-to-file — work only
        // because the registrar hands it the IDE's own ViewerTabHandler. Core
        // cannot assert this: the pane type is this plugin's.
        $project = self::project(['img.png' => PngFixture::bytes(4, 4)]);
        \putenv('PATH=/nonexistent');

        $this->runIde(
            $project,
            $project->path('img.png'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $pane = Services::resolve($ide, EditorPanes::class)->paneFor('image');
                    $this->assertNotNull($pane, 'the bundled plugin registered its pane');
                    $this->assertInstanceOf(BufferNavigator::class, Probe::get($pane, 'handler'));
                },
            ],
        );
    }

    public function testImageOpensInlineWithHalfBlocksZoomAndPan(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project(['img.png' => $this->twoColorPng(64, 64)]);

        $this->runIde(
            $project,
            $project->path('img.png'),
            [
                200 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'the viewer takes editor-area focus');
                    $output = $terminal->getOutput();
                    $this->assertStringContainsString('▀', $output, 'half-block cells rendered');
                    $this->assertStringContainsString('38;2;255;0;0', $output, 'red pixels as truecolor fg');
                    $this->assertStringContainsString('48;2;0;0;255', $output, 'blue pixels as truecolor bg');
                    $this->assertStringContainsString('img.png', $output);
                    $this->assertStringContainsString('64 × 64', $output, 'dimensions in the status bar');
                    $this->assertStringContainsString('Fit', $output, 'fit zoom label');
                    $tui->handleInput('='); // zoom in one ladder step
                },
                340 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertStringContainsString('100%', $terminal->getOutput(), 'zoomed to the next step');
                    // Pan with arrows; wheel + click routing over the image pane.
                    $tui->handleInput("\x1b[C");
                    $tui->handleInput("\x1b[<64;80;10M"); // wheel down over the image → pans
                    $tui->handleInput("\x1b[<67;80;10M"); // horizontal wheel → pans sideways
                    $tui->handleInput("\x1b[<0;80;10M"); // left click → keeps viewer focus
                    $tui->handleInput("\x1b[<0;80;10m");
                    $tui->handleInput("\x1b[<80;80;10M"); // ctrl+wheel up → zoom in toward the pointer
                },
                480 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertStringContainsString('200%', $terminal->getOutput(), 'ctrl+wheel zoomed a ladder step');
                    $tui->handleInput("\x1b[<81;80;10M"); // ctrl+wheel down → back out
                    $this->assertSame('100%', $tui->getById('image-viewer')->zoomLabel());
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'click over the image keeps the viewer focused');
                    // Go-to-file is now the editor-hosted picker (a files-only
                    // Search Everywhere), so with only an image tab it refuses
                    // with the same flash the other editor modals give.
                    $tui->handleInput("\x10"); // ctrl+p
                    $this->frame($tui);
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'go-to-file refuses with only an image tab');
                    $this->assertStringContainsString('Not available in this view', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // The menu composites over the image pane (no hole), and closing
                    // it restores focus to the viewer (editor-area-aware default).
                    $tui->handleInput("\x1b[21~"); // F10 → menu
                    $this->awaitPainted($tui, $terminal, 'Ctrl+Q');
                    $this->assertStringContainsString('Ctrl+Q', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the File dropdown composites over the image');
                    $tui->handleInput("\x1b"); // close the menu
                },
                620 => function ($tui): void {
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'menu close returns focus to the viewer');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testATrackpadCtrlScrollBurstGlidesWhereASingleNotchSteps(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project(['img.png' => $this->twoColorPng(64, 64)]);

        $this->runIde(
            $project,
            $project->path('img.png'),
            [
                200 => function ($tui): void {
                    $viewer = $tui->getById('image-viewer');
                    $tui->handleInput('1'); // 100%, off the fit scale
                    $this->assertSame('100%', $viewer->zoomLabel());

                    // What a trackpad sends for one pinch-out: a burst of
                    // ctrl+wheel notches, microseconds apart in one step. The
                    // FIRST cannot be known to belong to a gesture yet, so it
                    // steps the ladder like a mouse click would…
                    $tui->handleInput("\x1b[<80;40;10M");
                    $this->assertSame('200%', $viewer->zoomLabel(), 'the opening notch is still a rung');

                    // …and everything after it glides, instead of crossing the
                    // whole ladder the way one flick used to.
                    foreach (\range(1, end: 4) as $ignored) {
                        $tui->handleInput("\x1b[<80;40;10M");
                    }
                    $this->assertSame('272%', $viewer->zoomLabel(), '200% × 1.08⁴ — between the rungs');
                },
                // A later step is >100ms away, so this notch stands alone again
                // and snaps to the rung above 272%.
                400 => function ($tui): void {
                    $tui->handleInput("\x1b[<80;40;10M");
                    $this->assertSame('400%', $tui->getById('image-viewer')->zoomLabel(), 'an isolated notch still steps');
                },
            ],
        );
    }

    public function testImageTabSwitchingSavingAndClosingKeepTheEditorSafe(): void
    {
        \putenv('PATH=/nonexistent');
        $png = $this->twoColorPng(8, 4);
        $project = self::project(['a.php' => "<?php\n// code\n", 'img.png' => $png]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile($project->path('img.png'), OpenFocus::Editor);
                    $this->frame($tui);
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $this->assertNull($editor->getContext(), 'the editor is detached under an image tab');
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId());
                    // Ctrl+S reaches the viewer (unbound) and saveBuffer guards anyway.
                    $tui->handleInput("\x13");
                    $service->saveCurrentFile();
                },
                340 => function ($tui, $terminal, $ide) use ($project, $png): void {
                    $this->assertSame($png, (string) \file_get_contents($project->path('img.png')), 'saving never writes over the image');
                    $tui->handleInput("\x1b[1;5D"); // ctrl+left → back to the code tab
                    $this->frame($tui);
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath());
                    $this->assertNotNull($tui->getById('editor')->getContext(), 'the editor is re-attached');
                    $tui->handleInput("\x1b[1;5C"); // ctrl+right → the image again
                },
                480 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->frame($tui);
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId());
                    // An external change re-decodes on the next poll; garbage keeps the old pixels.
                    \file_put_contents($project->path('img.png'), $this->twoColorPng(16, 2));
                    \touch($project->path('img.png'), \time() + 2); // force a new disk stamp within the same second
                    $service = Services::resolve($ide, BufferService::class);
                    $service->pollFilesystem();
                    $buffers = Services::resolve($ide, BufferManager::class);
                    $this->assertSame(16, $buffers->active()?->panePayload?->width, 'externally changed image reloaded');
                    $this->awaitPainted($tui, $terminal, 'Reloaded img.png');
                    $this->assertStringContainsString('Reloaded img.png', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    \file_put_contents($project->path('img.png'), data: 'not a png anymore');
                    \touch($project->path('img.png'), \time() + 4);
                    $service->pollFilesystem();
                    $this->assertSame(16, $buffers->active()?->panePayload?->width, 'undecodable bytes keep the old pixels');
                    $tui->handleInput("\x1bw"); // alt+w → close the image tab
                },
                620 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath(), 'closing the image tab restores the editor');
                    $this->assertNotNull($tui->getById('editor')->getContext());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testImageTabGuardsEditorHostedModals(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project(['a.php' => "<?php\n", 'img.png' => $this->twoColorPng(4, 4)]);

        // With only the image open, the editor-hosted modals refuse with a flash.
        $this->runIde(
            $project,
            $project->path('img.png'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b[101;6u"); // ctrl+shift+e → search everywhere
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → find in files (same guard)
                    $this->awaitPainted($tui, $terminal, 'Not available in this view');
                    $this->assertStringContainsString('Not available in this view', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        // With a text tab open too, the modal hops to it first.
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui, $terminal, $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('img.png'), OpenFocus::Editor);
                    $tui->handleInput("\x1b[102;6u"); // ctrl+shift+f → find in files
                },
                340 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertStringEndsWith('a.php', (string) $tui->getById('editor')->getFilePath(), 'hopped to the text tab');
                    $this->assertStringContainsString('Find in Files', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the modal opened over it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testImageOpenRejectsOversizedCorruptAndLastTabCloseEmptiesTheEditor(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project([
            // The plugin's maxBytes claim is raised past the core decoder cap,
            // so the oversized file still ROUTES to the image path and the
            // core backstop refuses it (the default claim would drop it to the
            // text path's own 2 MB refusal instead).
            '.porta.json' => (string) \json_encode(['plugins' => ['image-viewer' => ['maxBytes' => 30 * 1024 * 1024]]]),
            'fake.png' => 'not an image at all',
            'img.png' => $this->twoColorPng(4, 4),
        ]);
        \file_put_contents($project->path('big.png'), \str_repeat('x', 21 * 1024 * 1024));

        $this->runIde(
            $project,
            null,
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile($project->path('big.png'));
                    $this->awaitPainted($tui, $terminal, 'Image too large (> 20 MB)');
                    $this->assertStringContainsString('Image too large (> 20 MB)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $service->openFile($project->path('fake.png'));
                    $this->awaitPainted($tui, $terminal, 'Cannot display image fake.png');
                    $this->assertStringContainsString('Cannot display image fake.png', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    // Open the good one, then close the last tab: the editor pane empties.
                    $service->openFile($project->path('img.png'), OpenFocus::Editor);
                    $tui->handleInput("\x1bw");
                },
                340 => function ($tui): void {
                    $this->frame($tui);
                    $this->assertNull($tui->getById('editor')->getFilePath(), 'empty editor after the last tab closed');
                    $this->assertNotNull($tui->getById('editor')->getContext(), 'editor pane restored');
                    $this->assertSame('tree', $tui->getFocus()?->getId());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** PNG bytes for a $width×$height image split vertically: left half red, right half blue. */
    private function twoColorPng(int $width, int $height): string
    {
        $gd = \imagecreatetruecolor($width, $height);
        \imagefilledrectangle($gd, x1: 0, y1: 0, x2: \intdiv($width, num2: 2) - 1, y2: $height - 1, color: (int) \imagecolorallocate($gd, red: 255, green: 0, blue: 0));
        \imagefilledrectangle($gd, \intdiv($width, num2: 2), y1: 0, x2: $width - 1, y2: $height - 1, color: (int) \imagecolorallocate($gd, red: 0, green: 0, blue: 255));
        \ob_start();
        \imagepng($gd);

        return (string) \ob_get_clean();
    }

    public function testReopeningAChangedImageRefreshesThePixelsImmediately(): void
    {
        \putenv('PATH=/nonexistent');
        $project = self::project(['img.png' => $this->twoColorPng(64, 64), 'a.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('img.png'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->awaitPainted($tui, $terminal, '64 × 64');
                    $this->assertStringContainsString('64 × 64', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

                    // Switch away, change the file on disk, and explicitly re-open:
                    // the payload refreshes without waiting out the 1s reconciler.
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile((string) \realpath($project->path('a.php')));
                    \file_put_contents($project->path('img.png'), data: $this->twoColorPng(32, 16));
                    $service->openFile((string) \realpath($project->path('img.png')));
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '32 × 16'), what: 'the refreshed dimensions');
                },
            ],
        );
    }

    private const string SMALL_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect width="100" height="50" fill="#284"/></svg>';

    /** The committed converter stub, driven through the plugin.s own svgConverter setting. */
    private static function fakeConverter(): string
    {
        return \dirname(__DIR__, levels: 3).'/tests/Support/fake-rsvg';
    }

    /**
     * The stub runs via `#!/usr/bin/env php`, so PATH must resolve `php` — the
     * graph suite's php-only bin dir, for the same reason.
     */
    private function phpOnlyPath(TempProject $project): void
    {
        $bin = $project->path('.php-only-bin');
        \mkdir($bin);
        \symlink(\PHP_BINARY, $bin.'/php');
        \putenv('PATH='.$bin);
    }

    public function testAPreMoveSvgConverterAdoptsOnceAndWritesThrough(): void
    {
        // The core `svgConverter` of an older project: the plugin adopts it
        // (the rasterizer resolves the stub, so the SVG paints) and writes it
        // through to plugins.image-viewer from onReady, so the file stops
        // answering the same question twice.
        $project = self::project([
            '.porta.json' => (string) \json_encode(['settings' => ['svgConverter' => self::fakeConverter()]]),
            'logo.svg' => self::SMALL_SVG,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('logo.svg'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '100 × 50'), what: 'the adopted converter rasterizing the SVG');
                    $this->await(static function () use ($project): bool {
                        $file = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true);

                        return \is_array($file) && ($file['plugins']['image-viewer']['svgConverter'] ?? null) === self::fakeConverter();
                    }, what: 'the adoption written through to plugins.image-viewer');
                },
            ],
        );
        $this->addToAssertionCount(1);
    }

    public function testAnSvgOpensAsAPictureRasterizedByTheConverter(): void
    {
        $project = self::project([
            '.porta.json' => (string) \json_encode(['plugins' => ['image-viewer' => ['svgConverter' => self::fakeConverter()]]]),
            'logo.svg' => self::SMALL_SVG,
            'other.svg' => self::SMALL_SVG,
            'binary.svg' => "\xff\xfe\x00<svg",
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('logo.svg'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '100 × 50'), what: 'the rasterized SVG');
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId(), 'an SVG takes the image pane');

                    $output = $terminal->getOutput();
                    $this->assertStringContainsString('▀', $output, 'half-block cells rendered');
                    $this->assertStringContainsString('38;2;40;130;70', $output, "the converter's pixels");
                    $this->assertStringContainsString('logo.svg', AnsiUtils::stripAnsiCodes($output));
                    // The size is the DOCUMENT's, not the raster's — the probe
                    // asked the converter rather than parsing the XML.
                    $this->assertStringContainsString('100 × 50', AnsiUtils::stripAnsiCodes($output));

                    // Opening another one with editor focus keeps the pane.
                    $service = Services::resolve($ide, BufferService::class);
                    $service->openFile((string) \realpath($project->path('other.svg')), OpenFocus::Editor);
                    $this->frame($tui);
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId());

                    // Bytes that are not UTF-8 are refused the same way any
                    // text-backed document is, rather than reaching a converter.
                    $service->openFile((string) \realpath($project->path('binary.svg')));
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Not a UTF-8 text file'), what: 'the refusal');
                },
            ],
        );
    }

    public function testReopeningAChangedSvgRerastersItImmediately(): void
    {
        $project = self::project([
            '.porta.json' => (string) \json_encode(['plugins' => ['image-viewer' => ['svgConverter' => self::fakeConverter()]]]),
            'logo.svg' => self::SMALL_SVG,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('logo.svg'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '100 × 50'), what: 'the first raster');

                    // Change the document on disk and re-open it explicitly: the
                    // source refreshes without waiting out the 1s reconciler, and
                    // the pane re-rasterizes what is now there.
                    \file_put_contents($project->path('logo.svg'), data: '<svg data-w="60" viewBox="0 0 60 30"/>');
                    Services::resolve($ide, BufferService::class)->openFile((string) \realpath($project->path('logo.svg')));
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '60 × 30'), what: 'the re-rasterized document');
                    $this->assertSame('60 × 30', $tui->getById('image-viewer')->statusInfo(), 'a resized document is fitted fresh');
                },
            ],
        );
    }

    public function testAnSvgChangedOnDiskReloadsOnItsOwn(): void
    {
        // Pre-pane, this never happened: a vector tab satisfied isImage(), so
        // the reconciler sent it down the BITMAP path, asked the decoder for an
        // SVG, was refused, and returned. Nothing reloaded until the tab was
        // re-opened by hand. The payload shape now tells the two apart.
        $project = self::project([
            '.porta.json' => (string) \json_encode(['plugins' => ['image-viewer' => ['svgConverter' => self::fakeConverter()]]]),
            'logo.svg' => self::SMALL_SVG,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('logo.svg'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '100 × 50'), what: 'the first raster');

                    \file_put_contents($project->path('logo.svg'), data: '<svg data-w="70" viewBox="0 0 70 35"/>');
                    \touch($project->path('logo.svg'), mtime: \time() + 2); // a new disk stamp within the same second
                    Services::resolve($ide, BufferService::class)->pollFilesystem();

                    // The stub converter reports height = width / 2, so a 70-wide
                    // document probes as 70 × 35.
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '70 × 35'), what: 'the reloaded document');
                    $this->assertSame('70 × 35', $tui->getById('image-viewer')->statusInfo(), 'the poller re-read the source and the pane re-rasterized it');

                    // The same rule on the EXPLICIT re-open path, which reads the
                    // payload through the pane's loader too: a refusal there
                    // keeps the last good document rather than blanking the pane.
                    \file_put_contents($project->path('logo.svg'), data: "\xff\xfe\x00<svg");
                    \touch($project->path('logo.svg'), mtime: \time() + 3);
                    Services::resolve($ide, BufferService::class)->openFile((string) \realpath($project->path('logo.svg')));
                    $this->pump(0.3);
                    $this->assertSame('70 × 35', $tui->getById('image-viewer')->statusInfo(), 'a refused re-open keeps the document');

                    // Garbage keeps the picture, the same contract the bitmap
                    // path keeps: an unreadable new payload is not a reason to
                    // blank a pane that is showing something valid.
                    \file_put_contents($project->path('logo.svg'), data: "\xff\xfe\x00<svg");
                    \touch($project->path('logo.svg'), mtime: \time() + 4);
                    Services::resolve($ide, BufferService::class)->pollFilesystem();
                    $this->pump(0.3);
                    $this->assertSame('70 × 35', $tui->getById('image-viewer')->statusInfo(), 'invalid new bytes keep the last good document');
                },
            ],
        );
    }

    public function testOpenAsTextGivesTheXmlBack(): void
    {
        $project = self::project([
            '.porta.json' => (string) \json_encode(['plugins' => ['image-viewer' => ['svgConverter' => self::fakeConverter()]]]),
            'logo.svg' => self::SMALL_SVG,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('logo.svg'),
            [
                200 => function ($tui, $terminal, $ide): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '100 × 50'), what: 'the rasterized SVG');
                    $this->assertSame('image-viewer', $tui->getFocus()?->getId());

                    Services::resolve($ide, CommandRegistry::class)->run('open_as_text');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '<svg'), what: 'the XML behind the picture');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the editor took the pane back');
                    $this->assertStringContainsString('viewBox', Services::resolve($ide, CodeEditorWidget::class)->getDocument()->getText(), 'and it is editable');
                },
            ],
        );
    }

    public function testWithoutAConverterAnSvgStaysText(): void
    {
        \putenv('PATH=/nonexistent'); // nothing to discover, and no setting
        $project = self::project(['logo.svg' => self::SMALL_SVG]);

        $this->runIde(
            $project,
            $project->path('logo.svg'),
            [
                200 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the text path, as before');
                    $this->assertStringContainsString('<svg', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the XML is editable');
                },
            ],
        );
    }

    public function testTheSvgSettingSendsItBackToTheEditor(): void
    {
        $project = self::project([
            '.porta.json' => (string) \json_encode([
                'plugins' => ['image-viewer' => ['svgConverter' => self::fakeConverter(), 'svg' => false]],
            ]),
            'logo.svg' => self::SMALL_SVG,
        ]);
        $this->phpOnlyPath($project);

        $this->runIde(
            $project,
            $project->path('logo.svg'),
            [
                200 => function ($tui, $terminal): void {
                    $this->frame($tui);
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'someone who edits SVGs turns the claim off');
                    $this->assertStringContainsString('<svg', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }
}
