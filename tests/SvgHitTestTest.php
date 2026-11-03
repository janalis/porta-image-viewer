<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\SvgHitTest;

/**
 * A point on an SVG's picture back to the tag that drew it: every shape the
 * reader aims at, through the transforms and the viewBox, topmost first.
 */
final class SvgHitTestTest extends TestCase
{
    /** The start tag found at ($x, $y) of a $w × $h picture, as its first 12 bytes, or null. */
    private static function at(string $svg, float $x, float $y, int $w = 100, int $h = 100): ?string
    {
        $offset = SvgHitTest::elementAt($svg, $w, $h, $x, $y);

        return null === $offset ? null : \substr($svg, $offset, length: 12);
    }

    private static function svg(string $body, string $root = ''): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" '.$root.'>'.$body.'</svg>';
    }

    public function testEveryKindOfShapeIsFoundByItsBox(): void
    {
        $svg = self::svg(
            '<rect x="0" y="0" width="10" height="10"/>'
            .'<circle cx="30" cy="5" r="4"/>'
            .'<ellipse cx="50" cy="5" rx="6" ry="3"/>'
            .'<line x1="60" y1="0" x2="70" y2="10"/>'
            .'<polyline points="75,0 85,10"/>'
            .'<polygon points="88,0 98,0 93,10"/>'
            .'<image x="0" y="20" width="10" height="10"/>'
            .'<use x="20" y="20" width="10" height="10"/>'
            .'<foreignObject x="40" y="20" width="10" height="10"/>'
            .'<text x="60" y="30" font-size="10">Hi</text>'
            .'<text x="80" y="36">Hi</text>',
        );

        $this->assertSame('<rect x="0" ', self::at($svg, 5, 5));
        $this->assertSame('<circle cx="', self::at($svg, 30, 8));
        $this->assertSame('<ellipse cx=', self::at($svg, 55, 6));
        $this->assertSame('<line x1="60', self::at($svg, 65, 5));
        $this->assertSame('<polyline po', self::at($svg, 80, 5));
        $this->assertSame('<polygon poi', self::at($svg, 93, 5));
        $this->assertSame('<image x="0"', self::at($svg, 5, 25));
        $this->assertSame('<use x="20" ', self::at($svg, 25, 25));
        $this->assertSame('<foreignObje', self::at($svg, 45, 25));
        $this->assertSame('<text x="60"', self::at($svg, 65, 25), 'an em above its baseline, 0.6 em a character');
        $this->assertSame('<text x="80"', self::at($svg, 85, 26), 'at the default 16px when no size is given');
        $this->assertNull(self::at($svg, 30, 60), 'nothing drawn there');
    }

    public function testThePathsEveryCommandFindsItsBounds(): void
    {
        $absolute = self::svg('<path d="M10 10 L20 10 H30 V20 C30 25 35 25 35 30 S40 35 40 40 Q45 45 50 45 T55 50 A5 5 0 0 1 60 55 Z"/>');
        $this->assertSame('<path d="M10', self::at($absolute, 58, 52), 'the arc\'s end, past every other point');
        $this->assertNull(self::at($absolute, 80, 80));

        $relative = self::svg('<path d="m10 10 l10 0 h10 v10 c0 5 5 5 5 10 s5 5 5 10 q5 5 10 5 t5 5 a5 5 0 0 1 5 5 z m50 0 10 10"/>');
        $this->assertSame('<path d="m10', self::at($relative, 58, 52), 'relative steps resolved from the current point');
        $this->assertSame('<path d="m10', self::at($relative, 68, 18), 'a move after a close starts where the path started, and its extra pair is a line');
    }

    public function testTransformsCarryAShapeToWhereItIsDrawn(): void
    {
        $shape = '<rect x="0" y="0" width="10" height="10"/>';
        foreach ([
            'translate(50,50)' => [55, 55],
            'translate(50)' => [55, 5],
            'scale(2)' => [15, 15],
            'scale(2,3)' => [15, 25],
            'matrix(1,0,0,1,40,40)' => [45, 45],
            'rotate(90)' => [-5, 5],
            'rotate(180,10,10)' => [15, 15],
            'skewX(45)' => [15, 9],
            'skewY(45)' => [9, 15],
            'translate(50,0) scale(2)' => [65, 15],
        ] as $transform => [$x, $y]) {
            $svg = self::svg('<g transform="'.$transform.'">'.$shape.'</g>');
            $this->assertSame('<rect x="0" ', self::at($svg, $x, $y), $transform);
        }
        $this->assertNull(self::at(self::svg('<g transform="translate(50,50)">'.$shape.'</g>'), 5, 5), 'not where it would be untransformed');
    }

    public function testTheViewBoxMapsUserUnitsOntoThePicturesPixels(): void
    {
        $svg = self::svg('<rect x="10" y="10" width="10" height="10"/>', 'viewBox="0 0 50 25"');

        $this->assertSame('<rect x="10"', self::at($svg, 30, 30, 100, 50), 'scaled 2×');
        $this->assertNull(self::at($svg, 15, 15, 100, 50));
        $this->assertSame('<rect x="10"', self::at($svg, 30, 55, 100, 100), 'centred in a taller picture: 25 rows of letterbox above');
        $this->assertSame('<rect x="10"', self::at(self::svg('<rect x="10" y="10" width="10" height="10"/>', 'viewBox="0 0 0 0"'), 15, 15), 'a degenerate viewBox is no viewBox');
    }

    public function testTheTopmostShapeWinsAndUndrawnSubtreesDoNot(): void
    {
        $svg =
            "<?xml version=\"1.0\"?>\n<!DOCTYPE svg>\n<!-- <rect> in a comment -->\n"
            .self::svg(
                '<defs><rect id="d" x="0" y="0" width="100" height="100"/></defs><rect x="0" y="0" width="100" height="100"/><![CDATA[<rect x="9">]]><g><circle cx="50" cy="50" r="10"/></g>',
            );

        $this->assertSame('<circle cx="', self::at($svg, 50, 50), 'drawn last, so on top');
        $this->assertSame('<rect x="0" ', self::at($svg, 5, 5), 'the drawn rect — the one in <defs> is not drawn where it stands');
        $this->assertSame("\n<!-- <rect>", \substr($svg, (int) \strpos($svg, needle: "\n<!--"), length: 12), 'the fixture\'s comment names a tag that must not be counted');
    }

    public function testWhatIsNotAnSvgHasNothingToFind(): void
    {
        $this->assertNull(SvgHitTest::elementAt('', 10, 10, 1, 1));
        $this->assertNull(SvgHitTest::elementAt('<svg><rect', 10, 10, 1, 1));
    }

    public function testAHairlineCanBeHitALittleBesideItButNotFarther(): void
    {
        $svg = self::svg('<line x1="10" y1="50" x2="90" y2="50"/>');

        $this->assertSame('<line x1="10', self::at($svg, 50, 51), 'a pixel off is on it');
        $this->assertNull(self::at($svg, 50, 52), 'two pixels off is not');
    }

    /** The slop is a pixel and a half on every side of a box, that far and no farther; inside the box needs none of it. */
    public function testTheSlopReachesExactlyAPixelAndAHalfOnEverySide(): void
    {
        $svg = self::svg('<rect x="10" y="10" width="10" height="10"/>');
        $hits = static fn (float $x, float $y): bool => null !== self::at($svg, $x, $y);

        $this->assertSame(
            [true, true, true, true, true, false, false, false, false],
            [$hits(8.5, y: 15), $hits(21.5, y: 15), $hits(15, y: 8.5), $hits(15, y: 21.5), $hits(10.5, y: 10.5), $hits(8.4, y: 15), $hits(21.6, y: 15), $hits(15, y: 8.4), $hits(15, y: 21.6)],
        );
    }

    public function testHitTestingLeavesLibxmlAsItFoundIt(): void
    {
        // Collecting errors already — switching it off would clear them anyway.
        $previous = \libxml_use_internal_errors(true);
        try {
            SvgHitTest::elementAt('<svg><g></svg>', 10, 10, 1, 1);

            $this->assertSame([], \libxml_get_errors(), 'the broken document\'s errors are not left to the caller');
            $this->assertTrue(\libxml_use_internal_errors(false), 'and its error mode is as it was');
            SvgHitTest::elementAt('<svg><g></svg>', 10, 10, 1, 1);
            $this->assertFalse(\libxml_use_internal_errors(true));
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }
    }

    public function testAViewBoxFitsInsideThePictureNotAcrossIt(): void
    {
        // A 50 × 25 viewBox on a 200 × 50 picture: scaled 2×, not 4×, and
        // centred across — so the rect sits at x 70..90, y 20..40.
        $svg = self::svg('<rect x="10" y="10" width="10" height="10"/>', 'viewBox="0 0 50 25"');

        $this->assertSame('<rect x="10"', self::at($svg, 85, 30, 200, 50));
        $this->assertNull(self::at($svg, 45, 30, 200, 50), 'where a 4× slice would have put it');
    }

    public function testAPairAfterAMoveIsALineAndACloseGoesBackToTheStart(): void
    {
        // m10 10 then 20 20 is a LINE to (30, 30); z returns to (10, 10); l5 0 ends at (15, 10).
        $svg = self::svg('<path d="m10 10 20 20 z l5 0"/>');

        $this->assertSame('<path d="m10', self::at($svg, 15, 10));
        $this->assertNull(self::at($svg, 34, 30), 'had the pair been a move, or z stayed put, the path would reach (35, 30)');
    }

    public function testAShapeWithNoPointsHasNoBoxToHit(): void
    {
        $svg = self::svg('<rect x="0" y="0" width="100" height="100"/><path d=""/><polygon/>');

        $this->assertSame('<rect x="0" ', self::at($svg, 5, 5), 'the empty path and polygon, drawn later, are not on top of anything');
    }
}
