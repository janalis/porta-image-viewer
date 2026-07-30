<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\HalfBlockRasterizer;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\SampleSource;
use Porta\ImageViewer\ScaledSource;
use Porta\ImageViewer\ZoomPanModel;

/** The downscale anti-aliasing: what the rasterizer samples, and when. */
final class ScaledSourceTest extends TestCase
{
    /** A $width × $height black image with one white column at $lineX. */
    private function withThinLine(int $width, int $height, int $lineX): ImageData
    {
        $gd = imagecreatetruecolor($width, height: $height);
        $black = (int) imagecolorallocate($gd, red: 0, green: 0, blue: 0);
        $white = (int) imagecolorallocate($gd, red: 255, green: 255, blue: 255);
        imagefilledrectangle($gd, x1: 0, y1: 0, x2: $width - 1, y2: $height - 1, color: $black);
        imagefilledrectangle($gd, x1: $lineX, y1: 0, x2: $lineX, y2: $height - 1, color: $white);

        return new ImageData($gd, $width, $height, 0);
    }

    public function testAtOrAboveOneToOneTheOriginalPixelsAreSampled(): void
    {
        $image = $this->withThinLine(20, 2, lineX: 5);
        $scaled = new ScaledSource();

        foreach ([1.0, 2.0, 8.0] as $scale) {
            $source = $scaled->forScale($image, $scale);
            $this->assertSame($image, $source->image, 'magnifying must stay crisp — no resample');
            $this->assertSame(1.0, $source->ratio);
        }
    }

    public function testBelowOneToOneItResamplesToTheScaleAndReportsTheRatio(): void
    {
        $image = $this->withThinLine(100, 50, lineX: 5);
        $source = new ScaledSource()->forScale($image, 0.1);

        $this->assertNotSame($image, $source->image);
        $this->assertSame(10, $source->image->width);
        $this->assertSame(5, $source->image->height);
        $this->assertSame(0.1, $source->ratio, 'the ratio is what was actually baked in, not what was asked');
    }

    public function testADegenerateScaleStillYieldsOnePixel(): void
    {
        $source = new ScaledSource()->forScale($this->withThinLine(20, 2, lineX: 5), 0.001);

        $this->assertSame(1, $source->image->width);
        $this->assertSame(1, $source->image->height);
    }

    public function testTheCopyIsMemoisedPerScaleAndPerImage(): void
    {
        $image = $this->withThinLine(40, 4, lineX: 5);
        $other = $this->withThinLine(40, 4, lineX: 9);
        $scaled = new ScaledSource();

        $first = $scaled->forScale($image, 0.25);
        $this->assertSame($first, $scaled->forScale($image, 0.25), 'a pan at one scale must not resample again');
        $this->assertNotSame($first, $scaled->forScale($image, 0.5), 'a zoom step needs its own copy');
        $this->assertNotSame($first, $scaled->forScale($other, 0.25), 'a new image at the same scale must not reuse the old copy');
    }

    public function testAnOversampledSourceIsMeasuredInTheCallersUnits(): void
    {
        // A bitmap rendered at 2 pixels per unit (the graph viewer's 144 dpi).
        $bitmap = $this->withThinLine(40, 40, lineX: 5);
        $scaled = new ScaledSource();

        $same = $scaled->forScale($bitmap, 2.0, ratio: 2.0);
        $this->assertSame($bitmap, $same->image, 'zoom 2 on a 2× bitmap is 1:1 — nothing to average');
        $this->assertSame(2.0, $same->ratio);

        $copy = $scaled->forScale($bitmap, 0.5, ratio: 2.0);
        $this->assertSame(10, $copy->image->width, 'zoom 0.5 on a 2× bitmap is a 4× shrink');
        $this->assertSame(0.5, $copy->ratio, 'and the ratio comes back in the caller\'s units, not the copy\'s');
    }

    /**
     * The point of the whole exercise: at a 10% fit, nearest-neighbour reads
     * every tenth pixel and a one-pixel line falls between the samples. The
     * averaged copy keeps it as a grey.
     */
    public function testAThinLineSurvivesADownscaleThatNearestNeighbourWouldDrop(): void
    {
        // Square, so the 2×1 pane's fit is 0.1 on both axes and every half-row
        // reads a real pixel (no letterbox to muddy the assertion).
        $image = $this->withThinLine(20, 20, lineX: 5);
        $view = new ZoomPanModel(20, 20);

        // The left cell covers x 0-9, so it is the one holding the line.
        $nearest = HalfBlockRasterizer::render(SampleSource::original($image), $view, 2, 1)[0];
        $this->assertStringStartsWith("\x1b[38;2;0;0;0;48;2;0;0;0m", $nearest, 'nearest-neighbour reads x=0 and misses the line entirely');

        $averaged = HalfBlockRasterizer::render(new ScaledSource()->forScale($image, 0.1), $view, 2, 1)[0];
        preg_match('/^\x1b\[38;2;(\d+);(\d+);(\d+)/', $averaged, $rgb);
        $this->assertGreaterThan(0, (int) $rgb[1], 'the averaged copy carries the line as a grey');
        $this->assertSame([$rgb[1], $rgb[1]], [$rgb[2], $rgb[3]], 'and it is neutral, not a colour cast');
    }
}
