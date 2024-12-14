<?php

namespace App\Core\Notification\Analytics\Services;

use PhpOffice\PhpSpreadsheet\Style\{Fill, Border, Alignment};
use PhpOffice\PhpSpreadsheet\Chart\{Chart, DataSeries, DataSeriesValues, Title, Legend};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait ReportSheetGenerationTrait
{
    private function addDeliveryMetricsSheet(array $metrics): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Delivery Metrics');

        // Add headers
        $headers = ['Period', 'Total Notifications', 'Delivered', 'Opened', 'Clicked', 'Converted', 'Avg Delivery Time'];
        $sheet->fromArray($headers, null, 'A1');

        // Add data
        $row = 2;
        foreach ($metrics['metrics'] as $metric) {
            $sheet->fromArray([
                $metric->period,
                $metric->total_notifications,
                $metric->delivered,
                $metric->opened,
                $metric->clicked,
                $metric->converted,
                round($metric->avg_delivery_time, 2)
            ], null, "A{$row}");
            $row++;
        }

        // Style the sheet
        $this->styleSheet($sheet, count($headers), $row - 1);

        // Add summary section
        $this->addMetricsSummary($sheet, $metrics['summary'], $row + 1);
    }

    private function addPerformanceMetricsSheet(array $metrics): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Performance Metrics');

        // Add headers
        $headers = ['Type', 'Avg Delivery Time', 'Min Delivery Time', 'Max Delivery Time', 'Total', 'Failures'];
        $sheet->fromArray($headers, null, 'A1');

        // Add data
        $row = 2;
        foreach ($metrics['metrics'] as $metric) {
            $sheet->fromArray([
                $metric->type,
                round($metric->avg_delivery_time, 2),
                round($metric->min_delivery_time, 2),
                round($metric->max_delivery_time, 2),
                $metric->total_notifications,
                $metric->failures
            ], null, "A{$row}");
            $row++;
        }

        // Style the sheet
        $this->styleSheet($sheet, count($headers), $row - 1);

        // Add aggregates section
        $this->addPerformanceAggregates($sheet, $metrics['aggregates'], $row + 1);
    }

    private function addEngagementMetricsSheet(array $metrics): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Engagement Metrics');

        // Add headers
        $headers = ['Period', 'Type', 'Total', 'Opened', 'Clicked', 'Converted'];
        $sheet->fromArray($headers, null, 'A1');

        // Add data
        $row = 2;
        foreach ($metrics['metrics'] as $metric) {
            $sheet->fromArray([
                $metric->period,
                $metric->type,
                $metric->total,
                $metric->opened,
                $metric->clicked,
                $metric->converted
            ], null, "A{$row}");
            $row++;
        }

        // Style the sheet
        $this->styleSheet($sheet, count($headers), $row - 1);

        // Add engagement rates
        $this->addEngagementRates($sheet, $metrics['rates'], $row + 1);
        
        // Add trends analysis
        $this->addEngagementTrends($sheet, $metrics['trends'], $row + 6);
    }

    private function addCharts(array $report): void
    {
        // Delivery Trends Chart
        $this->addDeliveryTrendsChart($report['delivery_metrics']);

        // Performance Distribution Chart
        $this->addPerformanceDistributionChart($report['performance_metrics']);

        // Engagement Funnel Chart
        $this->addEngagementFunnelChart($report['engagement_metrics']);
    }

    private function styleSheet(Worksheet $sheet, int $columns, int $rows): void
    {
        // Style headers
        $headerRange = "A1:" . chr(64 + $columns) . "1";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ]
        ]);

        // Style data cells
        $dataRange = "A2:" . chr(64 + $columns) . $rows;
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN
                ]
            ]
        ]);

        // Auto-size columns
        for ($col = 'A'; $col <= chr(64 + $columns); $col++) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function addMetricsSummary(Worksheet $sheet, array $summary, int $startRow): void
    {
        $sheet->setCellValue("A{$startRow}", 'Summary');
        $sheet->getStyle("A{$startRow}")->getFont()->setBold(true);
        $startRow++;

        foreach ($summary as $metric => $value) {
            $sheet->setCellValue("A{$startRow}", ucwords(str_replace('_', ' ', $metric)));
            $sheet->setCellValue("B{$startRow}", is_numeric($value) ? round($value, 2) . '%' : $value);
            $startRow++;
        }
    }

    private function addPerformanceAggregates(Worksheet $sheet, array $aggregates, int $startRow): void
    {
        $sheet->setCellValue("A{$startRow}", 'Performance Aggregates');
        $sheet->getStyle("A{$startRow}")->getFont()->setBold(true);
        $startRow++;

        foreach ($aggregates as $metric => $value) {
            if (is_array($value)) {
                $sheet->setCellValue("A{$startRow}", ucwords(str_replace('_', ' ', $metric)));
                $startRow++;
                foreach ($value as $subMetric => $subValue) {
                    $sheet->setCellValue("B{$startRow}", ucwords($subMetric));
                    $sheet->setCellValue("C{$startRow}", round($subValue, 2) . '%');
                    $startRow++;
                }
            } else {
                $sheet->setCellValue("A{$startRow}", ucwords(str_replace('_', ' ', $metric)));
                $sheet->setCellValue("B{$startRow}", round($value, 2) . '%');
                $startRow++;
            }
        }
    }

    private function addEngagementRates(Worksheet $sheet, array $rates, int $startRow): void
    {
        $sheet->setCellValue("A{$startRow}", 'Engagement Rates by Type');
        $sheet->getStyle("A{$startRow}")->getFont()->setBold(true);
        $startRow++;

        foreach ($rates as $type => $metrics) {
            $sheet->setCellValue("A{$startRow}", $type);
            $sheet->getStyle("A{$startRow}")->getFont()->setBold(true);
            $startRow++;

            foreach ($metrics as $metric => $value) {
                $sheet->setCellValue("B{$startRow}", ucwords(str_replace('_', ' ', $metric)));
                $sheet->setCellValue("C{$startRow}", round($value, 2) . '%');
                $startRow++;
            }
        }
    }

    private function addEngagementTrends(Worksheet $sheet, array $trends, int $startRow): void
    {
        $sheet->setCellValue("A{$startRow}", 'Engagement Trends Analysis');
        $sheet->getStyle("A{$startRow}")->getFont()->setBold(true);
        $startRow++;

        foreach ($trends as $type => $trend) {
            $sheet->setCellValue("A{$startRow}", $type);
            $sheet->getStyle("A{$startRow}")->getFont()->setBold(true);
            $startRow++;

            foreach ($trend as $metric => $value) {
                if (is_array($value)) {
                    $sheet->setCellValue("B{$startRow}", ucwords(str_replace('_', ' ', $metric)));
                    $startRow++;
                    foreach ($value as $key => $val) {
                        $sheet->setCellValue("C{$startRow}", ucwords(str_replace('_', ' ', $key)));
                        $sheet->setCellValue("D{$startRow}", is_numeric($val) ? round($val, 2) . '%' : $val);
                        $startRow++;
                    }
                } else {
                    $sheet->setCellValue("B{$startRow}", ucwords(str_replace('_', ' ', $metric)));
                    $sheet->setCellValue("C{$startRow}", is_numeric($value) ? round($value, 2) . '%' : $value);
                    $startRow++;
                }
            }
        }
    }
}
