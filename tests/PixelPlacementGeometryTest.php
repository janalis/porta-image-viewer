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

/**
 * Where the pixel path cuts, sizes and places a view, to the pixel — the
 * sizes, the cells and the picture sent for views away from the origin, off
 * the half-block grid and against each limit, where {@see PixelPlacementTest}
 * samples a colour or two.
 */
final class PixelPlacementGeometryTest extends TestCase
{
    /**
     * A 6 × 6 document rendered at twice its size, every raster pixel coloured
     * by where it is, at 1:1 in a 4 × 2 pane of 3 × 5 pixel cells: the view is
     * document (1, 1) to (5, 5), so raster 2 to 9 both ways, sent at 12 × 10.
     * Each sent pixel is named by the raster pixel it came from; `##` is the
     * outline, stroked two pixels wide three outside document (2.5, 2.2) to
     * (3.5, 3.2).
     */
    public function testAPannedRerenderIsCutFromItsOwnRasterPixels(): void
    {
        $this->assertSame(
            [
                '22 32 32 42 52 52 62 72 72 82 92 92',
                '23 33 33 43 53 53 63 73 73 83 93 93',
                '24 34 34 44 54 54 64 74 74 84 94 94',
                '25 35 35 45 55 55 65 75 75 85 95 95',
                '25 35 35 45 55 55 65 75 75 85 95 95',
                '26 36 36 46 56 56 66 76 76 86 96 96',
                '27 37 37 47 57 57 67 77 77 87 97 97',
                '28 38 38 48 58 58 68 78 78 88 98 98',
                '29 39 39 49 59 59 69 79 79 89 99 99',
                '29 39 39 49 59 59 69 79 79 89 99 99',
            ],
            self::cut(null),
        );
        $this->assertSame(
            [
                '22 ## ## ## ## ## ## ## ## ## ## ##',
                '23 ## ## ## 53 53 63 73 73 83 ## ##',
                '24 ## ## ## 54 54 64 74 74 84 ## ##',
                '25 ## ## ## 55 55 65 75 75 85 ## ##',
                '25 ## ## ## 55 55 65 75 75 85 ## ##',
                '26 ## ## ## 56 56 66 76 76 86 ## ##',
                '27 ## ## ## 57 57 67 77 77 87 ## ##',
                '28 ## ## ## 58 58 68 78 78 88 ## ##',
                '29 ## ## ## 59 59 69 79 79 89 ## ##',
                '29 ## ## ## ## ## ## ## ## ## ## ##',
            ],
            self::cut(new PixelOutline(2.5, 2.2, 3.5, 3.2, [0, 0, 255])),
        );
    }

    /** At a density of 1.5 the view starts on raster 1.5, which rounds to 2: six raster pixels, two sent pixels each. */
    public function testAFractionalDensityRoundsWhereTheCutStarts(): void
    {
        $this->assertSame(
            [
                '22 22 32 32 42 42 52 52 62 62 72 72',
                '23 23 33 33 43 43 53 53 63 63 73 73',
                '23 23 33 33 43 43 53 53 63 63 73 73',
                '24 24 34 34 44 44 54 54 64 64 74 74',
                '24 24 34 34 44 44 54 54 64 64 74 74',
                '25 25 35 35 45 45 55 55 65 65 75 75',
                '26 26 36 36 46 46 56 56 66 66 76 76',
                '26 26 36 36 46 46 56 56 66 66 76 76',
                '27 27 37 37 47 47 57 57 67 67 77 77',
                '27 27 37 37 47 47 57 57 67 67 77 77',
            ],
            self::cut(null, 1.5),
        );
    }

    /**
     * Fitted by its height, the picture sits between letterbox columns: each
     * placed row is the blank before it, the placement's own cells and the
     * blank after, and the picture is cut at the height's scale — its two
     * halves meet in the middle of what is sent.
     */
    public function testAHeightBoundFitIsPlacedBetweenItsLetterboxColumns(): void
    {
        [$graphics, $terminal] = PixelGraphics::answered(cellWidth: 8, cellHeight: 16);
        $pixels = new PixelPlacement($graphics, static function (): void {});

        $lines = $pixels->render(PixelPlacementTest::halves(40, 20), 1.0, new ZoomPanModel(40, 20), 40, 5);

        $blank = \str_repeat(' ', times: 10);
        $this->assertSame(
            \array_map(static fn (int $row): string => $blank.KittyPlaceholders::row(1000, $row, 0, 20).$blank, \range(0, end: 4)),
            $lines,
        );
        $this->assertStringContainsString('c=20,r=5,', $terminal->getOutput(), 'an even span of half-rows covers no extra row');
        [$image] = PixelGraphics::sentImages($terminal);
        $this->assertSame([160, 80], [\imagesx($image), \imagesy($image)]);
        $this->assertSame([0xFF_00_00, 0x00_00_FF], [\imagecolorat($image, x: 79, y: 40), \imagecolorat($image, x: 80, y: 40)]);
    }

    /** Past the budget each side is floored to it; and a single pixel's cell still sends a pixel. */
    public function testTheSentSizeIsTheBudgetFloored(): void
    {
        $this->assertSame([968, 1032], self::sentSize(100, 200, 200, 30, 20), '3000 × 3200 cell pixels, scaled by √(10⁶ / 9.6 × 10⁶)');
        $this->assertSame([1, 1], self::sentSize(1, 1, 1, 1, 1));
    }

    /** A placement as wide, or as tall, as the protocol can address is still placed. */
    public function testAPlacementAtTheAddressLimitIsPlaced(): void
    {
        $most = KittyPlaceholders::MAX_CELLS;
        [$graphics] = PixelGraphics::answered(cellWidth: 1, cellHeight: 1);
        $pixels = new PixelPlacement($graphics, static function (): void {});

        $this->assertCount(10, (array) $pixels->render(PixelPlacementTest::halves($most, 20), 1.0, new ZoomPanModel($most, 20), $most, 10));
        $this->assertCount($most, (array) $pixels->render(PixelPlacementTest::halves(10, 2 * $most), 1.0, new ZoomPanModel(10, 2 * $most), 10, $most));
    }

    /** @return list<string> the picture sent for the panned re-render, a row of raster coordinates per pixel row */
    private static function cut(?PixelOutline $outline, float $density = 2.0): array
    {
        [$graphics, $terminal] = PixelGraphics::answered(cellWidth: 3, cellHeight: 5);
        $pixels = new PixelPlacement($graphics, static function (): void {});
        $side = (int) (6 * $density);
        $raster = \imagecreatetruecolor($side, $side);
        for ($x = 0; $x < $side; ++$x) {
            for ($y = 0; $y < $side; ++$y) {
                \imagesetpixel($raster, $x, $y, (int) \imagecolorallocate($raster, red: $x * 20, green: $y * 20, blue: 0));
            }
        }
        $view = new ZoomPanModel(6, 6);
        $view->zoomIn(cols: 4, pxRows: 4); // the fit's 2/3 → 1:1, centred: panned to (1, 1)

        $pixels->render(new ImageData($raster, $side, $side, 0), $density, $view, 4, 2, $outline);

        [$image] = PixelGraphics::sentImages($terminal);
        $rows = [];
        for ($y = 0; $y < \imagesy($image); ++$y) {
            $cells = [];
            for ($x = 0; $x < \imagesx($image); ++$x) {
                $pixel = \imagecolorat($image, $x, $y);
                $cells[] = match (true) {
                    0x00_00_FF === $pixel => '##',
                    0 !== ($pixel >> 24) => '..',
                    default => \sprintf('%X%X', \intdiv(($pixel >> 16) & 0xFF, num2: 20), \intdiv(($pixel >> 8) & 0xFF, num2: 20)),
                };
            }
            $rows[] = \implode(' ', $cells);
        }

        return $rows;
    }

    /** @return array{int, int} */
    private static function sentSize(int $cellWidth, int $cellHeight, int $side, int $cols, int $rows): array
    {
        [$graphics, $terminal] = PixelGraphics::answered(cellWidth: $cellWidth, cellHeight: $cellHeight);
        new PixelPlacement($graphics, static function (): void {})->render(PixelPlacementTest::halves($side, $side), 1.0, new ZoomPanModel($side, $side), $cols, $rows);
        [$image] = PixelGraphics::sentImages($terminal);

        return [\imagesx($image), \imagesy($image)];
    }
}
