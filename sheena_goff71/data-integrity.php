```php
namespace App\Core\Data;

class DataIntegrityEnforcer
{
    private const INTEGRITY_MODE = 'STRICT';
    private ValidationChain $validator;
    private IntegrityChecker $checker;
    private SecurityEnforcer $security;

    public function enforceIntegrity(DataOperation $operation): void
    {
        DB::transaction(function() use ($operation) {
            $this->validateOperation($operation);
            $this->checkIntegrity($operation);
            $this->enforceSecurityConstraints($operation);
            $this->verifyIntegrityState($operation);
        });
    }

    private function validateOperation(DataOperation $operation): void
    {
        if (!$this->validator->validate($operation)) {
            throw new ValidationException("Data operation validation failed");
        }
    }

    private function checkIntegrity(DataOperation $operation): void
    {
        $result = $this->checker->checkIntegrity($operation);
        if (!$result->isValid()) {
            throw new IntegrityException("Data integrity check failed");
        }
    }

    private function enforceSecurityConstraints(DataOperation $operation): void
    {
        $this->security->enforceConstraints($operation);
    }

    private function verifyIntegrityState(DataOperation $operation): void
    {
        $state = $this->checker->verifyState($operation);
        if (!$state->isVerified()) {
            throw new StateException("Integrity state verification failed");
        }
    }
}

class IntegrityChecker
{
    private HashValidator $hashValidator;
    private StateValidator $stateValidator;
    private ConsistencyChecker $consistencyChecker;

    public function checkIntegrity(DataOperation $operation): IntegrityResult
    {
        return new IntegrityResult(
            $this->validateHash($operation) &&
            $this->validateState($operation) &&
            $this->checkConsistency($operation)
        );
    }

    private function validateHash(DataOperation $operation): bool
    {
        return $this->hashValidator->validate($operation->getData());
    }

    private function validateState(DataOperation $operation): bool
    {
        return $this->stateValidator->validate($operation->getState());
    }

    private function checkConsistency(DataOperation $operation): bool
    {
        return $this->consistencyChecker->check($operation);
    }
}
```
