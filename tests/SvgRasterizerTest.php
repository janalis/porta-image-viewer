<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageDecoder;
use Porta\ImageViewer\SvgConverter;
use Porta\ImageViewer\SvgRasterizer;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\SvgConverterBinary;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Graphics\PngDimensions;

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
        [$png, $error] = $this->render(new SvgRasterizer(\sys_get_temp_dir(), self::stub()), self::SMALL_SVG, null);

        $this->assertNull($error);
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertInstanceOf(ImageData::class, $image, 'the bytes decode as an image — no temp file in between');
        // The stub renders 100×50 at 1:1, which is how the viewer probes an
        // SVG's intrinsic size: it asks the converter instead of parsing units.
        $this->assertSame([100, 50], [$image->width, $image->height]);
    }

    public function testAScaleReachesTheConverterAsItsOwnFlag(): void
    {
        $rasterizer = new SvgRasterizer(\sys_get_temp_dir(), self::stub());

        [$png] = $this->render($rasterizer, self::SMALL_SVG, 2.5);
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertInstanceOf(ImageData::class, $image);
        $this->assertSame([250, 125], [$image->width, $image->height], 'librsvg -z 2.5');
    }

    public function testTheImageMagickArgvExpressesTheSameScaleAsADensity(): void
    {
        // Driving the same stub under ImageMagick's name proves the other argv
        // shape agrees: -density 96×scale is what -z is.
        $magick = \sys_get_temp_dir().'/porta-fake-magick-'.\getmypid();
        \copy(self::stub(), $magick);
        \chmod($magick, permissions: 0o755);

        try {
            $this->assertSame(SvgConverter::Magick, SvgConverter::forBinary('/usr/bin/magick'));
            [$png] = $this->render(new SvgRasterizer(\sys_get_temp_dir(), $magick), self::SMALL_SVG, 2.5);
            $image = new ImageDecoder()->decodeBytes((string) $png);
            $this->assertInstanceOf(ImageData::class, $image);
            $this->assertSame([250, 125], [$image->width, $image->height], '-density 240 is -z 2.5');
        } finally {
            \unlink($magick);
        }
    }

    /** @return ?string the PNG atWidth() answered, once it has */
    private function atWidth(SvgRasterizer $rasterizer, string $svg, int $width): ?string
    {
        $answers = [];
        $rasterizer->atWidth($svg, $width, static function (?string $png) use (&$answers): void {
            $answers[] = $png;
        });
        $this->await(static function () use (&$answers): bool {
            return [] !== $answers;
        }, what: 'the drawing at a width');
        $this->pump(0.1);
        $this->assertCount(1, $answers, 'answered exactly once');

        return $answers[0];
    }

    /** Core asks for a width; the converter takes a scale: its own size first, then the scale that width needs. */
    public function testADocumentIsDrawnAtTheWidthAsked(): void
    {
        $rasterizer = new SvgRasterizer(\sys_get_temp_dir(), self::stub());

        $this->assertSame([512, 256], PngDimensions::read((string) $this->atWidth($rasterizer, self::SMALL_SVG, 512)));
        $this->assertSame([100, 50], PngDimensions::read((string) $this->atWidth($rasterizer, self::SMALL_SVG, 100)), 'its own width: drawn once');
        $this->assertNull($this->atWidth($rasterizer, '<svg>broken', 512));
    }

    public function testWhatIsNoPngIsNothing(): void
    {
        $bin = new TempProject();
        $bin->write('rsvg-convert', "#!/bin/sh\ncat >/dev/null\nprintf 'not a png'\n");
        \chmod($bin->path('rsvg-convert'), permissions: 0o755);

        $this->assertNull($this->atWidth(new SvgRasterizer(\sys_get_temp_dir(), $bin->path('rsvg-convert')), self::SMALL_SVG, 512));
    }

    public function testAFailureSurfacesTheConvertersStderr(): void
    {
        [$png, $error] = $this->render(new SvgRasterizer(\sys_get_temp_dir(), self::stub()), '<svg>broken', null);

        $this->assertNull($png);
        $this->assertStringContainsString('XML parse error', (string) $error);
    }

    public function testANewRenderSupersedesTheInFlightOne(): void
    {
        $rasterizer = new SvgRasterizer(\sys_get_temp_dir(), self::stub());
        $fired = [];
        $onError = static function (string $message): never {
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
        $rasterizer = new SvgRasterizer(\sys_get_temp_dir(), self::stub());
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
        \putenv('PATH=/nonexistent');
        $rasterizer = new SvgRasterizer(\sys_get_temp_dir());
        $error = null;
        $rasterizer->rasterize(
            self::SMALL_SVG,
            null,
            static function (): never {
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
        $this->assertSame(self::stub(), new SvgRasterizer(\sys_get_temp_dir(), self::stub())->binary());
    }

    /** A setting of only spaces is no path: discovery runs, and finds nothing here. */
    public function testABlankConverterPathIsNoPath(): void
    {
        \putenv('PATH=/nonexistent');

        $this->assertNull(new SvgRasterizer(\sys_get_temp_dir(), '   ')->binary());
    }

    public function testAClosurePathIsReReadPerCallSoASettingsEditApplies(): void
    {
        // The plugin hands a closure over its `svgConverter` setting rather
        // than a resolved string, which is what makes the setting live;
        // memoizing the first answer would make it restart-required again.
        \putenv('PATH=/nonexistent');
        $configured = null;
        $rasterizer = new SvgRasterizer(\sys_get_temp_dir(), static function () use (&$configured): ?string {
            return $configured;
        });
        $this->assertNull($rasterizer->binary(), 'nothing configured, nothing on PATH');

        $configured = self::stub();

        $this->assertSame(self::stub(), $rasterizer->binary(), 'the new value is picked up with no rebuild');
    }

    /**
     * Discovery walks PATH for a converter by name, whatever machine this runs
     * on: the real-converter test below skips where none is installed (a CI
     * runner), and discovery must not go untested there.
     */
    public function testDiscoveryFindsAConverterOnThePath(): void
    {
        $bin = new TempProject([], 'porta-svgbin-');
        \symlink(self::stub(), $bin->path('rsvg-convert'));
        \putenv('PATH='.$bin->dir);

        $this->assertSame(SvgConverter::Rsvg, SvgConverter::discover());
        $this->assertSame($bin->path('rsvg-convert'), new SvgRasterizer(\sys_get_temp_dir())->binary());
    }

    public function testARealConverterRendersTheDocument(): void
    {
        $this->skipWithoutSvgConverter();
        $rasterizer = new SvgRasterizer(\sys_get_temp_dir());
        $this->assertNotNull($rasterizer->binary(), 'discovery found the installed converter');

        [$png, $error] = $this->render($rasterizer, self::SMALL_SVG, 2.0);
        $this->assertNull($error);
        $image = new ImageDecoder()->decodeBytes((string) $png);
        $this->assertInstanceOf(ImageData::class, $image, 'a real converter emitted a decodable PNG');
        $this->assertSame([200, 100], [$image->width, $image->height], 'and honoured the scale');
    }
}
