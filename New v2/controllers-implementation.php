<?php

namespace App\Http\Controllers;

class AuthController extends Controller
{
    private AuthenticationManager $auth;
    private ValidationService $validator;

    public function login(Request $request): JsonResponse
    {
        $credentials = $this->validator->validate($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        $token = $this->auth->authenticate($credentials);

        return response()->json([
            'token' => $token->toString(),
            'expires' => $token->expiresAt()
        ]);
    }

    public function verify2FA(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request->all(), [
            'code' => 'required|string|size:6'
        ]);

        $token = $this->auth->verify2FA($request->user(), $data['code']);

        return response()->json([
            'token' => $token->toString(),
            'expires' => $token->expiresAt()
        ]);
    }
}

class ContentController extends Controller
{
    private ContentManager $content;
    private ValidationService $validator;
    private SecurityManager $security;

    public function store(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        $content = $this->content->store(array_merge($data, [
            'author_id' => $request->user()->id
        ]));

        return response()->json($content, Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $content = $this->content->retrieve($id);
        
        if (!$this->security->validateAccess($request->user(), $content)) {
            throw new AuthorizationException('Access denied');
        }

        return response()->json($content);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $content = $this->content->retrieve($id);
        
        if (!$this->security->validateAccess($request->user(), $content)) {
            throw new AuthorizationException('Access denied');
        }

        $data = $this->validator->validate($request->all(), [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published'
        ]);

        $updated = $this->content->update($id, $data);

        return response()->json($updated);
    }
}

class MediaController extends Controller
{
    private MediaManager $media;
    private ValidationService $validator;
    private SecurityManager $security;

    public function upload(Request $request): JsonResponse
    {
        $file = $this->validator->validateFile($request->file('file'), [
            'mime_types' => ['image/*', 'application/pdf'],
            'max_size' => '10M'
        ]);

        $data = $this->validator->validate($request->all(), [
            'type' => 'required|in:image,document'
        ]);

        $media = $this->media->store($file, array_merge($data, [
            'uploader_id' => $request->user()->id
        ]));

        return response()->json($media, Response::HTTP_CREATED);
    }

    public function show(int $id): JsonResponse
    {
        $media = $this->media->retrieve($id);
        
        if (!$this->security->validateAccess($request->user(), $media)) {
            throw new AuthorizationException('Access denied');
        }

        return response()->json($media);
    }
}
