<?php
namespace App\Http\Controllers;

use App\Core\{AuthManager, ContentManager, SecurityManager, TemplateEngine};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};

class AuthController extends Controller
{
    private AuthManager $auth;
    private SecurityManager $security;

    public function __construct(AuthManager $auth, SecurityManager $security) 
    {
        $this->auth = $auth;
        $this->security = $security;
    }

    public function login(Request $request)
    {
        return DB::transaction(function() use ($request) {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string'
            ]);
            
            $user = $this->auth->authenticate($credentials);
            return ['token' => $this->security->encrypt($user)];
        });
    }

    public function logout(Request $request)
    {
        $token = $request->header('X-Auth-Token');
        Cache::forget('session:'.$token);
    }
}

class ContentController extends Controller
{
    private ContentManager $content;
    private SecurityManager $security;

    public function store(Request $request)
    {
        return DB::transaction(function() use ($request) {
            $data = $request->validate([
                'title' => 'required|string|max:200',
                'content' => 'required|string',
                'status' => 'required|in:draft,published'
            ]);

            return $this->content->createContent($data);
        });
    }

    public function update(Request $request, $id)
    {
        return DB::transaction(function() use ($request, $id) {
            $data = $request->validate([
                'title' => 'required|string|max:200',
                'content' => 'required|string',
                'status' => 'required|in:draft,published'
            ]);

            return $this->content->updateContent($id, $data);
        });
    }

    public function show($id)
    {
        $content = Cache::remember('content:'.$id, 3600, function() use ($id) {
            return $this->content->getContent($id);
        });

        abort_if(!$content, 404, 'Content not found');
        return $content;
    }

    public function destroy($id)
    {
        return DB::transaction(function() use ($id) {
            $deleted = $this->content->deleteContent($id);
            Cache::forget('content:'.$id);
            return $deleted;
        });
    }
}

class TemplateController extends Controller
{
    private TemplateEngine $templates;

    public function __construct(TemplateEngine $templates)
    {
        $this->templates = $templates;
    }

    public function render(Request $request)
    {
        return DB::transaction(function() use ($request) {
            $template = Cache::remember(
                'template:'.$request->path,
                3600,
                fn() => $this->templates->getTemplate($request->path)
            );

            abort_if(!$template, 404, 'Template not found');

            return $this->templates->render($template, $request->data ?? []);
        });
    }
}

class AdminController extends Controller
{
    private SecurityManager $security;
    private ContentManager $content;

    public function dashboard(Request $request)
    {
        return [
            'content_count' => Cache::remember('stats:content_count', 3600, 
                fn() => DB::table('contents')->count()
            ),
            'recent_content' => Cache::remember('stats:recent_content', 300,
                fn() => DB::table('contents')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
            ),
            'user_count' => Cache::remember('stats:user_count', 3600,
                fn() => DB::table('users')->count()
            )
        ];
    }

    public function clearCache(Request $request)
    {
        return DB::transaction(function() use ($request) {
            $tags = $request->input('tags', ['content', 'templates', 'users']);
            foreach ($tags as $tag) {
                Cache::tags($tag)->flush();
            }
            return ['status' => 'Cache cleared'];
        });
    }
}

class MediaController extends Controller
{
    private SecurityManager $security;
    private string $uploadPath = 'uploads';

    public function store(Request $request)
    {
        return DB::transaction(function() use ($request) {
            $file = $request->file('file');
            $path = $file->store($this->uploadPath);
            
            $id = DB::table('media')->insertGetId([
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'created_at' => now()
            ]);

            return ['id' => $id, 'path' => $path];
        });
    }

    public function show($id)
    {
        $media = Cache::remember('media:'.$id, 3600, function() use ($id) {
            return DB::table('media')->find($id);
        });

        abort_if(!$media, 404, 'Media not found');
        return $media;
    }
}
