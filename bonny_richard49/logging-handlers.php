// app/Core/Logging/Handlers/LogHandler.php
<?php

namespace App\Core\Logging\Handlers;

abstract class LogHandler
{
    protected int $level;

    public function __construct(int $level)
    {
        $this->level = $level;
    }

    abstract public function handle(array $record): void;

    public function isHandling(array $record): bool
    {
        return $record['level'] >= $this->level;
    }
}

// app/Core/Logging/Handlers/FileHandler.php
<?php

namespace App\Core\Logging\Handlers;

use App\Core\Logging\Handlers\LogHandler;

class FileHandler extends LogHandler
{
    private string $filename;
    private string $permissions;

    public function __construct(
        int $level,
        string $filename,
        string $permissions = '0644'
    ) {
        parent::__construct($level);
        $this->filename = $filename;
        $this->permissions = $permissions;
    }

    public function handle(array $record): void
    {
        $logLine = implode(' ', [
            $record['datetime']->format('Y-m-d H:i:s'),
            "[{$record['level']}]",
            $record['message'],
            json_encode($record['context'])
        ]) . PHP_EOL;

        file_put_contents(
            $this->filename,
            $logLine,
            FILE_APPEND | LOCK_EX
        );
    }
}

// app/Core/Logging/Handlers/DatabaseHandler.php
<?php

namespace App\Core\Logging\Handlers;

use App\Core\Logging\Handlers\LogHandler;
use Illuminate\Support\Facades\DB;

class DatabaseHandler extends LogHandler
{
    private string $table;

    public function __construct(int $level, string $table = 'logs')
    {
        parent::__construct($level);
        $this->table = $table;
    }

    public function handle(array $record): void
    {
        DB::table($this->table)->insert([
            'level' => $record['level'],
            'message' => $record['message'],
            'context' => json_encode($record['context']),
            'datetime' => $record['datetime'],
            'extra' => json_encode($record['extra']),
            'created_at' => now(),
        ]);
    }
}

// app/Core/Logging/Handlers/ElasticsearchHandler.php
<?php

namespace App\Core\Logging\Handlers;

use App\Core\Logging\Handlers\LogHandler;
use Elasticsearch\Client;

class ElasticsearchHandler extends LogHandler
{
    private Client $client;
    private string $index;

    public function __construct(
        int $level,
        Client $client,
        string $index = 'logs'
    ) {
        parent::__construct($level);
        $this->client = $client;
        $this->index = $index;
    }

    public function handle(array $record): void
    {
        $this->client->index([
            'index' => $this->index,
            'type' => '_doc',
            'body' => [
                'level' => $record['level'],
                'message' => $record['message'],
                'context' => $record['context'],
                'datetime' => $record['datetime']->format('c'),
                'extra' => $record['extra'],
                '@timestamp' => $record['datetime']->format('c'),
            ]
        ]);
    }
}