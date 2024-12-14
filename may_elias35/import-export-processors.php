// File: app/Core/ImportExport/Processors/CsvProcessor.php
<?php

namespace App\Core\ImportExport\Processors;

class CsvProcessor implements ImportProcessor, ExportProcessor
{
    protected CsvReader $reader;
    protected CsvWriter $writer;
    protected array $options;

    public function import(File $file): array
    {
        return $this->reader
            ->setDelimiter($this->options['delimiter'] ?? ',')
            ->setEnclosure($this->options['enclosure'] ?? '"')
            ->setEscape($this->options['escape'] ?? '\\')
            ->read($file);
    }

    public function export(array $data): File
    {
        return $this->writer
            ->setDelimiter($this->options['delimiter'] ?? ',')
            ->setEnclosure($this->options['enclosure'] ?? '"')
            ->setEscape($this->options['escape'] ?? '\\')
            ->write($data);
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }
}

// File: app/Core/ImportExport/Processors/JsonProcessor.php
<?php

namespace App\Core\ImportExport\Processors;

class JsonProcessor implements ImportProcessor, ExportProcessor
{
    protected JsonReader $reader;
    protected JsonWriter $writer;
    protected array $options;

    public function import(File $file): array
    {
        return $this->reader
            ->setPrettyPrint($this->options['prettyPrint'] ?? false)
            ->setDepth($this->options['depth'] ?? 512)
            ->read($file);
    }

    public function export(array $data): File
    {
        return $this->writer
            ->setPrettyPrint($this->options['prettyPrint'] ?? true)
            ->setDepth($this->options['depth'] ?? 512)
            ->write($data);
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }
}
