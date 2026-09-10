<?php

declare(strict_types=1);

namespace Porta\ImageViewer\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageViewerWidget;
use Porta\Tests\Support\FakeRasterizer;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\RendersExactWidth;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The image pane may not render a row wider than the pane it was given.
 *
 * This plugin's share of {@see \Porta\Tests\Unit\Widget\NarrowPaneRenderTest},
 * which is a core test over core widgets and had therefore never seen this
 * widget. The same sweep over the git plugin found three live crashes; this one
 * found none, which is worth having written down rather than assumed.
 *
 * The sweep's own vacuity guard is what makes that a real answer: a widget with
 * nothing to draw renders no rows, and a loop over no rows passes by having
 * nothing to look at.
 */
final class NarrowImagePaneRenderTest extends TestCase
{
    use RendersExactWidth;

    /** A 64x8 gradient, the shape the pane's own tests use for sampling. */
    private static function gradient(): ImageData
    {
        $gd = imagecreatetruecolor(64, height: 8);
        \assert(false !== $gd, description: 'the truecolor image was created');
        for ($x = 0; $x < 64; ++$x) {
            for ($y = 0; $y < 8; ++$y) {
                imagesetpixel($gd, $x, $y, (int) imagecolorallocate($gd, $x * 4, green: 0, blue: 0));
            }
        }

        return new ImageData($gd, 64, 8, 123);
    }

    /**
     * The pane showing an image, the same pane focused (the focus frame is its
     * own row assembler), and the empty state.
     *
     * @return iterable<string, array{AbstractWidget}>
     */
    public static function imagePanes(): iterable
    {
        $shown = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'), scheduler: new FakeScheduler());
        $shown->show('/p/a.png', self::gradient());
        yield 'image' => [$shown];

        $focused = new ImageViewerWidget(new FakeRasterizer(), new Translator('en'), scheduler: new FakeScheduler());
        $focused->show('/p/a.png', self::gradient());
        $focused->setFocused(true);
        yield 'image, focused' => [$focused];

        yield 'no image' => [new ImageViewerWidget(new FakeRasterizer(), new Translator('en'))];
    }

    #[DataProvider('imagePanes')]
    public function testTheImagePaneFitsEveryWidthDownToOne(AbstractWidget $widget): void
    {
        $this->assertFitsEveryWidth($widget);
    }
}
