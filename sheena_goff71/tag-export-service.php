<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Contracts\ExportInterface;
use App\Core\Tag\Exceptions\ExportException;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;

class TagExportService implements ExportInterface
{
    /**
     * @var TagReportingService
     */
    protected TagReportingService $reportingService;

    public function __construct(TagReportingService $reportingService)
    {
        $this->reportingService = $reportingService;
    }

    /**
     * Export tags to CSV format.
     */
    public function exportToCsv(array $filters = []): string
    {
        try {
            $writer = Writer::createFromString();
            
            // Set headers
            $writer->insertOne([
                'ID',
                'Name',
                'Slug',
                'Description',
                'Usage Count',
                'Created At',
                'Updated At'
            ]);

            // Add tag data
            Tag::query()
                ->when($filters, function ($query) use ($filters) {
                    $this->applyFilters($query, $filters);
                })
                ->chunk(100, function ($tags) use ($writer) {
                    $tags->each(function ($tag) use ($writer) {
                        $writer->insertOne([
                            $tag->id,
                            $tag->name,
                            $tag->slug,
                            $tag->description,
                            $tag->contents()->count(),
                            $tag->created_at->toDateTimeString(),
                            $tag->updated_at->toDateTimeString()
                        ]);
                    });
                });

            return $writer->toString();
        } catch (\Exception $e) {
            throw new ExportException("Failed to export tags to CSV: {$e->getMessage()}");
        }
    }

    /**
     * Export tags to JSON format.
     */
    public function exportToJson(array $filters = []): string
    {
        try {
            $tags = Tag::query()
                ->when($filters, function ($query) use ($filters) {
                    $this->applyFilters($query, $filters);
                })
                ->get()
                ->map(function ($tag) {
                    return [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                        'description' => $tag->description,
                        'usage_count' => $tag->contents()->count(),
                        'created_at' => $tag->created_at->toDateTimeString(),
                        'updated_at' => $tag->updated_at->toDateTimeString()
                    ];
                });

            return json_encode($tags, JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            throw new ExportException("Failed to export tags to JSON: {$e->getMessage()}");
        }
    }

    /**
     * Export tag report to PDF.
     */
    public function exportReportToPdf(array $filters = []): string
    {
        try {
            $report = $this->reportingService->generateUsageReport($filters);
            
            $pdf = new \TCPDF();
            $pdf->AddPage();
            
            // Add report header
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'Tag Usage Report', 0, 1, 'C');
            
            // Add report content
            $pdf->SetFont('helvetica', '', 12);
            $this->addReportContent($pdf, $report);
            
            return $pdf->Output('tag_report.pdf', 'S');
        } catch (\Exception $e) {
            throw new ExportException("Failed to export report to PDF: {$e->getMessage()}");
        }
    }

    /**
     * Apply filters to query.
     */
    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['period'])) {
            $query->where('created_at', '>=', now()->sub($filters['period']));
        }

        if (isset($filters['usage_min'])) {
            $query->has('contents', '>=', $filters['usage_min']);
        }

        if (isset($filters['search'])) {
            $query->where('name', 'LIKE', "%{$filters['search']}%");
        }
    }

    /**
     * Add report content to PDF.
     */
    protected function addReportContent($pdf, $report): void
    {
        // Add summary section
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Summary', 0, 1);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->MultiCell(0, 10, "Total Tags: {$report->totalTags}\nActive Tags: {$report->activeTagsCount}\nUnused Tags: {$report->unusedTagsCount}");

        // Add top tags section
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Top Tags', 0, 1);
        $pdf->SetFont('helvetica', '', 12);
        foreach ($report->topTags as $tag) {
            $pdf->Cell(0, 10, "{$tag->name}: {$tag->usage_count} uses", 0, 1);
        }

        // Add usage trends section
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Usage Trends', 0, 1);
        // Add trend chart here
    }
}
