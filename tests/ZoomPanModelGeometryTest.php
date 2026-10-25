<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\ZoomPanModel;

/**
 * Where each gesture leaves the view, to the pixel, on a 100 × 60 picture —
 * what {@see ZoomPanModelTest} reads through labels and inequalities. The
 * state is read as its fingerprint: scale, pan x, pan y.
 */
final class ZoomPanModelGeometryTest extends TestCase
{
    /**
     * From the fit (a third, from the height) to 1:1 centres on the picture's
     * middle (50, 30); doubling keeps that middle; an odd pane rounds its
     * half-column and half-row to the nearest pixel.
     */
    public function testAZoomKeepsTheMiddleOfWhatWasShown(): void
    {
        $view = new ZoomPanModel(100, 60);
        $view->actualSize(40, 20);
        $this->assertSame('1:30:20', $view->stateKey());
        $view->zoomIn(40, 20);
        $this->assertSame('2:40:25', $view->stateKey());
        $view->panBy(9, 9, 40, 20);
        $view->fit();
        $view->actualSize(40, 20);
        $this->assertSame('1:30:20', $view->stateKey(), 'a fit forgets the pan: 1:1 after it is the middle again');

        $odd = new ZoomPanModel(100, 60);
        $odd->actualSize(41, 21); // 50 − 20.5 and 30 − 10.5, both rounded up
        $this->assertSame('1:30:20', $odd->stateKey());
        $odd->zoomIn(41, 21); // 50.5 − 10.25 and 30.5 − 5.25, both rounded down
        $this->assertSame('2:40:25', $odd->stateKey());
    }

    /** At 200% three cells are a pixel and a half, rounded away from zero both ways. */
    public function testAPanRoundsItsPixelsToTheNearest(): void
    {
        $view = new ZoomPanModel(100, 60);
        $view->actualSize(40, 20);
        $view->zoomIn(40, 20);

        $view->panBy(3, 3, 40, 20);
        $this->assertSame('2:42:27', $view->stateKey());
        $view->panBy(-3, -3, 40, 20);
        $this->assertSame('2:40:25', $view->stateKey());
    }

    /** At 800% a cell is an eighth of a pixel, and a pan still moves one, either way. */
    public function testAPanAtTheTopZoomMovesAPixelEitherWay(): void
    {
        $view = self::at800(40);
        $start = $view->visibleRect(40, 20);

        $view->panBy(1, 1, 40, 20);
        $this->assertSame([$start['srcX'] + 1, $start['srcY'] + 1], self::origin($view, 40));
        $view->panBy(-1, -1, 40, 20);
        $view->panBy(-1, -1, 40, 20);
        $this->assertSame([$start['srcX'] - 1, $start['srcY'] - 1], self::origin($view, 40));
    }

    /**
     * Panned as far as it goes, the view stops where its own width — the pane
     * over the zoom — reaches the picture's edge, rounded up: 100 − 45 / 8 =
     * 94.375 → 95 across, 60 − 20 / 8 = 57.5 → 58 down.
     */
    public function testAPanStopsAtThePicturesFarEdge(): void
    {
        $view = self::at800(45);
        $view->panBy(10_000, 10_000, 45, 20);
        $this->assertSame('8:95:58', $view->stateKey());

        $doubled = new ZoomPanModel(100, 60);
        $doubled->actualSize(40, 20);
        $doubled->zoomIn(40, 20);
        $doubled->panBy(10_000, 10_000, 40, 20);
        $this->assertSame('2:80:50', $doubled->stateKey(), 'at 200%, the pane is 20 × 10 pixels of it');
    }

    /** The span drawn is the picture's size at the zoom, to the nearest cell — and never less than one. */
    public function testTheDrawnSpanRoundsToTheNearestCell(): void
    {
        $view = new ZoomPanModel(7, 7, [0.3, 0.5]);
        $view->zoomOut(100, 100);
        $this->assertSame([4, 4], self::span($view), '3.5 at 50%');
        $view->zoomOut(100, 100);
        $this->assertSame([2, 2], self::span($view), '2.1 at 30%');

        $dot = new ZoomPanModel(1, 1);
        $dot->actualSize(1, 1);
        $dot->zoomOut(1, 1);
        $dot->zoomOut(1, 1);
        $dot->zoomOut(1, 1);
        $this->assertSame('0.125', \explode(':', $dot->stateKey())[0]);
        $this->assertSame(1, $dot->visibleRect(1, 1)['outCols'], 'an eighth of a pixel is still a cell');
        $this->assertSame(1, $dot->visibleRect(1, 1)['outHalfRows']);
    }

    /**
     * Ctrl+wheel over a letterboxed fit: the point under the pointer is counted
     * from the picture's own edge, past the letterbox — across, where the fit
     * leaves three columns either side, and down, where it leaves three
     * half-rows above.
     */
    public function testAWheelZoomCountsTheAnchorFromThePicturesEdge(): void
    {
        $wide = new ZoomPanModel(100, 60);
        $wide->zoomAt(1, 23, 10, 40, 20); // column 23 is pixel 60, half-row 10 is pixel 30
        $this->assertSame('0.5:14:10', $wide->stateKey());

        $tall = new ZoomPanModel(60, 100);
        $tall->zoomAt(1, 10, 23, 20, 40);
        $this->assertSame('0.5:10:14', $tall->stateKey());
    }

    /** Two views whose pans spell the same digits are told apart. */
    public function testTheFingerprintSeparatesItsNumbers(): void
    {
        $a = self::at800(40);
        $b = self::at800(40);
        [$x, $y] = self::origin($a, 40);
        $a->panBy(8 * (12 - $x), 8 * (3 - $y), 40, 20);
        $b->panBy(8 * (1 - $x), 8 * (23 - $y), 40, 20);

        $this->assertSame([12, 3], self::origin($a, 40));
        $this->assertSame([1, 23], self::origin($b, 40));
        $this->assertNotSame($a->stateKey(), $b->stateKey());
    }

    private static function at800(int $cols): ZoomPanModel
    {
        $view = new ZoomPanModel(100, 60);
        $view->actualSize($cols, 20);
        $view->zoomIn($cols, 20);
        $view->zoomIn($cols, 20);
        $view->zoomIn($cols, 20);

        return $view;
    }

    /** @return array{int, int} */
    private static function origin(ZoomPanModel $view, int $cols): array
    {
        $rect = $view->visibleRect($cols, 20);

        return [$rect['srcX'], $rect['srcY']];
    }

    /** @return array{int, int} */
    private static function span(ZoomPanModel $view): array
    {
        $rect = $view->visibleRect(100, 100);

        return [$rect['outCols'], $rect['outHalfRows']];
    }
}
