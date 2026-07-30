<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\ImageDecoder;
use Porta\ImageViewer\SvgConverter;
use Porta\ImageViewer\SvgRasterizer;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\SvgConverterBinary;

final class SvgRasterizerTest extends TestCase
{
    use PreservesPath;
    use PumpsLoop;
    use SvgConverterBinary;

    private const string SMALL_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"><rect width="100" height="50" fill="#284"/></svg>';

    private static function stub(): string
    {
        return \dirname(__DIR__, levels: 3).'/tests/Support/fake-rsvg';
    }

    /** @return array{0: ?string, 1: ?string} [png bytes, error] once the run settles */
    private function render(SvgRasterizer $rasterizer, string $svg, ?float $scale): array
    {
        $png = null;
        $error = null;
        $rasterizer->rasterize(
            $svg,
            $scale,
            static function (string $bytes) use (&$png): void {
                $png = $bytes;
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );
        $this->await(static function () use (&$png, &$error): bool {
            return null !== $png || null !== $error;
        }, what: 'the rasterizer to answer');

        return [$png, $error];
    }

    public function testRendersTheDocumentsOwnSizeWhenNoScaleIsAsked(): void
    {
        [$png, $error] = $this->render(new SvgRasterizer(sys_get_temp_dir(), self::stub()), self::SMALL_SVG, null);

        $this->assertNull($error);
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertNotNull($image, 'the bytes decode as an image — no temp file in between');
        // The stub renders 100×50 at 1:1, which is how the viewer probes an
        // SVG's intrinsic size: it asks the converter instead of parsing units.
        $this->assertSame([100, 50], [$image->width, $image->height]);
    }

    public function testAScaleReachesTheConverterAsItsOwnFlag(): void
    {
        $rasterizer = new SvgRasterizer(sys_get_temp_dir(), self::stub());

        [$png] = $this->render($rasterizer, self::SMALL_SVG, 2.5);
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertNotNull($image);
        $this->assertSame([250, 125], [$image->width, $image->height], 'librsvg -z 2.5');
    }

    public function testTheImageMagickArgvExpressesTheSameScaleAsADensity(): void
    {
        // Driving the same stub under ImageMagick's name proves the other argv
        // shape agrees: -density 96×scale is what -z is.
        $magick = sys_get_temp_dir().'/porta-fake-magick-'.getmypid();
        copy(self::stub(), $magick);
        chmod($magick, permissions: 0o755);

        try {
            $this->assertSame(SvgConverter::Magick, SvgConverter::forBinary('/usr/bin/magick'));
            [$png] = $this->render(new SvgRasterizer(sys_get_temp_dir(), $magick), self::SMALL_SVG, 2.5);
            $image = new ImageDecoder()->decodeBytes((string) $png);
            $this->assertNotNull($image);
            $this->assertSame([250, 125], [$image->width, $image->height], '-density 240 is -z 2.5');
        } finally {
            unlink($magick);
        }
    }

    public function testAFailureSurfacesTheConvertersStderr(): void
    {
        [$png, $error] = $this->render(new SvgRasterizer(sys_get_temp_dir(), self::stub()), '<svg>broken', null);

        $this->assertNull($png);
        $this->assertStringContainsString('XML parse error', (string) $error);
    }

    public function testANewRenderSupersedesTheInFlightOne(): void
    {
        $rasterizer = new SvgRasterizer(sys_get_temp_dir(), self::stub());
        $fired = [];
        $onError = static function (string $message): void {
            self::fail("unexpected rasterize error: {$message}");
        };
        $rasterizer->rasterize(
            self::SMALL_SVG,
            1.0,
            static function () use (&$fired): void {
                $fired[] = 'first';
            },
            $onError,
        );
        $rasterizer->rasterize(
            self::SMALL_SVG,
            2.0,
            static function () use (&$fired): void {
                $fired[] = 'second';
            },
            $onError,
        );
        $this->await(static function () use (&$fired): bool {
            return [] !== $fired;
        }, what: 'the surviving render');
        $this->pump(0.2);

        $this->assertSame(['second'], $fired, 'the superseded render went silent, latest wins');
    }

    public function testCancelIsIdempotentAndStopsInFlightWork(): void
    {
        $rasterizer = new SvgRasterizer(sys_get_temp_dir(), self::stub());
        $fired = false;
        $mark = static function () use (&$fired): void {
            $fired = true;
        };
        $rasterizer->rasterize(self::SMALL_SVG, null, $mark, $mark);
        $rasterizer->cancel();
        $rasterizer->cancel();
        $this->pump(0.2);

        $this->assertFalse($fired, 'a cancelled render never reports');
    }

    public function testWithoutAConverterItErrorsWithoutSpawning(): void
    {
        putenv('PATH=/nonexistent');
        $rasterizer = new SvgRasterizer(sys_get_temp_dir(), null);
        $error = null;
        $rasterizer->rasterize(
            self::SMALL_SVG,
            null,
            static function (): void {
                self::fail('onPng must not fire without a converter');
            },
            static function (string $message) use (&$error): void {
                $error = $message;
            },
        );

        $this->assertSame('no SVG converter found', $error, 'synchronous — no process was involved');
        $this->assertNull($rasterizer->binary());
    }

    public function testAnExplicitConverterPathWinsOverDiscovery(): void
    {
        $this->assertSame(self::stub(), new SvgRasterizer(sys_get_temp_dir(), self::stub())->binary());
    }

    public function testARealConverterRendersTheDocument(): void
    {
        $this->skipWithoutSvgConverter();
        $rasterizer = new SvgRasterizer(sys_get_temp_dir(), null);
        $this->assertNotNull($rasterizer->binary(), 'discovery found the installed converter');

        [$png, $error] = $this->render($rasterizer, self::SMALL_SVG, 2.0);
        $this->assertNull($error);
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertNotNull($image, 'a real converter emitted a decodable PNG');
        $this->assertSame([200, 100], [$image->width, $image->height], 'and honoured the scale');
    }
}
