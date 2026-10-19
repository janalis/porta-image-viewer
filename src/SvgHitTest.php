<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/**
 * Which element of an SVG a point on its picture belongs to — what
 * click-to-source needs from a drawing: the reader clicked a shape and wants
 * the tag that wrote it.
 *
 * A converter draws the SVG; nothing tells us what it drew where. So this
 * reads the markup itself: every shape's box in its own user space, carried
 * through the `transform`s above it, and through the root's `viewBox` onto
 * the document's pixels. The topmost shape whose box holds the point wins —
 * the one drawn last. A box is not the shape's outline (a diagonal line's
 * box is a rectangle), so this finds the shape a reader aims at, not the one
 * a renderer would antialias there.
 *
 * Elements are counted in document order, which is the order their start
 * tags appear in the text, so the answer is the byte offset of that tag —
 * right even when the whole file is one line.
 */
final class SvgHitTest
{
    /** Subtrees that are referenced, not drawn where they stand. */
    private const array NOT_DRAWN = ['defs', 'clipPath', 'mask', 'symbol', 'marker', 'pattern', 'linearGradient', 'radialGradient', 'filter', 'style', 'script', 'title', 'desc', 'metadata'];

    /** How far outside a box a click still counts, in document pixels — a hairline is hard to hit exactly. */
    private const float SLOP = 1.5;

    /**
     * The byte offset of the start tag of the shape drawn at document pixel
     * ($x, $y) of a picture $width × $height pixels, or null when the point
     * is on no shape — or the text is not an SVG this can read.
     */
    public static function elementAt(string $svg, int $width, int $height, float $x, float $y): ?int
    {
        $dom = new \DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        $loaded = '' !== \trim($svg) && $dom->loadXML($svg, \LIBXML_NONET);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        $root = $loaded ? $dom->documentElement : null;
        if (null === $root) {
            return null;
        }
        $index = 0;
        $hit = null;
        self::walk($root, SvgGeometry::viewBoxMatrix($root, $width, $height), $x, $y, $index, $hit);

        return null === $hit ? null : self::startTags($svg)[$hit] ?? null;
    }

    /**
     * Visit $element and its descendants in document order, counting every
     * element (drawn or not, so the count matches the start tags), and keep
     * the last drawn shape whose box holds the point. A subtree drawn only by
     * reference is counted whole and not looked into.
     *
     * @param array{float, float, float, float, float, float} $matrix user space → document pixels
     */
    private static function walk(\DOMElement $element, array $matrix, float $x, float $y, int &$index, ?int &$hit): void
    {
        if (\in_array($element->localName, self::NOT_DRAWN, strict: true)) {
            $index += 1 + $element->getElementsByTagName('*')->length;

            return;
        }
        $self = $index++;
        $matrix = SvgGeometry::multiply($matrix, SvgGeometry::transform($element->getAttribute('transform')));
        $box = SvgGeometry::box($element);
        if (null !== $box && self::holds(SvgGeometry::mapBox($matrix, $box), $x, $y)) {
            $hit = $self;
        }
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            self::walk($child, $matrix, $x, $y, $index, $hit);
        }
    }

    /** @param array{float, float, float, float} $box */
    private static function holds(array $box, float $x, float $y): bool
    {
        return $x >= ($box[0] - self::SLOP) && $x <= ($box[2] + self::SLOP) && $y >= ($box[1] - self::SLOP) && $y <= ($box[3] + self::SLOP);
    }

    /**
     * The byte offset of every element's start tag, in document order —
     * comments, CDATA, processing instructions and the doctype skipped, since
     * none of them is an element.
     *
     * @return list<int>
     */
    private static function startTags(string $svg): array
    {
        $tags = [];
        \preg_match_all('/<!--.*?-->|<!\[CDATA\[.*?\]\]>|<\?.*?\?>|<![^>]*>|<([A-Za-z_][\w:.-]*)/s', $svg, $tags, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $offsets = [];
        foreach ($tags as $tag) {
            if ('' === ($tag[1][0] ?? '')) {
                continue; // a comment, CDATA, an instruction: no element
            }
            $offsets[] = (int) $tag[0][1];
        }

        return $offsets;
    }
}
