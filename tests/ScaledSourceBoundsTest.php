<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ScaledSource;
use Porta\Tests\Support\Probe;

/**
 * The downscale's edges: when a halving is worth taking, in each axis on its
 * own, the size of the copy, and what it keeps of the picture's transparency.
 */
final class ScaledSourceBoundsTest extends TestCase
{
    /**
     * A level is halved while it is at least four times the target in BOTH
     * axes: exactly four times is enough, and a quarter scale that rounds
     * either side of the target up to 251 is not.
     */
    public function testALevelIsHalvedOnlyWhileBothAxesHoldFourTargets(): void
    {
        $this->assertSame([500], self::levelsFor(1000, 1000), 'exactly four times');
        $this->assertSame([], self::levelsFor(1000, 1002), 'the height two pixels short of four 251s');
        $this->assertSame([], self::levelsFor(1002, 1000), 'the width two pixels short');
    }

    /** The copy is the picture's size at the scale, to the nearest pixel. */
    public function testTheCopyIsRoundedToTheNearestPixel(): void
    {
        $size = static function (int $width, int $height): array {
            $copy = new ScaledSource()->forScale(self::blank($width, $height), 0.1);

            return [$copy->image->width, $copy->image->height];
        };

        $this->assertSame([11, 5], $size(107, height: 51), '10.7 and 5.1');
        $this->assertSame([10, 6], $size(101, height: 57), '10.1 and 5.7');
    }

    /** A transparent picture shrinks to a transparent copy, not one painted over the canvas's black. */
    public function testTransparencySurvivesTheShrink(): void
    {
        $gd = \imagecreatetruecolor(8, height: 8);
        \imagealphablending($gd, enable: false);
        \imagesavealpha($gd, enable: true);
        \imagefill($gd, x: 0, y: 0, color: (int) \imagecolorallocatealpha($gd, red: 255, green: 255, blue: 255, alpha: 127));

        $copy = new ScaledSource()->forScale(new ImageData($gd, 8, 8, 0), 0.5);

        $this->assertSame(127, (\imagecolorat($copy->image->gd, x: 1, y: 1) >> 24) & 0x7F);
    }

    /** @return list<int> the widths of the halvings a quarter-scale copy of a $width × $height picture built */
    private static function levelsFor(int $width, int $height): array
    {
        $scaled = new ScaledSource();
        $scaled->forScale(self::blank($width, $height), 0.25);
        $levels = Probe::get($scaled, 'levels');
        self::assertIsArray($levels);

        return \array_map(static fn (ImageData $level): int => $level->width, $levels);
    }

    private static function blank(int $width, int $height): ImageData
    {
        return new ImageData(\imagecreatetruecolor($width, $height), $width, $height, 0);
    }
}
