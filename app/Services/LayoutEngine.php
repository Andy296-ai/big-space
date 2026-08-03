<?php

namespace App\Services;

use App\Models\Node;

class LayoutEngine
{
    private const GOLDEN_ANGLE = 2.399963229728653; // radians, ~137.5 degrees

    private const SPACING = 90.0;                   // base radius step between siblings

    /**
     * Calculates 3D coordinates for a new child node using golden-angle spiral placement.
     *
     * @return array{x: float, y: float, z: float}
     */
    public static function placeChild(Node $parent, int $siblingIndex): array
    {
        $angle = $siblingIndex * self::GOLDEN_ANGLE;
        $radius = self::SPACING * sqrt($siblingIndex + 1);

        $x = $parent->pos_x + $radius * cos($angle);
        $y = $parent->pos_y + $radius * sin($angle);
        $z = $parent->pos_z;

        return [
            'x' => round($x, 2),
            'y' => round($y, 2),
            'z' => round($z, 2),
        ];
    }

    /**
     * Nudge position using spiral offset if collision avoidance is required.
     *
     * @return array{x: float, y: float, z: float}
     */
    public static function escapeOffset(int $attempt, float $stepSize = 45.0): array
    {
        $angle = $attempt * self::GOLDEN_ANGLE;
        $radius = $stepSize * sqrt($attempt);

        return [
            'x' => round($radius * cos($angle), 2),
            'y' => round($radius * sin($angle), 2),
            'z' => 0.0,
        ];
    }
}
