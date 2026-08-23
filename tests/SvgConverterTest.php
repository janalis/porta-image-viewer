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

    public function testForBinaryDefaultsToRsvgForAnUnknownName(): void
    {
        $this->assertSame(SvgConverter::Rsvg, SvgConverter::forBinary('/usr/local/bin/some-other-tool'));
    }
}
