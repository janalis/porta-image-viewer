<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\Support\ProjectPaths;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The SVG converters {@see SvgRasterizer} knows how to drive, in discovery
 * order. Each case carries its binary name and its argv shape; both were
 * verified over stdin→stdout, which is why neither needs a temp file.
 *
 * `Rsvg` (librsvg) is first: it is the fastest, the most accurate, and its `-w`
 * scales the whole document proportionally. ImageMagick follows in its two
 * spellings, and there the size is expressed as **`-density`, never `-resize`**:
 * ImageMagick rasterizes at the intrinsic size first, so `-resize` would upscale
 * a bitmap where `-density` re-renders the vector.
 */
enum SvgConverter
{
    case Rsvg;
    case Magick;
    case Convert;

    /** The extensions the viewer claims when a converter is available. */
    public const array EXTENSIONS = ['svg'];

    /** Whether the path names an SVG (the viewer's routing test, {@see ImageDecoder::isImagePath}'s sibling). */
    public static function isSvgPath(string $path): bool
    {
        return ProjectPaths::hasExtension($path, self::EXTENSIONS);
    }

    public function binaryName(): string
    {
        return match ($this) {
            self::Rsvg => 'rsvg-convert',
            self::Magick => 'magick',
            self::Convert => 'convert',
        };
    }

    /**
     * The argv after the binary. Both converters take a SCALE rather than a
     * pixel size — `-z` and a density relative to the 96-dpi default — which is
     * why the rasterizer never needs to know a document's intrinsic width;
     * measured to agree exactly (a 100×50 document at 3.5 gives 350×175 from
     * either). A null scale renders at the document's own size.
     *
     * @return list<string>
     */
    public function arguments(?float $scale): array
    {
        return match ($this) {
            self::Rsvg => null === $scale ? ['-f', 'png'] : ['-f', 'png', '-z', (string) $scale],
            self::Magick, self::Convert => [
                '-background',
                'none',
                ...(null === $scale ? [] : ['-density', (string) (int) \round(96 * $scale)]),
                'svg:-',
                'png:-',
            ],
        };
    }

    /**
     * The first installed converter, or null when none is — asked by the
     * image-viewer plugin's routing claim, so an `.svg` stays TEXT on a machine
     * that cannot render one. Plugin-constructible on purpose (no container),
     * like {@see \Porta\GraphViewer\ArchitectureArtifact}.
     */
    public static function discover(): ?self
    {
        $finder = new ExecutableFinder();
        foreach (self::cases() as $converter) {
            if (null !== $finder->find($converter->binaryName())) {
                return $converter;
            }
        }

        return null;
    }

    /** The converter a configured binary PATH names, by its file name (an unknown name is driven as librsvg). */
    public static function forBinary(string $path): self
    {
        $name = \basename($path);
        foreach (self::cases() as $converter) {
            if ($converter->binaryName() === $name) {
                return $converter;
            }
        }

        return self::Rsvg;
    }
}
