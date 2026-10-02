<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

use Porta\Support\Files;
use Porta\Support\ProjectPaths;

/**
 * Decodes an image file into truecolor pixels via GD. Palette images (GIF,
 * indexed PNG) are normalized to truecolor so {@see HalfBlockRasterizer} can
 * read every pixel with one `imagecolorat` contract. Caps both the encoded
 * bytes and the decoded pixel count — a small JPEG can decode to a huge bitmap.
 */
final readonly class ImageDecoder
{
    /** @var list<string> extensions routed to the viewer instead of the text editor */
    public const array EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];

    public const int MAX_BYTES = 20 * 1024 * 1024;
    public const int MAX_PIXELS = 16_000_000;

    /** Resolved once — ext-gd is a suggestion, not a requirement, of the host. */
    private bool $gdAvailable;

    public function __construct(
        private int $maxPixels = self::MAX_PIXELS,
        ?bool $gdAvailable = null,
    ) {
        $this->gdAvailable = $gdAvailable ?? \function_exists('imagecreatefromstring');
    }

    /** Whether the path names an image by extension (the viewer's routing test). */
    public static function isImagePath(string $path): bool
    {
        return ProjectPaths::hasExtension($path, self::EXTENSIONS);
    }

    /** Decode the file, or null when the bytes are not a displayable image. */
    public function decode(string $path): ?ImageData
    {
        return $this->decodeBytes(Files::read($path));
    }

    /**
     * Decode an in-memory image, or null when the bytes are not a displayable
     * one. The file-less door: the graph viewer pipes `dot -Tpng` straight from
     * the child's stdout, so there is no temp file to write, poll and unlink.
     */
    public function decodeBytes(string $bytes): ?ImageData
    {
        if (!$this->gdAvailable) {
            return null; // no GD, no bitmap — the viewer declines like any undecodable file
        }
        // The header's size, read BEFORE decoding: a few KiB of PNG can declare
        // a 50 000² bitmap, and GD would allocate it in full (outside PHP's
        // memory_limit) before any check after the decode could run. Bytes
        // whose size can't be read are not a displayable image either.
        // Both calls warn on non-image data; the false returns say it instead.
        \set_error_handler(static fn (): bool => true);
        try {
            $size = \getimagesizefromstring($bytes);
            $gd = false === $size || ($size[0] * $size[1]) > $this->maxPixels ? null : \imagecreatefromstring($bytes);
        } finally {
            \restore_error_handler();
        }
        if (!$gd instanceof \GdImage) {
            return null;
        }
        $width = \imagesx($gd);
        $height = \imagesy($gd);
        if (!\imageistruecolor($gd)) {
            \imagepalettetotruecolor($gd);
        }

        return new ImageData($gd, $width, $height, \strlen($bytes));
    }
}
