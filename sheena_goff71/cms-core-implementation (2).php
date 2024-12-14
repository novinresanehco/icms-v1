<?php

namespace App\Core;

// Models
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'parent_id'];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function contents()
    {
        return $this->hasMany(Content::class);
    }
}

class Content extends Model 
{
    protected $fillable = [
        'title',
        'slug',
        'content',
        'excerpt',
        'category_id',
        'user_id',
        'status',
        'featured_image',
        'meta_description',
        'meta_keywords'
    ];

    protected $casts = [
        'published_at' => 'datetime'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}

// Controllers
namespace App\Http\Controllers;

class CategoryController extends Controller
{
    private $cacheService;
    
    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;
        $this->middleware('auth');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|max:255',
            'slug' => 'required|unique:categories',
            'description' => 'nullable',
            'parent_id' => 'nullable|exists:categories,id'
        ]);

        DB::beginTransaction();
        try {
            $category = Category::create($validated);
            $this->cacheService->clearCategoryCache();
            DB::commit();

            return redirect()->route('categories.index');
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function index()
    {
        $categories = $this->cacheService->getCategories();
        return view('categories.index', compact('categories'));
    }
}

// Services
namespace App\Services;

class CacheService
{
    public function getCategories()
    {
        return Cache::tags(['categories'])->remember('categories.all', 3600, function() {
            return Category::with('children')
                         ->whereNull('parent_id')
                         ->orderBy('name')
                         ->get();
        });
    }

    public function clearCategoryCache()
    {
        Cache::tags(['categories'])->flush();
    }

    public function getContent($slug)
    {
        return Cache::tags(['contents'])->remember("content.{$slug}", 3600, function() use ($slug) {
            return Content::with(['category', 'user', 'tags'])
                         ->where('slug', $slug)
                         ->firstOrFail();
        });
    }

    public function clearContentCache($slug = null)
    {
        if ($slug) {
            Cache::tags(['contents'])->forget("content.{$slug}");
        } else {
            Cache::tags(['contents'])->flush();
        }
    }
}

// Migration Files
namespace Database\Migrations;

class CreateCategoriesTable extends Migration
{
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()
                  ->constrained('categories')
                  ->onDelete('cascade');
            $table->timestamps();
            $table->index(['slug', 'parent_id']);
        });
    }
}

class CreateContentsTable extends Migration
{
    public function up()
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');
            $table->text('excerpt')->nullable();
            $table->foreignId('category_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->string('status')->default('draft');
            $table->string('featured_image')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['slug', 'status', 'published_at']);
        });
    }
}

// Config File
return [
    'cache' => [
        'ttl' => env('CACHE_TTL', 3600),
        'tags' => [
            'categories',
            'contents',
            'tags'
        ]
    ],
    'content' => [
        'statuses' => [
            'draft',
            'published',
            'archived'
        ],
        'image_path' => 'content/images',
        'per_page' => 20
    ],
    'security' => [
        'roles' => [
            'admin',
            'editor',
            'author'
        ],
        'permissions' => [
            'manage_categories',
            'manage_content',
            'manage_users'
        ]
    ]
];
