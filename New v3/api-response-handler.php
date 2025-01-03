<?php

namespace App\Http\Response;

use Illuminate\Http\JsonResponse;
use App\Core\Contracts\ResponseFormatterInterface;

class ApiResponseHandler implements ResponseFormatterInterface
{
    private array $meta = [];
    private array $links = [];
    private array $debug = [];
    private bool $shouldWrap = true;

    public function success($data, int $status = 200): JsonResponse
    {
        $response = $this->shouldWrap ? [
            'status' => 'success',
            'data' => $data
        ] : $data;

        return $this->buildResponse($response, $status);
    }

    public function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $response = [
            'status' => 'error',
            'message' => $message,
            'code' => $status
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return $this->buildResponse($response, $status);
    }

    public function paginated($data, array $pagination): JsonResponse
    {
        $response = [
            'status' => 'success',
            'data' => $data,
            'pagination' => [
                'total' => $pagination['total'],
                'per_page' => $pagination['per_page'],
                'current_page' => $pagination['current_page'],
                'last_page' => $pagination['last_page']
            ]
        ];

        return $this->buildResponse($response);
    }

    public function withMeta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);
        return $this;
    }

    public function withLinks(array $links): self
    {
        $this->links = array_merge($this->links, $links);
        return $this;
    }

    public function withDebug(array $debug): self
    {
        if (config('app.debug')) {
            $this->debug = array_merge($this->debug, $debug);
        }
        return $this;
    }

    public function raw(): self
    {
        $this->shouldWrap = false;
        return $this;
    }

    protected function buildResponse($data, int $status = 200): JsonResponse
    {
        $response = $data;

        if (!empty($this->meta)) {
            $response['meta'] = $this->meta;
        }

        if (!empty($this->links)) {
            $response['links'] = $this->links;
        }

        if (!empty($this->debug)) {
            $response['debug'] = $this->debug;
        }

        return response()->json($response, $status, [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Cache-Control' => 'no-cache, private'
        ]);
    }

    protected function addPaginationHeaders(JsonResponse $response, array $pagination): JsonResponse
    {
        return $response->withHeaders([
            'X-Total-Count' => $pagination['total'],
            'X-Per-Page' => $pagination['per_page'],
            'X-Current-Page' => $pagination['current_page'],
            'X-Last-Page' => $pagination['last_page']
        ]);
    }

    protected function sanitizeData($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeData'], $data);
        }

        if (is_string($data)) {
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }

        return $data;
    }

    protected function validateResponse($data): void
    {
        if (is_resource($data)) {
            throw new InvalidResponseException('Cannot JSON encode resource');
        }

        if (is_object($data) && !method_exists($data, 'toArray')) {
            throw new InvalidResponseException('Object must implement toArray()');
        }
    }
}
