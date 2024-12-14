<?php

namespace App\Core\Session;

class SessionManager implements SessionInterface 
{
    private SecurityManager $security;
    private EncryptionService $encryption;
    private StateManager $state;
    private AuditLogger $logger;

    public function start(): void
    {
        $this->security->executeCriticalOperation(
            new StartSessionOperation(
                $this->state,
                $this->encryption,
                $this->logger
            )
        );
    }

    public function store(string $key, $value): void
    {
        $this->security->executeCriticalOperation(
            new StoreSessionOperation(
                $key,
                $value,
                $this->state,
                $this->encryption
            )
        );
    }

    public function retrieve(string $key)
    {
        return $this->security->executeCriticalOperation(
            new RetrieveSessionOperation(
                $key,
                $this->state,
                $this->encryption
            )
        );
    }
}

class StartSessionOperation implements CriticalOperation 
{
    private StateManager $state;
    private EncryptionService $encryption;
    private AuditLogger $logger;

    public function execute(): void
    {
        $sessionId = $this->generateSecureId();
        
        $this->state->initialize([
            'id' => $sessionId,
            'created' => time(),
            'last_active' => time(),
            'data' => []
        ]);

        $this->logger->logSessionStart($sessionId);
    }

    private function generateSecureId(): string 
    {
        return bin2hex(random_bytes(32));
    }
}

class StoreSessionOperation implements CriticalOperation 
{
    private string $key;
    private $value;
    private StateManager $state;
    private EncryptionService $encryption;

    public function execute(): void
    {
        $this->validateKey($this->key);
        
        $encrypted = $this->encryption->encrypt(
            serialize($this->value)
        );

        $this->state->set("session.data.{$this->key}", $encrypted);
    }

    private function validateKey(string $key): void 
    {
        if (strlen($key) > 64) {
            throw new SessionException('Session key too long');
        }

        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
            throw new SessionException('Invalid session key format');
        }
    }
}

class RetrieveSessionOperation implements CriticalOperation 
{
    private string $key;
    private StateManager $state;
    private EncryptionService $encryption;

    public function execute()
    {
        $encrypted = $this->state->get("session.data.{$this->key}");
        
        if ($encrypted === null) {
            return null;
        }

        $decrypted = $this->encryption->decrypt($encrypted);
        return unserialize($decrypted);
    }
}

class SecureSessionHandler implements \SessionHandlerInterface 
{
    private SecurityManager $security;
    private StorageInterface $storage;
    private EncryptionService $encryption;
    private AuditLogger $logger;

    public function open($path, $name): bool
    {
        try {
            $this->validateSession($path, $name);
            $this->logger->logSessionOpen($name);
            return true;
        } catch (\Exception $e) {
            $this->logger->logSessionError($e);
            return false;
        }
    }

    public function read($id): string
    {
        try {
            $data = $this->storage->read($id);
            
            if ($data === false) {
                return '';
            }

            return $this->encryption->decrypt($data);
        } catch (\Exception $e) {
            $this->logger->logSessionError($e);
            return '';
        }
    }

    public function write($id, $data): bool
    {
        try {
            $encrypted = $this->encryption->encrypt($data);
            return $this->storage->write($id, $encrypted);
        } catch (\Exception $e) {
            $this->logger->logSessionError($e);
            return false;
        }
    }

    private function validateSession(string $path, string $name): void
    {
        if (!$this->security->validateSessionPath($path)) {
            throw new SecurityException('Invalid session path');
        }

        if (!$this->security->validateSessionName($name)) {
            throw new SecurityException('Invalid session name');
        }
    }
}

class SessionEncryption
{
    private string $key;
    private string $cipher = 'aes-256-gcm';
    
    public function encrypt(string $data): EncryptedData
    {
        $iv = random_bytes(16);
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        return new EncryptedData($encrypted, $iv, $tag);
    }

    public function decrypt(EncryptedData $data): string
    {
        $decrypted = openssl_decrypt(
            $data->getContent(),
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $data->getIv(),
            $data->getTag()
        );

        if ($decrypted === false) {
            throw new SecurityException('Failed to decrypt session data');
        }

        return $decrypted;
    }
}
