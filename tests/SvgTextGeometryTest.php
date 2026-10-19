<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\SvgGeometry;

final class SvgTextGeometryTest extends TestCase
{
    private static function bounds(string $markup): array
    {
        $document = new \DOMDocument();
        $document->loadXML('<svg xmlns="http://www.w3.org/2000/svg">'.$markup.'</svg>');

        return SvgGeometry::box($document->getElementsByTagName('text')->item(0));
    }

    public function testTextAnchorsBaselineAndRelativeOffsetsMatchTheirSvgPlacement(): void
    {
        $this->assertSame([76.0, 125.0, 124.0, 145.0], self::bounds('<text x="100" y="119" dy="1em" text-anchor="middle" dominant-baseline="middle">Hello</text>'));
        $this->assertSame([71.2, 34.0, 100.0, 64.0], self::bounds('<g font-size="24"><text x="100" y="10" dy="2em" alignment-baseline="auto" style="text-anchor:end">Hi</text></g>'));
        $this->assertSame([24.0, 20.0, 48.0, 45.0], self::bounds('<g style="font-size:20px"><text x="20" y="30" dx="0.2em" dy="-1ex" dominant-baseline="hanging">Hi</text></g>'));
        $this->assertSame([20.0, 20.0, 32.0, 45.0], self::bounds('<text x="20" y="45" font-size="10" style="font-size:12px; font-size:20px" dominant-baseline="text-after-edge">A</text>'));
        $this->assertSame([10.0, 12.0, 22.0, 24.5], self::bounds('<text x="10" y="10" dy="2px" font-size="10" dominant-baseline="text-before-edge">Hi</text>'));
        $this->assertSame([4.0, 13.75, 16.0, 26.25], self::bounds('<text x="10" y="20" font-size="10" text-anchor="middle" dominant-baseline="central">Hi</text>'));
    }
}
