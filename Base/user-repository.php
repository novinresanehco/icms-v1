<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Interfaces\UserRepositoryInterface;

class UserRepository implements UserRepositoryInterface
{
    private const CACHE_PREFIX = 'user:';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly User $model
    ) {}

    public function findById(int $id, array $with = []): ?User
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with($with)->find($id)
        );
    }

    public function findByEmail(string $email): ?User
    {
        return Cache::remember(
            self::CACHE_PREFIX . "email:{$email}",
            self::CACHE_TTL,
            fn () => $this->model->where('email', $email)->first()
        );
    }

    public function create(array $data): User
    {
        $user = $this->model->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => $data['status'] ?? 'active',
            'email_verified_at' => $data['email_verified_at'] ?? null
        ]);

        if (isset($data['roles'])) {
            $user->roles()->sync($data['roles']);
        }

        if (isset($data['permissions'])) {
            $user->permissions()->sync($data['permissions']);
        }

        $this->clearCache($user->id);

        return $user;
    }

    public function update(int $id, array $data): bool
    {
        $user = $this->findById($id);
        
        if (!$user) {
            return false;
        }

        $updateData = [
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'status' => $data['status'] ?? $user->status
        ];

        if (isset($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        $updated = $user->update($updateData);

        if (isset($data['roles'])) {
            $user->roles()->sync($data['roles']);
        }

        if (isset($data['permissions'])) {
            $user->permissions()->sync($data['permissions']);
        }

        if ($updated) {
            $this->clearCache($id);
        }

        return