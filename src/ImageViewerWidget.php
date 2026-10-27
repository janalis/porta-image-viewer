<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\I18n\Translator;
use Porta\Support\DebouncedRequest;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Porta\Support\Text;
use Porta\Ui\Glyphs;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\NavigablePaneWidget;
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
    use ZoomPanPane;

    private ?ImageData $image = null;
    /** The SVG source of a VECTOR tab, re-rasterized per zoom level; null for a bitmap tab. */
    private ?string $svg = null;
    /** The vector tab's path, so a REGENERATION is told from a tab switch. */
    private ?string $svgPath = null;
    /** The scale the shown raster was rendered at — its pixels per document pixel. */
    private float $rasterScale = 1.0;

    /**
     * Raster memo: the ▀-cell rasterization does 2 imagecolorat() calls per
     * cell (20k GD calls and ~10k 24-bit SGRs for a 200×50 pane) and used to
     * re-run on EVERY widget invalidation — a toast, a diagnostics push, the
     * focus frame. Keyed on the image generation (not spl_object_id, which GC
     * can reuse), the view fingerprint and the geometry.
     */
    /** @var list<string>|null */
    private ?array $rasterMemo = null;
    private string $rasterKey = '';
    private int $imageGeneration = 0;
    /** Coalesces and debounces the re-render the current zoom wants. */
    private readonly DebouncedRequest $pending;
    private bool $rasterizing = false;
    /** The converter's stderr (or the missing-converter message), shown as a banner. */
    private ?string $error = null;
    /** Memoises the area-averaged copy this pane samples while shrinking. */
    private readonly ScaledSource $scaled;

    /**
     * @param \Closure(string, ?float, \Closure(string): void, \Closure(string): void): void $rasterize
     *        SVG source to PNG bytes at a scale (null: the document's own size),
     *        asynchronously; exactly one callback fires unless a newer call
     *        supersedes it. {@see SvgRasterizer::rasterize()} in production.
     */
    public function __construct(
        private readonly \Closure $rasterize,
        private readonly Translator $translator,
        ?Scheduler $scheduler = null,
        ?Styles $styles = null,
        // Trailing and optional: without it the pane paints half-blocks, which
        // is also what it paints while the terminal has not answered yet.
        ?TerminalGraphics $graphics = null,
    ) {
        $this->initStyles($styles);
        // Hand-built by its plugin, so the id #[AsWidget] used to apply is set here.
        $this->setId('image-viewer');
        $this->pending = new DebouncedRequest($scheduler ?? new RevoltScheduler(), self::RENDER_DEBOUNCE);
        $this->scaled = new ScaledSource();
        $this->pixels = null !== $graphics ? new PixelPlacement($graphics, $this->afterViewChange(...)) : null;
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
        ++$this->imageGeneration;
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
        ++$this->imageGeneration;
        $this->rasterMemo = null; // free the previous pane's raster promptly
        $this->image = null;
        $this->view = null;
        $this->svg = null;
        $this->svgPath = null;
        $this->rasterScale = 1.0;
        $this->pixels?->forget();
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
        ($this->rasterize)(
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
                ++$this->imageGeneration;
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
        // Drawn by the terminal, one column is a whole cell of screen pixels,
        // and the raster has to cover those to look sharp.
        $perColumn = $this->pixelsPerColumn();
        // CEIL, not round: a continuous zoom (the Ctrl+scroll glide) would
        // otherwise ask the converter for a different size at every nudge.
        $wanted = (float) (int) \ceil(\max(1.0, $viewScale * $perColumn));
        [$width, $height] = $this->view?->dimensions() ?? [1, 1];
        $cap = \sqrt(ImageDecoder::MAX_PIXELS / \max(1, $width * $height));

        return \max(0.01, \min($wanted, $cap));
    }

    /**
     * Ask the converter for a sharper raster once the zoom has settled.
     * Debounced and coalesced on the WANTED scale, so a pan (or a zoom that
     * lands on the same resolution) costs nothing, and a Ctrl+scroll glide costs
     * one process rather than one per notch — the graph viewer's arrangement.
     */
    protected function scheduleRefine(): void
    {
        if (null === $this->svg || null === $this->view) {
            return; // a bitmap tab has nothing to re-render
        }
        $scale = $this->scaleFor($this->view->effectiveScale($this->lastCols, 2 * $this->lastRows));
        $this->pending->want($scale, $this->rasterize(...));
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

    /**
     * A press on the picture arms the drag-pan, and a release that never moved
     * is a click ({@see selectAt()}) — the graph viewer's gesture pair.
     */
    #[\Override]
    public function press(int $relRow, int $relCol): bool
    {
        return null !== $this->view;
    }

    /**
     * Click-to-source for an SVG: the cell back to a point in the document's
     * pixels, through the zoom and the pan, then the shape drawn there to the
     * tag that wrote it. A bitmap has no source to go to.
     */
    #[\Override]
    public function selectAt(int $relRow, int $relCol): void
    {
        if (null === $this->svg || null === $this->view) {
            return;
        }
        $rect = $this->view->visibleRect($this->lastCols, 2 * $this->lastRows);
        $scale = $this->view->effectiveScale($this->lastCols, 2 * $this->lastRows);
        [$width, $height] = $this->view->dimensions();
        $offset = SvgHitTest::elementAt(
            $this->svg,
            $width,
            $height,
            $rect['srcX'] + (($relCol + 0.5 - $rect['padCols']) / $scale),
            $rect['srcY'] + (((2 * $relRow) + 1 - $rect['padHalfRows']) / $scale),
        );
        if (null !== $offset) {
            $this->handler?->revealSource(...Text::lineAndColumn($this->svg, $offset));
        }
    }

    /** The status bar's size text ('64 × 64' in the document's own pixels), '' when nothing is shown. */
    // @mago-expect lint:no-missing-format-argument -- mago 1.45's new rule cannot count through a spread: it flags every spread call and reports the placeholder count as the value count ("2 placeholders, 2 values given"). dimensions() returns array{0: int, 1: int}.
    public function statusInfo(): string
    {
        return null === $this->view ? '' : \sprintf('%d × %d', ...$this->view->dimensions());
    }

    /** This pane's status-segment key hints — arrows pan, nothing to select. */
    protected function keysHint(): string
    {
        return $this->translator->trans('+/- zoom  0 fit  Arrows pan  Ctrl+Q quit');
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $lines = $this->framedLines($context);
        // A vector tab's banner outranks the focus frame's top border, the graph
        // viewer's rule — a converter error must not hide behind focus chrome.
        if ([] !== $lines && null !== $this->image && null !== $this->error) {
            $lines[0] = $this->errorBanner($this->error, $context->getColumns());
        }

        return $lines;
    }

    /**
     * The pane's pixels, or — for a vector tab with nothing rendered yet — the
     * progress hint or the reason there never will be one.
     *
     * @return list<string>
     */
    private function contentLines(int $cols, int $rows): array
    {
        $image = $this->image;
        $view = $this->view;
        if (null !== $image && null !== $view) {
            return $this->imageLines($image, $view, $cols, $rows);
        }
        $message = match (true) {
            null !== $this->error => Text::firstLine($this->error),
            $this->rasterizing => Glyphs::WORKING.' '.$this->translator->trans('rendering…'),
            default => '',
        };

        return $this->messagePane($message, null !== $this->error ? Role::DiagError : Role::Dim, $cols, $rows);
    }

    /** @return list<string> */
    private function imageLines(ImageData $image, ZoomPanModel $view, int $cols, int $rows): array
    {
        $pixels = $this->pixels?->render($image, $this->rasterScale, $view, $cols, $rows);
        if (null !== $pixels) {
            return $pixels; // cheap to build, and the placement dedupes itself
        }
        $key = $this->imageGeneration.':'.$view->stateKey().':'.$cols.':'.$rows;
        if (null === $this->rasterMemo || $key !== $this->rasterKey) {
            $this->rasterKey = $key;
            $this->rasterMemo = HalfBlockRasterizer::render(
                $this->scaled->forScale($image, $view->effectiveScale($cols, 2 * $rows), ratio: $this->rasterScale),
                $view,
                $cols,
                $rows,
            );
        }

        return $this->rasterMemo;
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
            default => $this->tabKey($data),
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
            ...self::TAB_KEYS,
        ];
    }
}
