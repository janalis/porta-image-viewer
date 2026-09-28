<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\Support\AnsiText;
use Porta\Support\Text;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Widget\NavigablePaneWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The zoom-pan chrome the image and graph panes share: one {@see ZoomPanModel}
 * driven view with its pointer and key gestures, the focus frame, the
 * status-bar segment and the blank-pane message fallback. The two panes had
 * forked — eleven byte-identical methods, drifting apart one fix at a time.
 *
 * What stays each widget's own is what it SHOWS and how it refines it: the
 * show/clear pipeline, contentLines' happy path, the keymap (its action ids
 * are user-visible rebinding names), and {@see scheduleRefine()} — whichever
 * sharper render (raster, bitmap) that pane knows how to ask for once a
 * gesture lands. The gesture tail itself (repaint, handler notify) is shared.
 *
 * @require-extends NavigablePaneWidget
 */
trait ZoomPanPane
{
    /** How long a zoom has to settle before a sharper render is asked for. */
    private const float RENDER_DEBOUNCE = 0.15;

    /** The shown document's zoom/pan state; null while nothing is shown. */
    private ?ZoomPanModel $view = null;
    /** last rendered pane size, so key handling scales/pans what is on screen */
    private int $lastCols = 80;
    private int $lastRows = 24;

    /** Every gesture ends here: repaint, tell the handler, refine the render. */
    protected function afterViewChange(): void
    {
        $this->invalidate();
        $this->handler?->viewChanged();
        $this->scheduleRefine();
    }

    /** Schedule whichever sharper render this pane knows how to ask for, once the gesture settles. */
    abstract protected function scheduleRefine(): void;

    /** The status bar's document summary, '' when nothing is shown. */
    abstract public function statusInfo(): string;

    /** The translated key hints of this pane's status segment. */
    abstract protected function keysHint(): string;

    /** The status bar's zoom text ('Fit (37%)' / '200%'), '' when nothing is shown. */
    public function zoomLabel(): string
    {
        return null === $this->view ? '' : $this->view->zoomLabel($this->lastCols, 2 * $this->lastRows);
    }

    /**
     * The status bar's right segment while this pane holds the slot: the
     * document's summary, the zoom, and the keys — the pane knows what its
     * keys do and the status bar does not.
     */
    public function statusRight(): string
    {
        return \trim(\sprintf('%s  %s  %s', $this->statusInfo(), $this->zoomLabel(), $this->keysHint()));
    }

    /** Ctrl+wheel: zoom one step anchored on the pane cell under the pointer. */
    public function zoomAt(int $dir, int $relCol, int $relRow): void
    {
        if (null === $this->view) {
            return;
        }
        $this->view->zoomAt($dir, $relCol, (2 * $relRow) + 1, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
    }

    /**
     * The continuous half of the same gesture: zoom by a factor on the same
     * anchor, for the burst of notches a trackpad's Ctrl+scroll delivers
     * ({@see \Porta\Input\ZoomGesture}).
     */
    public function magnifyAt(float $factor, int $relCol, int $relRow): void
    {
        if (null === $this->view) {
            return;
        }
        $this->view->magnifyAt($factor, $relCol, (2 * $relRow) + 1, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
    }

    /** Pan by wheel/drag (in half-rows vertically, columns horizontally). */
    public function scrollBy(int $dxCells, int $dyHalfRows): void
    {
        if (null === $this->view) {
            return;
        }
        $this->view->panBy($dxCells, $dyHalfRows, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
    }

    private function zoom(int $dir): void
    {
        $this->withView(fn (ZoomPanModel $v) => $dir > 0 ? $v->zoomIn($this->lastCols, 2 * $this->lastRows) : $v->zoomOut($this->lastCols, 2 * $this->lastRows));
    }

    /** Shift+arrow pans a 5× larger step. */
    private function pan(int $dx, int $dy, string $data): void
    {
        $factor = \str_starts_with($data, "\x1b[1;2") ? 5 : 1; // CSI 1;2 = shift-modified arrow
        if (null !== $this->view) {
            $this->view->panBy($dx * $factor, $dy * $factor, $this->lastCols, 2 * $this->lastRows);
            $this->afterViewChange();
        }
    }

    /** @param \Closure(ZoomPanModel): mixed $mutate */
    private function withView(\Closure $mutate): void
    {
        if (null !== $this->view) {
            $mutate($this->view);
            $this->afterViewChange();
        }
    }

    /**
     * Overlay the focus frame on the outer ring, keeping every line $cols wide
     * (the box glyphs are one column). The middle rows keep the content between
     * their side rails via a column slice, so the pan/zoom geometry is untouched.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function focusFrame(array $lines, int $cols, int $rows): array
    {
        $accent = $this->styles->of(Role::TabAccent);
        $span = \max(0, $cols - 2);
        $lines[0] = $accent->apply(Glyphs::FRAME_TOP_LEFT.\str_repeat(Glyphs::FRAME_HORIZONTAL, $span).Glyphs::FRAME_TOP_RIGHT);
        $lines[$rows - 1] = $accent->apply(Glyphs::FRAME_BOTTOM_LEFT.\str_repeat(Glyphs::FRAME_HORIZONTAL, $span).Glyphs::FRAME_BOTTOM_RIGHT);
        $rail = $accent->apply(Glyphs::FRAME_VERTICAL);
        for ($r = 1; $r < ($rows - 1); ++$r) {
            // Exactly $cols - 2 between the rails, even with a wide glyph of a
            // message under one of them: its cut half is a blank, nothing shifts.
            $lines[$r] = $rail.AnsiUtils::sliceToWidth($lines[$r], 1, $cols - 2).$rail;
        }

        return $lines;
    }

    /** The one-row banner that outranks the focus frame's top border: an error must not hide behind focus chrome. */
    private function errorBanner(string $error, int $cols): string
    {
        return AnsiUtils::truncateToWidth($this->styles->of(Role::DiagError)->apply(' ✗ '.Text::firstLine($error).' '), $cols, pad: true);
    }

    /**
     * A blank pane with $message centered on its middle row ('' stays plain
     * blank) — the fallback while there is nothing renderable yet, or never
     * will be.
     *
     * @return list<string>
     */
    private function messagePane(string $message, Role $role, int $cols, int $rows): array
    {
        $blank = \array_fill(0, \max(1, $rows), AnsiText::blank(\max(1, $cols)));
        if ('' === $message) {
            return $blank;
        }
        $text = AnsiUtils::truncateToWidth($message, $cols);
        $pad = \max(0, \intdiv($cols - AnsiUtils::visibleWidth($text), num2: 2));
        $blank[\intdiv($rows, num2: 2)] = AnsiText::blank($pad).$this->styles->of($role)->apply($text).AnsiText::blank($cols - $pad - AnsiUtils::visibleWidth($text));

        return $blank;
    }
}
