namespace App\Database\Migrations;

class CoreSystemMigrations
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('mfa_secret')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('content', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('body');
            $table->string('status')->default('draft');
            $table->foreignId('author_id')->constrained('users');
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->timestamps();
        });

        Schema::create('category_content', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'content_id']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->integer('size');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('action');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->json('changes')->nullable();
            $table->ipAddress('ip_address');
            $table->string('user_agent');
            $table->timestamp('created_at');
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('media');
        Schema::dropIfExists('category_content');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('content');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
}

namespace App\Database\Seeders;

class CoreSystemSeeder
{
    public function run(): void
    {
        $this->seedRoles();
        $this->seedAdmin();
        $this->seedBaseTemplates();
    }

    private function seedRoles(): void
    {
        DB::table('roles')->insert([
            [
                'name' => 'Administrator',
                'slug' => 'admin',
                'permissions' => json_encode(['*']),
                'created_at' => now()
            ],
            [
                'name' => 'Editor',
                'slug' => 'editor',
                'permissions' => json_encode([
                    'content.*',
                    'media.*',
                    'templates.view'
                ]),
                'created_at' => now()
            ]
        ]);
    }

    private function seedAdmin(): void
    {
        $admin = DB::table('users')->insertGetId([
            'username' => 'admin',
            'email' => 'admin@system.local',
            'password' => Hash::make('Admin123!'),
            'email_verified_at' => now(),
            'created_at' => now()
        ]);

        DB::table('role_user')->insert([
            'user_id' => $admin,
            'role_id' => 1
        ]);
    }

    private function seedBaseTemplates(): void
    {
        DB::table('templates')->insert([
            [
                'name' => 'default',
                'content' => file_get_contents(resource_path('templates/default.blade.php')),
                'metadata' => json_encode([
                    'type' => 'layout',
                    'version' => '1.0'
                ]),
                'created_at' => now()
            ],
            [
                'name' => 'post',
                'content' => file_get_contents(resource_path('templates/post.blade.php')),
                'metadata' => json_encode([
                    'type' => 'content',
                    'version' => '1.0'
                ]),
                'created_at' => now()
            ]
        ]);
    }
}
