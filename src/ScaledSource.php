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
 */
final class ScaledSource
{
    private ?ImageData $source = null;
    private float $scale = 0.0;
    private ?SampleSource $memo = null;

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
        if (null !== $this->memo && $this->source === $image && abs($this->scale - $relative) < 1e-9) {
            return $this->memo;
        }

        $this->source = $image;
        $this->scale = $relative;
        $this->memo = self::resample($image, $relative, $ratio);

        return $this->memo;
    }

    /** One area-averaged copy at $scale of $image (never smaller than a single pixel). */
    private static function resample(ImageData $image, float $scale, float $ratio): SampleSource
    {
        $width = max(1, (int) round($image->width * $scale));
        $height = max(1, (int) round($image->height * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        // Alpha survives the resample so the rasterizer's checkerboard blend
        // still sees real transparency: blending OFF makes the copy replace
        // rather than composite, and savealpha keeps the channel.
        imagealphablending($canvas, enable: false);
        imagesavealpha($canvas, enable: true);
        imagecopyresampled(
            $canvas,
            $image->gd,
            dst_x: 0,
            dst_y: 0,
            src_x: 0,
            src_y: 0,
            dst_width: $width,
            dst_height: $height,
            src_width: $image->width,
            src_height: $image->height,
        );

        return new SampleSource(
            new ImageData($canvas, $width, $height, $image->byteSize),
            // Back into the caller's units: the copy's own shrink, times what
            // the source was already denser by.
            ratio: ($width / $image->width) * $ratio,
        );
    }
}
