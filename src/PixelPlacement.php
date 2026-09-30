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

    /** An outline's stroke, and how far outside its rectangle it runs, in the sent picture's pixels. */
    private const int OUTLINE_WIDTH = 2;
    private const int OUTLINE_GAP = 3;

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
     * @param ?PixelOutline $outline a rectangle to stroke over the picture (a selection), in document pixels
     *
     * @return list<string>|null
     */
    public function render(ImageData $image, float $ratio, ZoomPanModel $view, int $cols, int $rows, ?PixelOutline $outline = null): ?array
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
        // above the visible rect, and a rounded span may reach past the
        // picture's edge. Neither is clamped here: the cut leaves what lies off
        // the picture empty, where stretching the picture over it moved every
        // point off the cell a click on it maps to.
        $scale = $view->effectiveScale($cols, 2 * $rows);
        $left = (float) $rect['srcX'];
        $top = $rect['srcY'] + (((2 * $firstRow) - $rect['padHalfRows']) / $scale);
        $right = $left + ($rect['outCols'] / $scale);
        $bottom = $top + ((2 * $placedRows) / $scale);

        // The cells' own pixels, within the budget.
        [$cellWidth, $cellHeight] = $this->graphics->cellPixels();
        $width = $rect['outCols'] * $cellWidth;
        $height = $placedRows * $cellHeight;
        $fit = \min(1.0, \sqrt(self::BUDGET / ($width * $height)));
        $width = \max(1, (int) \floor($width * $fit));
        $height = \max(1, (int) \floor($height * $fit));

        $shown = \sprintf('%.4F:%.4F:%.4F:%.4F:%.6F:%d:%d:%d:%d:%s', $left, $top, $right, $bottom, $ratio, $width, $height, $rect['outCols'], $placedRows, $outline?->key() ?? '');
        if ($shown !== $this->shown || $this->source?->get() !== $image) {
            $this->front = 0 === $this->front ? 1 : 0;
            $this->ids[$this->front] ??= $this->graphics->allocateId();
            $this->graphics->transmit((int) $this->ids[$this->front], $this->cut($image, $ratio, $view->dimensions(), [$left, $top, $right, $bottom], $width, $height, $outline));
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
     * Whatever of $view lies off the $document's edges stays transparent, and
     * $outline, if any, is stroked just outside its rectangle — over the
     * picture, not over what it frames.
     *
     * @param array{0: int, 1: int} $document the picture's size in document pixels
     * @param array{float, float, float, float} $view
     */
    private function cut(ImageData $image, float $ratio, array $document, array $view, int $width, int $height, ?PixelOutline $outline): string
    {
        [$left, $top, $right, $bottom] = $view;
        $perX = $width / \max(1e-9, $right - $left);
        $perY = $height / \max(1e-9, $bottom - $top);
        $source = $this->scaled->forScale($image, $perX, $ratio);
        $canvas = \imagecreatetruecolor($width, $height);
        // Transparency is the terminal's to show over its own background, so
        // the alpha channel has to survive into the file.
        \imagealphablending($canvas, enable: false);
        \imagesavealpha($canvas, enable: true);
        \imagefill($canvas, x: 0, y: 0, color: (int) \imagecolorallocatealpha($canvas, red: 0, green: 0, blue: 0, alpha: 127));
        // The part of the view the picture covers, and where it lands.
        $x0 = \max(0.0, $left);
        $y0 = \max(0.0, $top);
        $x1 = \min((float) $document[0], $right);
        $y1 = \min((float) $document[1], $bottom);
        $destX = (int) \round(($x0 - $left) * $perX);
        $destY = (int) \round(($y0 - $top) * $perY);
        $destWidth = \max(1, (int) \round(($x1 - $left) * $perX) - $destX);
        $destHeight = \max(1, (int) \round(($y1 - $top) * $perY) - $destY);
        $sourceWidth = \max(1, (int) \round(($x1 - $x0) * $source->ratio));
        $sourceHeight = \max(1, (int) \round(($y1 - $y0) * $source->ratio));
        $copy = $destWidth > $sourceWidth ? \imagecopyresized(...) : \imagecopyresampled(...);
        $copy(
            $canvas,
            $source->image->gd,
            dst_x: $destX,
            dst_y: $destY,
            src_x: (int) \round($x0 * $source->ratio),
            src_y: (int) \round($y0 * $source->ratio),
            dst_width: $destWidth,
            dst_height: $destHeight,
            src_width: $sourceWidth,
            src_height: $sourceHeight,
        );
        if (null !== $outline) {
            [$red, $green, $blue] = $outline->rgb;
            \imagesetthickness($canvas, self::OUTLINE_WIDTH);
            \imagerectangle(
                $canvas,
                (int) \round((($outline->x0 - $left) * $perX) - self::OUTLINE_GAP),
                (int) \round((($outline->y0 - $top) * $perY) - self::OUTLINE_GAP),
                (int) \round((($outline->x1 - $left) * $perX) + self::OUTLINE_GAP),
                (int) \round((($outline->y1 - $top) * $perY) + self::OUTLINE_GAP),
                (int) \imagecolorallocatealpha($canvas, $red, $green, $blue, alpha: 0),
            );
        }
        \ob_start();
        // Fastest compression: the bytes cross a local pty, and the encode runs
        // on the event loop.
        \imagepng($canvas, quality: 1);

        return (string) \ob_get_clean();
    }
}
