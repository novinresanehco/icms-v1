<?php
namespace CriticalCms;

class CoreSystem
{
    public function __construct()
    {
        $this->initializeSystem();
        $this->registerRoutes();
    }

    protected function initializeSystem(): void
    {
        Schema::create('users', function($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contents', function($table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('templates', function($table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('path');
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function registerRoutes(): void
    {
        Route::prefix('api')->group(function() {
            Route::post('auth/login', fn(Request $r) => (new AuthController)->login($r));
            
            Route::middleware('auth:api')->group(function() {
                Route::resource('content', ContentController::class);
                Route::get('templates/{name}/render', [TemplateController::class, 'render']);
            });
        });
    }
}

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        try {
            $user = User::where('email', $data['email'])->firstOrFail();
            if (!Hash::check($data['password'], $user->password)) {
                throw new AuthException('Invalid credentials');
            }

            $token = $this->createToken($user);
            return response()->json(['token' => $token, 'user' => $user]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }
    }

    protected function createToken(User $user): string
    {
        return JWT::encode([
            'user_id' => $user->id,
            'exp' => time() + 3600
        ], config('app.key'));
    }
}

class ContentController extends Controller
{
    protected ContentService $content;
    
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'status' => 'in:draft,published'
        ]);

        return DB::transaction(function() use ($data) {
            $content = Content::create($data + ['user_id' => auth()->id()]);
            Cache::tags('content')->flush();
            return response()->json($content, 201);
        });
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'title' => 'string|max:255',
            'body' => 'string',
            'status' => 'in:draft,published'
        ]);

        return DB::transaction(function() use ($id, $data) {
            $content = Content::findOrFail($id);
            $content->update($data);
            Cache::tags(['content', $id])->flush();
            return response()->json($content);
        });
    }
}

class TemplateController extends Controller
{
    public function render(Request $request, string $name)
    {
        try {
            $template = Template::where('name', $name)->firstOrFail();
            $data = $request->validate(['data' => 'array']);
            
            $html = Cache::remember("template.$name", 3600, function() use ($template, $data) {
                return view($template->path, $data['data'] ?? [])->render();
            });

            return response()->json(['html' => $html]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Template error'], 500);
        }
    }
}

class User extends Model 
{
    protected $hidden = ['password'];
    protected $fillable = ['email', 'password', 'is_admin'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function($user) {
            $user->password = Hash::make($user->password);
        });
    }
}

class Content extends Model
{
    protected $fillable = ['title', 'body', 'status', 'user_id'];
    protected $casts = ['published_at' => 'datetime'];
}

class Template extends Model
{
    protected $fillable = ['name', 'path', 'config'];
    protected $casts = ['config' => 'array'];
}

class AuthException extends \Exception {}
