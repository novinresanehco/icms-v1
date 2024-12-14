<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ConnectionException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;
use PDO;

class ConnectionManager implements ConnectionManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $activeConnections = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function getConnection(string $name = 'default'): PDO
    {
        $connectionId = $this->generateConnectionId();

        try {
            $this->security->validateSecureOperation('database:connect', [
                'connection_id' => $connectionId,
                'connection_name' => $name
            ]);

            return $this->establishConnection($name, $connectionId);

        } catch (\Exception $e) {
            $this->handleConnectionFailure($connectionId, 'get', $e);
            throw new ConnectionException('Database connection failed', 0, $e);
        }
    }

    public function releaseConnection(PDO $connection): void
    {
        $connectionId = $this->getConnectionId($connection);

        try {
            $this->security->validateSecureOperation('database:release', [
                'connection_id' => $connectionId
            ]);

            $this->closeConnection($connection);
            $this->logConnectionEvent($connectionId, 'release');

        } catch (\Exception $e) {
            $this->handleConnectionFailure($connectionId, 'release', $e);
            throw new ConnectionException('Connection release failed', 0, $e);
        }
    }

    public function optimizeConnections(): void
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('database:optimize', [
                'operation_id' => $operationId
            ]);

            $this->cleanupIdleConnections();
            $this->rebalanceConnections();
            
            $this->logConnectionEvent($operationId, 'optimize');

        } catch (\Exception $e) {
            $this->handleConnectionFailure($operationId, 'optimize', $e);
            throw new ConnectionException('Connection optimization failed', 0, $e);
        }
    }

    private function establishConnection(string $name, string $connectionId): PDO
    {
        if (count($this->activeConnections) >= $this->config['max_connections']) {
            throw new ConnectionException('Maximum connections reached');
        }

        $config = $this->getConnectionConfig($name);
        $connection = $this->createConnection($config);
        
        $this->configureConnection($connection);
        $this->validateConnection($connection);
        
        $this->activeConnections[$connectionId] = [
            'connection' => $connection,
            'created_at' => microtime(true),
            'last_used' => microtime(true)
        ];

        $this->logConnectionEvent($connectionId, 'establish');

        return $connection;
    }

    private function createConnection(array $config): PDO
    {
        $dsn = $this->buildDsn($config);
        $options = $this->getConnectionOptions();

        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            $options
        );
    }

    private function configureConnection(PDO $connection): void
    {
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $connection->setAttribute(PDO::ATTR_PERSISTENT, $this->config['persistent_connections']);
        
        if ($this->config['strict_mode']) {
            $connection->exec('SET SESSION sql_mode = "STRICT_ALL_TABLES"');
        }
    }

    private function validateConnection(PDO $connection): void
    {
        try {
            $connection->query('SELECT 1');
        } catch (\PDOException $e) {
            throw new ConnectionException('Connection validation failed', 0, $e);
        }
    }

    private function closeConnection(PDO $connection): void
    {
        $connectionId = $this->getConnectionId($connection);
        
        if ($connectionId) {
            unset($this->activeConnections[$connectionId]);
            $connection = null;
        }
    }

    private function cleanupIdleConnections(): void
    {
        $now = microtime(true);
        
        foreach ($this->activeConnections as $id => $data) {
            if (($now - $data['last_used']) > $this->config['idle_timeout']) {
                $this->closeConnection($data['connection']);
            }
        }
    }

    private function rebalanceConnections(): void
    {
        if (count($this->activeConnections) < $this->config['min_connections']) {
            $this->ensureMinimumConnections();
        }
    }

    private function ensureMinimumConnections(): void
    {
        while (count($this->activeConnections) < $this->config['min_connections']) {
            $this->getConnection();
        }
    }

    private function buildDsn(array $config): string
    {
        return sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );
    }

    private function getConnectionOptions(): array
    {
        return [
            PDO::ATTR_TIMEOUT => $this->config['connection_timeout'],
            PDO::ATTR_PERSISTENT => $this->config['persistent_connections'],
            PDO::MYSQL_ATTR_SSL_CA => $this->config['ssl_certificate'],
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true
        ];
    }

    private function getConnectionId(PDO $connection): ?string
    {
        foreach ($this->activeConnections as $id => $data) {
            if ($data['connection'] === $connection) {
                return $id;
            }
        }

        return null;
    }

    private function generateConnectionId(): string
    {
        return uniqid('conn_', true);
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    private function getDefaultConfig(): array
    {
        return [
            'min_connections' => 2,
            'max_connections' => 10,
            'connection_timeout' => 5,
            'idle_timeout' => 300,
            'persistent_connections' => false,
            'strict_mode' => true,
            'ssl_certificate'