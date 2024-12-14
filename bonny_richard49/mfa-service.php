<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Security\{SecurityConfig, SecurityException};
use App\Core\Interfaces\MFAServiceInterface;

class MFAService implements MFAServiceInterface
{
    private SecurityConfig $config;
    private AuditLogger $auditLogger;
    private EncryptionService $encryption;
    private NotificationService $notifier;

    public function __construct(
        SecurityConfig $config,
        AuditLogger $auditLogger,
        EncryptionService $encryption,
        NotificationService $notifier
    ) {
        $this->config = $config;
        $this->auditLogger = $auditLogger;
        $this->encryption = $encryption;
        $this->notifier = $notifier;
    }

    public function setupMFA(User $user, string $method): MFACredentials
    {
        DB::beginTransaction();
        try {
            // Validate MFA method
            if (!$this->isMethodSupported($method)) {
                throw new UnsupportedMFAMethodException("MFA method not supported: {$method}");
            }

            // Generate MFA credentials based on method
            $credentials = match($method) {
                'totp' => $this->setupTOTP($user),
                'sms' => $this->setupSMS($user),
                'email' => $this->setupEmail($user),
                'backup_codes' => $this->generateBackupCodes($user),
                default => throw new UnsupportedMFAMethodException()
            };

            // Store MFA settings
            $this->storeMFACredentials($user, $credentials, $method);

            // Log MFA setup
            $this->auditLogger->logMFASetup($user->id, $method);

            DB::commit();
            return $credentials;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMFAError($e, 'setup', ['user_id' => $user->id, 'method' => $method]);
            throw new MFASetupException(
                'Failed to setup MFA',
                previous: $e
            );
        }
    }

    public function verifyMFA(User $user, string $token, ?string $method = null): bool
    {
        try {
            // Get active MFA method if not specified
            $method = $method ?? $user->mfa_method;

            // Verify token based on method
            $isValid = match($method) {
                'totp' => $this->verifyTOTP($user, $token),
                'sms' => $this->verifySMSToken($user, $token),
                'email' => $this->verifyEmailToken($user, $token),
                'backup_codes' => $this->verifyBackupCode($user, $token),
                default => throw new UnsupportedMFAMethodException()
            };

            if (!$isValid) {
                $this->handleFailedVerification($user, $method);
                return false;
            }

            // Log successful verification
            $this->auditLogger->logMFAVerification($user->id, $method, true);

            return true;

        } catch (\Exception $e) {
            $this->handleMFAError($e, 'verify', [
                'user_id' => $user->id,
                'method' => $method
            ]);
            throw new MFAVerificationException(
                'MFA verification failed',
                previous: $e
            );
        }
    }

    public function generateMFAToken(User $user, string $method): string
    {
        try {
            // Generate token based on method
            $token = match($method) {
                'sms' => $this->generateSMSToken(),
                'email' => $this->generateEmailToken(),
                default => throw new UnsupportedMFAMethodException()
            };

            // Store token with expiration
            $this->storeMFAToken($user, $token, $method);

            // Send token to user
            $this->sendMFAToken($user, $token, $method);

            return $token;

        } catch (\Exception $e) {
            $this->handleMFAError($e, 'generate', [
                'user_id' => $user->id,
                'method' => $method
            ]);
            throw new MFATokenGenerationException(
                'Failed to generate MFA token',
                previous: $e
            );
        }
    }

    public function disableMFA(User $user): void
    {
        DB::transaction(function() use ($user) {
            // Remove MFA credentials
            $user->mfa_credentials = null;
            $user->mfa_method = null;
            $user->backup_codes = null;
            $user->save();

            // Log MFA disable
            $this->auditLogger->logMFADisabled($user->id);
        });
    }

    private function setupTOTP(User $user): MFACredentials
    {
        $secret = $this->generateTOTPSecret();
        return new MFACredentials(
            method: 'totp',
            secret: $secret,
            qr_code: $this->generateTOTPQRCode($user, $secret)
        );
    }

    private function setupSMS(User $user): MFACredentials
    {
        if (!$user->phone_number) {
            throw new MFASetupException('Phone number required for SMS MFA');
        }
        return new MFACredentials(method: 'sms');
    }

    private function setupEmail(User $user): MFACredentials
    {
        return new MFACredentials(method: 'email');
    }

    private function generateBackupCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = bin2hex(random_bytes(16));
        }
        return $codes;
    }

    private function verifyTOTP(User $user, string $token): bool
    {
        $secret = $this->decryptMFASecret($user->mfa_credentials['secret']);
        return $this->validateTOTPToken($token, $secret);
    }

    private function verifySMSToken(User $user, string $token): bool
    {
        $storedToken = Cache::get("mfa:sms:{$user->id}");
        return $storedToken && hash_equals($storedToken, $token);
    }

    private function verifyEmailToken(User $user, string $token): bool
    {
        $storedToken = Cache::get("mfa:email:{$user->id}");
        return $storedToken && hash_equals($storedToken, $token);
    }

    private function verifyBackupCode(User $user, string $code): bool
    {
        $codes = $user->backup_codes;
        if (!$codes || !in_array($code, $codes)) {
            return false;
        }

        // Remove used backup code
        $codes = array_diff($codes, [$code]);
        $user->backup_codes = $codes;
        $user->save();

        return true;
    }

    private function generateSMSToken(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function generateEmailToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function storeMFAToken(User $user, string $token, string $method): void
    {
        Cache::put(
            "mfa:{$method}:{$user->id}",
            $token,
            now()->addMinutes($this->config->getMFATokenExpiry())
        );
    }

    private function sendMFAToken(User $user, string $token, string $method): void
    {
        match($method) {
            'sms' => $this->notifier->sendSMS($user->phone_number, $token),
            'email' => $this->notifier->sendEmail($user->email, $token),
            default => throw new UnsupportedMFAMethodException()
        };
    }

    private function handleFailedVerification(User $user, string $method): void
    {
        $key = "mfa:failures:{$user->id}";
        $attempts = Cache::increment($key);

        if ($attempts === 1) {
            Cache::put($key, 1, now()->addMinutes(30));
        }

        if ($attempts >= $this->config->getMaxMFAAttempts()) {
            $this->lockMFAAccess($user);
        }

        $this->auditLogger->logMFAVerification($user->id, $method, false);
    }

    private function lockMFAAccess(User $user): void
    {
        $user->mfa_locked_until = now()->addMinutes(30);
        $user->save();
        $this->auditLogger->logMFALocked($user->id);
    }

    private function handleMFAError(\Exception $e, string $operation, array $context): void
    {
        $this->auditLogger->logMFAError($e, $operation, $context);
    }

    private function isMethodSupported(string $method): bool
    {
        return in_array($method, ['totp', 'sms', 'email', 'backup_codes']);
    }
}
