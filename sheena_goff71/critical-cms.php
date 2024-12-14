<?php
namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Auth};
use App\Core\Security\SecurityManager;

class CoreCMS {
    private SecurityManager $security;
    private ContentRepository $content;
    private MediaManager $media;

    public function __construct(
        SecurityManager $security,
        ContentRepository $content,
        MediaManager $media
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->media = $media;
    }

    public function handleRequest($request) {
        return $this->security->executeCriticalOperation(
            fn() => $this->processRequest($request),
            new SecurityContext($request)
        );
    }

    private function processRequest($request) {
        return match($request->type) {
            'content' => $this->handleContent($request),
            'media' => $this->handleMedia($request),
            'auth' => $this->handleAuth($request),
            default => throw new \InvalidArgumentException('Invalid request type')
        };
    }

    private function handleContent($request) {
        return match($request->action) {
            'create' => $this->content->create($request->data),
            'update' => $this->content->update($request->id, $request->data),
            'delete' => $this->content->delete($request->id),
            'get' => $this->content->get($request->id),
            default => throw new \InvalidArgumentException('Invalid content action')
        };
    }

    private function handleMedia($request) {
        return match($request->action) {
            'upload' => $this->media->store($request->file),
            'delete' => $this->media->delete($request->id),
            'get' => $this->media->get($request->id),
            default => throw new \InvalidArgumentException('Invalid media action')
        };
    }

    private function handleAuth($request) {
        return match($request->action) {
            'login' => Auth::attempt($request->credentials),
            'logout' => Auth::logout(),
            'verify' => Auth::check(),
            default => throw new \InvalidArgumentException('Invalid auth action')
        };
    }
}

class ContentRepository {
    private DB $db;
    private Cache $cache;

    public function create(array $data) {
        $content = DB::transaction(function() use ($data) {
            $content = DB::table('contents')->insert($data);
            Cache::tags('content')->flush();
            return $content;
        });
        return $content;
    }

    public function get($id) {
        return Cache::tags('content')->remember(
            "content.$id",
            3600,
            fn() => DB::table('contents')->find($id)
        );
    }

    public function update($id, array $data) {
        return DB::transaction(function() use ($id, $data) {
            $updated = DB::table('contents')->where('id', $id)->update($data);
            Cache::tags('content')->forget("content.$id");
            return $updated;
        });
    }

    public function delete($id) {
        return DB::transaction(function() use ($id) {
            $deleted = DB::table('contents')->delete($id);
            Cache::tags('content')->forget("content.$id");
            return $deleted;
        });
    }
}

class MediaManager {
    private $storage;
    private $db;

    public function store($file) {
        return DB::transaction(function() use ($file) {
            $path = $file->store('media');
            return DB::table('media')->insert([
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize()
            ]);
        });
    }

    public function get($id) {
        return DB::table('media')->find($id);
    }

    public function delete($id) {
        return DB::transaction(function() use ($id) {
            $media = $this->get($id);
            Storage::delete($media->path);
            return DB::table('media')->delete($id);
        });
    }
}

trait BaseTemplate {
    protected function render($view, $data = []) {
        return view($this->getTemplate($view), $data);
    }

    protected function getTemplate($view) {
        return Cache::remember(
            "template.$view",
            3600,
            fn() => DB::table('templates')
                ->where('path', $view)
                ->where('active', true)
                ->value('content')
        );
    }

    protected function compileTemplate($content, $data) {
        extract($data);
        ob_start();
        eval('?>' . $content);
        return ob_get_clean();
    }
}

class SecurityContext {
    private $request;
    private $user;

    public function __construct($request) {
        $this->request = $request;
        $this->user = Auth::user();
    }

    public function getUser() {
        return $this->user;
    }

    public function getData() {
        return $this->request->all();
    }

    public function getAction() {
        return $this->request->action;
    }
}
