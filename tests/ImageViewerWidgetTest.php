<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\I18n\Translator;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageViewerWidget;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\FakeRasterizer;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Ui\Glyphs;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class ImageViewerWidgetTest extends TestCase
{
    private FakeRasterizer $rasterizer;
    private FakeScheduler $scheduler;

    protected function setUp(): void
    {
        $this->rasterizer = new FakeRasterizer();
        $this->scheduler = new FakeScheduler();
    }

    /** A pane whose converter and debounce are both scripted — the vector-mode rig. */
    private function vectorWidget(): ImageViewerWidget
    {
        return new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), scheduler: $this->scheduler);
    }

    /** Let the re-render debounce elapse, as the event loop would. */
    private function settle(): void
    {
        if ([] !== $this->scheduler->scheduled) {
            $this->scheduler->fire();
        }
    }

    private static function plain(array $lines): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    /** A 64×8 horizontal gradient (red channel = x*4) for pan-visible sampling. */
    private function gradient(): ImageData
    {
        $gd = \imagecreatetruecolor(64, height: 8);
        for ($x = 0; $x < 64; ++$x) {
            for ($y = 0; $y < 8; ++$y) {
                \imagesetpixel($gd, $x, $y, (int) \imagecolorallocate($gd, $x * 4, green: 0, blue: 0));
            }
        }

        return new ImageData($gd, 64, 8, 123);
    }

    public function testAFocusedPaneShowsAFocusFrameThatSurvivesNoAnsi(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $plain = static fn (array $lines): string => \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $lines));

        $unfocused = $plain($widget->render(new RenderContext(12, 4)));
        $this->assertStringNotContainsString(Glyphs::FRAME_TOP_LEFT, $unfocused, 'no frame when the pane is blurred');

        $widget->setFocused(true);
        $focused = $plain($widget->render(new RenderContext(12, 4)));
        // The glyph carries the cue, so stripping ANSI still shows the whole box.
        $this->assertStringContainsString(Glyphs::FRAME_TOP_LEFT, $focused);
        $this->assertStringContainsString(Glyphs::FRAME_BOTTOM_RIGHT, $focused);
        $this->assertStringContainsString(Glyphs::FRAME_VERTICAL, $focused, 'the side rails');
        foreach ($widget->render(new RenderContext(12, 4)) as $line) {
            $this->assertSame(12, AnsiUtils::visibleWidth($line), 'the frame keeps every line pane-wide');
        }
    }

    /**
     * The focus frame is drawn over whatever the pane shows, a message included.
     * A 12-column error `ab中中中中中` puts its last 中 on columns 10-11, across
     * the right rail's column: sliced non-strict from column 1, that glyph came
     * back whole, the framed row was 13 columns wide, and the renderer throws on
     * an over-wide row. The rail covers the glyph's right half; its left half is blank.
     */
    public function testTheFocusFrameKeepsAWideGlyphUnderTheRailPaneWide(): void
    {
        $this->rasterizer->deferred = true;
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $fail = $this->rasterizer->pendingError;
        $this->assertNotNull($fail);
        $fail('ab中中中中中');
        $widget->setFocused(true);

        $lines = $widget->render(new RenderContext(12, 5));
        foreach ($lines as $i => $line) {
            $this->assertSame(12, AnsiUtils::visibleWidth($line), 'row '.$i);
        }
        $this->assertSame(Glyphs::FRAME_VERTICAL.'b中中中中 '.Glyphs::FRAME_VERTICAL, AnsiUtils::stripAnsiCodes($lines[2]));
    }

    public function testRendersBlankWithoutAnImageAndNothingOnAZeroSizePane(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $this->assertSame([], $widget->render(new RenderContext(10, 0)), 'zero rows');
        $this->assertSame(\array_fill(0, count: 3, value: \str_repeat(' ', times: 10)), $widget->render(new RenderContext(10, 3)));
        $this->assertSame('', $widget->zoomLabel(), 'no image, no label');

        $widget->handleInput('='); // zoom without an image is a no-op, not an error
        $widget->scrollBy(1, 1);
        $widget->zoomAt(1, 0, 0);
        $widget->magnifyAt(1.08, 0, 0);
        $this->assertSame('', $widget->zoomLabel());
    }

    public function testTheStatusSegmentCarriesTheSizeTheZoomAndItsOwnKeys(): void
    {
        // The pane answers for its whole segment, hints included: the status bar
        // used to hold one hint string per viewer and had to know which was which.
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $this->assertSame('+/- zoom  0 fit  Arrows pan  Ctrl+Q quit', $widget->statusRight(), 'an empty pane still names its keys');

        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(20, 6));

        $segment = $widget->statusRight();
        $this->assertStringContainsString('64 × 8', $segment, "the document's own size");
        $this->assertStringContainsString('Arrows pan', $segment);
        $this->assertSame('image', $widget->paneId());
    }

    public function testTheRasterIsMemoizedUntilTheViewTheGeometryOrTheImageChanges(): void
    {
        // 2 imagecolorat() calls per cell per frame: a toast or a diagnostics
        // push invalidates the widget, and the identical picture re-rasterized
        // on every such frame (the 300-486ms slow-paints in perf.log).
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $widget->show('/p/a.png', $this->gradient());
        $context = new RenderContext(20, 6);

        $first = $widget->render($context);
        $this->assertSame($first, $widget->render($context), 'an unchanged frame serves the memo');

        $widget->handleInput('='); // zoom: the view fingerprint moves
        $zoomed = $widget->render($context);
        $this->assertNotSame(\implode('', $first), \implode('', $zoomed), 'a zoom re-rasterizes');

        $this->assertNotSame($zoomed, $widget->render(new RenderContext(24, 6)), 'a resize re-rasterizes');

        // A DIFFERENT image with identical dimensions, view and geometry: the
        // generation counter is what must miss — an object-identity key could
        // be fooled by allocator reuse, a keyless memo would show stale pixels.
        $gd = \imagecreatetruecolor(64, height: 8); // flat black, unlike the gradient
        $widget->show('/p/b.png', new ImageData($gd, 64, 8, 124));
        $replacedFirst = $widget->render($context);
        $this->assertNotSame(\implode('', $first), \implode('', $replacedFirst), 'a replaced image must not serve the previous picture');
    }

    public function testADeferredVectorRefinementRastersItsNewPixelsNotTheMemo(): void
    {
        // The paint-then-refine delivery swaps the image with the view and the
        // geometry UNCHANGED — the image generation is the only thing that can
        // miss the raster memo there (show() paths clear it via reset()).
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50); // the red probe
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $context = new RenderContext(50, 13);
        $widget->render($context);

        $widget->handleInput('='); // fit 0.5 → 1.0 (still the probe's own resolution)
        $widget->handleInput('='); // → 2.0: a sharper raster is now wanted
        $zoomed = \implode('', $widget->render($context)); // still the red probe, magnified
        $this->assertStringContainsString('38;2;200;40;40', $zoomed);

        $this->rasterizer->nextPng = PngFixture::bytes(200, 100, [40, 200, 40]); // the green refinement
        $this->settle();
        $refined = \implode('', $widget->render($context));
        $this->assertStringContainsString('38;2;40;200;40', $refined, 'the refined raster shows — a stale memo would keep the probe');
    }

    public function testTheZoomHintsAreStatusCellsThatZoomAsTheirKeysDo(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $this->assertSame([], $widget->statusClicks(), 'nothing shown, nothing to zoom');
        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(8, 2));

        $cells = $this->cellTexts($widget);
        $this->assertSame(['zoom_in' => '+', 'zoom_out' => '-', 'zoom_fit' => '0 fit'], $cells);

        $this->assertTrue($widget->runStatusAction('zoom_in'));
        $this->assertSame('25%', $widget->zoomLabel());
        $this->assertTrue($widget->runStatusAction('zoom_in'));
        $this->assertTrue($widget->runStatusAction('zoom_out'));
        $this->assertSame('25%', $widget->zoomLabel());
        $this->assertTrue($widget->runStatusAction('zoom_fit'));
        $this->assertSame('Fit (13%)', $widget->zoomLabel());
        $this->assertFalse($widget->runStatusAction('goto_line'), 'not a zoom cell: the status bar runs it elsewhere');
    }

    public function testTheZoomCellsAreFoundByTheirKeysInAWideScript(): void
    {
        // ズーム is six columns in three characters: a span counted in bytes or
        // characters would land the fit cell on the wrong words.
        $translator = new Translator('ja');
        $translator->addCatalogDirectory(\dirname(__DIR__).'/translations');
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), $translator);
        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(8, 2));

        $this->assertSame(['zoom_in' => '+', 'zoom_out' => '-', 'zoom_fit' => '0 フィット'], $this->cellTexts($widget));
    }

    /** @return array<string, string> each status cell's action => the text it covers */
    private function cellTexts(ImageViewerWidget $widget): array
    {
        $text = $widget->statusRight();
        $cells = [];
        foreach ($widget->statusClicks() as $span) {
            $cells[$span->action] = AnsiUtils::sliceToWidth($text, $span->from, $span->to - $span->from);
        }

        return $cells;
    }

    public function testShowsAnImageAndZoomKeysDriveTheLabel(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(8, 2)); // fit = min(8/64, 4/8) = 0.125

        $this->assertSame('Fit (13%)', $widget->zoomLabel());
        $this->assertStringContainsString('▀', \implode('', $widget->render(new RenderContext(8, 2))));
        $this->assertStringContainsString('38;2;', \implode('', $widget->render(new RenderContext(8, 2))));

        $widget->handleInput('='); // 0.125 → 0.25
        $this->assertSame('25%', $widget->zoomLabel());
        $widget->handleInput('+'); // raw alias → 0.5
        $this->assertSame('50%', $widget->zoomLabel());
        $widget->handleInput('-');
        $this->assertSame('25%', $widget->zoomLabel());
        $widget->handleInput('1');
        $this->assertSame('100%', $widget->zoomLabel());
        $widget->handleInput('0');
        $this->assertSame('Fit (13%)', $widget->zoomLabel());
        $widget->handleInput('1');
        $widget->handleInput('f');
        $this->assertSame('Fit (13%)', $widget->zoomLabel());

        $widget->clear();
        $this->assertSame('', $widget->zoomLabel());
    }

    public function testATrackpadBurstGlidesBetweenTheRungs(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(8, 2));
        $widget->handleInput('1'); // 100%, off the fit

        // What WheelRouter hands over for each notch of a Ctrl+scroll burst.
        foreach (\range(1, end: 4) as $ignored) {
            $widget->magnifyAt(1.08, 4, 1);
        }
        $this->assertSame('136%', $widget->zoomLabel(), 'four notches of 8%, not two rungs');

        $widget->magnifyAt(1 / 1.08, 4, 1);
        $this->assertSame('126%', $widget->zoomLabel(), 'and it glides back');
    }

    public function testArrowsPanAndShiftPansFiveTimesAsFar(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(4, 2)); // viewport 4×4 px at 100%
        $widget->handleInput('1');
        $widget->handleInput("\x1b[1;2H"); // unmatched sequence → no-op branch
        $widget->scrollBy(-1000, -1000); // 100% centers the viewport; rewind to the origin

        $first = static fn (array $lines): string => $lines[0];
        $home = $first($widget->render(new RenderContext(4, 2)));
        $this->assertStringContainsString('38;2;0;0;0', $home, 'origin sample is pixel x=0');

        $widget->handleInput("\x1b[C"); // pan right 2 cells = 2 px → gradient shifts
        $afterRight = $first($widget->render(new RenderContext(4, 2)));
        $this->assertNotSame($home, $afterRight);
        $this->assertStringContainsString('38;2;8;0;0', $afterRight, 'leftmost sample is now pixel x=2');

        $widget->handleInput("\x1b[1;2C"); // shift+right pans 10 px
        $this->assertStringContainsString('38;2;48;0;0', $first($widget->render(new RenderContext(4, 2))), 'x=12 after the shift step');

        $widget->handleInput("\x1b[1;2D"); // shift+left back to x=2
        $widget->handleInput("\x1b[D"); // and to x=0
        $this->assertSame($home, $first($widget->render(new RenderContext(4, 2))));

        $widget->handleInput("\x1b[B"); // vertical pans clamp within the 8px-tall image
        $widget->handleInput("\x1b[1;2B");
        $widget->handleInput("\x1b[1;2A");
        $widget->handleInput("\x1b[A");
        $this->assertSame($home, $first($widget->render(new RenderContext(4, 2))));

        $widget->scrollBy(2, 0); // wheel path
        $this->assertSame($afterRight, $first($widget->render(new RenderContext(4, 2))));
    }

    public function testPassThroughActionsFireTheCallbacks(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(8, 2));

        $widget->handleInput("\x1b[1;5C"); // ctrl+right → next tab
        $widget->handleInput("\x1b[1;5D"); // ctrl+left → previous tab
        $widget->handleInput("\x1bw"); // alt+w → close tab
        $widget->handleInput("\x10"); // ctrl+p → go to file
        $widget->handleInput("\x1b\x13"); // ctrl+alt+s (legacy: ESC + ctrl+s) → save all
        $widget->handleInput('='); // zoom → onViewChanged
        $widget->zoomAt(1, 2, 1); // ctrl+wheel zoom → onViewChanged too

        $this->assertSame(['cycle:1', 'cycle:-1', 'close', 'goto', 'save_all', 'view', 'view'], $handler->events);
        $this->assertSame('50%', $widget->zoomLabel(), 'wheel zoom stepped the ladder');
    }

    public function testAnSvgIsProbedAtItsOwnSizeAndPaintsWhatComesBack(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $handler = new RecordingViewerTabHandler();
        $widget = $this->vectorWidget();
        $widget->setHandler($handler);
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');

        $this->assertSame([null], $this->rasterizer->scales, 'the first ask has no scale: that IS the size probe');
        $this->assertSame('100 × 50', $widget->statusInfo(), "the document's own pixels, from the converter");
        $raw = \implode("\n", $widget->render(new RenderContext(50, 13)));
        $this->assertStringContainsString('▀', $raw, 'and it paints as half-blocks like any image');
        $this->assertStringContainsString('38;2;200;40;40', $raw);
        $this->assertContains('view', $handler->events, 'the status bar hears about it');
    }

    public function testTheSameDocumentIsNotRasterizedTwice(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg/>');
        $widget->show('/p/a.svg', '<svg/>');

        $this->assertSame([null], $this->rasterizer->scales, 'same tab, same document');
    }

    public function testTheHintShowsWhileTheConverterWorks(): void
    {
        $this->rasterizer->deferred = true;
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');

        $this->assertStringContainsString('rendering…', self::plain($widget->render(new RenderContext(40, 8))));
        $this->assertSame('', $widget->statusInfo(), 'no size until the converter answers');

        $deliver = $this->rasterizer->pendingPng;
        $this->assertNotNull($deliver);
        $deliver(PngFixture::bytes(100, 50));
        $this->assertStringContainsString('▀', \implode('', $widget->render(new RenderContext(40, 8))), 'delivery repaints');
    }

    public function testAConverterFailureFillsThePaneWithItsReason(): void
    {
        $this->rasterizer->nextPng = 'no SVG converter found';
        $this->rasterizer->pngIsError = true;
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg/>');

        $this->assertStringContainsString('no SVG converter found', self::plain($widget->render(new RenderContext(60, 9))));
    }

    public function testAFailedRerenderKeepsThePictureUnderABanner(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $widget->render(new RenderContext(50, 13));

        // A later render fails: the pane keeps its pixels and says why.
        $this->rasterizer->deferred = true;
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"><rect/></svg>');
        $fail = $this->rasterizer->pendingError;
        $this->assertNotNull($fail);
        $fail('XML parse error');

        $lines = $widget->render(new RenderContext(50, 13));
        $this->assertStringContainsString('XML parse error', self::plain($lines));
        $this->assertStringContainsString('▀', \implode('', $lines), 'the previous raster is still on screen');
    }

    public function testASameTabProbeThatChangesSizeIsFittedFreshNotKept(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $widget->render(new RenderContext(50, 13));
        $widget->handleInput('1'); // actual size: away from fit, so a reset is observable
        $this->assertSame('100%', $widget->zoomLabel());

        // The file changed on disk: same tab, new content, and the converter's
        // fresh probe reports a DIFFERENT size.
        $this->rasterizer->nextPng = PngFixture::bytes(300, 150);
        $widget->show('/p/a.svg', '<svg viewBox="0 0 300 150"><rect/></svg>');

        $this->assertSame('300 × 150', $widget->statusInfo(), 'the new document size, not the old one');
        $this->assertStringStartsWith('Fit (', $widget->zoomLabel(), 'a differently-sized probe is a new document — fitted fresh, not kept at 100%');
    }

    public function testUndecodableBytesReportInsteadOfBlanking(): void
    {
        $this->rasterizer->nextPng = 'this is not a PNG';
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg/>');

        $this->assertStringContainsString('could not be decoded', self::plain($widget->render(new RenderContext(60, 9))));
    }

    public function testALateRasterForAnAbandonedTabIsDropped(): void
    {
        $this->rasterizer->deferred = true;
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg id="a"/>');
        $deliverA = $this->rasterizer->pendingPng;
        $this->assertNotNull($deliverA);

        $widget->show('/p/b.svg', '<svg id="b"/>'); // the tab moves on
        $deliverA(PngFixture::bytes(100, 50)); // the converter answers for the one that left

        $this->assertSame('', $widget->statusInfo(), 'a stale raster never reaches the pane');
    }

    public function testClearingLeavesNoVectorStateBehind(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg/>');
        $widget->clear();

        $this->assertSame('', $widget->statusInfo());
        $this->assertSame('', $widget->zoomLabel());
        $widget->show('/p/a.svg', '<svg/>'); // the same source is a fresh document after a clear
        $this->assertSame([null, null], $this->rasterizer->scales);
    }

    public function testZoomingPastTheDocumentAsksForASharperRaster(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $widget->render(new RenderContext(50, 13));
        $widget->handleInput('1'); // actual size — the probe already covers 1:1
        $this->settle();
        $this->assertSame([null], $this->rasterizer->scales, 'nothing to re-render at the document\'s own size');

        $widget->handleInput('='); // 100% → 200%
        $this->rasterizer->nextPng = PngFixture::bytes(200, 100);
        $this->settle();

        $this->assertSame([null, 2.0], $this->rasterizer->scales, 'the converter re-renders at twice the size');
        $this->assertSame('100 × 50', $widget->statusInfo(), 'the document is still the document');
        $this->assertSame('200%', $widget->zoomLabel(), 'and the zoom is unchanged by the re-render');
    }

    public function testAGlideBetweenTheRungsAsksForOneSharperRaster(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $widget->render(new RenderContext(50, 13));
        $widget->handleInput('1'); // actual size

        // A Ctrl+scroll burst: several fractional steps, one resolution.
        foreach (\range(1, end: 3) as $ignored) {
            $widget->magnifyAt(1.08, 25, 6);
        }
        $this->assertSame('126%', $widget->zoomLabel());
        $this->rasterizer->nextPng = PngFixture::bytes(200, 100);
        $this->settle();

        $this->assertSame([null, 2.0], $this->rasterizer->scales, 'ceilinged to the next whole multiple');
    }

    public function testAPanNeverAsksForANewRaster(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $widget->render(new RenderContext(50, 13));

        $widget->handleInput("\x1b[C"); // right
        $widget->scrollBy(0, 4);

        $this->assertSame([], $this->scheduler->scheduled, 'panning changes no resolution');
        $this->assertSame([null], $this->rasterizer->scales);
    }

    public function testTheRasterScaleIsCappedForAHugeDocument(): void
    {
        // 2000×1500 is 3M pixels at 1:1 — fine — but 4× would be 48M, three
        // times the decoder's ceiling, so the ask is capped instead.
        $this->rasterizer->nextPng = PngFixture::bytes(2000, 1500);
        $widget = $this->vectorWidget();
        $widget->show('/p/big.svg', '<svg viewBox="0 0 2000 1500"/>');
        $widget->render(new RenderContext(50, 13));
        $widget->handleInput('1'); // fit is ~2% here, so start from the document's own size
        $widget->handleInput('=');
        $widget->handleInput('='); // 400%
        $this->settle();

        $asked = $this->rasterizer->scales;
        $this->assertCount(2, $asked);
        $this->assertSame('400%', $widget->zoomLabel(), 'the ZOOM is not capped');
        $this->assertEqualsWithDelta(\sqrt(16_000_000 / (2000 * 1500)), (float) $asked[1], 0.01, 'but the raster is: 72 × sqrt(MAX_PIXELS / area)');
    }

    public function testATabSwitchDropsThePendingRerender(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $widget->render(new RenderContext(50, 13));
        $widget->handleInput('1');
        $widget->handleInput('='); // 200%: arms a re-render
        $this->assertNotSame([], $this->scheduler->scheduled);

        $widget->clear();

        $this->assertSame([], $this->scheduler->scheduled, 'the pane went away; so did the request');
        $this->assertNotSame([], $this->scheduler->cancelled);
    }

    public function testItBindsExactlyTheActionsThePluginDeclares(): void
    {
        // The pane's keys and the keymap page's rows are both this plugin's
        // now — plugin.php declares the ids, the widget binds them — and each
        // direction catches what the other cannot see: a declared action
        // nothing binds is a dead keymap row, a bound key nothing declares is a
        // key the user can neither find nor rebind.
        $manager = new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
        $api = new PluginApi('image-viewer', $manager, '/p', new PluginSettings('/p', []));
        $plugin = require \dirname(__DIR__).'/plugin.php';
        $plugin->activate($api);

        $declared = \array_column($api->contributions()->actions->all(), 'id');
        /** @var array<string, list<string>> $bound */
        $bound = Probe::invokeStatic(ImageViewerWidget::class, 'getDefaultKeybindings');

        // Scoped to the ids CORE does not declare — a pane also binds core
        // actions (goto_file, next_buffer…) and those are the catalog's rows,
        // not this plugin's. What must match is the remainder: the actions that
        // exist only because this plugin exists.
        $ours = \array_keys(\array_diff_key($bound, ActionCatalog::ACTIONS));

        $this->assertNotSame([], $declared);
        \sort($declared);
        \sort($ours);
        $this->assertSame($ours, $declared, 'every declared action is bound, and every key core does not declare is declared here');
    }

    public function testAClickOnAnSvgRevealsTheTagThatDrewTheShapeUnderIt(): void
    {
        // The probe is the document's own size, 100 × 50; at 50 × 13 the fit
        // is 0.5 — one column two document pixels, one row four.
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 50\">\n  <rect x=\"0\" y=\"0\" width=\"50\" height=\"50\"/>\n  <circle cx=\"80\" cy=\"25\" r=\"10\"/>\n</svg>";
        $widget = $this->vectorWidget();
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/p/a.svg', $svg);
        $widget->render(new RenderContext(50, 13));
        $handler->events = [];

        $this->assertTrue($widget->press(6, 40), 'a press arms the drag-pan and the click');
        $widget->selectAt(6, 40); // document (81, 25): the circle
        $widget->selectAt(6, 10); // document (21, 25): the rect
        $widget->selectAt(6, 30); // document (61, 25): nothing drawn there

        $this->assertSame(['reveal:2:2', 'reveal:1:2'], $handler->events, 'each shape\'s line, and the column of its tag');
    }

    public function testABitmapHasNoSourceAndAnEmptyPaneNothingToPress(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer()->rasterize(...), new Translator('en'));
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $this->assertFalse($widget->press(1, 1), 'nothing shown, nothing to drag');

        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(80, 24));
        $handler->events = [];
        $widget->selectAt(3, 3);

        $this->assertSame([], $handler->events);
    }

    public function testAClickLandsOnTheMiddleOfItsCellNotItsCorner(): void
    {
        // At the 0.5 fit a row is four document pixels and a column two: row 1
        // is y 4..8, its middle 6 — past a 4-pixel strip's reach, where the
        // cell's top corner would still be on it.
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->vectorWidget();
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/p/a.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect x="0" y="0" width="100" height="4"/><rect x="0" y="0" width="3" height="50"/></svg>');
        $widget->render(new RenderContext(50, 13));
        $handler->events = [];

        $widget->selectAt(1, 20); // (41, 6): below the strip
        $widget->selectAt(20, 2); // past the bottom: nothing drawn
        $widget->selectAt(6, 2); // (5, 26): beside the bar, past its reach

        $this->assertSame([], $handler->events);
        $widget->selectAt(0, 20);
        $this->assertSame(['reveal:0:61'], $handler->events, 'row 0 is on the strip');
    }
}
