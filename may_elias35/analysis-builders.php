<?php

namespace App\Core\Audit\Builders;

class AnalysisBuilder
{
    private array $data = [];
    private array $config = [];
    private array $processors = [];
    private array $validators = [];

    public function withData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function withConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    public function addProcessor(ProcessorInterface $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    public function addValidator(ValidatorInterface $validator): self
    {
        $this->validators[] = $validator;
        return $this;
    }

    public function build(): Analysis
    {
        $this->validate();
        
        return new Analysis(
            $this->data,
            $this->config,
            $this->processors,
            $this->validators
        );
    }

    private function validate(): void
    {
        foreach ($this->validators as $validator) {
            $result = $validator->validate($this->data);
            if (!$result->isValid()) {
                throw new ValidationException($result->getErrors());
            }
        }
    }
}

class QueryBuilder
{
    private array $select = [];
    private array $where = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function select(array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBy[$column] = $direction;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function build(): array
    {
        return [
            'select' => $this->select,
            'where' => $this->where,
            'orderBy' => $this->orderBy,
            'limit' => $this->limit,
            'offset' => $this->offset
        ];
    }

    public function toSql(): string
    {
        $sql = 'SELECT ' . ($this->select ? implode(', ', $this->select) : '*');
        
        if ($this->where) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }
        
        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . $this->buildOrderByClause();
        }
        
        if ($this->limit) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        
        if ($this->offset) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        
        return $sql;
    }

    private function buildWhereClause(): string
    {
        return implode(' AND ', array_map(
            fn($condition) => sprintf(
                '%s %s ?',
                $condition['column'],
                $condition['operator']
            ),
            $this->where
        ));
    }

    private function buildOrderByClause(): string
    {
        return implode(', ', array_map(
            fn($column, $direction) => "{$column} {$direction}",
            array_keys($this->orderBy),
            $this->orderBy
        ));
    }
}

class ReportBuilder
{
    private array $data = [];
    private array $sections = [];
    private array $metrics = [];
    private array $config = [];

    public function addData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    public function addSection(string $name, callable $builder): self
    {
        $this->sections[$name] = $builder;
        return $this;
    }

    public function addMetric(string $name, $value): self
    {
        $this->metrics[$name] = $value;
        return $this;
    }

    public function withConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    public function build(): Report
    {
        $sections = [];
        foreach ($this->sections as $name => $builder) {
            $sections[$name] = $builder($this->data);
        }

        return new Report(
            $this->data,
            $sections,
            $this->metrics,
            $this->config
        );
    }
}

class NotificationBuilder
{
    private string $type;
    private array $data = [];
    private array $recipients = [];
    private array $channels = [];
    private array $options = [];

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function withData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    public function addRecipient(string $recipient): self
    {
        $this->recipients[] = $recipient;
        return $this;
    }

    public function addChannel(string $channel): self
    {
        $this->channels[] = $channel;
        return $this;
    }

    public function withOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    public function build(): Notification
    {
        if (!isset($this->type)) {
            throw new \InvalidArgumentException('Notification type is required');
        }

        return new Notification(
            $this->type,
            $this->data,
            $this->recipients,
            $this->channels,
            $this->options
        );
    }
}

class FilterBuilder
{
    private array $conditions = [];
    private array $operators = [];
    private array $options = [];

    public function where(string $field, $value, string $operator = '='): self
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => $value,
            'operator' => $operator
        ];
        return $this;
    }

    public function orWhere(string $field, $value, string $operator = '='): self
    {
        $this->operators[] = 'OR';
        return $this->where($field, $value, $operator);
    }

    public function andWhere(string $field, $value, string $operator = '='): self
    {
        $this->operators[] = 'AND';
        return $this->where($field, $value, $operator);
    }

    public function withOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    public function build(): Filter
    {
        return new Filter(
            $this->conditions,
            $this->operators,
            $this->options
        );
    }
}
