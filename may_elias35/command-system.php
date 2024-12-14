<?php

namespace App\Core\Commands\Models;

class Command extends Model
{
    protected $fillable = [
        'name',
        'handler',
        'priority',
        'timeout',
        'status',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'executed_at' => 'datetime'
    ];
}

class CommandHistory extends Model
{
    protected $fillable = [
        'command_id',
        'status',
        'output',
        'error',
        'executed_at',
        'completed_at'
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'completed_at' => 'datetime'
    ];
}

namespace App\Core\Commands\Services;

class CommandBus
{
    private HandlerRegistry $registry;
    private CommandValidator $validator;
    private HistoryManager $history;

    public function dispatch($command): mixed
    {
        $this->validator->validate($command);
        
        $handler = $this->registry->getHandler($command);
        $history = $this->history->create($command);
        
        try {
            $result = $handler->handle($command);
            $this->history->complete($history, $result);
            return $result;
        } catch (\Exception $e) {
            $this->history->fail($history, $e);
            throw $e;
        }
    }
}

class HandlerRegistry
{
    private array $handlers = [];

    public function register(string $commandClass, string $handlerClass): void
    {
        $this->handlers[$commandClass] = $handlerClass;
    }

    public function getHandler($command): CommandHandler
    {
        $commandClass = get_class($command);
        
        if (!isset($this->handlers[$commandClass])) {
            throw new HandlerNotFoundException("No handler found for {$commandClass}");
        }
        
        return app($this->handlers[$commandClass]);
    }
}

class CommandValidator
{
    public function validate($command): void
    {
        if (method_exists($command, 'validate')) {
            $command->validate();
        }
    }
}

class HistoryManager
{
    private CommandHistoryRepository $repository;

    public function create($command): CommandHistory
    {
        return $this->repository->create([
            'command_id' => $command->id,
            'status' => 'executing',
            'executed_at' => now()
        ]);
    }

    public function complete(CommandHistory $history, $result): void
    {
        $this->repository->update($history->id, [
            'status' => 'completed',
            'output' => $result,
            'completed_at' => now()
        ]);
    }

    public function fail(CommandHistory $history, \Exception $e): void
    {
        $this->repository->update($history->id, [
            'status' => 'failed',
            'error' => $e->getMessage(),
            'completed_at' => now()
        ]);
    }
}

abstract class CommandHandler
{
    abstract public function handle($command);
}

namespace App\Core\Commands\Http\Controllers;

class CommandController extends Controller
{
    private CommandBus $commandBus;

    public function execute(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'command' => 'required|string',
                'parameters' => 'array'
            ]);

            $command = $this->createCommand(
                $request->input('command'),
                $request->input('parameters', [])
            );

            $result = $this->commandBus->dispatch($command);

            return response()->json(['result' => $result]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $history = CommandHistory::with('command')
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($history);
    }
}

namespace App\Core\Commands\Console;

class ExecuteCommandCommand extends Command
{
    protected $signature = 'command:execute {name} {--parameters=}';

    public function handle(CommandBus $commandBus): void
    {
        $command = $this->createCommand(
            $this->argument('name'),
            json_decode($this->option('parameters') ?? '{}', true)
        );

        try {
            $result = $commandBus->dispatch($command);
            $this->info('Command executed successfully.');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->error('Command execution failed: ' . $e->getMessage());
        }
    }
}
