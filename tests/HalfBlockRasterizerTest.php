<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\HalfBlockRasterizer;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\SampleSource;
use Porta\ImageViewer\ZoomPanModel;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class HalfBlockRasterizerTest extends TestCase
{
    /** @param list<list<array{0: int, 1: int, 2: int}>> $pixels rows of [r,g,b] */
    private function image(array $pixels): ImageData
    {
        $height = \count($pixels);
        $width = \count($pixels[0]);
        $gd = \imagecreatetruecolor($width, $height);
        foreach ($pixels as $y => $row) {
            foreach ($row as $x => [$r, $g, $b]) {
                \imagesetpixel($gd, $x, $y, (int) \imagecolorallocate($gd, $r, $g, $b));
            }
        }

        return new ImageData($gd, $width, $height, 0);
    }

    public function testEmitsOneCellPerTwoPixelsWithExactSgr(): void
    {
        $image = $this->image([
            [[255, 0, 0], [0, 255, 0]],
            [[0, 0, 255], [255, 255, 255]],
        ]);
        $lines = HalfBlockRasterizer::render(SampleSource::original($image), new ZoomPanModel(2, 2), 2, 1);

        $this->assertSame(
            ["\x1b[38;2;255;0;0;48;2;0;0;255m▀\x1b[38;2;0;255;0;48;2;255;255;255m▀\x1b[0m"],
            $lines,
        );
    }

    public function testCoalescesRunsOfTheSameColorPair(): void
    {
        $image = $this->image([\array_fill(0, count: 4, value: [10, 20, 30]), \array_fill(0, count: 4, value: [40, 50, 60])]);
        $lines = HalfBlockRasterizer::render(SampleSource::original($image), new ZoomPanModel(4, 2), 4, 1);

        $this->assertSame(["\x1b[38;2;10;20;30;48;2;40;50;60m▀▀▀▀\x1b[0m"], $lines);
    }

    public function testLetterboxesAndCentersASmallerImage(): void
    {
        // 2×2 red image in a 6×2 pane: fit = 2 → 4 cols centered with 1 space each side.
        $image = $this->image([
            [[255, 0, 0], [255, 0, 0]],
            [[255, 0, 0], [255, 0, 0]],
        ]);
        $lines = HalfBlockRasterizer::render(SampleSource::original($image), new ZoomPanModel(2, 2), 6, 2);

        $this->assertCount(2, $lines);
        $this->assertSame(' '."\x1b[38;2;255;0;0;48;2;255;0;0m▀▀▀▀\x1b[0m".' ', $lines[0]);
        foreach ($lines as $line) {
            $this->assertSame(6, AnsiUtils::visibleWidth($line), 'every line spans the pane exactly');
        }
    }

    public function testCellStraddlingTheImageEdgeFillsTheOutsideHalfDark(): void
    {
        // 1×1 image in a 1×1 pane: fit = 1 → only the TOP half-row shows the pixel.
        $image = $this->image([[[255, 0, 0]]]);
        $lines = HalfBlockRasterizer::render(SampleSource::original($image), new ZoomPanModel(1, 1), 1, 1);

        $this->assertSame(["\x1b[38;2;255;0;0;48;2;30;30;30m▀\x1b[0m"], $lines);
    }

    public function testAlphaBlendsOntoTheCheckerboard(): void
    {
        $gd = \imagecreatetruecolor(1, height: 1);
        \imagealphablending($gd, enable: false);
        \imagesavealpha($gd, enable: true);
        \imagesetpixel($gd, x: 0, y: 0, color: (int) \imagecolorallocatealpha($gd, red: 255, green: 255, blue: 255, alpha: 127));
        $transparent = new ImageData($gd, 1, 1, 0);
        $lines = HalfBlockRasterizer::render(SampleSource::original($transparent), new ZoomPanModel(1, 1), 1, 1);
        $this->assertStringContainsString('38;2;90;90;90', $lines[0], 'fully transparent → pure checkerboard (origin square = 90)');

        $gd2 = \imagecreatetruecolor(1, height: 1);
        \imagealphablending($gd2, enable: false);
        \imagesavealpha($gd2, enable: true);
        \imagesetpixel($gd2, x: 0, y: 0, color: (int) \imagecolorallocatealpha($gd2, red: 255, green: 255, blue: 255, alpha: 64));
        $half = new ImageData($gd2, 1, 1, 0);
        $lines = HalfBlockRasterizer::render(SampleSource::original($half), new ZoomPanModel(1, 1), 1, 1);
        $this->assertStringContainsString('38;2;172;172;172', $lines[0], 'alpha 64/127 blends white onto 90-gray → 172');
    }

    public function testTheCheckerboardAlternatesEveryEightPixels(): void
    {
        // A 9×1 fully-transparent image: pixel 8 sits in the neighbouring 8×8
        // checker square (x>>3 = 1), which must land on the OTHER shade (140).
        $gd = \imagecreatetruecolor(9, height: 1);
        \imagealphablending($gd, enable: false);
        \imagesavealpha($gd, enable: true);
        for ($x = 0; $x < 9; ++$x) {
            \imagesetpixel($gd, $x, y: 0, color: (int) \imagecolorallocatealpha($gd, red: 255, green: 255, blue: 255, alpha: 127));
        }
        $image = new ImageData($gd, 9, 1, 0);
        $lines = HalfBlockRasterizer::render(SampleSource::original($image), new ZoomPanModel(9, 1), 9, 1);

        $this->assertStringContainsString('38;2;140;140;140', $lines[0], 'the neighbouring 8×8 square is the OTHER checker shade');
    }

    public function testBlankCellsResetBeforeTheSpaceRun(): void
    {
        // Tall 1×4 image in a wide 5×2 pane: fit = min(5, 1) = 1 → single centered column.
        $image = $this->image([[[9, 9, 9]], [[9, 9, 9]], [[9, 9, 9]], [[9, 9, 9]]]);
        $lines = HalfBlockRasterizer::render(SampleSource::original($image), new ZoomPanModel(1, 4), 5, 2);

        $this->assertSame('  '."\x1b[38;2;9;9;9;48;2;9;9;9m▀\x1b[0m".'  ', $lines[0]);
        $this->assertSame(5, AnsiUtils::visibleWidth($lines[1]));
    }
}
