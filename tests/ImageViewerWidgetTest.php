<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageViewerWidget;
use Porta\Tests\Support\FakeRasterizer;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Ui\Glyphs;
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
        return new ImageViewerWidget($this->rasterizer, new Translator('en'), scheduler: $this->scheduler);
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
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
    }

    /** A 64×8 horizontal gradient (red channel = x*4) for pan-visible sampling. */
    private function gradient(): ImageData
    {
        $gd = imagecreatetruecolor(64, height: 8);
        for ($x = 0; $x < 64; ++$x) {
            for ($y = 0; $y < 8; ++$y) {
                imagesetpixel($gd, $x, $y, (int) imagecolorallocate($gd, $x * 4, green: 0, blue: 0));
            }
        }

        return new ImageData($gd, 64, 8, 123);
    }

    public function testAFocusedPaneShowsAFocusFrameThatSurvivesNoAnsi(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'));
        $plain = static fn (array $lines): string => implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));

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

    public function testRendersBlankWithoutAnImageAndNothingOnAZeroSizePane(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'));
        $this->assertSame([], $widget->render(new RenderContext(10, 0)), 'zero rows');
        $this->assertSame(array_fill(0, count: 3, value: str_repeat(' ', times: 10)), $widget->render(new RenderContext(10, 3)));
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
        $widget = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'));
        $this->assertSame('+/- zoom  0 fit  Arrows pan  Ctrl+Q quit', $widget->statusRight(), 'an empty pane still names its keys');

        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(20, 6));

        $segment = $widget->statusRight();
        $this->assertStringContainsString('64 × 8', $segment, "the document's own size");
        $this->assertStringContainsString('Arrows pan', $segment);
        $this->assertSame('image', $widget->paneId());
    }

    public function testShowsAnImageAndZoomKeysDriveTheLabel(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'));
        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(8, 2)); // fit = min(8/64, 4/8) = 0.125

        $this->assertSame('Fit (13%)', $widget->zoomLabel());
        $this->assertStringContainsString('▀', implode('', $widget->render(new RenderContext(8, 2))));
        $this->assertStringContainsString('38;2;', implode('', $widget->render(new RenderContext(8, 2))));

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
        $widget = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'));
        $widget->show('/p/a.png', $this->gradient());
        $widget->render(new RenderContext(8, 2));
        $widget->handleInput('1'); // 100%, off the fit

        // What WheelRouter hands over for each notch of a Ctrl+scroll burst.
        foreach (range(1, end: 4) as $ignored) {
            $widget->magnifyAt(1.08, 4, 1);
        }
        $this->assertSame('136%', $widget->zoomLabel(), 'four notches of 8%, not two rungs');

        $widget->magnifyAt(1 / 1.08, 4, 1);
        $this->assertSame('126%', $widget->zoomLabel(), 'and it glides back');
    }

    public function testArrowsPanAndShiftPansFiveTimesAsFar(): void
    {
        $widget = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'));
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
        $widget = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'));
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
        $raw = implode("\n", $widget->render(new RenderContext(50, 13)));
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
        $this->assertStringContainsString('▀', implode('', $widget->render(new RenderContext(40, 8))), 'delivery repaints');
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
        $this->assertStringContainsString('▀', implode('', $lines), 'the previous raster is still on screen');
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
        foreach (range(1, end: 3) as $ignored) {
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
        $this->assertEqualsWithDelta(sqrt(16_000_000 / (2000 * 1500)), (float) $asked[1], 0.01, 'but the raster is: 72 × sqrt(MAX_PIXELS / area)');
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
}
