<?php

namespace App\Core\Security;

class RequestPipeline implements PipelineInterface
{
    private SecurityEnforcer $security;
    private ValidationChain $validator;
    private AuditLogger $logger;

    public function process(Request $request): Response
    {
        return DB::transaction(function() use ($request) {
            $context = $this->createContext($request);
            
            $this->validator->validateChain([
                new AuthenticationValidator($context),
                new AuthorizationValidator($context),
                new InputValidator($context),
                new RateLimitValidator($context),
                new ResourceValidator($context)
            ]);
            
            $operation = $this->createOperation($context);
            
            $result = $this->executeOperation($operation, $context);
            
            $this->validateResult($result, $context);
            
            return $this->createResponse($result);
        });
    }

    protected function executeOperation(Operation $operation, Context $context): Result
    {
        $this->logger->logOperationStart($context);
        
        try {
            $result = $operation->execute();
            $this->logger->logOperationSuccess($context);
            return $result;
        } catch (\Exception $e) {
            $this->logger->logOperationFailure($e, $context);
            throw $e;
        }
    }

    protected function validateResult(Result $result, Context $context): void
    {
        $this->validator->validateChain([
            new ResultIntegrityValidator($result),
            new SecurityComplianceValidator($result),
            new DataConsistencyValidator($result)
        ]);
    }
}