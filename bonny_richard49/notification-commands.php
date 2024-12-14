<?php

namespace App\Core\Notification\Console\Commands;

use Illuminate\Console\Command;
use App\Core\Notification\Services\NotificationService;
use App\Core\Notification\Repositories\NotificationRepository;
use Illuminate\Support\Facades\Log;

class CleanupNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:cleanup 
                          {--days=30 : Number of days to keep notifications} 
                          {--type= : Specific notification type to cleanup}
                          {--dry-run : Run without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old notifications from the database';

    /**
     * The notification service instance.
     *
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Create a new command instance.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            $days = (int) $this->option('days');
            $type = $this->option('type');
            $dryRun = $this->option('dry-run');

            $this->info("Starting notification cleanup...");
            
            if ($dryRun) {
                $this->warn("Running in dry-run mode - no deletions will occur");
            }

            $count = $this->notificationService->cleanup([
                'days' => $days,
                'type' => $type,
                'dry_run' => $dryRun
            ]);

            $this->info("Successfully cleaned up {$count} notifications");
            
            Log::info('Notification cleanup completed', [
                'deleted_count' => $count,
                'days_threshold' => $days,
                'type' => $type,
                'dry_run' => $dryRun
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to cleanup notifications: " . $e->getMessage());
            
            Log::error('Notification cleanup failed', [
                'error' => $e->getMessage()
            ]);

            return Command::FAILURE;
        }
    }
}

class SendScheduledNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send-scheduled
                          {--limit=100 : Maximum number of notifications to process}
                          {--timeout=60 : Maximum execution time in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled notifications that are due';

    /**
     * The notification service instance.
     *
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Create a new command instance.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            $limit = (int) $this->option('limit');
            $timeout = (int) $this->option('timeout');

            $this->info("Processing scheduled notifications...");

            $startTime = microtime(true);
            $processed = 0;

            while ($processed < $limit && $this->withinTimeLimit($startTime, $timeout)) {
                $batch = $this->notificationService->processScheduledNotifications([
                    'limit' => min(25, $limit - $processed),
                    'lock_timeout' => 30
                ]);

                if (empty($batch)) {
                    break;
                }

                $processed += count($batch);
                
                $this->info("Processed batch of " . count($batch) . " notifications");
                
                // Small delay between batches
                if ($processed < $limit) {
                    usleep(100000); // 100ms
                }
            }

            $this->info("Completed processing {$processed} scheduled notifications");
            
            Log::info('Scheduled notifications processed', [
                'processed_count' => $processed,
                'execution_time' => microtime(true) - $startTime
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to process scheduled notifications: " . $e->getMessage());
            
            Log::error('Scheduled notifications processing failed', [
                'error' => $e->getMessage()
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Check if we're still within the time limit.
     *
     * @param float $startTime
     * @param int $timeout
     * @return bool
     */
    protected function withinTimeLimit(float $startTime, int $timeout): bool
    {
        return (microtime(true) - $startTime) < $timeout;
    }
}

class GenerateNotificationTemplatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:generate-templates
                          {--force : Force overwrite existing templates}
                          {--type= : Specific template type to generate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate notification templates from stubs';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            $force = $this->option('force');
            $type = $this->option('type');

            $this->info("Generating notification templates...");

            $templates = $this->generateTemplates($type, $force);

            $this->info("Successfully generated " . count($templates) . " templates");

            foreach ($templates as $template) {
                $this->line("- Generated template: {$template}");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to generate templates: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Generate templates from stubs.
     *
     * @param string|null $type
     * @param bool $force
     * @return array
     */
    protected function generateTemplates(?string $type, bool $force): array
    {
        $stubPath = __DIR__ . '/../stubs/templates';
        $templatePath = resource_path('views/notifications');
        $generated = [];

        // Ensure template directory exists
        if (!is_dir($templatePath)) {
            mkdir($templatePath, 0755, true);
        }

        // Get all stub files
        $stubs = glob($stubPath . '/**/*.stub');

        foreach ($stubs as $stub) {
            $relativePath = str_replace($stubPath . '/', '', $stub);
            $templateType = explode('/', $relativePath)[0];

            // Skip if specific type requested and this isn't it
            if ($type && $templateType !== $type) {
                continue;
            }

            $targetFile = $templatePath . '/' . str_replace('.stub', '.blade.php', $relativePath);

            // Skip if file exists and not forcing
            if (file_exists($targetFile) && !$force) {
                $this->warn("Skipping existing template: {$relativePath}");
                continue;
            }

            // Ensure target directory exists
            $targetDir = dirname($targetFile);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            // Copy and process stub
            $content = file_get_contents($stub);
            $content = $this->processStubContent($content);
            file_put_contents($targetFile, $content);

            $generated[] = $relativePath;
        }

        return $generated;
    }

    /**
     * Process stub content and replace placeholders.
     *
     * @param string $content
     * @return string
     */
    protected function processStubContent(string $content): string
    {
        $replacements = [
            '{{app_name}}' => config('app.name'),
            '{{year}}' => date('Y'),
            // Add more replacements as needed
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }
}