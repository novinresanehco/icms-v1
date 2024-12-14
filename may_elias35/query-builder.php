<?php

namespace App\Core\Audit;

class AuditQueryBuilder
{
    private array $conditions = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $includes = [];
    private array $aggregations = [];
    private QueryValidator $validator;
    private PerformanceAnalyzer $analyzer;

    public function __construct(
        QueryValidator $validator,
        PerformanceAnalyzer $analyzer
    ) {
        $this->validator = $validator;
        $this->analyzer = $analyzer;
    }

    public function where(string $field, $operator, $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->validator->validateField($field);
        $this->validator->validateOperator($operator);
        $this->validator->validateValue($value);

        $this->conditions[] = [
            'type' => 'where',
            'field' => $field,
            'operator' => $operator,
            'value' => $value
        ];

        return $this;
    }

    public function whereIn(string $field, array $values): self
    {
        $this->validator->validateField($field);
        $this->validator->validateArray($values);

        $this->conditions[] = [
            'type' => 'whereIn',
            'field' => $field,
            'values' => $values
        ];

        return $this;
    }

    public function whereBetween(string $field, $start, $end): self
    {
        $this->validator->validateField($field);
        $this->validator->validateRange($start, $end);

        $this->conditions[] = [
            'type' => 'whereBetween',
            'field' => $field,
            'start' => $start,
            'end' => $end
        ];

        return $this;
    }

    public function whereNull(string $field): self
    {
        $this->validator->validateField($field);

        $this->conditions[] = [
            'type' => 'whereNull',
            'field' => $field
        ];

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->validator->validateField($field);
        $this->validator->validateDirection($direction);

        $this->orderBy[] = [
            'field' => $field,
            'direction' => strtolower($direction)
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->validator->validateLimit($limit);
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->validator->validateOffset($offset);
        $this->offset = $offset;
        return $this;
    }

    public function include(string ...$relations): self
    {
        foreach ($relations as $relation) {
            $this->validator->validateRelation($relation);
        }

        $this->includes = array_merge($this->includes, $relations);
        return $this;
    }

    public function aggregate(string $function, string $field): self
    {
        $this->validator->validateAggregateFunction($function);
        $this->validator->validateField($field);

        $this->aggregations[] = [
            'function' => $function,
            'field' => $field
        ];

        return $this;
    }

    public function build(): QuerySpecification
    {
        // Analyze query before building
        $this->analyzer->analyzeQuery($this);

        // Validate final query structure
        $this->validateQueryStructure();

        return new QuerySpecification(
            $this->conditions,
            $this->orderBy,
            $this->limit,
            $this->offset,
            $this->includes,
            $this->aggregations
        );
    }

    public function buildSQL(): string
    {
        $query = $this->build();
        return $query->toSQL();
    }

    protected function validateQueryStructure(): void
    {
        // Check for conflicting conditions
        $this->checkConflictingConditions();

        // Validate query complexity
        $this->validateQueryComplexity();

        // Check for potential performance issues
        $this->checkPerformanceImplications();
    }

    protected function checkConflictingConditions(): void
    {
        $fields = [];
        foreach ($this->conditions as $condition) {
            $field = $condition['field'];
            if (isset($fields[$field])) {
                $this->validateFieldConditionCompatibility($field, $fields[$field], $condition);
            }
            $fields[$field][] = $condition;
        }
    }

    protected function validateQueryComplexity(): void
    {
        $complexity = $this->calculateQueryComplexity();
        if ($complexity > QueryConfiguration::MAX_QUERY_COMPLEXITY) {
            throw new QueryComplexityException(
                "Query complexity {$complexity} exceeds maximum allowed "
                . QueryConfiguration::MAX_QUERY_COMPLEXITY
            );
        }
    }

    protected function calculateQueryComplexity(): int
    {
        $complexity = 0;
        $complexity += count($this->conditions) * 2;
        $complexity += count($this->includes) * 3;
        $complexity += count($this->aggregations) * 2;
        return $complexity;
    }

    protected function checkPerformanceImplications(): void
    {
        $implications = $this->analyzer->analyzePerformanceImplications(
            $this->conditions,
            $this->includes,
            $this->aggregations
        );

        if ($implications->hasWarnings()) {
            foreach ($implications->getWarnings() as $warning) {
                // Log performance warnings
                logger()->warning('Audit query performance warning', [
                    'warning' => $warning,
                    'query' => $this->buildSQL()
                ]);
            }
        }

        if ($implications->hasBlockers()) {
            throw new QueryPerformanceException(
                'Query has critical performance implications: ' .
                implode(', ', $implications->getBlockers())
            );
        }
    }
}
