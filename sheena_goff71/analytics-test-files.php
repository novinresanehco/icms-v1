<?php

namespace Tests\Unit\Analytics;

use App\Core\Analytics\Services\AnalyticsValidator;
use App\Core\Analytics\Exceptions\AnalyticsValidationException;
use PHPUnit\Framework\TestCase;

class AnalyticsValidatorTest extends TestCase
{
    private AnalyticsValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AnalyticsValidator();
    }

    public function test_validates_valid_event_name(): void
    {
        $this->validator->validate('page_view');
        $this->expectNotToPerformAssertions();
    }

    public function test_throws_exception_for_empty_event_name(): void
    {
        $this->expectException(AnalyticsValidationException::class);
        $this->validator->validate('');
    }

    public function test_throws_exception_for_invalid_event_name(): void
    {
        $this->expectException(AnalyticsValidationException::class);
        $this->validator->validate('invalid-event-name');
    }

    public function test_validates_valid_properties(): void
    {
        $this->validator->validate('test_event', [
            'property1' => 'value1',
            'property2' => 123,
            'property3' => ['nested' => 'value']
        ]);
        $this->expectNotToPerformAssertions();
    }

    public function test_throws_exception_for_invalid_property_key(): void
    {
        $this->expectException(AnalyticsValidationException::class);
        $this->validator->validate('test_event', ['invalid-key' => 'value']);
    }
}

namespace Tests\Unit\Analytics;

use App\Core\Analytics\Services\AnalyticsTransformer;
use App\Core\Analytics\DTOs\AnalyticsEvent;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Carbon;

class AnalyticsTransformerTest extends TestCase
{
    private AnalyticsTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new AnalyticsTransformer();
    }

    public function test_transforms_event_name_to_snake_case(): void
    {
        $event = new AnalyticsEvent('testEventName');
        $transformed = $this->transformer->transform($event);
        
        $this->assertEquals('test_event_name', $transformed->name);
    }

    public function test_transforms_property_keys_to_snake_case(): void
    {
        $event = new AnalyticsEvent('test', ['testProperty' => 'value']);
        $transformed = $this->transformer->transform($event);
        
        $this->assertArrayHasKey('test_property', $transformed->properties);
    }

    public function test_transforms_carbon_dates_to_iso8601(): void
    {
        $date = Carbon::create(2024, 1, 1, 12, 0, 0);
        $event = new AnalyticsEvent('test', ['date' => $date]);
        $transformed = $this->transformer->transform($event);
        
        $this->assertEquals($date->toIso8601String(), $transformed->properties['date']);
    }

    public function test_enriches_metadata(): void
    {
        $event = new AnalyticsEvent('test', [], ['custom' => 'value']);
        $transformed = $this->transformer->transform($event);
        
        $this->assertArrayHasKey('processed_at', $transformed->metadata);
        $this->assertArrayHasKey('environment', $transformed->metadata);
        $this->assertArrayHasKey('version', $transformed->metadata);
        $this->assertEquals('value', $transformed->metadata['custom']);
    }
}

namespace Tests\Unit\Analytics;

use App\Core\Analytics\Services\AnalyticsProcessor;
use App\Core\Analytics\DTOs\AnalyticsEvent;
use App\Core\Analytics\Exceptions\AnalyticsProcessingException;
use App\Core\Analytics\Services\{
    AnalyticsValidator,
    AnalyticsTransformer,
    AnalyticsStore
};
use Mockery;
use PHPUnit\Framework\TestCase;

class AnalyticsProcessorTest extends TestCase
{
    private AnalyticsProcessor $processor;
    private $validator;
    private $transformer;
    private $store;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->validator = Mockery::mock(AnalyticsValidator::class);
        $this->transformer = Mockery::mock(AnalyticsTransformer::class);
        $this->store = Mockery::mock(AnalyticsStore::class);
        
        $this->processor = new AnalyticsProcessor(
            $this->validator,
            $this->transformer,
            $this->store
        );
    }

    public function test_processes_event_successfully(): void
    {
        $event = new AnalyticsEvent('test_event');
        $transformedEvent = new AnalyticsEvent('transformed_event');

        $this->validator->shouldReceive('validate')
            ->once()
            ->with($event->name, $event->properties);

        $this->transformer->shouldReceive('transform')
            ->once()
            ->with($event)
            ->andReturn($transformedEvent);

        $this->store->shouldReceive('store')
            ->once()
            ->with($transformedEvent);

        $this->processor->process($event);
    }

    public function test_throws_exception_on_validation_failure(): void
    {
        $event = new AnalyticsEvent('test_event');

        $this->validator->shouldReceive('validate')
            ->once()
            ->andThrow(new AnalyticsProcessingException('Validation failed'));

        $this->expectException(AnalyticsProcessingException::class);
        $this->processor->process($event);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

namespace Tests\Unit\Analytics;

use App\Core\Analytics\Services\AnalyticsStore;
use App\Core\Analytics\DTOs\AnalyticsEvent;
use App\Core\Analytics\Exceptions\AnalyticsStorageException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

class AnalyticsStoreTest extends TestCase
{
    private AnalyticsStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new AnalyticsStore();
    }

    public function test_stores_event_successfully(): void
    {
        DB::shouldReceive('beginTransaction')->once();
        
        DB::shouldReceive('table')
            ->with('analytics_events')
            ->once()
            ->andReturn($eventQuery = Mockery::mock());
            
        $eventQuery->shouldReceive('insertGetId')
            ->once()
            ->andReturn(1);

        DB::shouldReceive('table')
            ->with('analytics_event_properties')
            ->once()
            ->andReturn($propertiesQuery = Mockery::mock());
            
        $propertiesQuery->shouldReceive('insert')->once();

        DB::shouldReceive('table')
            ->with('analytics_event_metadata')
            ->once()
            ->andReturn($metadataQuery = Mockery::mock());
            
        $metadataQuery->shouldReceive('insert')->once();

        DB::shouldReceive('commit')->once();

        $event = new AnalyticsEvent(
            'test_event',
            ['property' => 'value'],
            ['metadata' => 'value']
        );

        $this->store->store($event);
    }

    public function test_rolls_back_transaction_on_failure(): void
    {
        DB::shouldReceive('beginTransaction')->once();
        
        DB::shouldReceive('table')
            ->with('analytics_events')
            ->once()
            ->andThrow(new \Exception('Database error'));

        DB::shouldReceive('rollBack')->once();

        $event = new AnalyticsEvent('test_event');

        $this->expectException(AnalyticsStorageException::class);
        $this->store->store($event);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

namespace Tests\Unit\Analytics;

use App\Core\Analytics\DTOs\AnalyticsEvent;
use PHPUnit\Framework\TestCase;

class AnalyticsEventTest extends TestCase
{
    public function test_creates_event_with_required_properties(): void
    {
        $event = new AnalyticsEvent('test_event');
        
        $this->assertEquals('test_event', $event->name);
        $this->assertEquals([], $event->properties);
        $this->assertEquals([], $event->metadata);
    }

    public function test_creates_event_with_all_properties(): void
    {
        $event = new AnalyticsEvent(
            'test_event',
            ['prop' => 'value'],
            ['meta' => 'value']
        );
        
        $this->assertEquals('test_event', $event->name);
        $this->assertEquals(['prop' => 'value'], $event->properties);
        $this->assertEquals(['meta' => 'value'], $event->metadata);
    }

    public function test_converts_to_array(): void
    {
        $event = new AnalyticsEvent(
            'test_event',
            ['prop' => 'value'],
            ['meta' => 'value']
        );
        
        $array = $event->toArray();
        
        $this->assertEquals([
            'name' => 'test_event',
            'properties' => ['prop' => 'value'],
            'metadata' => ['meta' => 'value']
        ], $array);
    }

    public function test_creates_from_array(): void
    {
        $array = [
            'name' => 'test_event',
            'properties' => ['prop' => 'value'],
            'metadata' => ['meta' => 'value']
        ];
        
        $event = AnalyticsEvent::fromArray($array);
        
        $this->assertEquals('test_event', $event->name);
        $this->assertEquals(['prop' => 'value'], $event->properties);
        $this->assertEquals(['meta' => 'value'], $event->metadata);
    }
}
