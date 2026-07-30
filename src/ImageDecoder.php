<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/**
 * Decodes an image file into truecolor pixels via GD. Palette images (GIF,
 * indexed PNG) are normalized to truecolor so {@see HalfBlockRasterizer} can
 * read every pixel with one `imagecolorat` contract. Caps both the encoded
 * bytes and the decoded pixel count — a small JPEG can decode to a huge bitmap.
 */
final class ImageDecoder
{
    /** @var list<string> extensions routed to the viewer instead of the text editor */
    public const array EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'];

    public const int MAX_BYTES = 20 * 1024 * 1024;
    public const int MAX_PIXELS = 16_000_000;

    public function __construct(
        private readonly int $maxPixels = self::MAX_PIXELS,
    ) {}

    /** Whether the path names an image by extension (the viewer's routing test). */
    public static function isImagePath(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), self::EXTENSIONS, strict: true);
    }

    /** Decode the file, or null when the bytes are not a displayable image. */
    public function decode(string $path): ?ImageData
    {
        return $this->decodeBytes(is_file($path) ? (string) file_get_contents($path) : '');
    }

    /**
     * Decode an in-memory image, or null when the bytes are not a displayable
     * one. The file-less door: the graph viewer pipes `dot -Tpng` straight from
     * the child's stdout, so there is no temp file to write, poll and unlink.
     */
    public function decodeBytes(string $bytes): ?ImageData
    {
        // Arbitrary bytes can't be pre-validated: swallow the warning GD raises
        // on non-image data and rely on the false return below.
        set_error_handler(static fn (): bool => true);
        $gd = imagecreatefromstring($bytes);
        restore_error_handler();
        if (!$gd instanceof \GdImage) {
            return null;
        }
        $width = imagesx($gd);
        $height = imagesy($gd);
        if (($width * $height) > $this->maxPixels) {
            return null;
        }
        if (!imageistruecolor($gd)) {
            imagepalettetotruecolor($gd);
        }

        return new ImageData($gd, $width, $height, \strlen($bytes));
    }
}
