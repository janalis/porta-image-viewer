<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\PixelOutline;
use Porta\ImageViewer\PixelPlacement;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\PixelGraphics;
use Porta\Ui\Graphics\KittyPlaceholders;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** The pixel path: what a pane paints, and what it sends, for a view. */
final class PixelPlacementTest extends TestCase
{
    /** A $width × $height image, its left half red and its right half blue. */
    private static function halves(int $width, int $height): ImageData
    {
        $gd = \imagecreatetruecolor($width, height: $height);
        \imagefilledrectangle($gd, x1: 0, y1: 0, x2: \intdiv($width, num2: 2) - 1, y2: $height - 1, color: (int) \imagecolorallocate($gd, red: 255, green: 0, blue: 0));
        \imagefilledrectangle($gd, x1: \intdiv($width, num2: 2), y1: 0, x2: $width - 1, y2: $height - 1, color: (int) \imagecolorallocate($gd, red: 0, green: 0, blue: 255));

        return new ImageData($gd, $width, $height, 0);
    }

    public function testWithoutAnAnswerThePaneKeepsItsHalfBlocks(): void
    {
        [$graphics, $terminal] = PixelGraphics::unanswered();
        $pixels = new PixelPlacement($graphics, static function (): void {});

        $this->assertNull($pixels->render(self::halves(40, 20), 1.0, new ZoomPanModel(40, 20), 20, 10));
        $this->assertSame('', $terminal->getOutput());
        $this->assertSame(1, $pixels->pixelsPerColumn(), 'a zoom counts half-block columns');
    }

    public function testTheAnswerRepaintsThePane(): void
    {
        [$graphics] = PixelGraphics::unanswered();
        $told = 0;
        $pixels = new PixelPlacement($graphics, static function () use (&$told): void {
            ++$told;
        });
        PixelGraphics::answer($graphics, "\x1b_Gi=31;OK\x1b\\", "\x1b[6;34;16t", "\x1b[?62c");

        $this->assertSame(1, $told);
        $this->assertSame(16, $pixels->pixelsPerColumn());
    }

    public function testAFitViewIsPlacedOverTheCellsItCoversAndSentAtTheirPixels(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered(cellWidth: 8, cellHeight: 16);
        $pixels = new PixelPlacement($graphics, static function (): void {});

        // 40×20 in 20 cols × 20 half-rows fits at 0.5: 20 columns × 10
        // half-rows, centred with 5 half-rows above — so the first covered cell
        // row is 2, and 6 rows are placed (the odd half-row rounds out).
        $lines = $pixels->render(self::halves(40, 20), 1.0, new ZoomPanModel(40, 20), 20, 10);

        $this->assertIsArray($lines);
        $this->assertCount(10, $lines);
        foreach ($lines as $index => $line) {
            $this->assertSame(20, AnsiUtils::visibleWidth($line), "row {$index}");
        }
        $this->assertSame(\str_repeat(' ', times: 20), $lines[1]);
        $this->assertSame(\str_repeat(' ', times: 20), $lines[8]);
        $this->assertSame(KittyPlaceholders::row(1000, 0, 0, 20), $lines[2]);
        $this->assertSame(KittyPlaceholders::row(1000, 5, 0, 20), $lines[7]);

        [$image] = PixelGraphics::sentImages($terminal);
        $this->assertSame([160, 96], [\imagesx($image), \imagesy($image)], 'the placed cells at 8×16 pixels each');
        $this->assertStringContainsString("\x1b_Ga=p,U=1,i=1000,p=1,c=20,r=6,q=2\x1b\\", $terminal->getOutput());
    }

    public function testAnUnchangedViewSendsNothing(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});
        $image = self::halves(40, 20);
        $view = new ZoomPanModel(40, 20);

        $first = $pixels->render($image, 1.0, $view, 20, 10);
        $terminal->clearOutput();

        $this->assertSame($first, $pixels->render($image, 1.0, $view, 20, 10));
        $this->assertSame('', $terminal->getOutput());
    }

    public function testANewViewIsSentUnderTheOtherIdSoItsCellsAreNewText(): void
    {
        // Ghostty never redraws cells whose text is unchanged, and a zoom that
        // keeps the cell box would otherwise paint identical cells.
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});
        $image = self::halves(40, 40);
        $view = new ZoomPanModel(40, 40);
        $view->zoomIn(cols: 20, pxRows: 20);

        $before = \implode("\n", (array) $pixels->render($image, 1.0, $view, 20, 10));
        $view->panBy(4, 0, 20, 20);
        $after = \implode("\n", (array) $pixels->render($image, 1.0, $view, 20, 10));
        $view->panBy(4, 0, 20, 20);
        $third = \implode("\n", (array) $pixels->render($image, 1.0, $view, 20, 10));

        $this->assertSame([1000], PixelGraphics::imageIdsIn($before));
        $this->assertSame([1001], PixelGraphics::imageIdsIn($after));
        $this->assertSame([1000], PixelGraphics::imageIdsIn($third), 'the two ids take turns');
        $this->assertCount(3, PixelGraphics::sentImages($terminal));
        $this->assertStringNotContainsString('a=d', $terminal->getOutput(), 'the previous view stays up under its own id');
    }

    public function testAnotherImageAtTheSameViewIsSent(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});
        $view = new ZoomPanModel(40, 20);
        $first = self::halves(40, 20);
        $pixels->render($first, 1.0, $view, 20, 10);

        $pixels->render(self::halves(40, 20), 1.0, $view, 20, 10);

        $this->assertCount(2, PixelGraphics::sentImages($terminal));
    }

    public function testMagnifyingKeepsThePixelsHard(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});

        // Two source pixels over 160 screen pixels: nearest neighbour, so only
        // the two colours arrive — no purple where they meet.
        $pixels->render(self::halves(2, 1), 1.0, new ZoomPanModel(2, 1), 20, 10);

        [$image] = PixelGraphics::sentImages($terminal);
        $colours = [];
        for ($x = 0; $x < \imagesx($image); ++$x) {
            $colours[\imagecolorat($image, $x, \intdiv(\imagesy($image), num2: 2)) & 0xFF_FF_FF] = true;
        }
        $this->assertSame([0xFF_00_00, 0x00_00_FF], \array_keys($colours));
    }

    /**
     * A view reaching past the picture — here the half row above and below a
     * picture whose letterbox is an odd number of half rows — is left empty,
     * not filled by stretching the picture over it: stretched, every point sat
     * off the cell a click on it maps to.
     */
    public function testWhatLiesOffThePictureStaysEmptyRatherThanStretched(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});

        // 2 × 1 at fit in 20 × 10: 10 half rows of picture in 20, a letterbox
        // of 5 above — so the first placed row starts half a row early.
        $pixels->render(self::halves(2, 1), 1.0, new ZoomPanModel(2, 1), 20, 10);

        [$image] = PixelGraphics::sentImages($terminal);
        $alpha = static fn (int $y): int => (\imagecolorat($image, x: 0, y: $y) >> 24) & 0x7F;
        $this->assertSame(96, \imagesy($image), 'six rows of 16 pixels');
        $this->assertSame([127, 127, 0, 0, 127, 127], [$alpha(0), $alpha(7), $alpha(8), $alpha(87), $alpha(88), $alpha(95)], 'half a row empty above and below, the picture between');
        $this->assertSame(0xFF_00_00, \imagecolorat($image, x: 79, y: 48) & 0xFF_FF_FF);
        $this->assertSame(0x00_00_FF, \imagecolorat($image, x: 80, y: 48) & 0xFF_FF_FF, 'and not a pixel off across');
    }

    /** An outline is stroked just outside its rectangle, in its colour, and changing it sends the picture again. */
    public function testAnOutlineIsStrokedAroundItsRectangle(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});
        $picture = self::halves(100, 100);
        $view = new ZoomPanModel(100, 100); // fit in 20 × 10: 20 columns of 8 pixels, 1.6 per document pixel

        $pixels->render($picture, 1.0, $view, 20, 10, new PixelOutline(25.0, 25.0, 75.0, 75.0, [0, 255, 0]));

        [$image] = PixelGraphics::sentImages($terminal);
        $green = static fn (int $x, int $y): bool => 0x00_FF_00 === (\imagecolorat($image, $x, $y) & 0xFF_FF_FF);
        $this->assertTrue($green(x: 37, y: 80), 'the left side: 25 × 1.6 = 40, three pixels out');
        $this->assertTrue($green(x: 123, y: 80), 'the right side: 75 × 1.6 = 120, three pixels out');
        $this->assertFalse($green(x: 40, y: 80), 'what it frames is left to show');
        $this->assertFalse($green(x: 80, y: 80));

        $pixels->render($picture, 1.0, $view, 20, 10, new PixelOutline(25.0, 25.0, 75.0, 75.0, [0, 255, 0]));
        $this->assertCount(1, PixelGraphics::sentImages($terminal), 'the same outline: nothing to send');
        $pixels->render($picture, 1.0, $view, 20, 10);
        $this->assertCount(2, PixelGraphics::sentImages($terminal), 'without it: sent again');
    }

    public function testShrinkingAveragesAndARerenderIsCutInItsOwnPixels(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});

        // A raster rendered at twice the document's size: the cut has to be
        // taken in the raster's pixels, or it would show only its top-left.
        $pixels->render(self::halves(800, 400), 2.0, new ZoomPanModel(400, 200), 20, 10);

        [$image] = PixelGraphics::sentImages($terminal);
        $middle = \intdiv(\imagesy($image), num2: 2);
        $this->assertSame(0xFF_00_00, \imagecolorat($image, x: 5, y: $middle) & 0xFF_FF_FF);
        $this->assertSame(0x00_00_FF, \imagecolorat($image, \imagesx($image) - 5, $middle) & 0xFF_FF_FF);
    }

    public function testAViewIsNeverSentPastTheBudget(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered(cellWidth: 100, cellHeight: 200);
        $pixels = new PixelPlacement($graphics, static function (): void {});

        $pixels->render(self::halves(200, 200), 1.0, new ZoomPanModel(200, 200), 40, 20);

        [$image] = PixelGraphics::sentImages($terminal);
        $this->assertLessThanOrEqual(PixelPlacement::BUDGET, \imagesx($image) * \imagesy($image));
        $this->assertGreaterThan(PixelPlacement::BUDGET * 0.9, \imagesx($image) * \imagesy($image), 'shrunk to it, not far below it');
    }

    public function testAPaneWiderThanAPlacementCanAddressKeepsItsHalfBlocks(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});
        $wide = KittyPlaceholders::MAX_CELLS + 1;

        $this->assertNull($pixels->render(self::halves($wide, 10), 1.0, new ZoomPanModel($wide, 10), $wide, 10));
        $this->assertNull($pixels->render(self::halves(10, 2 * $wide), 1.0, new ZoomPanModel(10, 2 * $wide), 10, $wide));
        $this->assertSame('', $terminal->getOutput());
    }

    public function testForgettingFreesBothViewsAndSendsTheNextAfresh(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();
        $pixels = new PixelPlacement($graphics, static function (): void {});
        $image = self::halves(40, 40);
        $view = new ZoomPanModel(40, 40);
        $pixels->render($image, 1.0, $view, 20, 10);
        $view->zoomIn(cols: 20, pxRows: 20);
        $pixels->render($image, 1.0, $view, 20, 10);
        $terminal->clearOutput();

        $pixels->forget();
        $deleted = $terminal->getOutput();
        $this->assertSame(2, \substr_count($deleted, needle: 'a=d,d=I'));
        $this->assertStringContainsString("\x1b_Ga=d,d=I,i=1000,q=2\x1b\\", $deleted);
        $this->assertStringContainsString("\x1b_Ga=d,d=I,i=1001,q=2\x1b\\", $deleted);

        $terminal->clearOutput();
        $pixels->render($image, 1.0, $view, 20, 10);
        $this->assertCount(1, PixelGraphics::sentImages($terminal), 'the same view, sent again: the terminal no longer holds it');
    }

    public function testForgettingBeforeAnythingWasShownSendsNothing(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered();

        new PixelPlacement($graphics, static function (): void {})->forget();

        $this->assertSame('', $terminal->getOutput());
    }
}
