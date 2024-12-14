namespace App\Core\UI\Integration;

class ComponentIntegration 
{
    private ComponentRegistry $registry;
    private TemplateManager $templates;
    private SecurityManager $security;

    public function __construct(
        ComponentRegistry $registry,
        TemplateManager $templates,
        SecurityManager $security
    ) {
        $this->registry = $registry;
        $this->templates = $templates;
        $this->security = $security;
    }

    public function registerCoreComponents(): void 
    {
        // Register critical UI components
        $this->registry->register('form', new FormComponent($this->templates, $this->security));
        $this->registry->register('grid', new GridComponent($this->templates, $this->security));
        $this->registry->register('card', new CardComponent($this->templates, $this->security));
        $this->registry->register('modal', new ModalComponent($this->templates, $this->security));
        $this->registry->register('alert', new AlertComponent($this->templates, $this->security));
    }

    public function integrateWithTemplate(): void 
    {
        $this->templates->registerFunction('component', function(string $name, array $props = []) {
            return $this->registry->render($name, $props);
        });
    }
}
