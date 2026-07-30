<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/** A decoded image: the GD handle plus the facts the viewer and status bar show. */
final readonly class ImageData
{
    public function __construct(
        public \GdImage $gd,
        public int $width,
        public int $height,
        public int $byteSize,
    ) {}
}
