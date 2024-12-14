<?php

namespace App\Core\Notification\Messages;

class MailMessage
{
    public string $view;
    public string $subject;
    public array $from = [];
    public array $cc = [];
    public array $bcc = [];
    public ?string $replyTo = null;
    public array $attachments = [];
    protected array $data = [];
    protected string $level = 'info';
    protected ?string $actionText = null;
    protected ?string $actionUrl = null;

    public function view(string $view): self
    {
        $this->view = $view;
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function from(string $address, ?string $name = null): self
    {
        $this->from = compact('address', 'name');
        return $this;
    }

    public function cc($address, ?string $name = null): self
    {
        $this->cc[] = compact('address', 'name');
        return $this;
    }

    public function bcc($address, ?string $name = null): self
    {
        $this->bcc[] = compact('address', 'name');
        return $this;
    }

    public function replyTo(string $address, ?string $name = null): self
    {
        $this->replyTo = compact('address', 'name');
        return $this;
    }

    public function attach(string $file, array $options = []): self
    {
        $this->attachments[] = compact('file', 'options');
        return $this;
    }

    public function line(string $line): self
    {
        $this->data['lines'][] = $line;
        return $this;
    }

    public function action(string $text, string $url): self
    {
        $this->actionText = $text;
        $this->actionUrl = $url;
        return $this;
    }

    public function level(string $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function data(): array
    {
        return array_merge($this->data, [
            'level' => $this->level,
            'actionText' => $this->actionText,
            'actionUrl' => $this->actionUrl
        ]);
    }
}

class DatabaseMessage
{
    protected array $data = [];
    protected string $type;
    protected ?string $icon = null;
    protected ?string $url = null;

    public function data(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function type(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    public function url(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'data' => $this->data,
            'type' => $this->type,
            'icon' => $this->icon,
            'url' => $this->url
        ]);
    }
}

namespace App\Core\Notification\Templates;

class NotificationTemplate
{
    protected string $name;
    protected array $channels;
    protected array $data;

    public function __construct(string $name, array $channels, array $data)
    {
        $this->name = $name;
        $this->channels = $channels;
        $this->data = $data;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getChannels(): array
    {
        return $this->channels;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function compile(array $parameters = []): array
    {
        $compiled = [];

        foreach ($this->data as $key => $value) {
            if (is_string($value)) {
                $compiled[$key] = $this->compileString($value, $parameters);
            } elseif (is_array($value)) {
                $compiled[$key] = $this->compile($value);
            } else {
                $compiled[$key] = $value;
            }
        }

        return $compiled;
    }

    protected function compileString(string $value, array $parameters): string
    {
        foreach ($parameters as $key => $parameter) {
            $value = str_replace(":{$key}", $parameter, $value);
        }

        return $value;
    }
}

class NotificationTemplateManager
{
    protected array $templates = [];
    protected TemplateLoader $loader;
    protected TemplateCache $cache;

    public function __construct(TemplateLoader $loader, TemplateCache $cache)
    {
        $this->loader = $loader;
        $this->cache = $cache;
    }

    public function getTemplate(string $name): NotificationTemplate
    {
        if (!isset($this->templates[$name])) {
            $this->templates[$name] = $this->loadTemplate($name);
        }

        return $this->templates[$name];
    }

    public function registerTemplate(NotificationTemplate $template): void
    {
        $this->templates[$template->getName()] = $template;
    }

    protected function loadTemplate(string $name): NotificationTemplate
    {
        return $this->cache->remember($name, function () use ($name) {
            return $this->loader->load($name);
        });
    }
}
