<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageViewerWidget;
use Porta\Tests\Support\FakeRasterizer;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\PixelGraphics;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Ui\Graphics\TerminalGraphics;
use Symfony\Component\Tui\Render\RenderContext;

/** The image pane when the terminal draws the pixels itself. */
final class ImageViewerPixelsTest extends TestCase
{
    private FakeRasterizer $rasterizer;
    private FakeScheduler $scheduler;

    protected function setUp(): void
    {
        $this->rasterizer = new FakeRasterizer();
        $this->scheduler = new FakeScheduler();
    }

    private function widget(TerminalGraphics $graphics): ImageViewerWidget
    {
        return new ImageViewerWidget($this->rasterizer, new Translator('en'), $this->scheduler, graphics: $graphics);
    }

    private static function bitmap(int $width, int $height): ImageData
    {
        return new ImageData(\imagecreatetruecolor($width, height: $height), $width, $height, 0);
    }

    public function testABitmapIsDrawnByTheTerminalAndItsZoomCountsScreenPixels(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered(cellWidth: 8);
        $widget = $this->widget($graphics);
        $widget->show('/p/a.png', self::bitmap(64, 8));

        $lines = \implode('', $widget->render(new RenderContext(8, 2)));

        $this->assertSame([1000], PixelGraphics::imageIdsIn($lines));
        $this->assertStringNotContainsString('▀', $lines);
        $this->assertStringContainsString("\x1b_Ga=t,", $terminal->getOutput());
        // fit = 8/64 of a column per pixel, and a column is 8 screen pixels.
        $this->assertSame('Fit (100%)', $widget->zoomLabel());
    }

    public function testTheAnswerArrivingRepaintsHalfBlocksAsPixelsAndRefreshesTheLabel(): void
    {
        [$graphics] = PixelGraphics::unanswered();
        $widget = $this->widget($graphics);
        $tabs = new RecordingViewerTabHandler();
        $widget->setHandler($tabs);
        $widget->show('/p/a.png', self::bitmap(64, 8));
        $this->assertStringContainsString('▀', \implode('', $widget->render(new RenderContext(8, 2))));
        $this->assertSame('Fit (13%)', $widget->zoomLabel());
        $pings = \count($tabs->events);

        PixelGraphics::answer($graphics, "\x1b_Gi=31;OK\x1b\\", "\x1b[6;16;8t", "\x1b[?62c");

        $this->assertContains('view', \array_slice($tabs->events, $pings), 'the status bar is told to read the label again');
        $this->assertSame('Fit (100%)', $widget->zoomLabel(), 'before the pane has even rendered');
        $this->assertSame([1000], PixelGraphics::imageIdsIn(\implode('', $widget->render(new RenderContext(8, 2)))));
    }

    public function testAVectorIsRerenderedAtTheScreensPixels(): void
    {
        [$graphics] = PixelGraphics::answered(cellWidth: 8);
        $this->rasterizer->nextPng = PngFixture::bytes(100, 50);
        $widget = $this->widget($graphics);
        $widget->show('/p/a.svg', '<svg viewBox="0 0 100 50"/>');

        $widget->render(new RenderContext(50, 13)); // fit 0.5 of a column per pixel
        $this->scheduler->fire();

        $this->assertSame([null, 4.0], $this->rasterizer->scales, 'half a column is four screen pixels');
    }

    public function testLettingGoOfThePictureFreesItInTheTerminal(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $widget = $this->widget($graphics);
        $widget->show('/p/a.png', self::bitmap(64, 8));
        $widget->render(new RenderContext(8, 2));
        $terminal->clearOutput();

        $widget->hide();

        $this->assertStringContainsString("\x1b_Ga=d,d=I,i=1000,q=2\x1b\\", $terminal->getOutput());
    }
}
