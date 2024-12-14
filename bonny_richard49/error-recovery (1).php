// app/Core/Error/Recovery/ErrorRecoveryManager.php
<?php

namespace App\Core\Error\Recovery;

use App\Core\Error\Recovery\Strategy\RecoveryStrategyInterface;
use Throwable;

class ErrorRecoveryManager
{
    private array $strategies = [];

    public function addStrategy(string $errorType, RecoveryStrategyInterface $strategy): void
    {
        $this->strategies[$errorType] = $strategy;
    }

    public function recover(Throwable $error)
    {
        $strategy = $this->getStrategy($error);
        
        if ($strategy) {
            return $strategy->recover($error);
        }
        
        throw $error;
    }

    private function getStrategy(Throwable $error): ?RecoveryStrategyInterface
    {
        foreach ($this->strategies as $type => $strategy) {
            if ($error instanceof $type) {
                return $strategy;
            }
        }
        
        return null;
    }
}

// app/Core/Error/Recovery/Strategy/DatabaseRecoveryStrategy.php
<?php

namespace App\Core\Error\Recovery\Strategy;

use App\Core\Error\Recovery\Strategy\RecoveryStrategyInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseRecoveryStrategy implements RecoveryStrategyInterface
{
    public function recover(Throwable $error)
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        try {
            DB::reconnect();
            return true;
        } catch (Throwable $e) {
            throw $e;
        }
    }
}

// app/Core/Error/Recovery/Strategy/CacheRecoveryStrategy.php
<?php

namespace App\Core\Error\Recovery\Strategy;

use App\Core\Error\Recovery\Strategy\RecoveryStrategyInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CacheRecoveryStrategy implements RecoveryStrategyInterface
{
    public function recover(Throwable $error)
    {
        try {
            Cache::store('redis')->reconnect();
            return true;
        } catch (Throwable $e) {
            Cache::store('file');
            return true;
        }
    }
}

// app/Core/Error/Recovery/Strategy/QueueRecoveryStrategy.php
<?php

namespace App\Core\Error\Recovery\Strategy;

use App\Core\Error\Recovery\Strategy\RecoveryStrategyInterface;
use Illuminate\Support\Facades\Queue;
use Throwable;

class QueueRecoveryStrategy implements RecoveryStrategyInterface
{
    private int $maxAttempts;

    public function __construct(int $maxAttempts = 3)
    {
        $this->maxAttempts = $maxAttempts;
    }

    public function recover(Throwable $error)
    {
        if ($error->job && $error->job->attempts() < $this->maxAttempts) {
            $error->job->release(30);
            return true;
        }
        
        return false;
    }
}

// app/Core/Error/Recovery/Strategy/RecoveryStrategyInterface.php
<?php

namespace App\Core\Error\Recovery\Strategy;

use Throwable;

interface RecoveryStrategyInterface
{
    public function recover(Throwable $error);
}

// app/Core/Error/Recovery/RecoveryServiceProvider.php
<?php

namespace App\Core\Error\Recovery;

use Illuminate\Support\ServiceProvider;
use App\Core\Error\Recovery\ErrorRecoveryManager;
use App\Core\Error\Recovery\Strategy\DatabaseRecoveryStrategy;
use App\Core\Error\Recovery\Strategy\CacheRecoveryStrategy;
use App\Core\Error\Recovery\Strategy\QueueRecoveryStrategy;

class RecoveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ErrorRecoveryManager::class, function () {
            $manager = new ErrorRecoveryManager();
            
            $manager->addStrategy(
                \Illuminate\Database\QueryException::class,
                new DatabaseRecoveryStrategy()
            );
            
            $manager->addStrategy(
                \Predis\Connection\ConnectionException::class,
                new CacheRecoveryStrategy()
            );
            
            $manager->addStrategy(
                \Illuminate\Queue\MaxAttemptsExceededException::class,
                new QueueRecoveryStrategy()
            );
            
            return $manager;
        });
    }
}