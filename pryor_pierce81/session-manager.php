<?php

namespace App\Core\Security;

use App\Core\Exception\SessionException;
use App\Core\Security\Encryption\EncryptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class SessionManager implements SessionManagerInterface
{
    private EncryptionService $encryption;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        EncryptionService $encryption,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->encryption = $encryption;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function start(array $data): string
    {
        $sessionId = $this->generateSessionId();

        try {
            DB::beginTransaction();

            $this->validateSessionData($data);
            $token = $this->createSession($sessionId, $data);
            
            $this->logSessionStart($sessionId, $data['user_id']);

            DB::commit();
            return $token;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSessionFailure($sessionId, 'start', $e);
            throw new SessionException('Session start failed', 0, $e);
        }
    }

    public function get(string $token): ?array
    {
        try {
            $this->validateToken($token);
            $sessionId = $this->getSessionId($token);
            
            if (!$sessionId) {
                return null;
            }

            return $this->getSessionData($sessionId);

        } catch (\Exception $e) {
            $this->handleSessionFailure($token, 'get', $e);
            throw new SessionException('Session retrieval failed', 0, $e);
        }
    }

    public function update(string $token, array $data): void
    {
        try {
            DB::beginTransaction();

            $this->validateToken($token);
            $sessionId = $this->getSessionId($token);
            
            if (!$sessionId) {
                throw new SessionException('Session not found');
            }

            $this->updateSessionData($sessionId, $data);
            $this->logSessionUpdate($sessionId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSessionFailure($token, 'update', $e);
            throw new SessionException('Session update failed', 0, $e);
        }
    }

    public function terminate(string $token): void
    {
        try {
            DB::beginTransaction();

            $this->validateToken($token);
            $sessionId = $this->getSessionId($token);
            
            if ($sessionId) {
                $this->removeSession($sessionId);
                $this->logSessionTermination($sessionId);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSessionFailure($token, 'terminate', $e);
            throw new SessionException('Session termination failed', 0, $e);
        }
    }

    private function validateSessionData(array $data): void
    {
        $required = ['user_id', 'ip_address', 'user_agent', 'expires_at'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new SessionException("Missing required field: {$field}");
            }
        }

        if ($data['expires_at'] <= now()) {
            throw new SessionException('Invalid expiration time');
        }
    }

    private function createSession(string $sessionId, array $data): string
    {
        $token = $this->encryption->generateSecureToken();
        $encryptedData = $this->encryption->encrypt(json_encode($data));

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'token' => $token,
            'data' => $encryptedData,
            'user_id' => $data['user_id'],
            'ip_address' => $data['ip_address'],
            'user_agent' => $data['user_agent'],
            'expires_at' => $data['expires_at'],
            'created_at' => now()
        ]);

        Cache::put("session_token:{$token}", $sessionId, $this->config['cache_ttl']);

        return $token;
    }

    private function getSessionId(string $token): ?string
    {
        return Cache::remember(
            "session_token:{$token}",
            $this->config['cache_ttl'],
            function() use ($token) {
                return DB::table('sessions')
                    ->where('token', $token)
                    ->where('expires_at', '>', now())
                    ->value('id');
            }
        );
    }

    private function getSessionData(string $sessionId): ?array
    {
        $session = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            return null;
        }

        $data = json_decode(
            $this->encryption->decrypt($session->data),
            true
        );

        return array_merge($data, [
            'session_id' => $session->id,
            'created_at' => $session->created_at
        ]);
    }

    private function updateSessionData(string $sessionId, array $data): void
    {
        $encryptedData = $this->encryption->encrypt(json_encode($data));

        DB::table('sessions')
            ->where('id', $sessionId)
            ->update([
                'data' => $encryptedData,
                'updated_at' => now()
            ]);

        Cache::forget("session_data:{$sessionId}");
    }

    private function removeSession(string $sessionId): void
    {
        $token = DB::table('sessions')
            ->where('id', $sessionId)
            ->value('token');

        DB::table('sessions')
            ->where('id', $sessionId)
            ->delete();

        if ($token) {
            Cache::forget("session_token:{$token}");
        }
        Cache::forget("session_data:{$sessionId}");
    }

    private function validateToken(string $token): void
    {
        if (strlen($token) !== $this->config['token_length']) {
            throw new SessionException('Invalid token length');
        }

        if (!preg_match($this->config['token_pattern'], $token)) {
            throw new SessionException('Invalid token format');
        }
    }

    private function generateSessionId(): string
    {
        return uniqid('session_', true);
    }

    private function getDefaultConfig(): array
    {
        return [
            'token_length' => 64,
            'token_pattern' => '/^[A-Za-z0-9]+$/',
            'cache_ttl' => 3600,
            'cleanup_interval'