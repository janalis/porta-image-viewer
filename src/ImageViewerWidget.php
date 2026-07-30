<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\I18n\Translator;
use Porta\ImageViewer\HalfBlockRasterizer;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageDecoder;
use Porta\ImageViewer\Rasterizer;
use Porta\ImageViewer\ScaledSource;
use Porta\ImageViewer\ZoomPanModel;
use Porta\Support\DebouncedRequest;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\NavigablePaneWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The image tab's pane: renders the active image as truecolor half-blocks
 * (via {@see HalfBlockRasterizer}) and owns its zoom/pan. It replaces the
 * code editor as the editor-pane's content while an image buffer is active,
 * so the tab-cycling / close / go-to-file keys are re-bound here and routed
 * back to the IDE through {@see ViewerTabHandler}.
 */
final class ImageViewerWidget extends NavigablePaneWidget
{
    /** How long a zoom has to settle before the converter is asked for a sharper raster. */
    private const float RENDER_DEBOUNCE = 0.15;

    private ?ImageData $image = null;
    private ?ZoomPanModel $view = null;
    /** The SVG source of a VECTOR tab, re-rasterized per zoom level; null for a bitmap tab. */
    private ?string $svg = null;
    /** The vector tab's path, so a REGENERATION is told from a tab switch. */
    private ?string $svgPath = null;
    /** The scale the shown raster was rendered at — its pixels per document pixel. */
    private float $rasterScale = 1.0;
    /** Coalesces and debounces the re-render the current zoom wants. */
    private readonly DebouncedRequest $pending;
    private bool $rasterizing = false;
    /** The converter's stderr (or the missing-converter message), shown as a banner. */
    private ?string $error = null;
    /** Memoises the area-averaged copy this pane samples while shrinking. */
    private readonly ScaledSource $scaled;
    /** last rendered pane size, so key handling scales/pans what is on screen */
    private int $lastCols = 80;
    private int $lastRows = 24;

    public function __construct(
        private readonly Rasterizer $rasterizer,
        private readonly Translator $translator,
        ?Scheduler $scheduler = null,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
        // Hand-built by its plugin, so the id #[AsWidget] used to apply is set here.
        $this->setId('image-viewer');
        $this->pending = new DebouncedRequest($scheduler ?? new RevoltScheduler(), self::RENDER_DEBOUNCE);
        $this->scaled = new ScaledSource();
    }

    public function paneId(): string
    {
        return 'image';
    }

    /**
     * Show $path's payload — decoded pixels, or an SVG's source this pane
     * rasterizes for itself. The two shapes are told apart HERE, where the
     * difference means something: core carries whichever its loader produced
     * and never looks inside.
     */
    public function show(string $path, mixed $payload): void
    {
        if (\is_string($payload)) {
            $this->showVector($path, $payload);

            return;
        }
        if ($payload instanceof ImageData) {
            $this->showBitmap($payload);
        }
    }

    /** Show decoded pixels, resetting the view to fit-to-pane. */
    private function showBitmap(ImageData $image): void
    {
        $this->reset();
        $this->image = $image;
        $this->view = new ZoomPanModel($image->width, $image->height);
        $this->invalidate();
    }

    /**
     * Show an SVG: the pane has no pixels yet, so it asks the converter for the
     * document at its OWN size. That first answer establishes both the bitmap
     * and the zoom model's dimensions, which is why the status bar and the zoom
     * label go on speaking in the SVG's own pixels — 100% means 100% of the
     * document, not of whatever raster happens to be on screen.
     */
    private function showVector(string $path, string $svg): void
    {
        if ($path === $this->svgPath && $svg === $this->svg) {
            return; // same tab, same document
        }
        if ($path !== $this->svgPath) {
            // A different tab: its raster must not paint under this document.
            // The SAME tab re-shown (the file changed on disk) keeps what is on
            // screen until the new render lands — the graph viewer's contract.
            $this->reset();
        }
        $this->error = null;
        $this->svgPath = $path;
        $this->svg = $svg;
        $this->rasterize(scale: null);
        $this->invalidate();
    }

    /** Losing the slot frees the pixels: whoever shows next needs the memory more. */
    public function hide(): void
    {
        $this->clear();
    }

    /** Drop the image reference (frees the GdImage once the buffer is gone too). */
    public function clear(): void
    {
        $this->reset();
        $this->invalidate();
    }

    /** Forget everything about the shown document — the shared head of show/showVector/clear. */
    private function reset(): void
    {
        $this->image = null;
        $this->view = null;
        $this->svg = null;
        $this->svgPath = null;
        $this->rasterScale = 1.0;
        $this->pending->reset();
        $this->rasterizing = false;
        $this->error = null;
    }

    /**
     * Ask the converter for this document at $scale (null = its own size). The
     * previous raster stays on screen while it works — paint-then-refine, the
     * highlighter's contract — and a failure keeps it too, under the banner.
     */
    private function rasterize(?float $scale): void
    {
        $svg = $this->svg;
        // Only reachable from showVector() and the zoom path, both of which run
        // with a document shown.
        \assert(null !== $svg, description: 'a rasterization is only ever asked for a shown document');
        $this->pending->settle($scale ?? 1.0);
        $this->rasterizing = true;
        $this->rasterizer->rasterize(
            $svg,
            $scale,
            function (string $png) use ($svg, $scale): void {
                $this->rasterizing = false;
                if ($svg !== $this->svg) {
                    return; // the tab moved on while the converter was working
                }
                $image = $this->decoded($png);
                if (null === $image) {
                    return;
                }
                $this->image = $image;
                $this->rasterScale = $scale ?? 1.0;
                // Only a PROBE may (re)establish what the document's size is:
                // a scaled re-render returns the same picture at a different
                // resolution, and must not redefine the coordinate space its own
                // zoom is expressed in. A probe whose size differs is a different
                // picture — the file changed on disk — so that one is fitted
                // fresh, the graph viewer's extent rule; a probe that agrees
                // keeps the zoom and pan.
                if (null === $scale) {
                    $size = [$image->width, $image->height];
                    if (null === $this->view || $size !== $this->view->dimensions()) {
                        $this->view = new ZoomPanModel(...$size);
                    }
                }
                $this->invalidate();
                $this->handler?->viewChanged();
            },
            function (string $message) use ($svg): void {
                $this->rasterizing = false;
                if ($svg === $this->svg) {
                    $this->error = $message;
                    $this->invalidate();
                }
            },
        );
    }

    /**
     * The raster scale the current zoom needs, capped so a big document cannot
     * ask for a bitmap the decoder would refuse. 1.0 is the document's own size,
     * so magnifying 2× wants 2 and nothing below 1:1 wants more than 1 —
     * shrinking is {@see ScaledSource}'s filter to do, not the converter's.
     */
    private function scaleFor(float $viewScale): float
    {
        // CEIL, not round: a continuous zoom (the Ctrl+scroll glide) would
        // otherwise ask the converter for a different size at every nudge.
        $wanted = (float) (int) ceil(max(1.0, $viewScale));
        [$width, $height] = $this->view?->dimensions() ?? [1, 1];
        $cap = sqrt(ImageDecoder::MAX_PIXELS / max(1, $width * $height));

        return max(0.01, min($wanted, $cap));
    }

    /**
     * Ask the converter for a sharper raster once the zoom has settled.
     * Debounced and coalesced on the WANTED scale, so a pan (or a zoom that
     * lands on the same resolution) costs nothing, and a Ctrl+scroll glide costs
     * one process rather than one per notch — the graph viewer's arrangement.
     */
    private function scheduleRaster(): void
    {
        if (null === $this->svg || null === $this->view) {
            return; // a bitmap tab has nothing to re-render
        }
        $scale = $this->scaleFor($this->view->effectiveScale($this->lastCols, 2 * $this->lastRows));
        $this->pending->want($scale, fn (float $value) => $this->rasterize($value));
    }

    /** The PNG the converter produced, or null after recording why it is unusable. */
    private function decoded(string $png): ?ImageData
    {
        $image = new ImageDecoder()->decodeBytes($png);
        if (null === $image) {
            $this->error = $this->translator->trans('the SVG could not be decoded');
            $this->invalidate();
        }

        return $image;
    }

    /** The status bar's size text ('64 × 64' in the document's own pixels), '' when nothing is shown. */
    // @mago-expect lint:no-missing-format-argument -- mago 1.45's new rule cannot count through a spread: it flags every spread call and reports the placeholder count as the value count ("2 placeholders, 2 values given"). dimensions() returns array{0: int, 1: int}.
    public function statusInfo(): string
    {
        return null === $this->view ? '' : \sprintf('%d × %d', ...$this->view->dimensions());
    }

    /** The status bar's zoom text ('Fit (37%)' / '200%'), '' when nothing is shown. */
    public function zoomLabel(): string
    {
        return null === $this->view ? '' : $this->view->zoomLabel($this->lastCols, 2 * $this->lastRows);
    }

    /** Ctrl+wheel: zoom one step anchored on the pane cell under the pointer. */
    /**
     * The status bar's right segment while this pane holds the slot: the
     * document's own size, the zoom, and the keys — the pane knows what its keys
     * do and the status bar does not.
     */
    public function statusRight(): string
    {
        return trim(\sprintf('%s  %s  %s', $this->statusInfo(), $this->zoomLabel(), $this->translator->trans('+/- zoom  0 fit  Arrows pan  Ctrl+Q quit')));
    }

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

    /** Pan by wheel scrolling (in half-rows vertically, columns horizontally). */
    public function scrollBy(int $dxCells, int $dyHalfRows): void
    {
        if (null === $this->view) {
            return;
        }
        $this->view->panBy($dxCells, $dyHalfRows, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $rows = $context->getRows();
        if ($cols < 1 || $rows < 1) {
            return [];
        }
        $this->lastCols = $cols;
        $this->lastRows = $rows;
        // The fit scale depends on the pane size, which is only known here; the
        // wanted-scale guard makes this a float compare on every other frame.
        $this->scheduleRaster();
        $lines = $this->contentLines($cols, $rows);

        // A caret-less, row-less pane still needs a focus cue like its siblings:
        // a box-drawing frame drawn over the image's edge ring while focused. The
        // glyph carries it (accent-tinted with colour, a bare box under --no-ansi).
        if ($this->isFocused() && $cols >= 3 && $rows >= 3) {
            $lines = $this->focusFrame($lines, $cols, $rows);
        }
        // A vector tab's banner outranks the focus frame's top border, the graph
        // viewer's rule — a converter error must not hide behind focus chrome.
        if (null !== $this->image && null !== $this->error) {
            $banner = ' ✗ '.explode("\n", $this->error)[0].' ';
            $lines[0] = AnsiUtils::truncateToWidth($this->styles->of(Role::DiagError)->apply($banner), $cols, pad: true);
        }

        return $this->withMenuOverlay($lines, $cols);
    }

    /**
     * The pane's pixels, or — for a vector tab with nothing rendered yet — the
     * progress hint or the reason there never will be one.
     *
     * @return list<string>
     */
    private function contentLines(int $cols, int $rows): array
    {
        if (null !== $this->image && null !== $this->view) {
            return HalfBlockRasterizer::render(
                $this->scaled->forScale($this->image, $this->view->effectiveScale($cols, 2 * $rows), ratio: $this->rasterScale),
                $this->view,
                $cols,
                $rows,
            );
        }
        $blank = array_fill(0, max(1, $rows), str_repeat(' ', max(1, $cols)));
        $message = match (true) {
            null !== $this->error => explode("\n", $this->error)[0],
            $this->rasterizing => '⏳ '.$this->translator->trans('rendering…'),
            default => '',
        };
        if ('' === $message) {
            return $blank;
        }
        $text = AnsiUtils::truncateToWidth($message, $cols);
        $pad = max(0, intdiv($cols - AnsiUtils::visibleWidth($text), num2: 2));
        $style = null !== $this->error ? Role::DiagError : Role::Dim;
        $blank[intdiv($rows, num2: 2)] = str_repeat(' ', $pad).$this->styles->of($style)->apply($text).str_repeat(' ', max(0, $cols - $pad - AnsiUtils::visibleWidth($text)));

        return $blank;
    }

    /**
     * Overlay the focus frame on the outer ring, keeping every line $cols wide
     * (the box glyphs are one column). The middle rows keep the image between
     * their side rails via a column slice, so the pan/zoom geometry is untouched.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function focusFrame(array $lines, int $cols, int $rows): array
    {
        $accent = $this->styles->of(Role::TabAccent);
        $span = max(0, $cols - 2);
        $lines[0] = $accent->apply(Glyphs::FRAME_TOP_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, $span).Glyphs::FRAME_TOP_RIGHT);
        $lines[$rows - 1] = $accent->apply(Glyphs::FRAME_BOTTOM_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, $span).Glyphs::FRAME_BOTTOM_RIGHT);
        $rail = $accent->apply(Glyphs::FRAME_VERTICAL);
        for ($r = 1; $r < ($rows - 1); ++$r) {
            $lines[$r] = $rail.AnsiUtils::sliceByColumn($lines[$r], 1, $cols - 2).$rail;
        }

        return $lines;
    }

    public function handleInput(string $data): void
    {
        if ('+' === $data) { // KeyParser cannot express a bare '+' spec; alias it here.
            $this->zoom(1);

            return;
        }
        $kb = $this->getKeybindings();
        $pxRows = 2 * $this->lastRows;
        match (true) {
            $kb->matches($data, 'image_zoom_in') => $this->zoom(1),
            $kb->matches($data, 'image_zoom_out') => $this->zoom(-1),
            $kb->matches($data, 'image_zoom_fit') => $this->withView(static fn (ZoomPanModel $v) => $v->fit()),
            $kb->matches($data, 'image_zoom_actual') => $this->withView(fn (ZoomPanModel $v) => $v->actualSize($this->lastCols, $pxRows)),
            $kb->matches($data, 'image_pan_left') => $this->pan(-2, 0, $data),
            $kb->matches($data, 'image_pan_right') => $this->pan(2, 0, $data),
            $kb->matches($data, 'image_pan_up') => $this->pan(0, -4, $data),
            $kb->matches($data, 'image_pan_down') => $this->pan(0, 4, $data),
            $kb->matches($data, 'next_buffer') => $this->handler?->cycleBuffer(1),
            $kb->matches($data, 'prev_buffer') => $this->handler?->cycleBuffer(-1),
            $kb->matches($data, 'close_buffer') => $this->handler?->closeBuffer(),
            $kb->matches($data, 'goto_file') => $this->handler?->openGotoFile(),
            $kb->matches($data, 'save_all') => $this->handler?->saveAll(),
            default => null,
        };
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'image_zoom_in' => ['='],
            'image_zoom_out' => ['-'],
            'image_zoom_fit' => ['0', 'f'],
            'image_zoom_actual' => ['1'],
            'image_pan_left' => [Key::LEFT, 'shift+left'],
            'image_pan_right' => [Key::RIGHT, 'shift+right'],
            'image_pan_up' => [Key::UP, 'shift+up'],
            'image_pan_down' => [Key::DOWN, 'shift+down'],
            // Re-bound to the editor's defaults so tab management keeps working
            // while the code editor is detached (see EditorKeymap).
            'next_buffer' => ['ctrl+right'],
            'prev_buffer' => ['ctrl+left'],
            'close_buffer' => ['ctrl+f4', 'alt+w'],
            'goto_file' => ['ctrl+shift+n', 'ctrl+p'],
            'save_all' => ['ctrl+alt+s'],
        ];
    }

    private function zoom(int $dir): void
    {
        $this->withView(fn (ZoomPanModel $v) => $dir > 0 ? $v->zoomIn($this->lastCols, 2 * $this->lastRows) : $v->zoomOut($this->lastCols, 2 * $this->lastRows));
    }

    /** Shift+arrow pans a 5× larger step. */
    private function pan(int $dx, int $dy, string $data): void
    {
        $factor = str_starts_with($data, "\x1b[1;2") ? 5 : 1; // CSI 1;2 = shift-modified arrow
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

    protected function afterViewChange(): void
    {
        $this->invalidate();
        $this->handler?->viewChanged();
        $this->scheduleRaster();
    }
}
