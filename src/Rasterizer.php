<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/**
 * Turns SVG source into PNG bytes asynchronously — the seam the image viewer
 * depends on, so tests (and any future in-process renderer) substitute a fake
 * without a converter binary anywhere near the suite. The
 * {@see \Porta\Graph\LayoutEngine} of the other vector format.
 */
interface Rasterizer
{
    /** The resolved converter path, or null when none is installed. */
    public function binary(): ?string;

    /**
     * Rasterize $svg at $scale (1.0 = the document's own size); exactly one
     * callback fires — unless a newer rasterize() supersedes this one first
     * (latest wins, the superseded call goes silent).
     *
     * A null $scale also renders at the document's own size, and is how the
     * viewer LEARNS that size: asking the converter beats parsing
     * `width`/`height`/`viewBox` by hand, because units, percentages and the
     * spec's defaults are then its problem and not ours.
     *
     * @param \Closure(string): void $onPng   the PNG bytes
     * @param \Closure(string): void $onError the converter's stderr (or why it could not run)
     */
    public function rasterize(string $svg, ?float $scale, \Closure $onPng, \Closure $onError): void;
}
