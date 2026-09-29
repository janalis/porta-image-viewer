<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\ImageViewer\ZoomPanModel;

final class ZoomPanModelTest extends TestCase
{
    public function testFitPicksTheTighterAxisAndLabelsItself(): void
    {
        $landscape = new ZoomPanModel(100, 50);
        $this->assertSame(0.4, $landscape->effectiveScale(40, 40), 'width is the tighter axis');
        $this->assertSame('Fit (40%)', $landscape->zoomLabel(40, 40));

        $portrait = new ZoomPanModel(50, 100);
        $this->assertSame(0.4, $portrait->effectiveScale(40, 40), 'height is the tighter axis');

        $tiny = new ZoomPanModel(4, 4);
        $this->assertSame(10.0, $tiny->effectiveScale(40, 40), 'fit upscales small images');
        $this->assertSame('Fit (1000%)', $tiny->zoomLabel(40, 40));
    }

    public function testZoomWalksTheLadderAndClampsAtBothEnds(): void
    {
        $view = new ZoomPanModel(100, 50); // fit at 40×40 = 0.4

        $view->zoomIn(40, 40);
        $this->assertSame('50%', $view->zoomLabel(40, 40), 'first step above 40%');
        $view->zoomOut(40, 40);
        $view->zoomOut(40, 40);
        $this->assertSame('13%', $view->zoomLabel(40, 40), '12.5% rounds to 13');
        $view->zoomOut(40, 40);
        $this->assertSame('13%', $view->zoomLabel(40, 40), 'clamped at the bottom step');

        for ($i = 0; $i < 12; ++$i) {
            $view->zoomIn(40, 40);
        }
        $this->assertSame('800%', $view->zoomLabel(40, 40), 'clamped at the top step');

        $view->actualSize(40, 40);
        $this->assertSame('100%', $view->zoomLabel(40, 40));
        $view->fit();
        $this->assertSame('Fit (40%)', $view->zoomLabel(40, 40));
    }

    public function testActualSizeCentersAndPanClampsAtEveryEdge(): void
    {
        $view = new ZoomPanModel(100, 50);
        $view->actualSize(40, 40); // viewport 40×40 image-px; pannable 0..60 / 0..10

        $rect = $view->visibleRect(40, 40);
        $this->assertSame(30, $rect['srcX'], 'zoom kept the fit center (50) centered');
        $this->assertSame(5, $rect['srcY']);

        $view->panBy(1000, 1000, 40, 40);
        $rect = $view->visibleRect(40, 40);
        $this->assertSame([60, 10], [$rect['srcX'], $rect['srcY']], 'clamped bottom-right');

        $view->panBy(-1000, -1000, 40, 40);
        $rect = $view->visibleRect(40, 40);
        $this->assertSame([0, 0], [$rect['srcX'], $rect['srcY']], 'clamped top-left');

        $view->panBy(8, 4, 40, 40);
        $rect = $view->visibleRect(40, 40);
        $this->assertSame([8, 4], [$rect['srcX'], $rect['srcY']], '1:1 cells to pixels at 100%');
    }

    public function testZoomNeverReversesWhenTheFitScaleLiesOutsideTheLadder(): void
    {
        // Small icon: fit ≈ 1050%, above the 800% top rung.
        $icon = new ZoomPanModel(4, 4);
        $icon->zoomIn(42, 42);
        $this->assertSame('Fit (1050%)', $icon->zoomLabel(42, 42), 'zoom in past the top must not shrink to 800%');
        $icon->zoomOut(42, 42);
        $this->assertSame('800%', $icon->zoomLabel(42, 42), 'zoom out from above the ladder lands on the top rung');

        // Big photo: fit ≈ 3%, below the 12.5% bottom rung.
        $photo = new ZoomPanModel(4000, 3000);
        $photo->zoomOut(120, 90);
        $this->assertSame('Fit (3%)', $photo->zoomLabel(120, 90), 'zoom out past the bottom must not enlarge to 13%');
        $photo->zoomIn(120, 90);
        $this->assertSame('13%', $photo->zoomLabel(120, 90), 'zoom in from below the ladder lands on the bottom rung');

        // Same contract through the pointer-anchored path (ctrl+wheel).
        $icon2 = new ZoomPanModel(4, 4);
        $icon2->zoomAt(1, 20, 20, 42, 42);
        $this->assertSame('Fit (1050%)', $icon2->zoomLabel(42, 42));
        $photo2 = new ZoomPanModel(4000, 3000);
        $photo2->zoomAt(-1, 60, 45, 120, 90);
        $this->assertSame('Fit (3%)', $photo2->zoomLabel(120, 90));
    }

    public function testPanAtMaximumZoomStillMovesAtLeastOnePixel(): void
    {
        $view = new ZoomPanModel(1000, 1000);
        $view->actualSize(40, 40);
        for ($i = 0; $i < 12; ++$i) {
            $view->zoomIn(40, 40); // to 800%: a 2-cell arrow step is 0.25 px
        }
        $this->assertSame('800%', $view->zoomLabel(40, 40));

        $before = $view->visibleRect(40, 40)['srcX'];
        $view->panBy(2, 0, 40, 40);
        $this->assertSame($before + 1, $view->visibleRect(40, 40)['srcX'], 'a plain arrow pans 1 px, not 0');
        $view->panBy(-2, 0, 40, 40);
        $this->assertSame($before, $view->visibleRect(40, 40)['srcX'], 'both directions respected');

        $beforeY = $view->visibleRect(40, 40)['srcY'];
        $view->panBy(0, 2, 40, 40); // 0.25 px vertically → still moves 1 px
        $this->assertSame($beforeY + 1, $view->visibleRect(40, 40)['srcY'], 'vertical minimum movement too');
    }

    public function testZoomAtKeepsTheAnchoredImagePointUnderThePointer(): void
    {
        $view = new ZoomPanModel(100, 50);
        $view->actualSize(40, 40); // srcX/srcY = 30/5 (centered), no letterbox pads

        // The image point under cell (10,10) is (40,15); zooming in must keep it there.
        $view->zoomAt(1, 10, 10, 40, 40); // 100% → 200%
        $this->assertSame('200%', $view->zoomLabel(40, 40));
        $rect = $view->visibleRect(40, 40);
        $this->assertSame([35, 10], [$rect['srcX'], $rect['srcY']], 'srcX + 10/2 = 40, srcY + 10/2 = 15 — anchor fixed');

        // Zooming back out at the same anchor returns exactly to the original view.
        $view->zoomAt(-1, 10, 10, 40, 40);
        $this->assertSame('100%', $view->zoomLabel(40, 40));
        $rect = $view->visibleRect(40, 40);
        $this->assertSame([30, 5], [$rect['srcX'], $rect['srcY']]);

        // The ladder still clamps at both ends.
        for ($i = 0; $i < 12; ++$i) {
            $view->zoomAt(1, 10, 10, 40, 40);
        }
        $this->assertSame('800%', $view->zoomLabel(40, 40));
    }

    public function testMagnifyAtGlidesOffTheLadderAndKeepsTheAnchorFixed(): void
    {
        $view = new ZoomPanModel(100, 50);
        $view->actualSize(40, 40); // 100%, srcX/srcY = 30/5

        // A gesture's worth of small increments lands between the rungs.
        $view->magnifyAt(1.25, 10, 10, 40, 40);
        $this->assertSame('125%', $view->zoomLabel(40, 40), 'a factor, not a rung');
        $view->magnifyAt(1.2, 10, 10, 40, 40);
        $this->assertSame('150%', $view->zoomLabel(40, 40));

        // The anchored image point (40,15) is still under cell (10,10): at 150%
        // that is srcX + 10/1.5 = 40 and srcY + 10/1.5 = 15, both rounded.
        $rect = $view->visibleRect(40, 40);
        $this->assertSame([33, 8], [$rect['srcX'], $rect['srcY']]);

        // And gliding back down returns to where it started.
        $view->magnifyAt(1 / 1.5, 10, 10, 40, 40);
        $this->assertSame('100%', $view->zoomLabel(40, 40));
        $rect = $view->visibleRect(40, 40);
        $this->assertSame([30, 5], [$rect['srcX'], $rect['srcY']]);
    }

    public function testMagnifyAtClampsToTheLaddersEndsAndIsInertThere(): void
    {
        $view = new ZoomPanModel(100, 50);
        $view->actualSize(40, 40);

        $view->magnifyAt(100.0, 10, 10, 40, 40); // way past the 800% ceiling
        $this->assertSame('800%', $view->zoomLabel(40, 40), 'the ladder still bounds a free zoom');
        $before = $view->visibleRect(40, 40);
        $view->magnifyAt(2.0, 10, 10, 40, 40); // already there: nothing moves
        $this->assertSame($before, $view->visibleRect(40, 40));

        $view->magnifyAt(0.001, 10, 10, 40, 40); // way past the 12.5% floor
        $this->assertSame('13%', $view->zoomLabel(40, 40), 'the 12.5% floor, rounded for display');
    }

    public function testMagnifyAtHonoursACustomLaddersBounds(): void
    {
        $view = new ZoomPanModel(10_000, 10_000, [0.031_25, 0.062_5, 0.125]);

        $view->magnifyAt(100.0, 10, 10, 80, 48);
        $this->assertSame('13%', $view->zoomLabel(80, 48), 'the graph ladder tops out at 12.5%');
        $view->magnifyAt(0.000_1, 10, 10, 80, 48);
        $this->assertSame('3%', $view->zoomLabel(80, 48), 'and bottoms out at its own 3.125% floor');
    }

    public function testZoomAtClampsALetterboxAnchorToTheImageEdge(): void
    {
        $view = new ZoomPanModel(2, 2); // fit = 2 in an 8×4 pane, padCols = 2
        $view->zoomAt(1, 0, 0, 8, 4); // pointer over the left letterbox

        $this->assertSame('400%', $view->zoomLabel(8, 4), 'next step above the 200% fit');
        $this->assertSame(0, $view->visibleRect(8, 4)['srcX'], 'anchor clamped to the left edge');
    }

    public function testVisibleRectCentersASmallerImageAndFitNeverPans(): void
    {
        $view = new ZoomPanModel(2, 2);
        $rect = $view->visibleRect(8, 4); // fit = min(8/2, 4/2) = 2 → out 4×4
        $this->assertSame(['srcX' => 0, 'srcY' => 0, 'padCols' => 2, 'padHalfRows' => 0, 'outCols' => 4, 'outHalfRows' => 4], $rect);

        $view->panBy(5, 5, 8, 4);
        $this->assertSame(0, $view->visibleRect(8, 4)['srcX'], 'a fully visible image cannot pan');
    }

    public function testACustomZoomLadderIsWalkedInBothDirections(): void
    {
        // A "graph" whose fit scale (~0.008) sits far below the default ladder's 12.5% floor.
        $view = new ZoomPanModel(10_000, 10_000, [0.031_25, 0.062_5, 0.125]);

        $view->zoomOut(80, 48);
        $this->assertSame('Fit (0%)', $view->zoomLabel(80, 48), 'no rung below fit: zoomOut stays at fit');

        $view->zoomIn(80, 48);
        $this->assertSame('3%', $view->zoomLabel(80, 48), 'zoomIn lands on the custom floor rung');

        $view->zoomIn(80, 48);
        $view->zoomIn(80, 48);
        $view->zoomIn(80, 48);
        $this->assertSame('13%', $view->zoomLabel(80, 48), 'the custom ceiling rung caps zoomIn');
    }

    public function testDrawnInPixelsTheLadderCountsScreenPixels(): void
    {
        // 800×600 fits a 100×60 pane at 0.125 — with 16-pixel cells that is
        // twice life size, so the zoom can go BELOW fit, down to 12.5% of it.
        $view = new ZoomPanModel(800, 600);
        $view->setPixelsPerColumn(16.0);
        $this->assertSame('Fit (200%)', $view->zoomLabel(100, 120));

        $view->zoomOut(100, 120);
        $this->assertSame('100%', $view->zoomLabel(100, 120));
        $this->assertEqualsWithDelta(1 / 16, $view->effectiveScale(100, 120), 1e-9);

        $view->actualSize(100, 120);
        $this->assertEqualsWithDelta(1 / 16, $view->effectiveScale(100, 120), 1e-9, 'actual size is one image pixel per screen pixel');

        $view->magnifyAt(1000.0, 0, 0, 100, 120);
        $this->assertSame('800%', $view->zoomLabel(100, 120), 'the ladder top, in screen pixels');
    }

    public function testAColumnIsNeverWorthLessThanOnePixel(): void
    {
        $view = new ZoomPanModel(800, 600);
        $view->setPixelsPerColumn(0.5);

        $view->actualSize(100, 120);
        $this->assertSame('100%', $view->zoomLabel(100, 120));
        $this->assertEqualsWithDelta(1.0, $view->effectiveScale(100, 120), 1e-9);
    }
}
