<?php

namespace App\Services\Report;

use App\Models\Report;

class SimplePdfRenderer
{
    /** @param array<string, mixed> $summary
     * @param  array<string, mixed>  $options
     */
    public function render(Report $report, array $summary, array $options): string
    {
        [$width, $height] = $this->dimensions(
            (string) ($options['page_size'] ?? 'a4'),
            (string) ($options['orientation'] ?? 'portrait'),
        );
        $lines = [
            ['MangroScan Monitoring Report', 18],
            [$report->report_title, 14],
            ['Report type: '.str_replace('_', ' ', $report->report_type), 10],
            ['Mission: '.$summary['mission']['mission_code'].' - '.$summary['mission']['mission_title'], 10],
            ['Site: '.$summary['site']['site_code'].' - '.$summary['site']['site_name'], 10],
            ['', 8],
        ];
        foreach ([
            'Audience' => $report->audience,
            'Summary' => $report->summary,
            'Interpretation' => $report->interpretation,
            'Limitations' => $report->limitations,
            'Recommendations' => $report->recommendations,
        ] as $heading => $content) {
            if ($content !== null) {
                $lines[] = [$heading, 12];
                foreach ($this->wrap($content, 88) as $line) {
                    $lines[] = [$line, 9];
                }
                $lines[] = ['', 6];
            }
        }
        if (($options['include_source_summary'] ?? true) === true) {
            $lines[] = ['Canonical source summary', 12];
            $lines[] = [sprintf(
                'Trees: %d total; %d species; %d validated; %d unvalidated; %d rejected',
                $summary['trees']['total'], $summary['trees']['distinct_species'],
                $summary['trees']['validated'], $summary['trees']['unvalidated'], $summary['trees']['rejected'],
            ), 9];
            $lines[] = [sprintf(
                'Validation: %d sessions; %d completed; %d ground-truth records',
                $summary['validation']['sessions'], $summary['validation']['completed_sessions'],
                $summary['validation']['ground_truth_records'],
            ), 9];
            $accuracy = collect($summary['accuracy'])->map(
                fn (?string $value, string $key): string => str_replace('_', ' ', $key).': '.($value ?? 'n/a'),
            )->implode('; ');
            foreach ($this->wrap('Accuracy: '.$accuracy, 88) as $line) {
                $lines[] = [$line, 9];
            }
        }

        return $this->document(array_slice($lines, 0, 52), $width, $height);
    }

    /** @return array{float, float} */
    private function dimensions(string $pageSize, string $orientation): array
    {
        $dimensions = $pageSize === 'letter' ? [612.0, 792.0] : [595.28, 841.89];

        return $orientation === 'landscape' ? [$dimensions[1], $dimensions[0]] : $dimensions;
    }

    /** @return list<string> */
    private function wrap(string $value, int $width): array
    {
        $plain = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return explode("\n", wordwrap($plain, $width, "\n", true));
    }

    /** @param list<array{string, int}> $lines */
    private function document(array $lines, float $width, float $height): string
    {
        $content = "BT\n";
        $y = $height - 54;
        foreach ($lines as [$line, $size]) {
            $content .= sprintf("/F1 %d Tf\n54 %.2F Td\n(%s) Tj\n-54 0 Td\n", $size, $y, $this->escape($line));
            $y = -($size + 7);
        }
        $content .= "ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', $width, $height),
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function escape(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii === false ? '' : $ascii);
    }
}
