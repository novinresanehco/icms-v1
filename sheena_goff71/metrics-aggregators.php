<?php

namespace App\Core\Metrics\Aggregators;

class DatabaseAggregator 
{
    private $connection;
    
    public function __construct($connection)
    {
        $this->connection = $connection;
    }

    public function __invoke(array $metrics): void
    {
        if (empty($metrics)) {
            return;
        }

        $records = $this->prepareRecords($metrics);
        $this->insertRecords($records);
    }

    private function prepareRecords(array $metrics): array
    {
        $records = [];
        
        foreach ($metrics as $metric) {
            $records[] = [
                'name' => $metric->name,
                'value' => is_numeric($metric->value) ? $metric->value : json_encode($metric->value),
                'tags' => json_encode($metric->tags),
                'timestamp' => $metric->timestamp,
                'created_at' => now(),
                'updated_at' => now()
            ];
        }
        
        return $records;
    }

    private function insertRecords(array $records): void
    {
        $this->connection->table('metrics')->insert($records);
    }
}
