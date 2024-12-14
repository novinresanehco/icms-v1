<?php

namespace App\Core\Audit\Converters;

class DataConverter
{
    private array $converters;
    private array $config;

    public function __construct(array $converters, array $config = [])
    {
        $this->converters = $converters;
        $this->config = $config;
    }

    public function convert(array $data, string $format): array
    {
        if (!isset($this->converters[$format])) {
            throw new \InvalidArgumentException("Unsupported format: {$format}");
        }

        return $this->converters[$format]->convert($data);
    }
}

class JsonConverter
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function convert(array $data): array
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        
        if ($this->options['pretty'] ?? false) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_decode(json_encode($data, $flags), true);
    }
}

class XmlConverter
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function convert(array $data): array
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><root/>');
        $this->arrayToXml($data, $xml);
        return $this->xmlToArray($xml);
    }

    private function arrayToXml(array $data, \SimpleXMLElement &$xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->arrayToXml($value, $xml->addChild($key));
            } else {
                $xml->addChild($key, (string)$value);
            }
        }
    }

    private function xmlToArray(\SimpleXMLElement $xml): array
    {
        return json_decode(json_encode($xml), true);
    }
}

class CsvConverter
{
    private array $options;

    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    public function convert(array $data): array
    {
        $output = fopen('php://temp', 'r+');

        if ($this->options['headers'] ?? true) {
            fputcsv($output, array_keys(reset($data)));
        }

        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $result = [];
        while ($row = fgetcsv($output)) {
            $result[] = $row;
        }
        fclose($output);

        return $result;
    }
}

class FormatConverter 
{
    private string $sourceFormat;
    private string $targetFormat;
    private array $config;

    public function __construct(string $sourceFormat, string $targetFormat, array $config = [])
    {
        $this->sourceFormat = $sourceFormat;
        $this->targetFormat = $targetFormat;
        $this->config = $config;
    }

    public function convert(mixed $data): mixed
    {
        $intermediate = $this->toIntermediate($data);
        return $this->fromIntermediate($intermediate);
    }

    private function toIntermediate(mixed $data): array
    {
        return match($this->sourceFormat) {
            'json' => json_decode($data, true),
            'xml' => simplexml_load_string($data),
            'csv' => $this->parseCsv($data),
            'array' => $data,
            default => throw new \InvalidArgumentException("Unsupported source format: {$this->sourceFormat}")
        };
    }

    private function fromIntermediate(array $data): mixed
    {
        return match($this->targetFormat) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'xml' => $this->arrayToXml($data),
            'csv' => $this->toCsv($data),
            'array' => $data,
            default => throw new \InvalidArgumentException("Unsupported target format: {$this->targetFormat}")
        };
    }

    private function parseCsv(string $data): array
    {
        $rows = str_getcsv($data, "\n");
        $headers = str_getcsv(array_shift($rows));
        $result = [];

        foreach ($rows as $row) {
            $values = str_getcsv($row);
            $result[] = array_combine($headers, $values);
        }

        return $result;
    }

    private function arrayToXml(array $data): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0"?><root></root>');
        $this->arrayToXmlRecursive($data, $xml);
        return $xml->asXML();
    }

    private function toCsv(array $data): string
    {
        $output = fopen('php://temp', 'r+');
        
        if (!empty($data)) {
            fputcsv($output, array_keys(reset($data)));
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);
        
        return $content;
    }

    private function arrayToXmlRecursive(array $data, \SimpleXMLElement &$xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild(is_numeric($key) ? 'item' : $key);
                $this->arrayToXmlRecursive($value, $child);
            } else {
                $xml->addChild(is_numeric($key) ? 'item' : $key, (string)$value);
            }
        }
    }
}
