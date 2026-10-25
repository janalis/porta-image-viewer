<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\SvgGeometry;

/**
 * The geometry under click-to-source, number for number: a hit test only asks
 * whether a point falls in a box padded by a slop, so a box a pixel off, or
 * a matrix with two terms swapped, still answers yes there.
 */
final class SvgGeometryTest extends TestCase
{
    public function testEachShapeIsBoundedByItsOwnAttributes(): void
    {
        $this->assertSame(
            [
                'rect' => [1.0, 2.0, 4.0, 6.0],
                'circle' => [7.0, 17.0, 13.0, 23.0],
                'ellipse' => [7.0, 15.0, 13.0, 25.0],
                'line' => [2.0, 1.0, 5.0, 8.0],
                // An odd number left over is no point.
                'polygon' => [1.0, 2.0, 7.0, 9.0],
                'group' => null,
            ],
            [
                'rect' => self::box('<rect x="1" y="2px" width="3" height="4"/>'),
                'circle' => self::box('<circle cx="10" cy="20" r="3"/>'),
                'ellipse' => self::box('<ellipse cx="10" cy="20" rx="3" ry="5"/>'),
                'line' => self::box('<line x1="5" y1="1" x2="2" y2="8"/>'),
                'polygon' => self::box('<polygon points="1,9 4,2 7,5 8"/>'),
                'group' => self::box('<g/>'),
            ],
        );
    }

    /** Two letters, however many bytes, between spaces that are not text; an offset in EMs and an anchor with a space after it. */
    public function testATextIsMeasuredInItsTrimmedLettersAndSpelledUnits(): void
    {
        $this->assertSame([4.0, 10.0, 16.0, 22.5], self::box('<text x="0" y="20" font-size="10" dx="1EM " style="text-anchor: middle ">  éé  </text>'));
    }

    /**
     * @return iterable<string, array{string, array{float, float, float, float}}>
     */
    public static function paths(): iterable
    {
        yield 'a relative move starts from the origin' => ['m5,5 l2,2', [5.0, 5.0, 7.0, 7.0]];
        yield 'an absolute line ignores where the pen is' => ['M2 3 L4 5', [2.0, 3.0, 4.0, 5.0]];
        yield 'a close before any move returns to the origin' => ['l3 3 Z l1 1', [1.0, 1.0, 3.0, 3.0]];
        yield 'a close returns to the last move, not the last point' => ['M2 2 L5 9 z l-1 -1', [1.0, 1.0, 5.0, 9.0]];
        yield 'h and v take one number each' => ['M1 1 h4 v6 h-2', [1.0, 1.0, 5.0, 7.0]];
        yield 'a curve is bounded by its control points too' => ['M10 10 c-5 9 8 -4 2 3', [5.0, 6.0, 18.0, 19.0]];
        yield 'an upper-case curve takes six numbers' => ['M0 0 C-5 9 8 -4 2 3', [-5.0, -4.0, 8.0, 9.0]];
        yield 'a curve repeats on its implicit numbers' => ['M0 0 c1 1 2 2 3 3 1 1 2 2 3 3', [0.0, 0.0, 6.0, 6.0]];
        yield 'an arc bulges by its radii, right' => ['M10 10 a3 4 0 0 1 6 0', [7.0, 6.0, 16.0, 14.0]];
        yield 'and left' => ['M10 10 a3 4 0 0 1 -6 0', [4.0, 6.0, 13.0, 14.0]];
        yield 'an upper-case arc takes seven numbers' => ['M10 10 A3 4 0 0 1 4 10', [4.0, 6.0, 13.0, 14.0]];
        yield 'numbers after a close are pairs from where it closed' => ['M1 1 z 3 5', [1.0, 1.0, 4.0, 6.0]];
    }

    /** @param array{float, float, float, float} $bounds */
    #[DataProvider('paths')]
    public function testAPathIsBoundedByEveryPointItsCommandsReach(string $d, array $bounds): void
    {
        $this->assertSame($bounds, self::box('<path d="'.$d.'"/>'));
    }

    public function testTheViewBoxIsFittedAndCentredIntoThePicture(): void
    {
        $identity = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

        $this->assertSame([2.0, 0.0, 0.0, 2.0, -20.0, 10.0], self::viewBox('10 20 100 50', 200.0, 200.0), 'fitted by its width, centred down');
        $this->assertSame([2.0, 0.0, 0.0, 2.0, 30.0, -40.0], self::viewBox('10 20 50 100', 200.0, 200.0), 'fitted by its height, centred across');
        foreach (['', '0 0 100', '0 0 0 50', '0 0 100 0', '0 0 -5 50'] as $box) {
            $this->assertSame($identity, self::viewBox($box, 200.0, 200.0), "'{$box}' maps nothing");
        }
    }

    /**
     * @return iterable<string, array{string, array{float, float, float, float, float, float}}>
     */
    public static function transforms(): iterable
    {
        $identity = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        yield 'matrix' => ['matrix(2 3 4 5 6 7)', [2.0, 3.0, 4.0, 5.0, 6.0, 7.0]];
        yield 'matrix of nothing' => ['matrix()', $identity];
        yield 'translate' => ['translate(3 4)', [1.0, 0.0, 0.0, 1.0, 3.0, 4.0]];
        yield 'translate along x' => ['translate(3)', [1.0, 0.0, 0.0, 1.0, 3.0, 0.0]];
        yield 'translate of nothing' => ['translate()', $identity];
        yield 'scale' => ['scale(2 3)', [2.0, 0.0, 0.0, 3.0, 0.0, 0.0]];
        yield 'scale alike' => ['scale(2)', [2.0, 0.0, 0.0, 2.0, 0.0, 0.0]];
        yield 'scale of nothing' => ['scale()', $identity];
        yield 'skewX' => ['skewX(45)', [1.0, 0.0, 1.0, 1.0, 0.0, 0.0]];
        yield 'skewX of nothing' => ['skewX()', $identity];
        yield 'skewY' => ['skewY(45)', [1.0, 1.0, 0.0, 1.0, 0.0, 0.0]];
        yield 'skewY of nothing' => ['skewY()', $identity];
        yield 'rotate' => ['rotate(90)', [0.0, 1.0, -1.0, 0.0, 0.0, 0.0]];
        yield 'rotate about a point' => ['rotate(90 10 20)', [0.0, 1.0, -1.0, 0.0, 30.0, 10.0]];
        yield 'rotate of nothing' => ['rotate()', $identity];
        yield 'a list, applied left to right' => ['translate(10) scale(2 3)', [2.0, 0.0, 0.0, 3.0, 10.0, 0.0]];
    }

    /** @param array{float, float, float, float, float, float} $matrix */
    #[DataProvider('transforms')]
    public function testEachTransformIsItsOwnMatrix(string $list, array $matrix): void
    {
        $this->assertEqualsWithDelta($matrix, SvgGeometry::transform($list), 1e-9);
    }

    public function testMatricesMultiplyAsAffineMaps(): void
    {
        $this->assertSame(
            [31.0, 46.0, 39.0, 58.0, 52.0, 76.0],
            SvgGeometry::multiply([1.0, 2.0, 3.0, 4.0, 5.0, 6.0], [7.0, 8.0, 9.0, 10.0, 11.0, 12.0]),
        );
    }

    /** Skewed, each corner lands somewhere no other one does: the box is all four of them bounded. */
    public function testABoxIsMappedThroughAllFourCorners(): void
    {
        $this->assertSame([0.0, 0.0, 4.0, 2.0], SvgGeometry::mapBox([1.0, 0.0, 1.0, 1.0, 0.0, 0.0], [0.0, 0.0, 2.0, 2.0]));
        $this->assertSame([-2.0, 0.0, 2.0, 2.0], SvgGeometry::mapBox([1.0, 0.0, -1.0, 1.0, 0.0, 0.0], [0.0, 0.0, 2.0, 2.0]));
    }

    /** @return array{float, float, float, float}|null */
    private static function box(string $markup): ?array
    {
        $document = new \DOMDocument();
        $document->loadXML('<svg xmlns="http://www.w3.org/2000/svg">'.$markup.'</svg>');
        $element = $document->documentElement?->firstElementChild;
        self::assertInstanceOf(\DOMElement::class, $element);

        return SvgGeometry::box($element);
    }

    /** @return array{float, float, float, float, float, float} */
    private static function viewBox(string $box, float $width, float $height): array
    {
        $document = new \DOMDocument();
        $document->loadXML('<svg xmlns="http://www.w3.org/2000/svg"'.('' === $box ? '' : ' viewBox="'.$box.'"').'/>');
        self::assertInstanceOf(\DOMElement::class, $document->documentElement);

        return SvgGeometry::viewBoxMatrix($document->documentElement, $width, $height);
    }
}
