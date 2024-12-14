<?php

namespace App\Core\Security;

class RapidSecurityManager implements SecurityInterface 
{
    private $validator;
    private $monitor;

    public function __construct(RapidValidator $validator, Monitor $monitor) 
    {
        $this->validator = $validator;
        $this->monitor = $monitor;
    }

    public function executeOperation(Operation $op): Result 
    {
        // فقط validationهای حیاتی 
        $this->validateCriticalOnly($op);
        
        try {
            DB::beginTransaction();
            
            // اجرای عملیات با مانیتورینگ حداقلی
            $result = $this->monitor->quickTrack(function() use ($op) {
                return $op->execute(); 
            });

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function validateCriticalOnly(Operation $op): void
    {
        // فقط اعتبارسنجی‌های امنیتی ضروری
        if (!$this->validator->quickValidate($op)) {
            throw new SecurityException();
        }
    }
}
