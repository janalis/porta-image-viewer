<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/**
 * A rectangle to stroke into a picture the terminal draws: a selection cue
 * placed to the screen pixel. Framing with text glyphs cannot be that exact,
 * since they land on whole cells, and they take the cells they cover away
 * from the picture.
 *
 * In document pixels, the unit {@see ZoomPanModel} works in, so it follows
 * every zoom and pan with the picture under it.
 */
final readonly class PixelOutline
{
    /** @param array{int, int, int} $rgb */
    public function __construct(
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        public array $rgb,
    ) {}

    /** What tells one outline from another for the placement's re-send check. */
    public function key(): string
    {
        return \sprintf('%.4F:%.4F:%.4F:%.4F:%s', $this->x0, $this->y0, $this->x1, $this->y1, \implode(',', $this->rgb));
    }
}
