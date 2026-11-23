<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\SvgConverter;

final class SvgConverterTest extends TestCase
{
    public function testIsSvgPathMatchesTheExtensionCaseInsensitively(): void
    {
        $this->assertTrue(SvgConverter::isSvgPath('a/icon.svg'));
        $this->assertTrue(SvgConverter::isSvgPath('LOGO.SVG'));
        $this->assertFalse(SvgConverter::isSvgPath('photo.png'));
        $this->assertFalse(SvgConverter::isSvgPath('svg'), 'no extension');
    }

    public function testForBinaryMatchesEachKnownNameByItsBasename(): void
    {
        $this->assertSame(SvgConverter::Rsvg, SvgConverter::forBinary('/usr/bin/rsvg-convert'));
        $this->assertSame(SvgConverter::Magick, SvgConverter::forBinary('/opt/homebrew/bin/magick'));
        $this->assertSame(SvgConverter::Convert, SvgConverter::forBinary('/usr/bin/convert'));
    }

    public function testEachConverterTakesTheScaleInItsOwnFlag(): void
    {
        $this->assertSame(['-f', 'png'], SvgConverter::Rsvg->arguments(null));
        $this->assertSame(['-f', 'png', '-z', '2.5'], SvgConverter::Rsvg->arguments(2.5));
        $this->assertSame(['-background', 'none', 'svg:-', 'png:-'], SvgConverter::Magick->arguments(null), 'the document\'s own size');
        $this->assertSame(['-background', 'none', '-density', '240', 'svg:-', 'png:-'], SvgConverter::Convert->arguments(2.5), 'a density relative to the 96-dpi default');
    }

    public function testForBinaryDefaultsToRsvgForAnUnknownName(): void
    {
        $this->assertSame(SvgConverter::Rsvg, SvgConverter::forBinary('/usr/local/bin/some-other-tool'));
    }
}
