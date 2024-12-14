<?php

namespace App\Core\Audit\Query;

class AuditQuery
{
    private array $conditions = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private int $offset = 0;

    public function where(string $field, string $operator, $value): self
    {
        $this->conditions[] = compact('field', 'operator', 'value');
        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->orderBy[$field] = $direction;
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

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}

class AuditQueryBuilder
{
    private $connection;

    public function execute(AuditQuery $query): array
    {
        $builder = $this->connection->table('audit_logs');

        foreach ($query->getConditions() as $condition) {
            $builder->where(
                $condition['field'],
                $condition['operator'],
                $condition['value']
            );
        }

        foreach ($query->getOrderBy() as $field => $direction) {
            $builder->orderBy($field, $direction);
        }

        if ($query->getLimit()) {
            $builder->limit($query->getLimit());
        }

        $builder->offset($query->getOffset());

        return $builder->get()->toArray();
    }
}
