<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/**
 * The pixels {@see HalfBlockRasterizer} should read, plus how they relate to the
 * original image: `ratio` is 1.0 for the image itself and ≈ the zoom scale for a
 * pre-downscaled copy, so the rasterizer can map a source coordinate either way
 * with one multiplication.
 */
final readonly class SampleSource
{
    public function __construct(
        public ImageData $image,
        public float $ratio,
    ) {}

    /** The image's own pixels, sampled 1:1 — what upscaling and 100% zoom use. */
    public static function original(ImageData $image): self
    {
        return new self($image, ratio: 1.0);
    }
}
