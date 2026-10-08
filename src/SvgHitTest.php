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
        self::walk($root, self::viewBoxMatrix($root, $width, $height), $x, $y, $index, $hit);

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
        $matrix = self::multiply($matrix, self::transform($element->getAttribute('transform')));
        $box = self::box($element);
        if (null !== $box && self::holds(self::mapBox($matrix, $box), $x, $y)) {
            $hit = $self;
        }
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            self::walk($child, $matrix, $x, $y, $index, $hit);
        }
    }

    /**
     * A shape's box in its own user space, [minX, minY, maxX, maxY], or null
     * for what draws nothing of its own (a group, the root).
     *
     * @return array{float, float, float, float}|null
     */
    private static function box(\DOMElement $el): ?array
    {
        $n = static fn (string $name): float => self::length($el->getAttribute($name));

        return match ($el->localName) {
            'rect', 'image', 'use', 'foreignObject' => [$n('x'), $n('y'), $n('x') + $n('width'), $n('y') + $n('height')],
            'circle' => [$n('cx') - $n('r'), $n('cy') - $n('r'), $n('cx') + $n('r'), $n('cy') + $n('r')],
            'ellipse' => [$n('cx') - $n('rx'), $n('cy') - $n('ry'), $n('cx') + $n('rx'), $n('cy') + $n('ry')],
            'line' => self::bounds([[$n('x1'), $n('y1')], [$n('x2'), $n('y2')]]),
            'polyline', 'polygon' => self::bounds(self::pairs(self::numbers($el->getAttribute('points')))),
            'path' => self::bounds(self::pathPoints($el->getAttribute('d'))),
            'text' => self::textBox($el),
            default => null,
        };
    }

    /**
     * A text's box from its anchor and size: glyph metrics would need the
     * font, so an average advance of 0.6 em and the em above the baseline.
     *
     * @return array{float, float, float, float}
     */
    private static function textBox(\DOMElement $el): array
    {
        $size = '' !== $el->getAttribute('font-size') ? self::length($el->getAttribute('font-size')) : 16.0;
        $x = self::length($el->getAttribute('x'));
        $y = self::length($el->getAttribute('y'));

        return [$x, $y - $size, $x + (\mb_strlen(\trim($el->textContent)) * $size * 0.6), $y + ($size * 0.25)];
    }

    /**
     * Every point a path's commands pass through or pull toward — its end
     * points and control points, relative ones resolved — whose bounds hold
     * the curve. An arc contributes its end point, its radii its extent.
     *
     * @return list<array{float, float}>
     */
    private static function pathPoints(string $d): array
    {
        $tokens = [];
        \preg_match_all('/[MmLlHhVvCcSsQqTtAaZz]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $d, $tokens);
        $points = [];
        [$cx, $cy, $sx, $sy] = [0.0, 0.0, 0.0, 0.0];
        $command = 'M';
        $args = [];
        foreach ($tokens[0] as $token) {
            if (\ctype_alpha($token)) {
                $command = $token;
                if ('z' === \strtolower($token)) {
                    [$cx, $cy] = [$sx, $sy];
                }
                continue;
            }
            // @noinspection TypesCastingCanBeUsedInspection -- mago rejects a (float) cast of a string it cannot prove numeric
            $args[] = \floatval($token);
            $arity = ['m' => 2, 'l' => 2, 'h' => 1, 'v' => 1, 'c' => 6, 's' => 4, 'q' => 4, 't' => 2, 'a' => 7][\strtolower($command)] ?? 2;
            if (\count($args) < $arity) {
                continue;
            }
            $relative = \ctype_lower($command);
            $ox = $relative ? $cx : 0.0;
            $oy = $relative ? $cy : 0.0;
            $lower = \strtolower($command);
            // The points the command pulls toward; an arc's radii stand in for its bulge.
            $pulled = match ($lower) {
                'h', 'v' => [],
                'a' => [[$cx - $args[0], $cy - $args[1]], [$cx + $args[0], $cy + $args[1]]],
                default => \array_map(static fn (array $p): array => [$ox + $p[0], $oy + $p[1]], self::pairs($args)),
            };
            [$cx, $cy] = match ($lower) {
                'h' => [$ox + $args[0], $cy],
                'v' => [$cx, $oy + $args[0]],
                default => [$ox + $args[$arity - 2], $oy + $args[$arity - 1]],
            };
            \array_push($points, ...$pulled);
            $points[] = [$cx, $cy];
            if ('m' === $lower) {
                [$sx, $sy] = [$cx, $cy];
                $command = $relative ? 'l' : 'L'; // extra pairs after a move are lines
            }
            $args = [];
        }

        return $points;
    }

    /**
     * The root's `viewBox` onto the picture's pixels, as a converter lays it
     * out by default (`xMidYMid meet`: scaled to fit, centred). Without one,
     * user units ARE pixels.
     *
     * @return array{float, float, float, float, float, float}
     */
    private static function viewBoxMatrix(\DOMElement $root, int $width, int $height): array
    {
        $box = self::numbers($root->getAttribute('viewBox'));
        if (4 !== \count($box) || $box[2] <= 0 || $box[3] <= 0) {
            return [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        }
        $scale = \min($width / $box[2], $height / $box[3]);

        return [$scale, 0.0, 0.0, $scale, (($width - ($box[2] * $scale)) / 2) - ($box[0] * $scale), (($height - ($box[3] * $scale)) / 2) - ($box[1] * $scale)];
    }

    /**
     * A `transform` list as one affine matrix [a, b, c, d, e, f].
     *
     * @return array{float, float, float, float, float, float}
     */
    private static function transform(string $list): array
    {
        $matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $calls = [];
        \preg_match_all('/(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/', $list, $calls, \PREG_SET_ORDER);
        foreach ($calls as [, $name, $params]) {
            $v = self::numbers($params);
            $matrix = self::multiply($matrix, match ($name) {
                'matrix' => [$v[0] ?? 1.0, $v[1] ?? 0.0, $v[2] ?? 0.0, $v[3] ?? 1.0, $v[4] ?? 0.0, $v[5] ?? 0.0],
                'translate' => [1.0, 0.0, 0.0, 1.0, $v[0] ?? 0.0, $v[1] ?? 0.0],
                'scale' => [$v[0] ?? 1.0, 0.0, 0.0, $v[1] ?? $v[0] ?? 1.0, 0.0, 0.0],
                'skewX' => [1.0, 0.0, \tan(\deg2rad($v[0] ?? 0.0)), 1.0, 0.0, 0.0],
                'skewY' => [1.0, \tan(\deg2rad($v[0] ?? 0.0)), 0.0, 1.0, 0.0, 0.0],
                default => self::rotation($v[0] ?? 0.0, $v[1] ?? 0.0, $v[2] ?? 0.0),
            });
        }

        return $matrix;
    }

    /** @return array{float, float, float, float, float, float} */
    private static function rotation(float $degrees, float $cx, float $cy): array
    {
        [$cos, $sin] = [\cos(\deg2rad($degrees)), \sin(\deg2rad($degrees))];

        return [$cos, $sin, -$sin, $cos, $cx - ($cos * $cx) + ($sin * $cy), $cy - ($sin * $cx) - ($cos * $cy)];
    }

    /**
     * @param array{float, float, float, float, float, float} $m
     * @param array{float, float, float, float, float, float} $n
     *
     * @return array{float, float, float, float, float, float}
     */
    private static function multiply(array $m, array $n): array
    {
        return [
            ($m[0] * $n[0]) + ($m[2] * $n[1]),
            ($m[1] * $n[0]) + ($m[3] * $n[1]),
            ($m[0] * $n[2]) + ($m[2] * $n[3]),
            ($m[1] * $n[2]) + ($m[3] * $n[3]),
            ($m[0] * $n[4]) + ($m[2] * $n[5]) + $m[4],
            ($m[1] * $n[4]) + ($m[3] * $n[5]) + $m[5],
        ];
    }

    /**
     * A box's four corners through $m, bounded again.
     *
     * @param array{float, float, float, float, float, float} $m
     * @param array{float, float, float, float}               $box
     *
     * @return array{float, float, float, float}
     */
    private static function mapBox(array $m, array $box): array
    {
        $corners = [];
        foreach ([[$box[0], $box[1]], [$box[2], $box[1]], [$box[0], $box[3]], [$box[2], $box[3]]] as [$px, $py]) {
            $corners[] = [($m[0] * $px) + ($m[2] * $py) + $m[4], ($m[1] * $px) + ($m[3] * $py) + $m[5]];
        }

        return self::bounds($corners) ?? $box; // four corners: never empty
    }

    /** @param array{float, float, float, float} $box */
    private static function holds(array $box, float $x, float $y): bool
    {
        return $x >= ($box[0] - self::SLOP) && $x <= ($box[2] + self::SLOP) && $y >= ($box[1] - self::SLOP) && $y <= ($box[3] + self::SLOP);
    }

    /**
     * @param list<array{float, float}> $points
     *
     * @return array{float, float, float, float}|null
     */
    private static function bounds(array $points): ?array
    {
        if ([] === $points) {
            return null;
        }
        $xs = \array_column($points, 0);
        $ys = \array_column($points, 1);

        return [\min($xs), \min($ys), \max($xs), \max($ys)];
    }

    /**
     * @param list<float> $numbers
     *
     * @return list<array{float, float}>
     */
    private static function pairs(array $numbers): array
    {
        $pairs = [];
        for ($i = 0, $length = \count($numbers); ($i + 1) < $length; $i += 2) {
            $pairs[] = [$numbers[$i], $numbers[$i + 1]];
        }

        return $pairs;
    }

    /** An attribute's length in user units: its leading number, units and all ignored; 0 when it has none. */
    private static function length(string $value): float
    {
        return self::numbers($value)[0] ?? 0.0;
    }

    /** @return list<float> */
    private static function numbers(string $text): array
    {
        $m = [];
        \preg_match_all('/[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $text, $m);

        return \array_values(\array_map(\floatval(...), $m[0] ?? []));
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
