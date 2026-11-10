<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\Support\SingleSlotProcess;
use Porta\Ui\Graphics\PngDimensions;
use Symfony\Component\Process\ExecutableFinder;

/**
 * SVG to PNG for the image pane: an external SVG renderer as an async
 * child process, the document fed via stdin and the PNG read back from stdout —
 * no temp file at either end. The one-slot-latest-wins run and its exit-code
 * bookkeeping are {@see SingleSlotProcess}'s — the same shape of problem
 * {@see \Porta\GraphViewer\Graph\DotLayoutEngine} and
 * {@see \Porta\GraphViewer\Graph\PlantUmlEngine} solve.
 *
 * Which converter, and how it is driven, is {@see SvgConverter}: an explicit
 * path (the `svgConverter` setting) wins over discovery, the `dotPath` pattern.
 */
final readonly class SvgRasterizer
{
    /**
     * @param null|(\Closure(): ?string)|string $converterPath the "SVG converter" setting; '' resolves to
     *                                                         null (auto-discover). A CLOSURE is read per
     *                                                         call, so a Settings edit applies without a
     *                                                         restart — the AbstractToolPlugin::binary() idiom
     */
    public function __construct(
        private string $rootDir,
        private \Closure|string|null $converterPath = null,
        private SingleSlotProcess $slot = new SingleSlotProcess(),
    ) {}

    public function binary(): ?string
    {
        return $this->resolve()[0];
    }

    public function rasterize(string $svg, ?float $scale, \Closure $onPng, \Closure $onError): void
    {
        [$binary, $converter] = $this->resolve();
        if (null === $binary || null === $converter) {
            $onError('no SVG converter found');

            return;
        }
        // A document that is not SVG at all lands here, with the converter's
        // own parse error as the message.
        $this->slot->run([$binary, ...$converter->arguments($scale)], $this->rootDir, $svg, 'the SVG converter', $onPng, $onError);
    }

    /**
     * $svg drawn $width pixels wide, or null — what this plugin lends core as
     * {@see \Porta\Plugin\Api\Capability::SvgRasterizer}. A converter takes
     * a scale, not a width, so the document is drawn at its own size first and
     * once more at the scale $width needs, when that differs. Give each call a
     * rasterizer of its own: two calls on one slot would cancel each other.
     *
     * @param \Closure(?string): void $onPng exactly once
     */
    public function atWidth(string $svg, int $width, \Closure $onPng): void
    {
        $failed = static function () use ($onPng): void {
            $onPng(null);
        };
        $this->rasterize(
            $svg,
            null,
            function (string $png) use ($svg, $width, $onPng, $failed): void {
                $own = PngDimensions::read($png)[0] ?? 0;
                if (0 === $own || $width === $own) {
                    $onPng(0 === $own ? null : $png);

                    return;
                }
                $this->rasterize($svg, $width / $own, $onPng, $failed);
            },
            $failed,
        );
    }

    /** Stop any in-flight render (viewer teardown; idempotent). */
    public function cancel(): void
    {
        $this->slot->cancel();
    }

    /**
     * The converter path and how to drive it: the configured binary if there is
     * one (its file name says which converter it is), else the first installed.
     *
     * @return array{0: ?string, 1: ?SvgConverter}
     */
    private function resolve(): array
    {
        $configured = \trim((string) ($this->converterPath instanceof \Closure ? ($this->converterPath)() : $this->converterPath));
        if ('' !== $configured) {
            return [$configured, SvgConverter::forBinary($configured)];
        }
        $converter = SvgConverter::discover();

        return [null === $converter ? null : new ExecutableFinder()->find($converter->binaryName()), $converter];
    }
}
