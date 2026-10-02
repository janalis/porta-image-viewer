<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageDecoder;
use Porta\Tests\Support\TempProject;

final class ImageDecoderTest extends TestCase
{
    public function testIsImagePathMatchesKnownExtensionsCaseInsensitively(): void
    {
        $this->assertTrue(ImageDecoder::isImagePath('a/logo.png'));
        $this->assertTrue(ImageDecoder::isImagePath('photo.JPG'));
        $this->assertTrue(ImageDecoder::isImagePath('anim.gif'));
        $this->assertTrue(ImageDecoder::isImagePath('pic.webp'));
        $this->assertTrue(ImageDecoder::isImagePath('old.bmp'));
        $this->assertFalse(ImageDecoder::isImagePath('code.php'));
        $this->assertFalse(ImageDecoder::isImagePath('notes.txt'));
        $this->assertFalse(ImageDecoder::isImagePath('png'), 'no extension');
    }

    public function testDecodesEverySupportedFormatToTruecolorPixels(): void
    {
        $project = new TempProject([]);
        $gd = \imagecreatetruecolor(3, height: 2);
        \imagefilledrectangle($gd, x1: 0, y1: 0, x2: 2, y2: 1, color: (int) \imagecolorallocate($gd, red: 255, green: 0, blue: 0));
        \imagesetpixel($gd, x: 2, y: 1, color: (int) \imagecolorallocate($gd, red: 0, green: 0, blue: 255));
        \imagepng($gd, $project->path('a.png'));
        \imagejpeg($gd, $project->path('a.jpg'));
        \imagegif($gd, $project->path('a.gif'));
        \imagewebp($gd, $project->path('a.webp'));
        \imagebmp($gd, $project->path('a.bmp'));

        $decoder = new ImageDecoder();
        foreach (['a.png', 'a.jpg', 'a.gif', 'a.webp', 'a.bmp'] as $file) {
            $image = $decoder->decode($project->path($file));
            $this->assertInstanceOf(ImageData::class, $image, $file);
            $this->assertSame(3, $image->width, $file);
            $this->assertSame(2, $image->height, $file);
            $this->assertTrue(\imageistruecolor($image->gd), "{$file} normalized to truecolor");
            $this->assertGreaterThan(0, $image->byteSize, $file);
        }

        // PNG is lossless: pixels survive exactly.
        $png = $decoder->decode($project->path('a.png'));
        $this->assertInstanceOf(ImageData::class, $png);
        $red = \imagecolorat($png->gd, x: 0, y: 0);
        $this->assertIsInt($red);
        $this->assertSame([255, 0, 0], [($red >> 16) & 0xFF, ($red >> 8) & 0xFF, $red & 0xFF]);

        // The GIF path exercises the palette → truecolor branch (quantization may
        // shift channels slightly, so only assert the dominant one).
        $gif = $decoder->decode($project->path('a.gif'));
        $this->assertInstanceOf(ImageData::class, $gif);
        $pixel = \imagecolorat($gif->gd, x: 0, y: 0);
        $this->assertIsInt($pixel);
        $this->assertGreaterThan(200, ($pixel >> 16) & 0xFF, 'red channel survives quantization');
    }

    public function testRejectsCorruptBytesAndOversizedPixelCounts(): void
    {
        $project = new TempProject(['fake.png' => 'not an image at all']);
        $decoder = new ImageDecoder();
        $this->assertNull($decoder->decode($project->path('fake.png')), 'corrupt bytes');
        $this->assertNull($decoder->decode($project->path('missing.png')), 'unreadable file');

        $gd = \imagecreatetruecolor(4, height: 4);
        \imagepng($gd, $project->path('big.png'));
        $this->assertNull(new ImageDecoder(maxPixels: 15)->decode($project->path('big.png')), '16 px > 15 px cap');
        $this->assertInstanceOf(ImageData::class, new ImageDecoder(maxPixels: 16)->decode($project->path('big.png')), 'exactly at the cap');
    }

    public function testADecompressionBombIsRefusedFromItsHeader(): void
    {
        // 24 KiB of PNG that inflates to a 5000×5000 bitmap — past the 16 Mpx
        // cap. GD allocates outside memory_limit, so only the header can stop it.
        $chunk = static fn (string $type, string $data): string => \pack('N', \strlen($data)).$type.$data.\pack('N', \crc32($type.$data));
        $z = \deflate_init(\ZLIB_ENCODING_DEFLATE);
        \assert(false !== $z, description: 'zlib');
        $idat = (string) \deflate_add($z, \str_repeat("\0", times: 5001 * 5000), flush_mode: \ZLIB_FINISH);
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', \pack('NNCCCCC', 5000, 5000, 8, 0, 0, 0, 0)).$chunk('IDAT', $idat).$chunk('IEND', data: '');

        $this->assertLessThan(100_000, \strlen($png));
        $this->assertNull(new ImageDecoder()->decodeBytes($png));
    }

    public function testWithoutGdEveryImageIsPolitelyUndecodable(): void
    {
        // ext-gd is a suggestion of the host, not a requirement: on a box
        // without it the decoder must decline like any undecodable file
        // instead of fataling on the first missing GD symbol.
        $project = new TempProject();
        $gd = \imagecreatetruecolor(2, height: 2);
        \imagepng($gd, $project->path('ok.png'));

        $this->assertNull(new ImageDecoder(gdAvailable: false)->decode($project->path('ok.png')));
        $this->assertInstanceOf(ImageData::class, new ImageDecoder()->decode($project->path('ok.png')), 'the default detects the loaded extension');
    }
}
