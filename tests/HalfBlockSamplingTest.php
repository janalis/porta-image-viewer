<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\HalfBlockRasterizer;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\SampleSource;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\Probe;

/**
 * Which pixel each half-cell shows. The pictures here colour every pixel by
 * where it is — red its x, green its y — so a rendered pane reads back as the
 * coordinates it sampled, where {@see HalfBlockRasterizerTest} checks the
 * escapes for pictures of one or two colours.
 */
final class HalfBlockSamplingTest extends TestCase
{
    /**
     * A copy shrunk to half is sampled in its own pixels: the view's origin
     * (4, 4) is the copy's (2, 2), one copy pixel a half-cell — the original's
     * every other pixel.
     */
    public function testAShrunkCopyIsSampledInItsOwnPixels(): void
    {
        $view = new ZoomPanModel(16, 16);
        $view->actualSize(4, 4);
        $view->zoomOut(4, 4); // 50%, centred: the original from (4, 4)
        $half = self::coded(8, 8, 2);

        $this->assertSame(
            [
                [[[4, 4], [4, 6]], [[6, 4], [6, 6]], [[8, 4], [8, 6]], [[10, 4], [10, 6]]],
                [[[4, 8], [4, 10]], [[6, 8], [6, 10]], [[8, 8], [8, 10]], [[10, 8], [10, 10]]],
            ],
            self::cells(HalfBlockRasterizer::render(new SampleSource($half, 0.5), $view, 4, 2)),
        );
    }

    /** Magnified 2.5×, a half-cell shows the pixel its top-left corner falls in. */
    public function testAMagnifiedPixelIsTheOneUnderTheCellsLeftEdge(): void
    {
        $cells = self::cells(HalfBlockRasterizer::render(SampleSource::original(self::coded(4, 4)), new ZoomPanModel(4, 4), 10, 5));
        $columns = \array_map(static fn (array $cell): int => $cell[0][0], $cells[0]);
        $halfRows = \array_merge(...\array_map(static fn (array $row): array => [$row[0][0][1], $row[0][1][1]], $cells));

        $this->assertSame([0, 0, 0, 1, 1, 2, 2, 2, 3, 3], $columns);
        $this->assertSame([0, 0, 0, 1, 1, 2, 2, 2, 3, 3], $halfRows, 'and its top edge, down');
    }

    /**
     * Panned to the far corner at 800%, the last column and row reach past the
     * picture — the view is 5⅝ pixels wide from 95 — and show its edge pixel.
     */
    public function testThePanesFarEdgeShowsThePicturesLastPixel(): void
    {
        $view = new ZoomPanModel(100, 60);
        $view->actualSize(45, 20);
        foreach (\range(1, end: 3) as $ignored) {
            $view->zoomIn(45, 20);
        }
        $view->panBy(10_000, 10_000, 45, 20);

        $cells = self::cells(HalfBlockRasterizer::render(SampleSource::original(self::coded(100, 60)), $view, 45, 10));

        $this->assertSame([[99, 59], [99, 59]], [$cells[9][44][1], $cells[9][43][1]]);
        $this->assertSame([95, 58], $cells[0][0][0]);
    }

    /** The checkerboard's squares are eight pixels each way. */
    public function testTheCheckerboardSquaresAreEightPixelsEachWay(): void
    {
        $shade = static fn (int $x, int $y): int => Probe::invokeStatic(HalfBlockRasterizer::class, 'overChecker', [0, 0, 0], 127, $x, $y)[0];

        $this->assertSame(
            ['0,0' => 90, '4,4' => 90, '7,7' => 90, '8,0' => 140, '0,8' => 140, '8,8' => 90, '0,16' => 90, '16,0' => 90],
            [
                '0,0' => $shade(0, y: 0),
                '4,4' => $shade(4, y: 4),
                '7,7' => $shade(7, y: 7),
                '8,0' => $shade(8, y: 0),
                '0,8' => $shade(0, y: 8),
                '8,8' => $shade(8, y: 8),
                '0,16' => $shade(0, y: 16),
                '16,0' => $shade(16, y: 0),
            ],
        );
    }

    /** A blend lands on the nearest shade: 200 at 70/127 over 90 is 139.4, so 139. */
    public function testABlendRoundsToTheNearestShade(): void
    {
        $this->assertSame([139, 139, 139], Probe::invokeStatic(HalfBlockRasterizer::class, 'overChecker', [200, 200, 200], 70, 0, 0));
    }

    /** A $width × $height picture whose pixel (x, y) is red $step·x, green $step·y. */
    private static function coded(int $width, int $height, int $step = 1): ImageData
    {
        $gd = \imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; ++$x) {
            for ($y = 0; $y < $height; ++$y) {
                \imagesetpixel($gd, $x, $y, (int) \imagecolorallocate($gd, $x * $step, $y * $step, blue: 0));
            }
        }

        return new ImageData($gd, $width, $height, 0);
    }

    /**
     * Every cell of each line as its two halves' [red, green].
     *
     * @param list<string> $lines
     *
     * @return list<list<array{array{int, int}, array{int, int}}>>
     */
    private static function cells(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $cells = [];
            $halves = null;
            \preg_match_all('/\x1b\[38;2;(\d+);(\d+);\d+;48;2;(\d+);(\d+);\d+m|\x1b\[0m|▀/u', $line, $tokens, \PREG_SET_ORDER);
            foreach ($tokens as $token) {
                if ('▀' === $token[0]) {
                    $cells[] = $halves;

                    continue;
                }
                if (\count($token) > 1) { // a colour, not the reset
                    $halves = [[(int) $token[1], (int) $token[2]], [(int) $token[3], (int) $token[4]]];
                }
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
