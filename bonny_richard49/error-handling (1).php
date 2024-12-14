// app/Core/Error/ErrorHandler.php
<?php

namespace App\Core\Error;

use App\Core\Error\Handlers\HandlerInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class ErrorHandler
{
    private array $handlers = [];
    private array $levels = [];

    public function register(HandlerInterface $handler, int $level): void
    {
        $this->handlers[] = $handler;
        $this->levels[] = $level;
    }

    public function handle(Throwable $error): void
    {
        $level = $this->determineLevel($error);

        foreach ($this->handlers as $key => $handler) {
            if ($this->levels[$key] <= $level) {
                try {
                    $handler->handle($error);
                } catch (Throwable $e) {
                    Log::error('Error handler failed', [
                        'handler' => get_class($handler),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    private function determineLevel(Throwable $error): int
    {
        if ($error instanceof \Error) {
            return 600;
        }

        if ($error instanceof \RuntimeException) {
            return 500;
        }

        if ($error instanceof \LogicException) {
            return 400;
        }

        return 300;
    }
}

// app/Core/Error/Handlers/LoggingHandler.php
<?php

namespace App\Core\Error\Handlers;

use App\Core\Error\Handlers\HandlerInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoggingHandler implements HandlerInterface
{
    public function handle(Throwable $error): void
    {
        Log::error($error->getMessage(), [
            'exception' => get_class($error),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString()
        ]);
    }
}

// app/Core/Error/Handlers/NotificationHandler.php
<?php

namespace App\Core\Error\Handlers;

use App\Core\Error\Handlers\HandlerInterface;
use App\Core\Notification\NotificationSender;
use Throwable;

class NotificationHandler implements HandlerInterface
{
    private NotificationSender $sender;
    
    public function __construct(NotificationSender $sender)
    {
        $this->sender = $sender;
    }

    public function handle(Throwable $error): void
    {
        $this->sender->sendUrgent('admin', [
            'type' => 'error',
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine()
        ]);
    }
}

// app/Core/Error/Handlers/DatabaseHandler.php
<?php

namespace App\Core\Error\Handlers;

use App\Core\Error\Handlers\HandlerInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseHandler implements HandlerInterface
{
    public function handle(Throwable $error): void
    {
        DB::table('errors')->insert([
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTraceAsString(),
            'created_at' => now()
        ]);
    }
}

// app/Core/Error/Handlers/HandlerInterface.php
<?php

namespace App\Core\Error\Handlers;

use Throwable;

interface HandlerInterface
{
    public function handle(Throwable $error): void;
}

// app/Core/Error/ErrorServiceProvider.php
<?php

namespace App\Core\Error;

use Illuminate\Support\ServiceProvider;
use App\Core\Error\ErrorHandler;
use App\Core\Error\Handlers\LoggingHandler;
use App\Core\Error\Handlers\NotificationHandler;
use App\Core\Error\Handlers\DatabaseHandler;

class ErrorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ErrorHandler::class, function ($app) {
            $handler = new ErrorHandler();
            
            $handler->register(new LoggingHandler(), 300);
            $handler->register(
                new NotificationHandler($app->make('notification.sender')),
                500
            );
            $handler->register(new DatabaseHandler(), 400);
            
            return $handler;
        });
    }
}

// app/Core/Error/Middleware/ErrorHandlingMiddleware.php
<?php

namespace App\Core\Error\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Error\ErrorHandler;
use Throwable;

class ErrorHandlingMiddleware
{
    private ErrorHandler $errorHandler;

    public function __construct(ErrorHandler $errorHandler)
    {
        $this->errorHandler = $errorHandler;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (Throwable $error) {
            $this->errorHandler->handle($error);
            throw $error;
        }
    }
}