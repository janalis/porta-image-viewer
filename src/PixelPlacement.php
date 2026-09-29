<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\Ui\Graphics\KittyPlaceholders;
use Porta\Ui\Graphics\TerminalGraphics;

/**
 * One pane's picture drawn by the terminal itself, at its own pixel density,
 * when {@see TerminalGraphics} says it can — the half-block rasterizer's
 * sibling, with the same contract: exactly $rows lines of exactly $cols
 * columns, the letterbox as plain spaces.
 *
 * The geometry is {@see ZoomPanModel}'s, unchanged: the same visible rectangle
 * the half-blocks would sample, rounded out to whole cells. Keeping the ONE
 * geometry is what keeps the graph viewer's selection box and click hit-test on
 * top of the node they name, since both are computed on the half-block grid.
 *
 * ## What is sent: the view, not the picture
 *
 * The protocol could take the whole picture once and show a crop of it — a
 * placement has a source rectangle — but Ghostty draws the whole image into a
 * placeholder placement whatever the rectangle says, so a zoom there changed
 * the status bar and nothing on screen. So the visible part is cut out HERE,
 * scaled to the cells' own pixels, and sent as the image, on every change of
 * view. What crosses the pty is then one pane's worth of pixels, capped at
 * {@see BUDGET}, whatever the size of the document.
 *
 * Two image ids take turns. The cells name their image in their colour, so a
 * new view is new TEXT — which the frame writer has to rewrite, and the
 * terminal has to redraw — and the previous view stays up, under its own id,
 * until the frame naming the new one lands. Neither is ever deleted mid-flight;
 * the next change of view simply replaces the older one.
 */
final class PixelPlacement
{
    /**
     * The most pixels one view is sent at. A pane on a high-density screen is
     * several million pixels, and the encode runs on the event loop while the
     * transfer is written in one piece — so past this the view is sent smaller
     * and the terminal scales it the rest of the way.
     */
    public const int BUDGET = 1_000_000;

    /** @var array{0: ?int, 1: ?int} the two ids the views alternate between */
    private array $ids = [null, null];

    /**
     * Which of the two ids the painted cells name.
     *
     * @var 0|1
     */
    private int $front = 0;

    /** What the front id shows: the view it was cut from; '' when nothing is. */
    private string $shown = '';

    /** @var \WeakReference<ImageData>|null the image $shown was cut from — compared by identity, never kept alive */
    private ?\WeakReference $source = null;

    /** Memoises the area-averaged copy a shrinking view is cut from. */
    private readonly ScaledSource $scaled;

    /** @param \Closure(): void $invalidate the pane's, called when the terminal's answer changes what it paints */
    public function __construct(
        private readonly TerminalGraphics $graphics,
        \Closure $invalidate,
    ) {
        $graphics->onChange($invalidate);
        $this->scaled = new ScaledSource();
    }

    /**
     * Screen pixels one pane column spans: a cell's width when the terminal
     * draws the picture, 1 while half-blocks do — what a sharp re-render has to
     * cover per column, and what a zoom percentage is counted in.
     */
    public function pixelsPerColumn(): int
    {
        return $this->graphics->available() ? $this->graphics->cellPixels()[0] : 1;
    }

    /**
     * The pane's lines, or null when the view cannot be placed (the terminal has
     * no pixels, or the pane is wider or taller than a placement can address) —
     * the caller then paints half-blocks.
     *
     * @param float $ratio $image's pixels per document pixel (1.0, or a re-render's density)
     *
     * @return list<string>|null
     */
    public function render(ImageData $image, float $ratio, ZoomPanModel $view, int $cols, int $rows): ?array
    {
        if (!$this->graphics->available()) {
            return null;
        }
        $rect = $view->visibleRect($cols, 2 * $rows);
        $firstRow = \intdiv($rect['padHalfRows'], num2: 2);
        $placedRows = \intdiv($rect['padHalfRows'] + $rect['outHalfRows'] + 1, num2: 2) - $firstRow;
        if ($rect['outCols'] > KittyPlaceholders::MAX_CELLS || $placedRows > KittyPlaceholders::MAX_CELLS) {
            return null;
        }

        // The view, in document pixels, of exactly the cells being covered: a
        // first row that starts on the lower half of a cell reaches half a row
        // above the visible rect, clamped back onto the picture.
        $scale = $view->effectiveScale($cols, 2 * $rows);
        [$docWidth, $docHeight] = $view->dimensions();
        $left = (float) $rect['srcX'];
        $top = \max(0.0, $rect['srcY'] + (((2 * $firstRow) - $rect['padHalfRows']) / $scale));
        $right = \min((float) $docWidth, $left + ($rect['outCols'] / $scale));
        $bottom = \min((float) $docHeight, $top + ((2 * $placedRows) / $scale));

        // The cells' own pixels, within the budget.
        [$cellWidth, $cellHeight] = $this->graphics->cellPixels();
        $width = $rect['outCols'] * $cellWidth;
        $height = $placedRows * $cellHeight;
        $fit = \min(1.0, \sqrt(self::BUDGET / ($width * $height)));
        $width = \max(1, (int) \floor($width * $fit));
        $height = \max(1, (int) \floor($height * $fit));

        $shown = \sprintf('%.4F:%.4F:%.4F:%.4F:%.6F:%d:%d:%d:%d', $left, $top, $right, $bottom, $ratio, $width, $height, $rect['outCols'], $placedRows);
        if ($shown !== $this->shown || $this->source?->get() !== $image) {
            $this->front = 0 === $this->front ? 1 : 0;
            $this->ids[$this->front] ??= $this->graphics->allocateId();
            $this->graphics->transmit((int) $this->ids[$this->front], $this->cut($image, $ratio, [$left, $top, $right, $bottom], $width, $height));
            $this->shown = $shown;
            $this->source = \WeakReference::create($image);
        }
        $id = (int) $this->ids[$this->front];
        $this->graphics->place($id, $rect['outCols'], $placedRows);

        $blank = \str_repeat(' ', \max(0, $cols));
        $before = \str_repeat(' ', \max(0, $rect['padCols']));
        $after = \str_repeat(' ', \max(0, $cols - $rect['padCols'] - $rect['outCols']));
        $lines = [];
        for ($row = 0; $row < $rows; ++$row) {
            $placementRow = $row - $firstRow;
            $inside = $placementRow >= 0 && $placementRow < $placedRows;
            $lines[] = $inside ? $before.KittyPlaceholders::row($id, $placementRow, 0, $rect['outCols']).$after : $blank;
        }

        return $lines;
    }

    /** The pane let go of its picture: free the terminal's copies. */
    public function forget(): void
    {
        foreach ($this->ids as $id) {
            if (null === $id) {
                continue;
            }
            $this->graphics->delete($id);
        }
        $this->shown = '';
        $this->source = null;
    }

    /**
     * The document rectangle $view ([left, top, right, bottom]) of $image as a
     * $width × $height PNG. Shrinking reads an area-averaged copy near the
     * target scale ({@see ScaledSource}, which also keeps a zoom from re-reading
     * a whole big image); magnifying stays crisp — nearest neighbour, the
     * half-blocks' contract: at 400% you want the pixels, not a blur.
     *
     * @param array{float, float, float, float} $view
     */
    private function cut(ImageData $image, float $ratio, array $view, int $width, int $height): string
    {
        [$left, $top, $right, $bottom] = $view;
        $source = $this->scaled->forScale($image, $width / \max(1e-9, $right - $left), $ratio);
        $canvas = \imagecreatetruecolor($width, $height);
        // Transparency is the terminal's to show over its own background, so
        // the alpha channel has to survive into the file.
        \imagealphablending($canvas, enable: false);
        \imagesavealpha($canvas, enable: true);
        $sourceX = (int) \round($left * $source->ratio);
        $sourceY = (int) \round($top * $source->ratio);
        $sourceWidth = \max(1, (int) \round(($right - $left) * $source->ratio));
        $sourceHeight = \max(1, (int) \round(($bottom - $top) * $source->ratio));
        $copy = $width > $sourceWidth ? \imagecopyresized(...) : \imagecopyresampled(...);
        $copy($canvas, $source->image->gd, dst_x: 0, dst_y: 0, src_x: $sourceX, src_y: $sourceY, dst_width: $width, dst_height: $height, src_width: $sourceWidth, src_height: $sourceHeight);
        \ob_start();
        // Fastest compression: the bytes cross a local pty, and the encode runs
        // on the event loop.
        \imagepng($canvas, quality: 1);

        return (string) \ob_get_clean();
    }
}
