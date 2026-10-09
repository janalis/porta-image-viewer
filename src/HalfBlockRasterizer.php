<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\Ui\Sgr;

/**
 * Rasterizes an image into terminal lines: each `▀` cell shows 2 vertical
 * pixels (24-bit foreground = top, background = bottom). Transparency is
 * blended onto a fixed gray checkerboard. SGR codes are emitted only when the
 * color pair changes, and letterbox cells are plain spaces, so a line costs far
 * less than 2 escapes per cell.
 *
 * Sampling is nearest-neighbour over whatever {@see SampleSource} hands it —
 * which is the image itself when magnifying, and a GD-resampled copy when
 * shrinking, so a downscale is area-averaged without this loop changing shape
 * (see {@see ScaledSource} for why the averaging cannot happen here).
 */
final class HalfBlockRasterizer
{
    /** The dark filler for the outside half of a cell that straddles the image edge. */
    private const array VOID = [30, 30, 30];

    /** @return list<string> exactly $rows lines of exactly $cols display columns */
    public static function render(SampleSource $source, ZoomPanModel $view, int $cols, int $rows): array
    {
        $image = $source->image;
        $pxRows = $rows * 2;
        $rect = $view->visibleRect($cols, $pxRows);
        // The view speaks in ORIGINAL image pixels; a prescaled source has its
        // own, smaller grid. Converting the two source-space fields (and the
        // scale) here keeps sample() below in one coordinate space, and both
        // conversions are identities at ratio 1.0.
        $scale = $view->effectiveScale($cols, $pxRows) / $source->ratio;
        $rect['srcX'] = (int) \round($rect['srcX'] * $source->ratio);
        $rect['srcY'] = (int) \round($rect['srcY'] * $source->ratio);

        $lines = [];
        for ($row = 0; $row < $rows; ++$row) {
            $line = '';
            $current = null;
            for ($col = 0; $col < $cols; ++$col) {
                $top = self::sample($image, $rect, $scale, $col, 2 * $row);
                $bottom = self::sample($image, $rect, $scale, $col, (2 * $row) + 1);
                if (null === $top && null === $bottom) {
                    if (null !== $current) {
                        $line .= Sgr::RESET;
                        $current = null;
                    }
                    $line .= ' ';

                    continue;
                }
                [$tr, $tg, $tb] = $top ?? self::VOID;
                [$br, $bg, $bb] = $bottom ?? self::VOID;
                $sgr = "\x1b[38;2;{$tr};{$tg};{$tb};48;2;{$br};{$bg};{$bb}m";
                if ($sgr !== $current) {
                    $line .= $sgr;
                    $current = $sgr;
                }
                $line .= '▀';
            }
            if (null !== $current) {
                $line .= Sgr::RESET;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The RGB shown at one pane half-row/column, alpha pre-blended onto the
     * checkerboard — or null when the position lies outside the image. Its
     * coordinates are the SOURCE's (see the conversion in render()), which is
     * also why the checkerboard squares stay 8 screen pixels rather than
     * shrinking with the zoom.
     *
     * @param array{srcX: int, srcY: int, padCols: int, padHalfRows: int, outCols: int, outHalfRows: int} $rect
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function sample(ImageData $image, array $rect, float $scale, int $col, int $halfRow): ?array
    {
        $dx = $col - $rect['padCols'];
        $dy = $halfRow - $rect['padHalfRows'];
        if ($dx < 0 || $dx >= $rect['outCols'] || $dy < 0 || $dy >= $rect['outHalfRows']) {
            return null;
        }
        $x = \min($image->width - 1, $rect['srcX'] + (int) \floor($dx / $scale));
        $y = \min($image->height - 1, $rect['srcY'] + (int) \floor($dy / $scale));

        $pixel = (int) \imagecolorat($image->gd, $x, $y); // coords are clamped; false cannot occur
        $alpha = ($pixel >> 24) & 0x7F; // GD: 0 = opaque … 127 = fully transparent
        $rgb = [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];

        return 0 === $alpha ? $rgb : self::overChecker($rgb, $alpha, $x, $y);
    }

    /**
     * A translucent pixel blended over the checkerboard square at source $x, $y.
     *
     * @param array{int, int, int} $rgb
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function overChecker(array $rgb, int $alpha, int $x, int $y): array
    {
        $checker = 0 === ((($x >> 3) + ($y >> 3)) & 1) ? 90 : 140;
        $opacity = 1 - ($alpha / 127);

        return \array_map(static fn (int $channel): int => (int) \round(($channel * $opacity) + ($checker * (1 - $opacity))), $rgb);
    }
}
