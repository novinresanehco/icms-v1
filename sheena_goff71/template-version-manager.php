<?php

namespace App\Core\Template\Versioning;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Template\Exceptions\VersionException;

class VersionManager
{
    private DiffGenerator $differ;
    private VersionStorage $storage;
    private Collection $versions;
    private array $config;

    public function __construct(
        DiffGenerator $differ,
        VersionStorage $storage,
        array $config = []
    ) {
        $this->differ = $differ;
        $this->storage = $storage;
        $this->versions = new Collection();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Create new version
     *
     * @param string $template
     * @param string $content
     * @param array $metadata
     * @return Version
     */
    public function createVersion(string $template, string $content, array $metadata = []): Version
    {
        DB::beginTransaction();
        
        try {
            $previousVersion = $this->getLatestVersion($template);
            $diff = $previousVersion 
                ? $this->differ->generate($previousVersion->getContent(), $content)
                : null;

            $version = new Version([
                'template' => $template,
                'content' => $content,
                'diff' => $diff,
                'metadata' => array_merge([
                    'created_by' => auth()->id(),
                    'created_at' => now()
                ], $metadata),
                'parent_id' => $previousVersion?->getId()
            ]);

            $this->storage->store($version);
            $this->versions->push($version);

            DB::commit();
            return $version;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new VersionException("Failed to create version: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get version by ID
     *
     * @param string $id
     * @return Version
     */
    public function getVersion(string $id): Version
    {
        return $this->storage->retrieve($id);
    }

    /**
     * Get latest version
     *
     * @param string $template
     * @return Version|null
     */
    public function getLatestVersion(string $template): ?Version
    {
        return $this->storage->getLatest($template);
    }

    /**
     * Restore version
     *
     * @param string $id
     * @return Version
     */
    public function restore(string $id): Version
    {
        $version = $this->getVersion($id);
        return $this->createVersion(
            $version->getTemplate(),
            $version->getContent(),
            ['restored_from' => $id]
        );
    }

    /**
     * Compare versions
     *
     * @param string $fromId
     * @param string $toId
     * @return array
     */
    public function compare(string $fromId, string $toId): array
    {
        $from = $this->getVersion($fromId);
        $to = $this->getVersion($toId);

        return [
            'diff' => $this->differ->generate($from->getContent(), $to->getContent()),
            'from' => $from->getMetadata(),
            'to' => $to->getMetadata()
        ];
    }

    /**
     * Get version history
     *
     * @param string $template
     * @return Collection
     */
    public function getHistory(string $template): Collection
    {
        return $this->storage->getHistory($template);
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'max_versions' => 100,
            'diff_algorithm' => 'myers',
            'storage_driver' => 'database',
            'auto_cleanup' => true
        ];
    }
}

class Version
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getId(): string
    {
        return $this->data['id'];
    }

    public function getTemplate(): string
    {
        return $this->data['template'];
    }

    public function getContent(): string
    {
        return $this->data['content'];
    }

    public function getDiff(): ?array
    {
        return $this->data['diff'];
    }

    public function getMetadata(): array
    {
        return $this->data['metadata'];
    }

    public function getParentId(): ?string
    {
        return $this->data['parent_id'];
    }
}

class DiffGenerator
{
    /**
     * Generate diff between versions
     *
     * @param string $from
     * @param string $to
     * @return array
     */
    public function generate(string $from, string $to): array
    {
        return [
            'ops' => $this->generateOperations($from, $to),
            'stats' => $this->generateStats($from, $to)
        ];
    }

    /**
     * Generate diff operations
     *
     * @param string $from
     * @param string $to
     * @return array
     */
    protected function generateOperations(string $from, string $to): array
    {
        $fromLines = explode("\n", $from);
        $toLines = explode("\n", $to);
        $operations = [];
        
        $matrix = $this->computeLCSMatrix($fromLines, $toLines);
        $i = count($fromLines);
        $j = count($toLines);

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $fromLines[$i-1] === $toLines[$j-1]) {
                $operations[] = ['type' => 'keep', 'content' => $fromLines[$i-1]];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $matrix[$i][$j-1] >= $matrix[$i-1][$j])) {
                $operations[] = ['type' => 'add', 'content' => $toLines[$j-1]];
                $j--;
            } elseif ($i > 0 && ($j === 0 || $matrix[$i][$j-1] < $matrix[$i-1][$j])) {
                $operations[] = ['type' => 'remove', 'content' => $fromLines[$i-1]];
                $i--;
            }
        }

        return array_reverse($operations);
    }

    /**
     * Compute LCS matrix
     *
     * @param array $fromLines
     * @param array $toLines
     * @return array
     */
    protected function computeLCSMatrix(array $fromLines, array $toLines): array
    {
        $m = count($fromLines);
        $n = count($toLines);
        $matrix = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($fromLines[$i-1] === $toLines[$j-1]) {
                    $matrix[$i][$j] = $matrix[$i-1][$j-1] + 1;
                } else {
                    $matrix[$i][$j] = max($matrix[$i][$j-1], $matrix[$i-1][$j]);
                }
            }
        }

        return $matrix;
    }

    /**
     * Generate diff statistics
     *
     * @param string $from
     * @param string $to
     * @return array
     */
    protected function generateStats(string $from, string $to): array
    {
        $fromLines = explode("\n", $from);
        $toLines = explode("\n", $to);

        return [
            'lines_added' => count($toLines) - count($fromLines),
            'lines_removed' => count($fromLines) - count($toLines),
            'total_changes' => abs(count($toLines) - count($fromLines)),
            'change_percentage' => $this->calculateChangePercentage($fromLines, $toLines)
        ];
    }

    /**
     * Calculate change percentage
     *
     * @param array $fromLines
     * @param array $toLines
     * @return float
     */
    protected function calculateChangePercentage(array $fromLines, array $toLines): float
    {
        $total = count($fromLines) + count($toLines);
        if ($total === 0) return 0;

        $changes = 0;
        foreach ($this->generateOperations(implode("\n", $fromLines), implode("\n", $toLines)) as $op) {
            if ($op['type'] !== 'keep') $changes++;
        }

        return round(($changes / $total) * 100, 2);
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Versioning\VersionManager;
use App\Core\Template\Versioning\DiffGenerator;
use App\Core\Template\Versioning\VersionStorage;

class VersionServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(VersionManager::class, function ($app) {
            return new VersionManager(
                new DiffGenerator(),
                new VersionStorage(config('template.versioning.storage')),
                config('template.versioning')
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        // Register middleware for version tracking
        $this->app['router']->pushMiddleware(\App\Http\Middleware\TrackTemplateVersions::class);
    }
}
