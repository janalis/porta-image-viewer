<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\ImageViewer\HalfBlockRasterizer;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageDecoder;
use Porta\ImageViewer\ImageViewerWidget;
use Porta\ImageViewer\SampleSource;
use Porta\ImageViewer\ScaledSource;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\FakeRasterizer;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * What the image pane leaves behind each key, each answer and each frame —
 * exactly, where {@see ImageViewerWidgetTest} reads it through the zoom label
 * or a sample colour that many different views would also show.
 */
final class ImageViewerViewTest extends TestCase
{
    private FakeRasterizer $rasterizer;
    private FakeScheduler $scheduler;

    protected function setUp(): void
    {
        $this->rasterizer = new FakeRasterizer();
        $this->scheduler = new FakeScheduler();
    }

    /**
     * From 1:1 on a 200 × 100 picture in a 40 × 6 pane, nowhere near an edge:
     * each key leaves the view the model's own call would.
     */
    public function testEachKeyLeavesTheViewItsModelCallWould(): void
    {
        $keys = [
            "\x1b[D" => static fn (ZoomPanModel $m) => $m->panBy(-2, 0, 40, 12),
            "\x1b[C" => static fn (ZoomPanModel $m) => $m->panBy(2, 0, 40, 12),
            "\x1b[A" => static fn (ZoomPanModel $m) => $m->panBy(0, -4, 40, 12),
            "\x1b[B" => static fn (ZoomPanModel $m) => $m->panBy(0, 4, 40, 12),
            "\x1b[1;2D" => static fn (ZoomPanModel $m) => $m->panBy(-10, 0, 40, 12),
            "\x1b[1;2C" => static fn (ZoomPanModel $m) => $m->panBy(10, 0, 40, 12),
            "\x1b[1;2A" => static fn (ZoomPanModel $m) => $m->panBy(0, -20, 40, 12),
            "\x1b[1;2B" => static fn (ZoomPanModel $m) => $m->panBy(0, 20, 40, 12),
            '=' => static fn (ZoomPanModel $m) => $m->zoomIn(40, 12),
            '+' => static fn (ZoomPanModel $m) => $m->zoomIn(40, 12),
            '-' => static fn (ZoomPanModel $m) => $m->zoomOut(40, 12),
            '0' => static fn (ZoomPanModel $m) => $m->fit(),
            'f' => static fn (ZoomPanModel $m) => $m->fit(),
        ];
        $widget = $this->bitmapWidget(200, 100);
        $view = Probe::get($widget, 'view');
        $this->assertInstanceOf(ZoomPanModel::class, $view);

        foreach ($keys as $key => $expect) {
            $view->fit();
            $view->actualSize(40, 12);
            $expected = new ZoomPanModel(200, 100);
            $expected->actualSize(40, 12);
            $expect($expected);

            $widget->handleInput((string) $key); // '0' is an int key

            $this->assertSame($expected->stateKey(), $view->stateKey(), \json_encode((string) $key, \JSON_THROW_ON_ERROR));
        }

        $view->fit();
        $expected = new ZoomPanModel(200, 100);
        $expected->actualSize(40, 12);
        $widget->handleInput('1');
        $this->assertSame($expected->stateKey(), $view->stateKey(), '1 from the fit');
    }

    /**
     * Pointer gestures act on the cell's middle half-row and on the whole
     * pane's half-rows: anchored on row 3 is half-row 7, and a wheel pan stops
     * at the bottom the 12 half-rows of a 6-row pane leave.
     */
    public function testEachPointerGestureLeavesTheViewItsModelCallWould(): void
    {
        $gestures = [
            'zoomAt' => [static fn (ImageViewerWidget $w) => $w->zoomAt(1, 9, 3), static fn (ZoomPanModel $m) => $m->zoomAt(1, 9, 7, 40, 12)],
            'magnifyAt' => [static fn (ImageViewerWidget $w) => $w->magnifyAt(2.0, 9, 3), static fn (ZoomPanModel $m) => $m->magnifyAt(2.0, 9, 7, 40, 12)],
            'scrollBy' => [static fn (ImageViewerWidget $w) => $w->scrollBy(10_000, 10_000), static fn (ZoomPanModel $m) => $m->panBy(10_000, 10_000, 40, 12)],
            'shift+down, far' => [
                static function (ImageViewerWidget $w): void {
                    foreach (\range(1, end: 5) as $ignored) {
                        $w->handleInput("\x1b[1;2B");
                    }
                },
                static fn (ZoomPanModel $m) => $m->panBy(0, 100, 40, 12),
            ],
        ];
        $widget = $this->bitmapWidget(200, 100);
        $view = Probe::get($widget, 'view');
        $this->assertInstanceOf(ZoomPanModel::class, $view);

        foreach ($gestures as $what => [$gesture, $expect]) {
            $view->fit();
            $view->actualSize(40, 12);
            $expected = new ZoomPanModel(200, 100);
            $expected->actualSize(40, 12);
            $expect($expected);
            $before = $widget->getRenderRevision();

            $gesture($widget);

            $this->assertSame($expected->stateKey(), $view->stateKey(), $what);
            $this->assertGreaterThan($before, $widget->getRenderRevision(), "{$what} repaints");
        }
    }

    /** A key right after a frame drawn in pixels counts in the display's pixels, without the label being asked first. */
    public function testTheFrameTellsTheViewWhatAColumnIsWorth(): void
    {
        [$graphics] = PixelGraphics::answered(cellWidth: 8);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler, graphics: $graphics);
        $widget->show('/p/a.png', self::bitmap(640, 320));
        $widget->render(new RenderContext(40, 6));

        $widget->handleInput('1');

        $expected = new ZoomPanModel(640, 320);
        $expected->setPixelsPerColumn(8 / $graphics->pixelRatio());
        $expected->actualSize(40, 12);
        $view = Probe::get($widget, 'view');
        $this->assertInstanceOf(ZoomPanModel::class, $view);
        $this->assertSame($expected->stateKey(), $view->stateKey());
    }

    /** The tab keys reach the IDE through the handler, and with none set they do nothing. */
    public function testTheTabKeysNeedNoHandler(): void
    {
        $widget = $this->bitmapWidget(20, 10);
        $keys = ["\x1b[1;5C", "\x1b[1;5D", "\x1b[1;5S", "\x1bw", "\x1b[110;6u", "\x10", "\x1b\x13"];
        foreach ($keys as $key) {
            $widget->handleInput($key);
        }

        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        foreach ($keys as $key) {
            $widget->handleInput($key);
        }
        $this->assertSame(['cycle:1', 'cycle:-1', 'close', 'close', 'goto', 'goto', 'save_all'], $handler->events, 'ctrl+f4 closes and ctrl+shift+n goes to a file too');
    }

    /** Every new thing to show repaints: a picture, a document, a clear, and each of the converter's answers. */
    public function testEveryChangeOfWhatIsShownRepaints(): void
    {
        $this->rasterizer->deferred = true;
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $repaints = static function (\Closure $change) use ($widget): bool {
            $before = $widget->getRenderRevision();
            $change();

            return $widget->getRenderRevision() > $before;
        };

        $this->assertTrue($repaints(static fn () => $widget->show('/p/a.png', self::bitmap(4, 4))), 'a bitmap');
        $this->assertTrue($repaints($widget->clear(...)), 'a clear');
        $this->assertTrue($repaints(static fn () => $widget->show('/p/a.svg', '<svg id="a"/>')), 'a document, before any answer');
        $this->assertTrue($repaints(fn () => ($this->rasterizer->pendingPng)(PngFixture::bytes(10, 10))), 'its picture');
        $widget->show('/p/a.svg', '<svg id="b"/>');
        $this->assertTrue($repaints(fn () => ($this->rasterizer->pendingError)('broken')), 'its failure');
        $widget->show('/p/a.svg', '<svg id="c"/>');
        $this->assertTrue($repaints(fn () => ($this->rasterizer->pendingPng)('not a picture')), 'an answer that is no picture');
    }

    /** Another tab's document starts from nothing; the same tab re-shown keeps its picture until the new one lands. */
    public function testAnotherTabsDocumentNeverPaintsOverThePreviousPicture(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', '<svg id="a"/>');
        $this->rasterizer->deferred = true;

        $widget->show('/p/a.svg', '<svg id="a2"/>');
        $this->assertSame('100 × 50', $widget->statusInfo(), 'the same tab keeps its picture meanwhile');

        $widget->show('/p/b.svg', '<svg id="b"/>');
        $this->assertSame('', $widget->statusInfo(), 'another tab does not');
        $this->assertSame('◌ rendering…', \trim(AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(30, 5))[2])));
    }

    /** The message sits on the middle row, centred with the odd column after it, and is cut to a pane too narrow for it. */
    public function testTheMessageIsCentredAndCutToThePane(): void
    {
        $this->rasterizer->deferred = true;
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', '<svg/>');

        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(31, 4)));
        $this->assertSame([\str_repeat(' ', times: 31), \str_repeat(' ', times: 31), \str_repeat(' ', times: 9).'◌ rendering…'.\str_repeat(' ', times: 10), \str_repeat(' ', times: 31)], $rows);

        foreach ($widget->render(new RenderContext(5, 3)) as $row) {
            $this->assertSame(5, AnsiUtils::visibleWidth($row));
        }
    }

    /** A clear while the converter works leaves a blank pane, not its hint. */
    public function testAClearMidRenderLeavesNoHint(): void
    {
        $this->rasterizer->deferred = true;
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', '<svg/>');

        $widget->clear();

        $this->assertSame(\array_fill(0, count: 5, value: \str_repeat(' ', times: 30)), $widget->render(new RenderContext(30, 5)));
    }

    /** The pane's own message is the error's colour when there is one, and dim while it waits. */
    public function testTheMessageIsColouredByWhatItSays(): void
    {
        $styles = Styles::dark();
        $this->rasterizer->deferred = true;
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', '<svg/>');
        $this->assertStringContainsString($styles->of(Role::Dim)->apply('◌ rendering…'), $widget->render(new RenderContext(30, 5))[2]);

        ($this->rasterizer->pendingError)('broken');
        $this->assertStringContainsString($styles->of(Role::DiagError)->apply('broken'), $widget->render(new RenderContext(30, 5))[2]);
    }

    /** A converter error over a picture takes the top row, and only it. */
    public function testTheBannerIsTheTopRow(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', '<svg id="a"/>');
        $this->rasterizer->deferred = true;
        $widget->show('/p/a.svg', '<svg id="b"/>');
        ($this->rasterizer->pendingError)("broken\nat line 2");

        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(30, 5)));

        $this->assertSame(' ✗ broken '.\str_repeat(' ', times: 20), $rows[0], 'its first line only');
        $this->assertStringNotContainsString('broken', $rows[1]);
    }

    /** A pane one column or one row big still shows the picture; three by three is enough to frame it. */
    public function testTheSmallestPanesStillPaint(): void
    {
        $widget = $this->bitmapWidget(4, 4);

        $this->assertCount(1, $widget->render(new RenderContext(1, 1)));
        $this->assertCount(1, $widget->render(new RenderContext(5, 1)));
        $this->assertCount(3, $widget->render(new RenderContext(1, 3)));

        $widget->setFocused(true);
        $this->assertSame(
            ['┌─┐', '│ │', '└─┘'],
            \array_map(static fn (string $row): string => \str_replace(search: '▀', replace: ' ', subject: AnsiUtils::stripAnsiCodes($row)), $widget->render(new RenderContext(3, 3))),
        );
    }

    /** Resized to a pane whose two numbers spell the same digits, the memo is not served. */
    public function testTheMemoTellsColumnsFromRows(): void
    {
        $widget = $this->bitmapWidget(4, 4);
        $widget->render(new RenderContext(12, 3));

        $this->assertCount(23, $widget->render(new RenderContext(1, 23)));
        $this->assertCount(5, $widget->render(new RenderContext(1, 5)), 'nor serves one only as wide');
    }

    /** A bitmap shown after a document has no document behind it to re-render. */
    public function testABitmapAfterADocumentAsksTheConverterForNothing(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', '<svg/>');
        $widget->show('/p/a.png', self::bitmap(100, 50));
        $widget->render(new RenderContext(50, 13));
        $widget->handleInput('1');
        $widget->handleInput('='); // 200%: a document would want a sharper raster here
        $this->settle();

        $this->assertSame([null], $this->rasterizer->scales);
    }

    /**
     * Shrinking, the pane samples a copy averaged at its own fit: height-bound
     * here, so a copy taken at another height's scale shows other rows.
     */
    public function testAShrunkPictureIsAveragedAtThePanesOwnScale(): void
    {
        $gd = \imagecreatetruecolor(8, height: 64);
        for ($y = 0; $y < 64; ++$y) {
            \imagefilledrectangle($gd, x1: 0, y1: $y, x2: 7, y2: $y, color: (int) \imagecolorallocate($gd, red: $y * 4, green: 0, blue: 0));
        }
        $image = new ImageData($gd, 8, 64, 0);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.png', $image);

        $lines = $widget->render(new RenderContext(8, 2));

        $view = Probe::get($widget, 'view');
        $this->assertInstanceOf(ZoomPanModel::class, $view);
        $this->assertSame(HalfBlockRasterizer::render(new ScaledSource()->forScale($image, 4 / 64), $view, 8, 2), $lines);
    }

    /** A sharper raster is sampled at its own density, and once asked for is not asked for again. */
    public function testARefinedRasterIsDrawnAtItsDensityAndAskedForOnce(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');
        $context = new RenderContext(50, 13);
        $widget->render($context);
        $widget->handleInput('1');
        $widget->handleInput('='); // 200%
        $refined = PngFixture::bytes(200, 100, [200, 40, 40], [40, 200, 40]);
        $this->rasterizer->nextPng = $refined;
        $this->settle();

        $lines = $widget->render($context);
        $this->settle();

        $this->assertSame([null, 2.0], $this->rasterizer->scales);
        $view = Probe::get($widget, 'view');
        $this->assertInstanceOf(ZoomPanModel::class, $view);
        $decoded = new ImageDecoder()->decodeBytes($refined);
        $this->assertInstanceOf(ImageData::class, $decoded);
        $this->assertSame(HalfBlockRasterizer::render(new SampleSource($decoded, 2.0), $view, 50, 13), $lines);
    }

    /** A small document fitted into a tall pane wants a raster at the fit's own scale: twice, from the height. */
    public function testTheFitAsksForTheRasterItsHeightWants(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(10, 10);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', '<svg/>');
        $widget->render(new RenderContext(80, 10)); // fit = min(80 / 10, 20 / 10)
        $this->settle();

        $this->assertSame([null, 2.0], $this->rasterizer->scales);
    }

    /**
     * A click maps through the letterbox and the fit: here 0.5, from the
     * height, with 15 columns of letterbox — column 17 of row 0 is document
     * (5, 2), the middle of its cell, on a shape one pixel square.
     */
    public function testAClickMapsThroughTheLetterboxAndTheFit(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(20, 20);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.svg', "<svg xmlns=\"http://www.w3.org/2000/svg\">\n<rect x=\"5\" y=\"0\" width=\"1\" height=\"1\"/>\n</svg>");
        $widget->render(new RenderContext(40, 5));
        $widget->selectAt(0, 17); // no handler: nothing to tell

        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->selectAt(0, 17);

        $this->assertSame(['reveal:1:0'], $handler->events);
    }

    /** Letterboxed above and below instead: a 0.5 fit from the width, five half-rows over it — row 3 of column 2 is document (5, 4). */
    public function testAClickMapsThroughALetterboxAboveThePicture(): void
    {
        $this->rasterizer->nextPng = PngFixture::bytes(20, 20);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $handler = new RecordingViewerTabHandler();
        $widget->setHandler($handler);
        $widget->show('/p/a.svg', "<svg xmlns=\"http://www.w3.org/2000/svg\">\n<rect x=\"5\" y=\"4\" width=\"1\" height=\"1\"/>\n</svg>");
        $widget->render(new RenderContext(10, 10));
        $handler->events = [];

        $widget->selectAt(3, 2);

        $this->assertSame(['reveal:1:0'], $handler->events);
    }

    /** Drawn by the terminal, the pane is still exactly as many rows as it is tall. */
    public function testThePixelPaneIsEveryRowTall(): void
    {
        [$graphics] = PixelGraphics::answered(cellWidth: 8);
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler, graphics: $graphics);
        $widget->show('/p/a.png', self::bitmap(64, 8));

        $this->assertCount(4, $widget->render(new RenderContext(8, 4)));
    }

    private function bitmapWidget(int $width, int $height): ImageViewerWidget
    {
        $widget = new ImageViewerWidget($this->rasterizer->rasterize(...), new Translator('en'), $this->scheduler);
        $widget->show('/p/a.png', self::bitmap($width, $height));
        $widget->render(new RenderContext(40, 6));

        return $widget;
    }

    private static function bitmap(int $width, int $height): ImageData
    {
        return new ImageData(\imagecreatetruecolor($width, height: $height), $width, $height, 0);
    }

    private function settle(): void
    {
        while ([] !== $this->scheduler->scheduled) {
            $this->scheduler->fire();
        }
    }
}
