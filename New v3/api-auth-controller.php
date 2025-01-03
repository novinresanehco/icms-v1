<?php

namespace App\Http\Controllers\Api;

use App\Core\Security\{
    SecurityManager,
    TokenManager,
    AuthManager,
    RateLimiter
};
use App\Core\Logging\AuditLogger;

class ApiAuthController extends Controller
{
    private SecurityManager $security;
    private TokenManager $tokens;
    private AuthManager $auth;
    private RateLimiter $limiter;
    private AuditLogger $audit;
    
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            // Rate limit check
            $this->limiter->check('api_login', $request->ip());
            
            // Validate credentials
            $user = $this->auth->validate($request->credentials());
            
            // Generate API token
            $token = $this->tokens->generate([
                'user_id' => $user->id,
                'scopes' => $request->scopes ?? ['api'],
                'expires' => config('auth.api_token_lifetime')
            ]);
            
            $this->audit->logApiLogin($user, $request);
            
            return response()->json([
                'token' => $token->toString(),
                'expires_at' => $token->expiresAt,
                'scopes' => $token->scopes
            ]);
        } catch (AuthenticationException $e) {
            $this->limiter->increment('api_login', $request->ip());
            throw $e;
        }
    }
    
    public function refresh(RefreshRequest $request): JsonResponse
    {
        $token = $this->tokens->validate($request->token());
        
        $newToken = $this->tokens->refresh($token, [
            'extends' => config('auth.api_token_lifetime')
        ]);
        
        $this->audit->logTokenRefresh($token, $newToken);
        
        return response()->json([
            'token' => $newToken->toString(),
            'expires_at' => $newToken->expiresAt
        ]);
    }
    
    public function revoke(Request $request): JsonResponse 
    {
        $token = $this->tokens->validate($request->bearerToken());
        
        $this->tokens->revoke($token);
        $this->audit->logTokenRevocation($token);
        
        return response()->json([
            'message' => 'Token revoked successfully'
        ]);
    }
    
    public function validate(Request $request): JsonResponse
    {
        $token = $this->tokens->validate($request->bearerToken());
        
        return response()->json([
            'valid' => true,
            'user' => $token->user,
            'scopes' => $token->scopes,
            'expires_at' => $token->expiresAt
        ]);
    }
    
    protected function getTwoFactorChallenge(User $user): array
    {
        return [
            'required' => $user->requiresTwoFactor(),
            'methods' => $user->getEnabledTwoFactorMethods(),
            'challenge' => $this->auth->generateTwoFactorChallenge($user)
        ];
    }
    
    protected function validateTwoFactor(string $code, User $user): void
    {
        if (!$this->auth->verifyTwoFactorCode($code, $user)) {
            throw new InvalidTwoFactorCodeException();
        }
    }
}
