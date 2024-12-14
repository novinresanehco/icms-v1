<?php

namespace App\Core\Http\Controllers;

use App\Core\Http\Requests\TemplateRequest;
use App\Core\Models\Template;
use App\Core\Services\Contracts\TemplateServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class TemplateController extends Controller
{
    protected TemplateServiceInterface $service;

    public function __construct(TemplateServiceInterface $service)
    {
        $this->service = $service;
    }

    public function index(): JsonResponse
    {
        $templates = $this->service->getTemplates(request()->all());
        return response()->json($templates);
    }

    public function store(TemplateRequest $request): JsonResponse
    {
        $template = $this->service->createTemplate($request->validated());
        return response()->json($template, Response::HTTP_CREATED);
    }

    public function show(Template $template): JsonResponse
    {
        return response()->json($template->load(['regions', 'author']));
    }

    public function update(TemplateRequest $request, Template $template): JsonResponse
    {
        $this->service->updateTemplate($template, $request->validated());
        return response()->json($template->fresh(['regions', 'author']));
    }

    public function destroy(Template $template): JsonResponse
    {
        $this->service->deleteTemplate($template);
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function compile(Template $template): JsonResponse
    {
        $compiled = $this->service->compileTemplate($template, request()->input('variables', []));
        return response()->json(['compiled' => $compiled]);
    }
}
