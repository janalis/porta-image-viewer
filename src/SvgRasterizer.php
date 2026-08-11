<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The converter-backed {@see Rasterizer}: an external SVG renderer as an async
 * child process, the document fed via stdin and the PNG read back from stdout —
 * no temp file at either end. Every rule here is {@see \Porta\Graph\DotLayoutEngine}'s,
 * because it is the same shape of problem: success is keyed on the EXIT CODE
 * (converters warn on stderr and still succeed, and stderr must be drained
 * regardless or a chatty one blocks on a full pipe), and there is ONE slot,
 * latest wins — a zoom-driven re-render cancels the render it supersedes, so a
 * stale bitmap can never repaint over a newer one.
 *
 * Which converter, and how it is driven, is {@see SvgConverter}: an explicit
 * path (the `svgConverter` setting) wins over discovery, the `dotPath` pattern.
 */
final class SvgRasterizer implements Rasterizer
{
    private ?AsyncProcess $running = null;

    public function __construct(
        private readonly string $rootDir,
        // The "SVG converter" setting; '' resolves to null (auto-discover).
        private readonly ?string $converterPath = null,
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
        $this->running?->cancel();
        $process = new AsyncProcess([$binary, ...$converter->arguments($scale)], $this->rootDir, stdin: $svg, captureStderr: true);
        $this->running = $process;
        $started = $process->start(function (int $code, string $stdout) use ($process, $onPng, $onError): void {
            if ($this->running === $process) {
                $this->running = null;
            }
            if (0 !== $code) {
                // A document that is not SVG at all lands here, with the
                // converter's own parse error as the message.
                $stderr = trim($process->errorOutput());
                $onError('' !== $stderr ? $stderr : "the SVG converter failed (exit {$code})");

                return;
            }
            $onPng($stdout);
        });
        if (!$started) {
            // proc_open spawns even unusable binaries (exec failure surfaces as
            // an exit code), so this is unreachable in tests.
            // @codeCoverageIgnoreStart
            $this->running = null;
            $onError('could not start the SVG converter');

            // @codeCoverageIgnoreEnd
        }
    }

    /** Stop any in-flight render (viewer teardown; idempotent). */
    public function cancel(): void
    {
        $this->running?->cancel();
        $this->running = null;
    }

    /**
     * The converter path and how to drive it: the configured binary if there is
     * one (its file name says which converter it is), else the first installed.
     *
     * @return array{0: ?string, 1: ?SvgConverter}
     */
    private function resolve(): array
    {
        $configured = trim((string) $this->converterPath);
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
