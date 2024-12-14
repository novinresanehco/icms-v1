// tests/Unit/Widget/Services/WidgetServiceTest.php
<?php

namespace Tests\Unit\Widget\Services;

use Tests\TestCase;
use App\Core\Widget\Services\WidgetService;
use App\Core\Widget\Repositories\WidgetRepository;
use App\Core\Widget\DTO\WidgetData;
use App\Core\Widget\Models\Widget;
use App\Core\Widget\Exceptions\WidgetValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class WidgetServiceTest extends TestCase
{
    use RefreshDatabase;

    private WidgetService $service;
    private WidgetRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(WidgetRepository::class);
        $this->service = new WidgetService($this->repository);
    }

    public function test_creates_widget_successfully(): void
    {
        $data = [
            'name' => 'Test Widget',
            'identifier' => 'test-widget',
            'type' => 'content',
            'area' => 'sidebar',
            'settings' => ['foo' => 'bar'],
            'is_active' => true
        ];

        $widget = new Widget($data);

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::type(WidgetData::class))
            ->andReturn($widget);

        $result = $this->service->createWidget($data);

        $this->assertInstanceOf(Widget::class, $result);
        $this->assertEquals('Test Widget', $result->name);
    }

    public function test_throws_validation_exception_on_invalid_data(): void
    {
        $this->expectException(WidgetValidationException::class);

        $data = [
            'identifier' => 'test-widget',
            'type' => 'content'
        ];

        $this->service->createWidget($data);
    }

    public function test_updates_widget_successfully(): void
    {
        $id = 1;
        $data = [
            'name' => 'Updated Widget',
            'identifier' => 'updated-widget',
            'type' => 'content',
            'area' => 'sidebar'
        ];

        $widget = new Widget($data);

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->with($id, Mockery::type(WidgetData::class))
            ->andReturn($widget);

        $result = $this->service->updateWidget($id, $data);

        $this->assertInstanceOf(Widget::class, $result);
        $this->assertEquals('Updated Widget', $result->name);
    }

    public function test_deletes_widget_successfully(): void
    {
        $id = 1;

        $this->repository
            ->shouldReceive('delete')
            ->once()
            ->with($id)
            ->andReturn(true);

        $result = $this->service->deleteWidget($id);

        $this->assertTrue($result);
    }
}

// tests/Feature/Widget/Controllers/Api/WidgetControllerTest.php
<?php

namespace Tests\Feature\Widget\Controllers\Api;

use Tests\TestCase;
use App\Core\Widget\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class WidgetControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createUser());
    }

    public function test_can_get_widgets_list(): void
    {
        Widget::factory()->count(3)->create();

        $response = $this->getJson('/api/widgets');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_widget(): void
    {
        $data = [
            'name' => 'Test Widget',
            'identifier' => 'test-widget',
            'type' => 'content',
            'area' => 'sidebar',
            'settings' => ['foo' => 'bar'],
            'is_active' => true
        ];

        $response = $this->postJson('/api/widgets', $data);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Test Widget']);
    }

    public function test_can_update_widget(): void
    {
        $widget = Widget::factory()->create();

        $data = [
            'name' => 'Updated Widget',
            'area' => 'footer'
        ];

        $response = $this->putJson("/api/widgets/{$widget->id}", $data);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Widget']);
    }

    public function test_can_delete_widget(): void
    {
        $widget = Widget::factory()->create();

        $response = $this->deleteJson("/api/widgets/{$widget->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('widgets', ['id' => $widget->id]);
    }

    public function test_validates_widget_creation(): void
    {
        $response = $this->postJson('/api/widgets', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'identifier', 'type', 'area']);
    }

    public function test_can_update_widget_order(): void
    {
        $widgets = Widget::factory()->count(3)->create();
        
        $order = $widgets->pluck('id')->flip()->toArray();

        $response = $this->putJson('/api/widgets/order', ['order' => $order]);

        $response->assertOk();
        
        foreach ($order as $id => $position) {
            $this->assertDatabaseHas('widgets', [
                'id' => $id,
                'order' => $position
            ]);
        }
    }
}

// tests/Unit/Widget/Validators/WidgetValidatorTest.php
<?php

namespace Tests\Unit\Widget\Validators;

use Tests\TestCase;
use App\Core\Widget\Validators\WidgetValidator;
use App\Core\Widget\Exceptions\WidgetValidationException;
use Illuminate\Foundation\Testing\WithFaker;

class WidgetValidatorTest extends TestCase
{
    use WithFaker;

    private WidgetValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WidgetValidator();
    }

    public function test_validates_valid_data(): void
    {
        $data = [
            'name' => 'Test Widget',
            'identifier' => 'test-widget',
            'type' => 'content',
            'area' => 'sidebar',
            'settings' => ['foo' => 'bar'],
            'is_active' => true
        ];

        $this->validator->validate($data);
        $this->addToAssertionCount(1);
    }

    public function test_throws_exception_for_invalid_data(): void
    {
        $this->expectException(WidgetValidationException::class);

        $data = [
            'name' => '',
            'identifier' => 'invalid identifier!',
            'type' => '',
            'area' => ''
        ];

        $this->validator->validate($data);
    }

    public function test_validates_identifier_format(): void
    {
        $this->expectException(WidgetValidationException::class);

        $data = [
            'name' => 'Test Widget',
            'identifier' => 'Invalid Identifier!',
            'type' => 'content',
            'area' => 'sidebar'
        ];

        $this->validator->validate($data);
    }
}

// tests/Unit/Widget/Services/WidgetAuthorizationServiceTest.php
<?php

namespace Tests\Unit\Widget\Services;

use Tests\TestCase;
use App\Core\Widget\Services\WidgetAuthorizationService;
use App\Core\Widget\Models\Widget;
use App\Models\User;
use Mockery;

class WidgetAuthorizationServiceTest extends TestCase
{
    private WidgetAuthorizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WidgetAuthorizationService();
    }

    public function test_allows_access_when_no_permissions_required(): void
    {
        $user = Mockery::mock(User::class);
        $widget = new Widget(['permissions' => []]);

        $result = $this->service->canAccess($user, $widget);

        $this->assertTrue($result);
    }

    public function test_denies_access_for_guest_when_permissions_required(): void
    {
        $widget = new Widget(['permissions' => [
            ['type' => 'role', 'value' => 'admin']
        ]]);

        $result = $this->service->canAccess(null, $widget);

        $this->assertFalse($result);
    }

    public function test_validates_role_based_permissions(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasRole')
            ->with('admin')
            ->once()
            ->andReturn(true);

        $widget = new Widget(['permissions' => [
            ['type' => 'role', 'value' => 'admin']
        ]]);

        $result = $this->service->canAccess($user, $widget);

        $this->assertTrue($result);
    }

    public function test_validates_permission_based_access(): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('can')
            ->with('edit_widgets')
            ->once()
            ->andReturn(true);

        $widget = new Widget(['permissions' => [
            ['type' => 'permission', 'value' => 'edit_widgets']
        ]]);

        $result = $this->service->canAccess($user, $widget);

        $this->assertTrue($result);
    }
}