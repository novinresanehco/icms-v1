<?php

namespace Tests\Core\Template;

use PHPUnit\Framework\TestCase;
use App\Core\Template\Compilation\{
    EnhancedTemplateCompiler,
    TemplateValidator,
    TemplateCacheManager,
    TemplatePerformanceMonitor
};
use App\Core\Template\Components\{
    ComponentLoader,
    ComponentInterface,
    BaseComponent
};
use App\Core\Template\Config\{
    TemplateConfig,
    SecurityConfig,
    CacheConfig
};
use App\Core\Template\Environment\{
    TemplateEnvironment,
    TemplateLoader
};
use App\Core\Security\SecurityManagerInterface;
use App\Core\Template\Exceptions\{
    CompilationException,
    ValidationException,
    RuntimeException
};

class TemplateCompilerTest extends TestCase
{
    private EnhancedTemplateCompiler $compiler;
    private SecurityManagerInterface $security;
    private TemplateValidator $validator;
    private TemplateCacheManager $cache;
    private TemplatePerformanceMonitor $monitor;

    protected function setUp(): void
    {
        $this->security = $this->createMock(SecurityManagerInterface::class);
        $this->validator = new TemplateValidator();
        $this->cache = new TemplateCacheManager('/tmp/cache');
        $this->monitor = new TemplatePerformanceMonitor();

        $this->compiler = new EnhancedTemplateCompiler(
            $this->security,
            '/tmp/compile',
            $this->validator,
            $this->cache,
            $this->monitor
        );
    }

    public function testCompileTemplate(): void
    {
        $template = '{{ $name }}';
        $expected = '<?php echo e($name); ?>';

        $this->security
            ->method('validateFile')
            ->willReturn(true);

        $compiled = $this->compiler->compile($template);
        
        $this->assertStringContainsString($expected, file_get_contents($compiled->getPath()));
    }

    public function testCompileWithCache(): void
    {
        $template = '{{ $name }}';

        $this->security
            ->method('validateFile')
            ->willReturn(true);

        $firstCompile = $this->compiler->compile($template);
        $secondCompile = $this->compiler->compile($template);

        $this->assertEquals(
            file_get_contents($firstCompile->getPath()),
            file_get_contents($secondCompile->getPath())
        );
    }

    public function testCompileWithValidation(): void
    {
        $this->expectException(ValidationException::class);

        $template = '<?php system("rm -rf /"); ?>';

        $this->security
            ->method('validateFile')
            ->willReturn(true);

        $this->compiler->compile($template);
    }
}

class ComponentLoaderTest extends TestCase
{
    private ComponentLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new ComponentLoader('/tmp/components');
    }

    public function testLoadComponent(): void
    {
        $componentClass = new class extends BaseComponent {
            public function render(array $data = []): string
            {
                return 'Test Component';
            }
        };

        $name = 'test';
        ComponentRegistry::register($name, get_class($componentClass));

        $component = $this->loader->load($name);
        
        $this->assertInstanceOf(ComponentInterface::class, $component);
        $this->assertEquals('Test Component', $component->render());
    }

    public function testLoadNonExistentComponent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->loader->load('non_existent');
    }
}

class TemplateEnvironmentTest extends TestCase
{
    private TemplateEnvironment $environment;
    private EnhancedTemplateCompiler $compiler;
    private ComponentLoader $componentLoader;
    private LifecycleManager $lifecycleManager;

    protected function setUp(): void
    {
        $this->compiler = $this->createMock(EnhancedTemplateCompiler::class);
        $this->componentLoader = $this->createMock(ComponentLoader::class);
        $this->lifecycleManager = $this->createMock(LifecycleManager::class);

        $this->environment = new TemplateEnvironment(
            $this->compiler,
            $this->componentLoader,
            $this->lifecycleManager
        );
    }

    public function testRenderTemplate(): void
    {
        $template = 'Hello {{ $name }}';
        $data = ['name' => 'World'];
        $expected = 'Hello World';

        $compiledTemplate = new CompiledTemplate('/tmp/compiled/template.php');

        $this->compiler
            ->method('compile')
            ->willReturn($compiledTemplate);

        $this->assertEquals($expected, $this->environment->render($template, $data));
    }

    public function testAddGlobal(): void
    {
        $name = 'siteName';
        $value = 'My Site';

        $this->environment->addGlobal($name, $value);
        
        $template = '{{ $siteName }}';
        $compiledTemplate = new CompiledTemplate('/tmp/compiled/template.php');

        $this->compiler
            ->method('compile')
            ->willReturn($compiledTemplate);

        $this->assertEquals($value, $this->environment->render($template));
    }
}

class ConfigurationTest extends TestCase
{
    private TemplateConfig $config;
    private SecurityConfig $security;
    private CacheConfig $cache;

    protected function setUp(): void
    {
        $this->config = new TemplateConfig();
        $this->security = new SecurityConfig();
        $this->cache = new CacheConfig();
    }

    public function testTemplateConfig(): void
    {
        $this->assertTrue($this->config->get('cache_enabled'));
        $this->assertEquals(3600, $this->config->get('cache_lifetime'));
        $this->assertTrue($this->config->get('compile_check'));
    }

    public function testSecurityConfig(): void
    {
        $this->assertTrue($this->security->isHtmlEscapingEnabled());
        $this->assertFalse($this->security->isPhpTagsAllowed());
        $this->assertTrue($this->security->isCsrfEnabled());
    }

    public function testCacheConfig(): void
    {
        $this->assertEquals('file', $this->cache->getDriver());
        $this->assertEquals('/tmp/cache', $this->cache->getPath());
        $this->assertEquals(3600, $this->cache->getLifetime());
    }
}