<?php

namespace App\Services;

use App\Events\UserCreated;
use App\Events\UserDeleted;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserService
{
    protected UserRepositoryInterface $userRepository;
    
    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }
    
    public function createUser(array $data): ?int
    {
        $this->validateUserData($data);
        
        $userId = $this->userRepository->create($data);
        
        if ($userId) {
            Event::dispatch(new UserCreated($userId));
        }
        
        return $userId;
    }
    
    public function updateUser(int $userId, array $data): bool
    {
        $this->validateUserData($data, $userId);
        return $this->userRepository->update($userId, $data);
    }
    
    public function deleteUser(int $userId): bool
    {
        $result = $this->userRepository->delete($userId);
        
        if ($result) {
            Event::dispatch(new UserDeleted($userId));
        }
        
        return $result;
    }
    
    public function getUser(int $userId): ?array
    {
        return $this->userRepository->get($userId);
    }
    
    public function findUserByEmail(string $email): ?array
    {
        return $this->userRepository->findByEmail($email);
    }
    
    public function getAllUsersPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->userRepository->getAllPaginated($filters, $perPage);
    }
    
    public function assignUserRole(int $userId, string $role): bool
    {
        return $this->userRepository->assignRole($userId, $role);
    }
    
    public function removeUserRole(int $userId, string $role): bool
    {
        return $this->userRepository->removeRole($userId, $role);
    }
    
    public function updateUserProfile(int $userId, array $data): bool
    {
        $this->validateProfileData($data);
        return $this->userRepository->updateProfile($userId, $data);
    }
    
    public function updateUserPassword(int $userId, string $password): bool
    {
        $this->validatePassword($password);
        return $this->userRepository->updatePassword($userId, $password);
    }
    
    public function getUsersByRole(string $role): Collection
    {
        return $this->userRepository->getByRole($role);
    }
    
    protected function validateUserData(array $data, ?int $userId = null): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email' . ($userId ? ",$userId" : ''),
            'username' => 'nullable|string|max:255|unique:users,username' . ($userId ? ",$userId" : ''),
            'password' => $userId ? 'nullable|string|min:8' : 'required|string|min:8',
            'roles' => 'array',
            'roles.*' => 'string|exists:roles,name',
            'is_active' => 'boolean',
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
    
    protected function validateProfileData(array $data): void
    {
        $validator = Validator::make($data, [
            'bio' => 'nullable|string|max:1000',
            'profile_photo' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
    
    protected function validatePassword(string $password): void
    {
        $validator = Validator::make(['password' => $password], [
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
