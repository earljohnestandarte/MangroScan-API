<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class CanonicalTreeExportRenderer
{
    private const HEADERS = [
        'tree_observation_id', 'tree_code', 'mission_id', 'flight_session_id', 'validation_status',
        'species_id', 'scientific_name', 'common_name', 'detection_confidence',
        'final_height_meters', 'final_estimated_age_years', 'longitude', 'latitude',
    ];

    public function __construct(private readonly StoredZipBuilder $zip) {}

    /** @param array<string, mixed> $filters
     * @return array{bytes:string,extension:string}
     */
    public function render(string $missionId, string $format, array $filters): array
    {
        $rows = $this->rows($missionId, $filters);
        $bytes = match ($format) {
            'csv' => $this->csv($rows),
            'xlsx' => $this->xlsx($rows),
            'geojson' => $this->geoJson($rows),
            'kml' => $this->kml($rows),
            default => throw new RuntimeException('Unsupported canonical export format.'),
        };

        return ['bytes' => $bytes, 'extension' => $format === 'geojson' ? 'geojson' : $format];
    }

    /** @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function rows(string $missionId, array $filters): array
    {
        $query = DB::table('tree_observations as tree')
            ->leftJoin('mangrove_species as species', 'species.species_id', '=', 'tree.final_species_id')
            ->where('tree.mission_id', $missionId)->whereNull('tree.deleted_at')
            ->select([
                'tree.tree_observation_id', 'tree.tree_code', 'tree.mission_id', 'tree.flight_session_id',
                'tree.validation_status', 'tree.final_species_id as species_id', 'species.scientific_name',
                'species.common_name', 'tree.detection_confidence', 'tree.final_height_meters',
                'tree.final_estimated_age_years',
            ]);
        if (($filters['species_id'] ?? null) !== null) {
            $query->where('tree.final_species_id', $filters['species_id']);
        }
        if (($filters['validation_status'] ?? null) !== null) {
            $query->where('tree.validation_status', $filters['validation_status']);
        }
        if (DB::getDriverName() === 'pgsql') {
            $query->selectRaw('ST_X(tree.tree_location) AS longitude, ST_Y(tree.tree_location) AS latitude');
        } else {
            $query->addSelect('tree.tree_location');
        }

        return $query->orderBy('tree.tree_code')->orderBy('tree.tree_observation_id')->get()
            ->map(function (object $row): array {
                $location = isset($row->tree_location) ? json_decode((string) $row->tree_location, true) : null;

                return [
                    'tree_observation_id' => $row->tree_observation_id,
                    'tree_code' => $row->tree_code,
                    'mission_id' => $row->mission_id,
                    'flight_session_id' => $row->flight_session_id,
                    'validation_status' => $row->validation_status,
                    'species_id' => $row->species_id,
                    'scientific_name' => $row->scientific_name,
                    'common_name' => $row->common_name,
                    'detection_confidence' => $this->decimal($row->detection_confidence, 4),
                    'final_height_meters' => $this->decimal($row->final_height_meters, 2),
                    'final_estimated_age_years' => $this->decimal($row->final_estimated_age_years, 2),
                    'longitude' => isset($row->longitude) ? (float) $row->longitude : ($location['coordinates'][0] ?? null),
                    'latitude' => isset($row->latitude) ? (float) $row->latitude : ($location['coordinates'][1] ?? null),
                ];
            })->all();
    }

    /** @param list<array<string, mixed>> $rows */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to allocate export stream.');
        }
        fputcsv($stream, self::HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $key): mixed => $this->csvValue($row[$key]), self::HEADERS), ',', '"', '');
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes === false ? '' : $bytes;
    }

    /** @param list<array<string, mixed>> $rows */
    private function geoJson(array $rows): string
    {
        return json_encode([
            'type' => 'FeatureCollection',
            'features' => array_map(fn (array $row): array => [
                'type' => 'Feature',
                'id' => $row['tree_observation_id'],
                'geometry' => $row['longitude'] === null || $row['latitude'] === null ? null : [
                    'type' => 'Point', 'coordinates' => [$row['longitude'], $row['latitude']],
                ],
                'properties' => array_diff_key($row, array_flip(['longitude', 'latitude'])),
            ], $rows),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param list<array<string, mixed>> $rows */
    private function kml(array $rows): string
    {
        $placemarks = '';
        foreach ($rows as $row) {
            if ($row['longitude'] === null || $row['latitude'] === null) {
                continue;
            }
            $description = $this->xml(json_encode(array_diff_key($row, array_flip(['longitude', 'latitude'])), JSON_THROW_ON_ERROR));
            $placemarks .= '<Placemark><name>'.$this->xml((string) $row['tree_code']).'</name><description>'.$description
                .'</description><Point><coordinates>'.$row['longitude'].','.$row['latitude'].',0</coordinates></Point></Placemark>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'
            .$placemarks.'</Document></kml>';
    }

    /** @param list<array<string, mixed>> $rows */
    private function xlsx(array $rows): string
    {
        $allRows = [self::HEADERS, ...array_map(
            fn (array $row): array => array_map(fn (string $key): mixed => $row[$key], self::HEADERS),
            $rows,
        )];
        $sheet = '';
        foreach ($allRows as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $column => $value) {
                $reference = $this->column($column + 1).($rowIndex + 1);
                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                } else {
                    $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t>'.$this->xml((string) ($value ?? '')).'</t></is></c>';
                }
            }
            $sheet .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }

        return $this->zip->build([
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Trees" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>',
        ]);
    }

    private function column(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function xml(string $value): string
    {
        $valid = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';

        return htmlspecialchars($valid, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function csvValue(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[\s]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    private function decimal(mixed $value, int $scale): ?string
    {
        return $value === null ? null : number_format((float) $value, $scale, '.', '');
    }
}
