<?php

namespace App\Core\Template\Forms;

use App\Core\Template\Exceptions\FormException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Factory as ValidatorFactory;

class FormBuilder
{
    private Collection $fields;
    private Collection $rules;
    private Collection $messages;
    private ValidatorFactory $validator;
    private string $method = 'POST';
    private ?string $action = null;
    private array $attributes = [];

    public function __construct(ValidatorFactory $validator)
    {
        $this->validator = $validator;
        $this->fields = new Collection();
        $this->rules = new Collection();
        $this->messages = new Collection();
    }

    /**
     * Add form field
     *
     * @param string $type
     * @param string $name
     * @param array $options
     * @return self
     */
    public function addField(string $type, string $name, array $options = []): self
    {
        $field = new FormField($type, $name, $options);
        $this->fields->put($name, $field);

        if (isset($options['rules'])) {
            $this->addRule($name, $options['rules']);
        }

        if (isset($options['messages'])) {
            $this->addMessages($name, $options['messages']);
        }

        return $this;
    }

    /**
     * Add validation rule
     *
     * @param string $field
     * @param string|array $rules
     * @return self
     */
    public function addRule(string $field, $rules): self
    {
        $this->rules->put($field, $rules);
        return $this;
    }

    /**
     * Add validation messages
     *
     * @param string $field
     * @param array $messages
     * @return self
     */
    public function addMessages(string $field, array $messages): self
    {
        foreach ($messages as $rule => $message) {
            $this->messages->put("{$field}.{$rule}", $message);
        }
        return $this;
    }

    /**
     * Set form method
     *
     * @param string $method
     * @return self
     */
    public function setMethod(string $method): self
    {
        $this->method = strtoupper($method);
        return $this;
    }

    /**
     * Set form action
     *
     * @param string $action
     * @return self
     */
    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    /**
     * Set form attributes
     *
     * @param array $attributes
     * @return self
     */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * Render form HTML
     *
     * @return string
     */
    public function render(): string
    {
        $attributes = $this->renderAttributes();
        $fields = $this->renderFields();
        $token = $this->renderCsrfToken();

        return <<<HTML
        <form method="{$this->method}" action="{$this->action}"{$attributes}>
            {$token}
            {$fields}
        </form>
        HTML;
    }

    /**
     * Validate form data
     *
     * @param array $data
     * @return bool
     * @throws FormException
     */
    public function validate(array $data): bool
    {
        $validator = $this->validator->make(
            $data,
            $this->rules->all(),
            $this->messages->all()
        );

        if ($validator->fails()) {
            throw new FormException(
                'Form validation failed',
                0,
                null,
                $validator->errors()->toArray()
            );
        }

        return true;
    }

    /**
     * Render form attributes
     *
     * @return string
     */
    protected function renderAttributes(): string
    {
        $attributes = array_merge([
            'id' => 'form-' . Str::random(8),
            'class' => 'form'
        ], $this->attributes);

        return collect($attributes)
            ->map(fn($value, $key) => sprintf('%s="%s"', $key, $value))
            ->implode(' ');
    }

    /**
     * Render form fields
     *
     * @return string
     */
    protected function renderFields(): string
    {
        return $this->fields
            ->map(fn(FormField $field) => $field->render())
            ->implode("\n");
    }

    /**
     * Render CSRF token field
     *
     * @return string
     */
    protected function renderCsrfToken(): string
    {
        if ($this->method === 'GET') {
            return '';
        }

        return sprintf(
            '<input type="hidden" name="_token" value="%s">',
            csrf_token()
        );
    }
}

class FormField
{
    private string $type;
    private string $name;
    private array $options;

    public function __construct(string $type, string $name, array $options = [])
    {
        $this->type = $type;
        $this->name = $name;
        $this->options = array_merge($this->getDefaultOptions(), $options);
    }

    /**
     * Render field HTML
     *
     * @return string
     */
    public function render(): string
    {
        $wrapper = $this->renderWrapper();
        $label = $this->renderLabel();
        $input = $this->renderInput();
        $error = $this->renderError();

        return <<<HTML
        <div {$wrapper}>
            {$label}
            {$input}
            {$error}
        </div>
        HTML;
    }

    /**
     * Render wrapper attributes
     *
     * @return string
     */
    protected function renderWrapper(): string
    {
        $classes = ['form-group'];
        
        if ($this->options['required']) {
            $classes[] = 'required';
        }

        return sprintf('class="%s"', implode(' ', $classes));
    }

    /**
     * Render field label
     *
     * @return string
     */
    protected function renderLabel(): string
    {
        if (!$this->options['label']) {
            return '';
        }

        return sprintf(
            '<label for="%s">%s%s</label>',
            $this->name,
            $this->options['label'],
            $this->options['required'] ? ' <span class="required">*</span>' : ''
        );
    }

    /**
     * Render input field
     *
     * @return string
     */
    protected function renderInput(): string
    {
        switch ($this->type) {
            case 'textarea':
                return $this->renderTextarea();
            case 'select':
                return $this->renderSelect();
            case 'checkbox':
            case 'radio':
                return $this->renderCheckableInput();
            default:
                return $this->renderDefaultInput();
        }
    }

    /**
     * Render textarea field
     *
     * @return string
     */
    protected function renderTextarea(): string
    {
        $attributes = $this->renderInputAttributes(['rows', 'cols']);
        return sprintf(
            '<textarea name="%s" id="%s"%s>%s</textarea>',
            $this->name,
            $this->name,
            $attributes,
            $this->options['value'] ?? ''
        );
    }

    /**
     * Render select field
     *
     * @return string
     */
    protected function renderSelect(): string
    {
        $attributes = $this->renderInputAttributes(['multiple']);
        $options = $this->renderSelectOptions();

        return sprintf(
            '<select name="%s" id="%s"%s>%s</select>',
            $this->name,
            $this->name,
            $attributes,
            $options
        );
    }

    /**
     * Render select options
     *
     * @return string
     */
    protected function renderSelectOptions(): string
    {
        return collect($this->options['options'] ?? [])
            ->map(function ($label, $value) {
                $selected = in_array($value, (array)($this->options['value'] ?? [])) 
                    ? ' selected' 
                    : '';
                return sprintf(
                    '<option value="%s"%s>%s</option>',
                    $value,
                    $selected,
                    $label
                );
            })
            ->implode("\n");
    }

    /**
     * Render checkbox/radio input
     *
     * @return string
     */
    protected function renderCheckableInput(): string
    {
        $attributes = $this->renderInputAttributes(['checked']);
        return sprintf(
            '<input type="%s" name="%s" value="%s"%s>',
            $this->type,
            $this->name,
            $this->options['value'] ?? '1',
            $attributes
        );
    }

    /**
     * Render default input field
     *
     * @return string
     */
    protected function renderDefaultInput(): string
    {
        $attributes = $this->renderInputAttributes([
            'maxlength', 'minlength', 'pattern', 'placeholder', 'readonly', 
            'required', 'disabled', 'autocomplete'
        ]);

        return sprintf(
            '<input type="%s" name="%s" id="%s"%s>',
            $this->type,
            $this->name,
            $this->name,
            $attributes
        );
    }

    /**
     * Render input attributes
     *
     * @param array $allowedAttributes
     * @return string
     */
    protected function renderInputAttributes(array $allowedAttributes = []): string
    {
        $attributes = array_merge(
            ['class' => 'form-control'],
            array_intersect_key($this->options, array_flip($allowedAttributes))
        );

        return collect($attributes)
            ->map(fn($value, $key) => sprintf('%s="%s"', $key, $value))
            ->implode(' ');
    }

    /**
     * Render error message placeholder
     *
     * @return string
     */
    protected function renderError(): string
    {
        return sprintf(
            '<div class="invalid-feedback" data-field="%s"></div>',
            $this->name
        );
    }

    /**
     * Get default field options
     *
     * @return array
     */
    protected function getDefaultOptions(): array
    {
        return [
            'label' => Str::title($this->name),
            'required' => false,
            'class' => 'form-control',
            'value' => null
        ];
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Forms\FormBuilder;

class FormServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(FormBuilder::class, function ($app) {
            return new FormBuilder($app['validator']);
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $blade = $this->app['blade.compiler'];

        // Add Blade directive for forms
        $blade->directive('form', function ($expression) {
            return "<?php echo app(FormBuilder::class)->render($expression); ?>";
        });
    }
}
