<?php

namespace App\Core\Reports\Models;

class Report extends Model
{
    protected $fillable = [
        'name',
        'type',
        'parameters',
        'schedule',
        'output_format',
        'metadata'
    ];

    protected $casts = [
        'parameters' => 'array',
        'metadata' => 'array',
        'last_run_at' => 'datetime'
    ];
}

class ReportExecution extends Model
{
    protected $fillable = [
        'report_id',
        'status',
        'parameters',
        'output',
        'error',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'parameters' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];
}

namespace App\Core\Reports\Services;

class ReportGenerator
{
    private ReportRepository $repository;
    private DataFetcher $dataFetcher;
    private ReportFormatter $formatter;
    private StorageManager $storage;

    public function generate(Report $report, array $parameters = []): string
    {
        $execution = $this->createExecution($report, $parameters);

        try {
            $data = $this->dataFetcher->fetch($report, $parameters);
            $formatted = $this->formatter->format($data, $report->output_format);
            $path = $this->storage->store($formatted, $report->output_format);
            
            $this->completeExecution($execution, $path);
            return $path;
        } catch (\Exception $e) {
            $this->failExecution($execution, $e);
            throw $e;
        }
    }

    private function createExecution(Report $report, array $parameters): ReportExecution
    {
        return ReportExecution::create([
            'report_id' => $report->id,
            'status' => 'running',
            'parameters' => $parameters,
            'started_at' => now()
        ]);
    }
}

class DataFetcher
{
    private QueryBuilder $queryBuilder;
    private DatabaseConnection $connection;

    public function fetch(Report $report, array $parameters = []): array
    {
        $query = $this->queryBuilder->build($report, $parameters);
        return $this->connection->execute($query);
    }
}

class ReportFormatter
{
    private array $formatters = [];

    public function addFormatter(string $format, FormatterInterface $formatter): void
    {
        $this->formatters[$format] = $formatter;
    }

    public function format(array $data, string $format): mixed
    {
        if (!isset($this->formatters[$format])) {
            throw new UnsupportedFormatException("Format {$format} not supported");
        }

        return $this->formatters[$format]->format($data);
    }
}

interface FormatterInterface
{
    public function format(array $data): mixed;
}

class PDFFormatter implements FormatterInterface
{
    private PDFGenerator $generator;

    public function format(array $data): string
    {
        return $this->generator->generate($data);
    }
}

class ExcelFormatter implements FormatterInterface
{
    private SpreadsheetGenerator $generator;

    public function format(array $data): string
    {
        return $this->generator->generate($data);
    }
}

namespace App\Core\Reports\Http\Controllers;

class ReportController extends Controller
{
    private ReportGenerator $generator;
    private ReportRepository $repository;

    public function generate(Request $request, int $id): JsonResponse
    {
        try {
            $report = $this->repository->find($id);
            $path = $this->generator->generate($report, $request->all());
            
            return response()->json([
                'status' => 'success',
                'path' => $path
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function executions(int $id): JsonResponse
    {
        $executions = ReportExecution::where('report_id', $id)
            ->latest()
            ->paginate(15);
            
        return response()->json($executions);
    }
}

namespace App\Core\Reports\Jobs;

class GenerateReportJob implements ShouldQueue
{
    private Report $report;
    private array $parameters;

    public function handle(ReportGenerator $generator): void
    {
        $generator->generate($this->report, $this->parameters);
    }
}

namespace App\Core\Reports\Console;

class GenerateReportCommand extends Command
{
    protected $signature = 'report:generate {id} {--parameters=}';

    public function handle(ReportGenerator $generator, ReportRepository $repository): void
    {
        $report = $repository->find($this->argument('id'));
        $parameters = json_decode($this->option('parameters') ?? '{}', true);

        try {
            $path = $generator->generate($report, $parameters);
            $this->info("Report generated successfully: {$path}");
        } catch (\Exception $e) {
            $this->error("Report generation failed: " . $e->getMessage());
        }
    }
}
