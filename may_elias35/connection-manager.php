```php
namespace App\Core\Database;

class ConnectionManager
{
    private array $connections = [];
    private SecurityManager $security;
    private ConfigManager $config;

    public function getConnection(string $name = 'default'): PDO
    {
        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->createConnection($name);
        }

        return $this->connections[$name];
    }

    private function createConnection(string $name): PDO
    {
        $config = $this->config->getDatabaseConfig($name);
        
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['database']
        );

        $connection = new PDO($dsn, 
            $this->security->decryptConfig($config['username']),
            $this->security->decryptConfig($config['password']),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_FOUND_ROWS => true
            ]
        );

        $this->configureConnection($connection);
        
        return $connection;
    }

    private function configureConnection(PDO $connection): void
    {
        $connection->exec('SET NAMES utf8mb4');
        $connection->exec('SET time_zone = "+00:00"');
        
        // Set session variables for security
        $connection->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_AUTO_CREATE_USER'");
        $connection->exec('SET SESSION max_execution_time = 30000'); // 30 seconds
    }
}
```
