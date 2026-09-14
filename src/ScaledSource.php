<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/**
 * Anti-aliasing for the way DOWN: below 1:1 it hands the rasterizer a GD-resampled
 * copy of the image at (about) the zoom scale, so each half-cell reads a pixel
 * that already AVERAGES the ones it covers. Nearest-neighbour sampling of the
 * original drops 9 pixels in 10 at a 10% fit — thin lines vanish and edges
 * shimmer, which is exactly what a downscaled graph or screenshot is made of.
 *
 * Above 1:1 it deliberately does nothing: a magnified image should stay crisp
 * (the PhpStorm contract — at 400% you want pixels, not blur), and prescaling
 * upward would allocate an 8× canvas to gain nothing.
 *
 * The resample runs in C once per scale, not per cell per frame: PHP averaging
 * would cost ~2M `imagecolorat` calls to paint one 200×48 pane. The memo is
 * keyed on the image IDENTITY as well as the scale, so a new tab cannot be shown
 * the previous image's downscaled copy — which is why there is no reset() to
 * forget to call. One pane holds one of these (internally newed, the
 * {@see \Porta\Editor\PositionMapper} pattern), so the memoised copy follows the
 * pane's own zoom.
 *
 * ## Why it resamples in stages
 *
 * `imagecopyresampled` costs what its SOURCE costs to read, not what its target
 * costs to write: shrinking a 2173×3147 graph bitmap to 77×112 and shrinking it
 * to 616×896 both take ~31ms, because both read all 6.8M pixels. A ZOOM is a new
 * scale on every keystroke, so the memo misses every frame and that 31ms lands
 * on each one — measured at 48ms per rendered frame while holding `=`, against
 * 7ms for a pan at a settled scale. Held down, it is what .porta/freeze.log
 * recorded as a stalled loop.
 *
 * So the copy is taken from the coarsest cached HALVING of the image that still
 * holds at least {@see MIN_OVERSAMPLE} times the target in both axes, and the
 * halvings are built (lazily, from each other) once per image rather than once
 * per scale. The second stage then reads a few hundred thousand pixels instead
 * of millions: 31ms becomes 3ms, and a zoom frame costs what a pan frame costs.
 *
 * Averaging twice is not averaging once, and two rules keep the difference
 * invisible. The oversample floor: the second stage must always be shrinking by
 * at least 2×, never magnifying a coarse level back up, which would genuinely
 * lose detail. And the pixel budget: stop halving once a level is already cheap
 * to read, because every further halving quantises a dimension for no gain —
 * without it a 97×5011 image drifts 7.7/255 from a single-stage resample, and
 * with it the worst of 54 shape/scale pairs is 1.7.
 *
 * The levels cost a third of the image on top of it (¼ + ¹⁄₁₆ + …) and are
 * dropped with it, so the class still holds one image's worth of pixels and the
 * "no cache per tab" rule the viewer is built on is untouched.
 */
final class ScaledSource
{
    /**
     * How much bigger than the target a level must be to be sampled from. Two
     * is the smallest honest value: at 2× every target pixel averages a 2×2
     * block, and below it the "average" would be reading some pixels twice.
     */
    private const int MIN_OVERSAMPLE = 2;

    /**
     * How big a level may be and still be worth sampling directly.
     *
     * The chain exists to bound the pixels READ, so this bounds them directly:
     * once a level is down to this, resampling from it already costs ~2.5ms and
     * halving again buys nothing — while each halving TAKES something, because
     * an integer halving of a dimension that is already small quantises and
     * truncates. Measured over 54 shape/scale pairs against a single-stage
     * resample: unbounded the worst case is 7.7/255 (a 97×5011 image, whose
     * narrow axis gets halved to nothing), and at this budget it is 1.7. Above
     * it the curve is flat — 1M and 1.6M give the identical level.
     */
    private const int LEVEL_PIXELS = 600_000;

    private ?ImageData $source = null;
    private float $scale = 0.0;
    private ?SampleSource $memo = null;

    /** @var list<ImageData> successive halvings of $source, coarsest last. */
    private array $levels = [];

    /**
     * The pixels to sample for a pane showing $image at $scale.
     *
     * $ratio is how many of $image's pixels already cover one unit of whatever
     * $scale is measured in — 1.0 for an ordinary image, and dpi/72 for the
     * graph viewer's oversampled bitmap, whose pixels are denser than the layout
     * points its zoom is expressed in. The work below is done in the image's own
     * grid and converted back on the way out.
     */
    public function forScale(ImageData $image, float $scale, float $ratio = 1.0): SampleSource
    {
        $relative = $scale / $ratio;
        if ($relative >= (1.0 - 1e-9)) {
            return new SampleSource($image, $ratio);
        }
        if (null !== $this->memo && $this->source === $image && \abs($this->scale - $relative) < 1e-9) {
            return $this->memo;
        }
        if ($this->source !== $image) {
            $this->levels = [];
        }

        // Sized off the ORIGINAL either way, so which level the pixels came
        // from cannot move the copy's dimensions or the ratio reported for it.
        $width = \max(1, (int) \round($image->width * $relative));
        $height = \max(1, (int) \round($image->height * $relative));
        $from = $this->levelFor($image, $width, $height);

        $this->source = $image;
        $this->scale = $relative;
        $this->memo = new SampleSource(
            self::resample($from, $width, $height, $image->byteSize),
            // Back into the caller's units: the copy's own shrink, times what
            // the source was already denser by.
            ratio: ($width / $image->width) * $ratio,
        );

        return $this->memo;
    }

    /**
     * The coarsest halving worth sampling $width × $height from, building the
     * missing ones on the way down. Cached levels are walked first because a
     * zoom that keeps landing in one band must not rebuild anything.
     */
    private function levelFor(ImageData $image, int $width, int $height): ImageData
    {
        $level = $image;
        foreach ($this->levels as $cached) {
            if (!self::worthHalving($level, $width, $height)) {
                break;
            }
            $level = $cached;
        }
        while (self::worthHalving($level, $width, $height)) {
            $level = self::resample(
                $level,
                \max(1, \intdiv($level->width, num2: 2)),
                \max(1, \intdiv($level->height, num2: 2)),
                $image->byteSize,
            );
            $this->levels[] = $level;
        }

        return $level;
    }

    /** Is $level both big enough to be worth halving, and still oversampling a $width × $height target afterwards? */
    private static function worthHalving(ImageData $level, int $width, int $height): bool
    {
        return ($level->width * $level->height) > self::LEVEL_PIXELS && $level->width >= (2 * self::MIN_OVERSAMPLE * $width) && $level->height >= (2 * self::MIN_OVERSAMPLE * $height);
    }

    /** One area-averaged copy of $from at exactly $width × $height. */
    private static function resample(ImageData $from, int $width, int $height, int $byteSize): ImageData
    {
        $canvas = \imagecreatetruecolor($width, $height);
        // Alpha survives the resample so the rasterizer's checkerboard blend
        // still sees real transparency: blending OFF makes the copy replace
        // rather than composite, and savealpha keeps the channel.
        \imagealphablending($canvas, enable: false);
        \imagesavealpha($canvas, enable: true);
        \imagecopyresampled(
            $canvas,
            $from->gd,
            dst_x: 0,
            dst_y: 0,
            src_x: 0,
            src_y: 0,
            dst_width: $width,
            dst_height: $height,
            src_width: $from->width,
            src_height: $from->height,
        );

        return new ImageData($canvas, $width, $height, $byteSize);
    }
}
