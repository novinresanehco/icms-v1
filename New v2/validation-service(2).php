<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Validator;
use App\Core\Exceptions\ValidationException;

abstract class BaseValidationService implements ValidationInterface
{
    public function validate(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException(
                $validator->errors()->first(),
                $validator->errors()->toArray()
            );
        }

        return $validator->validated();
    }

    public function validateWithContext(array $data, array $rules, array $context): array
    {
        $mergedRules = $this->mergeContextRules($rules, $context);
        return $this->validate($data, $mergedRules);
    }

    protected function mergeContextRules(array $rules, array $context): array
    {
        foreach ($rules as $field => $rule) {
            if (isset($context[$field])) {
                $rules[$field] .= '|' . $context[$field];
            }
        }
        return $rules;
    }
}
