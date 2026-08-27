<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\Support\SingleSlotProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The converter-backed {@see Rasterizer}: an external SVG renderer as an async
 * child process, the document fed via stdin and the PNG read back from stdout —
 * no temp file at either end. The one-slot-latest-wins run and its exit-code
 * bookkeeping are {@see SingleSlotProcess}'s — the same shape of problem
 * {@see \Porta\GraphViewer\Graph\DotLayoutEngine} and
 * {@see \Porta\GraphViewer\Graph\PlantUmlEngine} solve.
 *
 * Which converter, and how it is driven, is {@see SvgConverter}: an explicit
 * path (the `svgConverter` setting) wins over discovery, the `dotPath` pattern.
 */
final class SvgRasterizer implements Rasterizer
{
    /**
     * @param null|(\Closure(): ?string)|string $converterPath the "SVG converter" setting; '' resolves to
     *                                                         null (auto-discover). A CLOSURE is read per
     *                                                         call, so a Settings edit applies without a
     *                                                         restart — the AbstractToolPlugin::binary() idiom
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly \Closure|string|null $converterPath = null,
        private readonly SingleSlotProcess $slot = new SingleSlotProcess(),
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
        $configured = trim((string) ($this->converterPath instanceof \Closure ? ($this->converterPath)() : $this->converterPath));
        if ('' !== $configured) {
            return [$configured, SvgConverter::forBinary($configured)];
        }
        $converter = SvgConverter::discover();
        if (null === $converter) {
            return [null, null];
        }

        return [new ExecutableFinder()->find($converter->binaryName()), $converter];
    }
}
