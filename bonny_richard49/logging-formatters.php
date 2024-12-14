// app/Core/Logging/Formatters/LogFormatter.php
<?php

namespace App\Core\Logging\Formatters;

abstract class LogFormatter
{
    abstract public function format(array $record): array;
}

// app/Core/Logging/Formatters/JsonFormatter.php
<?php

namespace App\Core\Logging\Formatters;

use App\Core\Logging\Formatters\LogFormatter;

class JsonFormatter extends LogFormatter
{
    public function format(array $record): array
    {
        $record['datetime'] = $record['datetime']->format('c');
        $record['formatted'] = json_encode($record);
        return $record;
    }
}

// app/Core/Logging/Formatters/LineFormatter.php
<?php

namespace App\Core\Logging\Formatters;

use App\Core\Logging\Formatters\LogFormatter;

class LineFormatter extends LogFormatter
{
    private string $format;
    private string $dateFormat;

    public function __construct(
        string $format = "[%datetime%] %level_name% %message% %context% %extra%\n",
        string $dateFormat = 'Y-m-d H:i:s'
    ) {
        $this->format = $format;
        $this->dateFormat = $dateFormat;
    }

    public function format(array $record): array
    {
        $vars = [
            '%datetime%' => $record['datetime']->format($this->dateFormat),
            '%level_name%' => strtoupper($record['level']),
            '%message%' => $record['message'],
            '%context%' => json_encode($record['context']),
            '%extra%' => json_encode($record['extra'])
        ];

        $record['formatted'] = strtr($this->format, $vars);
        return $record;
    }
}

// app/Core/Logging/Formatters/HtmlFormatter.php
<?php

namespace App\Core\Logging\Formatters;

use App\Core\Logging\Formatters\LogFormatter;

class HtmlFormatter extends LogFormatter
{
    public function format(array $record): array
    {
        $output = sprintf(
            '<tr class="log-record log-%s">
                <td class="log-datetime">%s</td>
                <td class="log-level">%s</td>
                <td class="log-message">%s</td>
                <td class="log-context">%s</td>
                <td class="log-extra">%s</td>
            </tr>',
            strtolower($record['level']),
            $record['datetime']->format('Y-m-d H:i:s'),
            strtoupper($record['level']),
            htmlspecialchars($record['message']),
            htmlspecialchars(json_encode($record['context'])),
            htmlspecialchars(json_encode($record['extra']))
        );

        $record['formatted'] = $output;
        return $record;
    }
}