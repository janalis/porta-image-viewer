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

    /**
     * The staging is a PERFORMANCE change and must not be a picture change.
     * Averaging twice is not averaging once, and MIN_OVERSAMPLE is the dial
     * that decides how far apart they land: on this fixture a floor of 2 keeps
     * the error at 2.3/255 and a floor of 1 — halving until a level merely
     * MATCHES the target, so the last stage averages nothing — pushes it to
     * 3.8. Line art at a size that halves untidily is where the difference
     * shows at all; a tidy grid cannot tell the two apart.
     */
    public function testAStagedCopyIsIndistinguishableFromASingleStageOne(): void
    {
        $image = $this->lineArt(1000, 700);
        $staged = new ScaledSource()->forScale($image, 37 / 1000);
        [$width, $height] = [$staged->image->width, $staged->image->height];

        $direct = imagecreatetruecolor($width, $height);
        imagealphablending($direct, enable: false);
        imagesavealpha($direct, enable: true);
        imagecopyresampled($direct, $image->gd, dst_x: 0, dst_y: 0, src_x: 0, src_y: 0, dst_width: $width, dst_height: $height, src_width: 1000, src_height: 700);

        $this->assertSame([37, 26], [$width, $height]);
        $this->assertLessThan(
            3.0,
            $this->meanAbsoluteDifference($staged->image, new ImageData($direct, $width, $height, 0)),
            'the staged copy must stay within a level or two of 255 of the direct one',
        );
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
        $image = $this->lineArt(640, 640);
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
        $image = $this->lineArt(640, 640);
        $scaled = new ScaledSource();
        $scaled->forScale($image, 0.03);
        $built = Probe::get($scaled, 'levels');
        $this->assertSame([320, 160, 80, 40], array_map(static fn (ImageData $level): int => $level->width, $built));

        // 128 wide: the 320 halving still oversamples it, the 160 one does not.
        $climbed = $scaled->forScale($image, 0.2)->image;
        $this->assertSame(128, $climbed->width);
        // 256 wide: nothing oversamples it, so the image itself is the source.
        $this->assertSame(256, $scaled->forScale($image, 0.4)->image->width);
        $this->assertSame($built, Probe::get($scaled, 'levels'), 'climbing back up must build nothing');

        // The sizes above hold whichever level the pixels came from — they are
        // computed off the original either way — so only the PICTURE says the
        // climb happened. Measured against a direct resample: climbing lands at
        // 3.5/255, and reading from the 40-wide level instead (a 3× MAGNIFY of
        // it) lands at 21.6.
        $direct = imagecreatetruecolor(128, height: 128);
        imagealphablending($direct, enable: false);
        imagesavealpha($direct, enable: true);
        imagecopyresampled($direct, $image->gd, dst_x: 0, dst_y: 0, src_x: 0, src_y: 0, dst_width: 128, dst_height: 128, src_width: 640, src_height: 640);
        $this->assertLessThan(
            8.0,
            $this->meanAbsoluteDifference($climbed, new ImageData($direct, 128, 128, 0)),
            'the copy must be an average of a finer level, not a blow-up of a coarser one',
        );
    }

    public function testANewImageDropsTheHalvingsWithIt(): void
    {
        $scaled = new ScaledSource();
        $scaled->forScale($this->lineArt(640, 640), 0.03);
        $this->assertNotSame([], Probe::get($scaled, 'levels'));

        $scaled->forScale($this->lineArt(320, 320), 0.03);
        $this->assertSame([160, 80, 40, 20], array_map(static fn (ImageData $level): int => $level->width, Probe::get($scaled, 'levels')));
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
