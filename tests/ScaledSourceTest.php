<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\HalfBlockRasterizer;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\SampleSource;
use Porta\ImageViewer\ScaledSource;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Tests\Support\Probe;

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

    /** The mean per-channel difference between two same-sized copies, out of 255. */
    private function meanAbsoluteDifference(ImageData $left, ImageData $right): float
    {
        $total = 0;
        for ($y = 0; $y < $left->height; ++$y) {
            for ($x = 0; $x < $left->width; ++$x) {
                $a = (int) imagecolorat($left->gd, $x, $y);
                $b = (int) imagecolorat($right->gd, $x, $y);
                foreach ([16, 8, 0] as $shift) {
                    $total += abs((($a >> $shift) & 255) - (($b >> $shift) & 255));
                }
            }
        }

        return $total / ($left->width * $left->height * 3);
    }

    /** Line art at an awkward size: what a graph bitmap actually looks like. */
    private function lineArt(int $width, int $height): ImageData
    {
        $gd = imagecreatetruecolor($width, height: $height);
        imagefilledrectangle($gd, x1: 0, y1: 0, x2: $width - 1, y2: $height - 1, color: (int) imagecolorallocate($gd, red: 255, green: 255, blue: 255));
        $black = (int) imagecolorallocate($gd, red: 0, green: 0, blue: 0);
        mt_srand(3);
        for ($i = 0; $i < 60; ++$i) {
            imageline($gd, x1: mt_rand(0, $width - 1), y1: mt_rand(0, $height - 1), x2: mt_rand(0, $width - 1), y2: mt_rand(0, $height - 1), color: $black);
        }
        for ($i = 0; $i < 40; ++$i) {
            imagerectangle($gd, x1: mt_rand(0, $width - 100), y1: mt_rand(0, $height - 100), x2: mt_rand(100, $width - 1), y2: mt_rand(100, $height - 1), color: $black);
        }

        return new ImageData($gd, $width, $height, 0);
    }

    /** One single-stage copy of $image, for the staged one to be measured against. */
    private function directResample(ImageData $image, int $width, int $height): ImageData
    {
        $canvas = imagecreatetruecolor($width, height: $height);
        imagealphablending($canvas, enable: false);
        imagesavealpha($canvas, enable: true);
        imagecopyresampled($canvas, $image->gd, dst_x: 0, dst_y: 0, src_x: 0, src_y: 0, dst_width: $width, dst_height: $height, src_width: $image->width, src_height: $image->height);

        return new ImageData($canvas, $width, $height, 0);
    }

    /**
     * The staging is a PERFORMANCE change and must not be a picture change, and
     * the PIXEL BUDGET is what keeps it one. Halving past the point where a
     * level is already cheap to read buys nothing and costs precision, because
     * an integer halving of a dimension that is already small quantises and
     * truncates. This tall fixture is where that shows: with the budget the
     * chain stops at one halving and the copy lands 0.02/255 from a direct
     * resample, and without it a second halving of the 300-wide axis pushes it
     * to 5.2 — measured, both.
     */
    public function testAStagedCopyIsIndistinguishableFromASingleStageOne(): void
    {
        $image = $this->lineArt(300, 5000);
        $staged = new ScaledSource()->forScale($image, 0.1);
        [$width, $height] = [$staged->image->width, $staged->image->height];

        $this->assertSame([30, 500], [$width, $height]);
        $this->assertLessThan(
            1.0,
            $this->meanAbsoluteDifference($staged->image, $this->directResample($image, $width, $height)),
            'the staged copy must be indistinguishable from the direct one',
        );
    }

    /**
     * Which side of the budget "already cheap" falls on. A 1000×600 image is
     * exactly LEVEL_PIXELS, and the rule reads as "bigger than this is worth
     * halving" — so this one is not, and nothing is built. Nothing else in the
     * suite can tell `>` from `>=` here, because no other fixture lands on the
     * boundary.
     */
    public function testALevelExactlyAtTheBudgetIsAlreadySmallEnough(): void
    {
        $scaled = new ScaledSource();
        $scaled->forScale($this->lineArt(1000, 600), 0.1);

        $this->assertSame([], Probe::get($scaled, 'levels'));
    }

    /**
     * The other half, and the failure the budget cannot catch: a level must
     * never be MAGNIFIED back up to the target. Here nothing may be halved at
     * all — a third of a 900-wide image is 300 wide, and the one halving that
     * the budget would allow is 450, only 1.5× the target. A floor of 1 builds
     * that level and samples from it.
     */
    public function testATargetTooBigToAverageIsTakenFromTheImageItself(): void
    {
        $scaled = new ScaledSource();
        $copy = $scaled->forScale($this->lineArt(900, 900), 1 / 3);

        $this->assertSame(300, $copy->image->width);
        $this->assertSame([], Probe::get($scaled, 'levels'), 'no halving is close enough to 300 to average from');
    }

    /**
     * The defect this staging exists for: a zoom is a NEW scale on every
     * keystroke, so the memo misses every frame. If each miss rebuilt from the
     * full image the frame would cost what one full read costs — which is what
     * .porta/freeze.log recorded. The halvings are per IMAGE, so eight zoom
     * steps must not leave eight times the levels behind.
     */
    public function testTheHalvingsAreBuiltPerImageNotPerScale(): void
    {
        $image = $this->lineArt(1600, 1600);
        $scaled = new ScaledSource();

        $scaled->forScale($image, 0.03);
        $afterFirst = Probe::get($scaled, 'levels');
        self::assertIsArray($afterFirst);
        $this->assertNotSame([], $afterFirst, 'a hard downscale is sampled from a halving, not from the image');

        for ($step = 1; $step <= 8; ++$step) {
            $scaled->forScale($image, 0.03 + ($step / 1000));
        }
        $afterZooming = Probe::get($scaled, 'levels');
        self::assertIsArray($afterZooming);
        $this->assertSame($afterFirst, $afterZooming, 'zooming within one band must reuse the levels it already has');
    }

    /**
     * Zooming back IN asks for a bigger target, and the halvings it built on
     * the way out are then too coarse to average from. It must climb back up
     * them — never magnify one, which is the way to actually lose detail — and
     * it must not rebuild anything to do it.
     */
    public function testZoomingBackInClimbsTheHalvingsItAlreadyBuilt(): void
    {
        $image = $this->lineArt(1600, 1600);
        $scaled = new ScaledSource();
        $scaled->forScale($image, 0.03);
        $built = Probe::get($scaled, 'levels');
        $this->assertSame([800, 400], array_map(static fn (ImageData $level): int => $level->width, $built));

        // 320 wide: the 800 halving still oversamples it (2.5×), the 400 one
        // does not — sampling THAT would be a 1.25× copy that averages nothing.
        $climbed = $scaled->forScale($image, 0.2)->image;
        $this->assertSame(320, $climbed->width);
        // 480 wide: nothing oversamples it, so the image itself is the source.
        $wider = $scaled->forScale($image, 0.3)->image;
        $this->assertSame(480, $wider->width);
        $this->assertSame($built, Probe::get($scaled, 'levels'), 'climbing back up must build nothing');

        // The sizes above hold whichever level the pixels came from — they are
        // computed off the ORIGINAL either way — so only the picture says the
        // climb happened, and both rungs are measured. Failing to climb reads
        // 320 out of the 400 level (1.25×, averaging nothing): 1.9/255 becomes
        // 4.5. And 480 out of it is an outright MAGNIFY: 0.0 becomes 6.3.
        $this->assertLessThan(
            3.0,
            $this->meanAbsoluteDifference($climbed, $this->directResample($image, 320, 320)),
            'the copy must be an average of a finer level, not a 1.25× copy of a coarser one',
        );
        $this->assertLessThan(
            1.0,
            $this->meanAbsoluteDifference($wider, $this->directResample($image, 480, 480)),
            'a target no level oversamples comes from the image, never from a blow-up',
        );
    }

    public function testANewImageDropsTheHalvingsWithIt(): void
    {
        $scaled = new ScaledSource();
        $scaled->forScale($this->lineArt(1600, 1600), 0.03);
        $this->assertNotSame([], Probe::get($scaled, 'levels'));

        $scaled->forScale($this->lineArt(1024, 1024), 0.03);
        $this->assertSame([512], array_map(static fn (ImageData $level): int => $level->width, Probe::get($scaled, 'levels')));
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
