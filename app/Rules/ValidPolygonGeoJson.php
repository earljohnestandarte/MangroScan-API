<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidPolygonGeoJson implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ($value['type'] ?? null) !== 'Polygon') {
            $fail('The :attribute field must be a GeoJSON Polygon.');

            return;
        }

        $rings = $value['coordinates'] ?? null;

        if (! is_array($rings) || $rings === []) {
            $fail('The :attribute field must contain at least one linear ring.');

            return;
        }

        foreach ($rings as $ring) {
            if (! is_array($ring) || count($ring) < 4) {
                $fail('Each :attribute linear ring must contain at least four positions.');

                return;
            }

            foreach ($ring as $position) {
                if (! is_array($position)
                    || count($position) !== 2
                    || ! is_numeric($position[0])
                    || ! is_numeric($position[1])
                    || (float) $position[0] < -180
                    || (float) $position[0] > 180
                    || (float) $position[1] < -90
                    || (float) $position[1] > 90) {
                    $fail('Each :attribute position must contain valid longitude and latitude.');

                    return;
                }
            }

            $first = array_map('floatval', $ring[0]);
            $last = array_map('floatval', $ring[array_key_last($ring)]);

            if ($first !== $last) {
                $fail('Each :attribute linear ring must be closed.');

                return;
            }

            $distinct = array_unique(array_map(
                static fn (array $position): string => (float) $position[0].','.(float) $position[1],
                array_slice($ring, 0, -1),
            ));

            if (count($distinct) < 3) {
                $fail('Each :attribute linear ring must contain at least three distinct positions.');

                return;
            }

            if ($this->selfIntersects($ring)) {
                $fail('Each :attribute linear ring must not self-intersect.');

                return;
            }
        }
    }

    /** @param list<array{0: numeric, 1: numeric}> $ring */
    private function selfIntersects(array $ring): bool
    {
        $lastSegment = count($ring) - 2;

        for ($first = 0; $first <= $lastSegment; $first++) {
            for ($second = $first + 1; $second <= $lastSegment; $second++) {
                if ($second === $first + 1 || ($first === 0 && $second === $lastSegment)) {
                    continue;
                }

                if ($this->segmentsIntersect(
                    $ring[$first],
                    $ring[$first + 1],
                    $ring[$second],
                    $ring[$second + 1],
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{0: numeric, 1: numeric}  $a
     * @param  array{0: numeric, 1: numeric}  $b
     * @param  array{0: numeric, 1: numeric}  $c
     * @param  array{0: numeric, 1: numeric}  $d
     */
    private function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        $orientation = static function (array $p, array $q, array $r): float {
            return ((float) $q[1] - (float) $p[1]) * ((float) $r[0] - (float) $q[0])
                - ((float) $q[0] - (float) $p[0]) * ((float) $r[1] - (float) $q[1]);
        };

        $o1 = $orientation($a, $b, $c);
        $o2 = $orientation($a, $b, $d);
        $o3 = $orientation($c, $d, $a);
        $o4 = $orientation($c, $d, $b);

        return (($o1 > 0 && $o2 < 0) || ($o1 < 0 && $o2 > 0))
            && (($o3 > 0 && $o4 < 0) || ($o3 < 0 && $o4 > 0));
    }
}
