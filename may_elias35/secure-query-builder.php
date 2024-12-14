namespace App\Core\Database;

class SecureQueryBuilder implements QueryBuilderInterface
{
    private SecurityManager $security;
    private DatabaseConnection $connection;
    private ValidationService $validator;
    private CacheManager $cache;
    private QueryAnalyzer $analyzer;

    public function __construct(
        SecurityManager $security,
        DatabaseConnection $connection,
        ValidationService $validator,
        CacheManager $cache,
        QueryAnalyzer $analyzer
    ) {
        $this->security = $security;
        $this->connection = $connection;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->analyzer = $analyzer;
    }

    public function select(array $columns = ['*']): QueryBuilder
    {
        return $this->security->executeCriticalOperation(new class($columns, $this->connection, $this->validator) implements CriticalOperation {
            private array $columns;
            private DatabaseConnection $connection;
            private ValidationService $validator;

            public function __construct(array $columns, DatabaseConnection $connection, ValidationService $validator)
            {
                $this->columns = $columns;
                $this->connection = $connection;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $this->validator->validateColumns($this->columns);
                
                $builder = $this->connection->createQueryBuilder();
                $builder->select($this->sanitizeColumns($this->columns));
                
                return new OperationResult($builder);
            }

            private function sanitizeColumns(array $columns): array
            {
                return array_map(function($column) {
                    return preg_replace('/[^a-zA-Z0-9_\.]/', '', $column);
                }, $columns);
            }

            public function getValidationRules(): array
            {
                return ['columns' => 'array'];
            }

            public function getData(): array
            {
                return ['columns' => $this->columns];
            }

            public function getRequiredPermissions(): array
            {
                return ['database.select'];
            }

            public function getRateLimitKey(): string
            {
                return 'database:select';
            }
        });
    }

    public function insert(string $table, array $values): int
    {
        return $this->security->executeCriticalOperation(new class($table, $values, $this->connection, $this->validator) implements CriticalOperation {
            private string $table;
            private array $values;
            private DatabaseConnection $connection;
            private ValidationService $validator;

            public function __construct(string $table, array $values, DatabaseConnection $connection, ValidationService $validator)
            {
                $this->table = $table;
                $this->values = $values;
                $this->connection = $connection;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $this->validator->validateTable($this->table);
                $this->validator->validateInsertData($this->values);

                $builder = $this->connection->createQueryBuilder();
                $builder->insert($this->sanitizeTable($this->table))
                       ->values($this->sanitizeValues($this->values));

                return new OperationResult($builder->execute());
            }

            private function sanitizeTable(string $table): string
            {
                return preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            }

            private function sanitizeValues(array $values): array
            {
                return array_map(function($value) {
                    return is_string($value) ? $this->connection->quote($value) : $value;
                }, $values);
            }

            public function getValidationRules(): array
            {
                return [
                    'table' => 'required|string|max:64',
                    'values' => 'required|array'
                ];
            }

            public function getData(): array
            {
                return [
                    'table' => $this->table,
                    'value_count' => count($this->values)
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['database.insert'];
            }

            public function getRateLimitKey(): string
            {
                return "database:insert:{$this->table}";
            }
        });
    }

    public function update(string $table, array $values, array $conditions): int
    {
        return $this->security->executeCriticalOperation(new class($table, $values, $conditions, $this->connection, $this->validator) implements CriticalOperation {
            private string $table;
            private array $values;
            private array $conditions;
            private DatabaseConnection $connection;
            private ValidationService $validator;

            public function __construct(
                string $table,
                array $values,
                array $conditions,
                DatabaseConnection $connection,
                ValidationService $validator
            ) {
                $this->table = $table;
                $this->values = $values;
                $this->conditions = $conditions;
                $this->connection = $connection;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $this->validator->validateTable($this->table);
                $this->validator->validateUpdateData($this->values);
                $this->validator->validateConditions($this->conditions);

                $builder = $this->connection->createQueryBuilder();
                $builder->update($this->sanitizeTable($this->table))
                       ->set($this->sanitizeValues($this->values))
                       ->where($this->buildConditions($this->conditions));

                return new OperationResult($builder->execute());
            }

            private function buildConditions(array $conditions): string
            {
                $parts = [];
                foreach ($conditions as $column => $value) {
                    $column = $this->sanitizeColumn($column);
                    $value = $this->connection->quote($value);
                    $parts[] = "$column = $value";
                }
                return implode(' AND ', $parts);
            }

            private function sanitizeColumn(string $column): string
            {
                return preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            }

            public function getValidationRules(): array
            {
                return [
                    'table' => 'required|string|max:64',
                    'values' => 'required|array',
                    'conditions' => 'required|array'
                ];
            }

            public function getData(): array
            {
                return [
                    'table' => $this->table,
                    'value_count' => count($this->values),
                    'condition_count' => count($this->conditions)
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['database.update'];
            }

            public function getRateLimitKey(): string
            {
                return "database:update:{$this->table}";
            }
        });
    }

    public function delete(string $table, array $conditions): int
    {
        return $this->security->executeCriticalOperation(new class($table, $conditions, $this->connection, $this->validator) implements CriticalOperation {
            private string $table;
            private array $conditions;
            private DatabaseConnection $connection;
            private ValidationService $validator;

            public function __construct(
                string $table,
                array $conditions,
                DatabaseConnection $connection,
                ValidationService $validator
            ) {
                $this->table = $table;
                $this->conditions = $conditions;
                $this->connection = $connection;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $this->validator->validateTable($this->table);
                $this->validator->validateConditions($this->conditions);

                if (empty($this->conditions)) {
                    throw new SecurityException('Delete without conditions is not allowed');
                }

                $builder = $this->connection->createQueryBuilder();
                $builder->delete($this->sanitizeTable($this->table))
                       ->where($this->buildConditions($this->conditions));

                return new OperationResult($builder->execute());
            }

            public function getValidationRules(): array
            {
                return [
                    'table' => 'required|string|max:64',
                    'conditions' => 'required|array|min:1'
                ];
            }

            public function getData(): array
            {
                return [
                    'table' => $this->table,
                    'condition_count' => count($this->conditions)
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['database.delete'];
            }

            public function getRateLimitKey(): string
            {
                return "database:delete:{$this->table}";
            }
        });
    }
}
