<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/**
 * The viewer's zoom/pan state, in image pixels. The viewport is measured in
 * terminal columns × half-rows (one character cell shows 2 vertical pixels via
 * `▀`, so 1 px ≈ 1 column ≈ 1 half-row reads roughly square). `scale` null
 * means fit-to-pane; explicit zoom walks the PhpStorm-style step ladder,
 * keeping the image point at the viewport's center fixed.
 */
final class ZoomPanModel
{
    /** @var list<float> the discrete zoom ladder (12.5% … 800%) */
    public const array STEPS = [0.125, 0.25, 0.5, 1.0, 2.0, 4.0, 8.0];

    /** Explicit zoom factor, or null for fit-to-pane. */
    private ?float $scale = null;
    private int $panX = 0;
    private int $panY = 0;

    /** @param ?list<float> $steps a custom zoom ladder — the graph viewer's fit scales sit far below the image ladder's 12.5% floor */
    public function __construct(
        private readonly int $imgW,
        private readonly int $imgH,
        private readonly ?array $steps = null,
    ) {}

    /**
     * The document's own size — what the status bar reports and what every
     * coordinate here is expressed in.
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(): array
    {
        return [$this->imgW, $this->imgH];
    }

    /** The factor actually applied for a pane of $cols × $pxRows (fit resolves here). */
    public function effectiveScale(int $cols, int $pxRows): float
    {
        return $this->scale ?? min($cols / $this->imgW, $pxRows / $this->imgH);
    }

    public function zoomIn(int $cols, int $pxRows): void
    {
        $new = $this->step($this->effectiveScale($cols, $pxRows), 1);
        if (null !== $new) {
            $this->setScale($new, $cols, $pxRows);
        }
    }

    public function zoomOut(int $cols, int $pxRows): void
    {
        $new = $this->step($this->effectiveScale($cols, $pxRows), -1);
        if (null !== $new) {
            $this->setScale($new, $cols, $pxRows);
        }
    }

    /**
     * Zoom one ladder step keeping the image point at ($anchorCol, $anchorHalfRow)
     * fixed on screen (Ctrl+wheel: zoom toward the pointer). An anchor over the
     * letterbox clamps to the nearest image edge.
     */
    public function zoomAt(int $dir, int $anchorCol, int $anchorHalfRow, int $cols, int $pxRows): void
    {
        $new = $this->step($this->effectiveScale($cols, $pxRows), $dir);
        if (null !== $new) {
            $this->applyAt($new, $anchorCol, $anchorHalfRow, $cols, $pxRows);
        }
    }

    /**
     * Zoom by an arbitrary FACTOR, off the ladder, keeping the same anchor fixed
     * — the continuous half of the zoom, for a gesture that arrives as a burst
     * of small increments (a trackpad's Ctrl+scroll). Clamped to the ladder's own
     * ends, so a ladder still defines how far a viewer may zoom even when the
     * rungs are bypassed; a factor that lands outside leaves the view untouched.
     */
    public function magnifyAt(float $factor, int $anchorCol, int $anchorHalfRow, int $cols, int $pxRows): void
    {
        $ladder = $this->steps ?? self::STEPS;
        $old = $this->effectiveScale($cols, $pxRows);
        $new = max($ladder[0], min($old * $factor, $ladder[\count($ladder) - 1]));
        if (abs($new - $old) > 1e-9) {
            $this->applyAt($new, $anchorCol, $anchorHalfRow, $cols, $pxRows);
        }
    }

    /**
     * Adopt $new, re-panning so the image point under ($anchorCol,
     * $anchorHalfRow) stays under it. An anchor over the letterbox clamps to the
     * nearest image edge.
     */
    private function applyAt(float $new, int $anchorCol, int $anchorHalfRow, int $cols, int $pxRows): void
    {
        $old = $this->effectiveScale($cols, $pxRows);
        $rect = $this->visibleRect($cols, $pxRows);
        $pX = max(0.0, min((float) $this->imgW, $rect['srcX'] + (($anchorCol - $rect['padCols']) / $old)));
        $pY = max(0.0, min((float) $this->imgH, $rect['srcY'] + (($anchorHalfRow - $rect['padHalfRows']) / $old)));

        $this->scale = $new;
        $pads = $this->visibleRect($cols, $pxRows); // pads at the new scale (pan-independent)
        $this->panX = $this->clampPan((int) round($pX - (($anchorCol - $pads['padCols']) / $new)), $this->imgW, $cols / $new);
        $this->panY = $this->clampPan((int) round($pY - (($anchorHalfRow - $pads['padHalfRows']) / $new)), $this->imgH, $pxRows / $new);
    }

    public function fit(): void
    {
        $this->scale = null;
        $this->panX = 0;
        $this->panY = 0;
    }

    public function actualSize(int $cols, int $pxRows): void
    {
        $this->setScale(1.0, $cols, $pxRows);
    }

    /**
     * Shift the viewport by whole cells/half-rows, clamped to the image. At high
     * zoom a small request can round to 0 image px; move at least 1 px so the
     * arrows never go dead.
     */
    public function panBy(int $dxCells, int $dyHalfRows, int $cols, int $pxRows): void
    {
        $scale = $this->effectiveScale($cols, $pxRows);
        $dxPx = (int) round($dxCells / $scale);
        if (0 === $dxPx && 0 !== $dxCells) {
            $dxPx = $dxCells <=> 0;
        }
        $dyPx = (int) round($dyHalfRows / $scale);
        if (0 === $dyPx && 0 !== $dyHalfRows) {
            $dyPx = $dyHalfRows <=> 0;
        }
        $this->panX = $this->clampPan($this->panX + $dxPx, $this->imgW, $cols / $scale);
        $this->panY = $this->clampPan($this->panY + $dyPx, $this->imgH, $pxRows / $scale);
    }

    /**
     * Where the image lands in the pane: the source offset in image pixels and
     * the centered destination span in columns / half-rows.
     *
     * @return array{srcX: int, srcY: int, padCols: int, padHalfRows: int, outCols: int, outHalfRows: int}
     */
    public function visibleRect(int $cols, int $pxRows): array
    {
        $scale = $this->effectiveScale($cols, $pxRows);
        $outCols = min($cols, (int) round($this->imgW * $scale));
        $outHalfRows = min($pxRows, (int) round($this->imgH * $scale));

        return [
            'srcX' => $this->clampPan($this->panX, $this->imgW, $cols / $scale),
            'srcY' => $this->clampPan($this->panY, $this->imgH, $pxRows / $scale),
            'padCols' => intdiv($cols - $outCols, num2: 2),
            'padHalfRows' => intdiv($pxRows - $outHalfRows, num2: 2),
            'outCols' => max(1, $outCols),
            'outHalfRows' => max(1, $outHalfRows),
        ];
    }

    /** '200%', or 'Fit (37%)' while fitting. The label is numeric — no translation. */
    public function zoomLabel(int $cols, int $pxRows): string
    {
        $percent = (int) round($this->effectiveScale($cols, $pxRows) * 100);

        return null === $this->scale ? \sprintf('Fit (%d%%)', $percent) : \sprintf('%d%%', $percent);
    }

    /** The next ladder rung in $dir, or null when none exists in that direction. */
    private function step(float $current, int $dir): ?float
    {
        $ladder = $this->steps ?? self::STEPS;
        $steps = $dir > 0 ? $ladder : array_reverse($ladder);
        foreach ($steps as $step) {
            if ($dir > 0 ? $step > ($current + 1e-9) : $step < ($current - 1e-9)) {
                return $step;
            }
        }

        return null; // already past the ladder's end in that direction
    }

    /** Re-anchor so the image point at the viewport center stays centered. */
    private function setScale(float $new, int $cols, int $pxRows): void
    {
        $old = $this->effectiveScale($cols, $pxRows);
        $centerX = $this->panX + (min($this->imgW, $cols / $old) / 2);
        $centerY = $this->panY + (min($this->imgH, $pxRows / $old) / 2);
        $this->scale = $new;
        $this->panX = $this->clampPan((int) round($centerX - (($cols / $new) / 2)), $this->imgW, $cols / $new);
        $this->panY = $this->clampPan((int) round($centerY - (($pxRows / $new) / 2)), $this->imgH, $pxRows / $new);
    }

    private function clampPan(int $pan, int $imgDim, float $viewDim): int
    {
        return max(0, min($pan, (int) ceil(max(0, $imgDim - $viewDim))));
    }
}
