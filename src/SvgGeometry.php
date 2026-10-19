<?php

declare(strict_types=1);

namespace Porta\ImageViewer;

/** Shape bounds and affine transforms shared by SVG source picking and diagram semantic maps. */
final class SvgGeometry
{
    /**
     * A shape's box in its own user space, [minX, minY, maxX, maxY], or null
     * for what draws nothing of its own (a group, the root).
     *
     * @return array{float, float, float, float}|null
     */
    public static function box(\DOMElement $el): ?array
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
        $size = self::length(self::property($el, 'font-size', '16'));
        $x = self::length($el->getAttribute('x')) + self::offset($el->getAttribute('dx'), $size);
        $y = self::length($el->getAttribute('y')) + self::offset($el->getAttribute('dy'), $size);
        $width = \mb_strlen(\trim($el->textContent)) * $size * 0.6;
        $left = $x
        - match (self::property($el, 'text-anchor', 'start')) {
            'middle' => $width / 2,
            'end' => $width,
            default => 0.0,
        };
        $baseline = self::property($el, 'dominant-baseline', self::property($el, 'alignment-baseline', 'auto'));
        $top = $y
        - match ($baseline) {
            'central', 'middle' => $size * 0.625,
            'hanging', 'text-before-edge' => 0.0,
            'text-after-edge' => $size * 1.25,
            default => $size,
        };

        return [$left, $top, $left + $width, $top + ($size * 1.25)];
    }

    /** Presentation attributes inherit; inline declarations override them on the same element. */
    private static function property(\DOMElement $element, string $name, string $fallback): string
    {
        for ($cursor = $element; $cursor instanceof \DOMElement; $cursor = $cursor->parentNode) {
            $style = [];
            \preg_match_all('/(?:^|;)\s*'.\preg_quote($name, delimiter: '/').'\s*:\s*([^;]+)/i', $cursor->getAttribute('style'), $style);
            $value = \trim(\array_last($style[1]) ?? $cursor->getAttribute($name));
            if ('' !== $value) {
                return $value;
            }
        }

        return $fallback;
    }

    private static function offset(string $value, float $size): float
    {
        $amount = self::length($value);
        $value = \strtolower(\trim($value));

        return $amount
        * match (true) {
            \str_ends_with($value, 'em') => $size,
            \str_ends_with($value, 'ex') => $size / 2,
            default => 1.0,
        };
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
    public static function viewBoxMatrix(\DOMElement $root, float $width, float $height): array
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
    public static function transform(string $list): array
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
    public static function multiply(array $m, array $n): array
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
    public static function mapBox(array $m, array $box): array
    {
        $corners = [];
        foreach ([[$box[0], $box[1]], [$box[2], $box[1]], [$box[0], $box[3]], [$box[2], $box[3]]] as [$px, $py]) {
            $corners[] = [($m[0] * $px) + ($m[2] * $py) + $m[4], ($m[1] * $px) + ($m[3] * $py) + $m[5]];
        }

        return self::bounds($corners) ?? $box; // four corners: never empty
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
}
