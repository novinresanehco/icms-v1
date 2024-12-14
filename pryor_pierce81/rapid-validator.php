<?php

namespace App\Core\Validation;

class RapidValidator
{
    public function quickValidate(Operation $op): bool
    {
        // فقط validation موارد بحرانی
        return $this->validateCriticalSecurity($op) && 
               $this->validateCriticalData($op);
    }

    private function validateCriticalSecurity(Operation $op): bool
    {
        // چک موارد امنیتی حیاتی مثل:
        // - احراز هویت 
        // - سطح دسترسی
        // - ورودی‌های حساس
        return true;
    }
    
    private function validateCriticalData(Operation $op): bool
    {
        // اعتبارسنجی داده‌های حیاتی
        // - فیلدهای اجباری
        // - فرمت‌های حساس
        return true;  
    }
}
