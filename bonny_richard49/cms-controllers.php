<?php

namespace App\Http\Controllers\Api;

class ContentController extends Controller
{
    private CMSService $service;
    private SecurityEnforcer $security;
    private ValidationService $validator;

    public function store(Request $request): JsonResponse
    {
        return $this->security->enforceOperation(
            function() use ($request) {
                $operation = new Operation(
                    type: OperationType::CREATE,
                    data: $this->validator->validate(
                        $request->all(),
                        $this->getValidationRules()
                    )
                );
                
                $result = $this->service->executeOperation($operation);
                
                return response()->json($result, 201);
            }
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->security->enforceOperation(
            function() use ($request, $id) {
                $operation = new Operation(
                    type: OperationType::UPDATE,
                    id: $id,
                    data: $this->validator->validate(
                        $request->all(),
                        $this->getUpdateRules()
                    )
                );
                
                $result = $this->service->executeOperation($operation);
                
                return response()->json($result);
            }
        );
    }

    public function delete(int $id): JsonResponse
    {
        return $this->security->enforceOperation(
            function() use ($id) {
                $operation = new Operation(
                    type: OperationType::DELETE,
                    id: $id
                );
                
                $result = $this->service->executeOperation($operation);
                
                return response()->json(null, 204);
            }
        );
    }

    public function query(Request $request): JsonResponse
    {
        return $this->security->enforceOperation(
            function() use ($request) {
                $operation = new Operation(
                    type: OperationType::QUERY,
                    criteria: $this->buildCriteria($request)
                );
                
                $result = $this->service->executeOperation($operation);
                
                return response()->json($result);
            }
        );
    }

    private function buildCriteria(Request $request): array
    {
        return $this->validator->validate(
            $request->all(),
            $this->getQueryRules()
        );
    }
}