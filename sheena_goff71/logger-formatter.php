<?php

namespace App\Core\Logger\Services;

use Illuminate\Support\Collection;
use League\Csv\Writer;

class LogFormatter
{
    public function formatContext(array $context): array
    {
        return array_map(function ($value) {
            if ($value instanceof \Throwable) {
                return [
                    'class' => get_class($value),
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine()
                ];
            }

            if (is_object($value)) {
                return method_exists($value, 'toArray') 
                    ? $value->toArray() 
                    : (string) $value;
            }

            return $value;
        }, $context);
    }

    public function formatForExport(Collection $logs): string
    {
        $csv = Writer::createFromString();
        
        $csv->insertOne([
            'ID',
            'Type',
            'Message',
            'Context',
            'Level',
            'User',
            'IP Address',
            'User Agent',
            'Created At'
        ]);

        foreach ($logs as $log) {
            $csv->insertOne([
                $log->id,
                $log->type,
                $log->message,
                json_encode($log->context),
                $log->level,
                $log->user ? $log->user->name : 'System',
                $log->ip_address,
                $log->user_agent,
                $log->created_at->toDateTimeString()
            ]);
        }

        return $csv->toString();
    }
}
