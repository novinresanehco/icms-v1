<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\LogRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class LogRepository implements LogRepositoryInterface
{
    protected string $logPath;
    protected array $logLevels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    public function __construct()
    {
        $this->logPath = storage_path('logs');
    }

    public function getLogFiles(): Collection
    {
        $files = File::files($this->logPath);
        
        return collect($files)->map(function ($file) {
            return [
                'name' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size' => $file->getSize(),
                'modified' => Carbon::createFromTimestamp($file->getMTime())
            ];
        })->sortByDesc('modified');
    }

    public function getLogContent(string $filename, int $lines = 1000): Collection
    {
        $path = $this->logPath . '/' . $filename;
        
        if (!File::exists($path)) {
            return collect();
        }

        $content = $this->tailFile($path, $lines);
        return $this->parseLogContent($content);
    }

    public function searchLogs(array $criteria): Collection
    {
        $results = collect();
        $files = $this->getLogFiles();

        foreach ($files as $file) {
            $content = $this->getLogContent($file['name']);
            $filtered = $content->filter(function ($log) use ($criteria) {
                return $this->logMatchesCriteria($log, $criteria);
            });
            $results = $results->merge($filtered);
        }

        return $results->sortByDesc('datetime');
    }

    public function getErrorStats(Carbon $startDate, Carbon $endDate): array
    {
        $stats = array_fill_keys($this->logLevels, 0);
        $logs = $this->searchLogs(['date_range' => [$startDate, $endDate]]);

        foreach ($logs as $log) {
            if (isset($stats[$log['level']])) {
                $stats[$log['level']]++;
            }
        }

        return $stats;
    }

    public function cleanOldLogs(int $daysOld = 30): int
    {
        $count = 0;
        $files = $this->getLogFiles();
        $cutoff = now()->subDays($daysOld);

        foreach ($files as $file) {
            if ($file['modified']->lt($cutoff)) {
                if (File::delete($file['path'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function archiveLogs(string $filename): bool
    {
        $path = $this->logPath . '/' . $filename;
        
        if (!File::exists($path)) {
            return false;
        }

        $archivePath = storage_path('logs/archive/' . date('Y/m'));
        File::ensureDirectoryExists($archivePath);

        return File::move(
            $path,
            $archivePath . '/' . $filename . '.' . now()->format('Y-m-d-H-i-s')
        );
    }

    protected function tailFile(string $path, int $lines): string
    {
        $handle = fopen($path, "r");
        $buffer = 4096;
        $output = '';
        
        fseek($handle, -1, SEEK_END);
        
        for ($lineCount = 0; $lineCount < $lines && ftell($handle) > 0; ) {
            $offset = min(ftell($handle), $buffer);
            fseek($handle, -$offset, SEEK_CUR);
            $output = fread($handle, $offset) . $output;
            fseek($handle, -$offset, SEEK_CUR);
            
            $lineCount += substr_count($output, PHP_EOL);
        }
        
        fclose($handle);
        
        // Get last n lines
        $output = explode(PHP_EOL, $output);
        return implode(PHP_EOL, array_slice($output, -$lines));
    }

    protected function parseLogContent(string $content): Collection
    {
        $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*?)(\{.*\})?$/m';
        $logs = collect();

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $logs->push([
                'datetime' => Carbon::createFromFormat('Y-m-d H:i:s', $match[1]),
                'environment' => $match[2],
                'level' => strtolower($match[3]),
                'message' => $match[4],
                'context' => isset($match[5]) ? json_decode($match[5], true) : null
            ]);
        }

        return $logs;
    }

    protected function logMatchesCriteria(array $log, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            switch ($key) {
                case 'level':
                    if ($log['level'] !== $value) {
                        return false;
                    }
                    break;
                    
                case 'date_range':
                    if (!($log['datetime']->between($value[0], $value[1]))) {
                        return false;
                    }
                    break;
                    
                case 'search':
                    if (!str_contains($log['message'], $value)) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }
}
