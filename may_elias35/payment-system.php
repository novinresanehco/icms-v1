<?php

namespace App\Core\Payments\Models;

class Payment extends Model
{
    protected $fillable = [
        'amount',
        'currency',
        'status',
        'provider',
        'payment_method',
        'reference',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'paid_at' => 'datetime'
    ];
}

class Transaction extends Model
{
    protected $fillable = [
        'payment_id',
        'type',
        'amount',
        'status',
        'reference',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array'
    ];
}

namespace App\Core\Payments\Services;

class PaymentProcessor
{
    private ProviderRegistry $providers;
    private PaymentRepository $repository;
    private TransactionManager $transactions;

    public function process(Payment $payment): PaymentResult
    {
        $provider = $this->providers->get($payment->provider);
        
        try {
            $result = $provider->process($payment);
            $this->updatePayment($payment, $result);
            return $result;
        } catch (\Exception $e) {
            $this->handleFailure($payment, $e);
            throw $e;
        }
    }

    public function verify(Payment $payment): bool
    {
        $provider = $this->providers->get($payment->provider);
        return $provider->verify($payment);
    }

    public function refund(Payment $payment): RefundResult
    {
        $provider = $this->providers->get($payment->provider);
        return $provider->refund($payment);
    }
}

class ProviderRegistry
{
    private array $providers = [];

    public function register(string $name, PaymentProvider $provider): void
    {
        $this->providers[$name] = $provider;
    }

    public function get(string $name): PaymentProvider
    {
        if (!isset($this->providers[$name])) {
            throw new ProviderNotFoundException("Provider {$name} not found");
        }
        return $this->providers[$name];
    }
}

abstract class PaymentProvider
{
    abstract public function process(Payment $payment): PaymentResult;
    abstract public function verify(Payment $payment): bool;
    abstract public function refund(Payment $payment): RefundResult;
}

class StripeProvider extends PaymentProvider
{
    private StripeClient $client;

    public function process(Payment $payment): PaymentResult
    {
        $charge = $this->client->charges->create([
            'amount' => $payment->amount * 100,
            'currency' => $payment->currency,
            'source' => $payment->payment_method,
            'metadata' => $payment->metadata
        ]);

        return new PaymentResult([
            'success' => true,
            'reference' => $charge->id,
            'status' => $charge->status
        ]);
    }

    public function verify(Payment $payment): bool
    {
        $charge = $this->client->charges->retrieve($payment->reference);
        return $charge->status === 'succeeded';
    }

    public function refund(Payment $payment): RefundResult
    {
        $refund = $this->client->refunds->create([
            'charge' => $payment->reference
        ]);

        return new RefundResult([
            'success' => true,
            'reference' => $refund->id,
            'status' => $refund->status
        ]);
    }
}

class TransactionManager
{
    public function createTransaction(Payment $payment, string $type): Transaction
    {
        return Transaction::create([
            'payment_id' => $payment->id,
            'type' => $type,
            'amount' => $payment->amount,
            'status' => 'pending',
            'metadata' => $payment->metadata
        ]);
    }

    public function updateTransaction(Transaction $transaction, string $status): void
    {
        $transaction->update([
            'status' => $status,
            'completed_at' => now()
        ]);
    }
}

namespace App\Core\Payments\Http\Controllers;

class PaymentController extends Controller
{
    private PaymentProcessor $processor;
    private PaymentRepository $repository;

    public function process(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0',
                'currency' => 'required|string|size:3',
                'provider' => 'required|string',
                'payment_method' => 'required|string'
            ]);

            $payment = $this->repository->create($request->all());
            $result = $this->processor->process($payment);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function verify(int $id): JsonResponse
    {
        try {
            $payment = $this->repository->find($id);
            $verified = $this->processor->verify($payment);

            return response()->json(['verified' => $verified]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function refund(int $id): JsonResponse
    {
        try {
            $payment = $this->repository->find($id);
            $result = $this->processor->refund($payment);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
