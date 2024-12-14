<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use League\Csv\{Reader, Writer};
use App\Core\Tag\Exceptions\TagImportException;

class TagImportExportService
{
    /**
     * @var TagValidationService
     */
    protected TagValidationService $validator;

    public function __construct(TagValidationService $validator)
    {
        $this->validator = $validator;
    }

    /**
     * Export tags to CSV.
     */
    public function exportToCsv(string $path): void
    {
        $writer = Writer::createFromPath($path, 'w+');
        $writer->insertOne(['name', 'slug', 'description', 'meta_title', 'meta_description']);

        Tag::chunk(100, function ($tags) use ($writer) {
            $writer->insertAll($tags->map(fn($tag) => [
                $tag->name,
                $tag->slug,
                $tag->description,
                $tag->meta_title,
                $tag->meta_description,
            ]));
        });
    }

    /**
     * Import tags from CSV.
     */
    public function importFromCsv(string $path): array
    {
        $reader = Reader::createFromPath($path);
        $reader->setHeaderOffset(0);

        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0];
        $failures = [];

        DB::beginTransaction();

        try {
            foreach ($reader->getRecords() as $record) {
                try {
                    $this->processTagRecord($record, $stats);
                } catch (\Exception $e) {
                    $failures[] = [
                        'record' => $record,
                        'error' => $e->getMessage()
                    ];
                    $stats['failed']++;
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagImportException("Import failed: {$e->getMessage()}");
        }

        return [
            'stats' => $stats,
            'failures' => $failures
        ];
    }

    /**
     * Process a single tag record.
     */
    protected function processTagRecord(array $record, array &$stats): void
    {
        $this->validator->validateTag($record);

        $tag = Tag::where('name', $record['name'])->first();

        if ($tag) {
            $tag->update($record);
            $stats['updated']++;
        } else {
            Tag::create($record);
            $stats['created']++;
        }
    }

    /**
     * Export tags to JSON.
     */
    public function exportToJson(string $path): void
    {
        $tags = Tag::with('contents')->get()
            ->map(fn($tag) => [
                'name' => $tag->name,
                'slug' => $tag->slug,
                'description' => $tag->description,
                'meta_title' => $tag->meta_title,
                'meta_description' => $tag->meta_description,
                'content_count' => $tag->contents->count(),
            ]);

        file_put_contents($path, json_encode($tags, JSON_PRETTY_PRINT));
    }
}
