<?php

namespace App\Widgets;

use Illuminate\Support\Facades\Validator;

class HtmlWidget extends AbstractWidget
{
    protected string $type = 'html';

    public function __construct(int $id, array $config)
    {
        $this->id = $id;
        $this->config = $config;
    }

    public function validate(): bool
    {
        $validator = Validator::make($this->config, [
            'content' => 'required|string|max:65535',
            'wrapper_class' => 'nullable|string|max:255',
            'cache_duration' => 'nullable|integer|min:0'
        ]);

        return $validator->passes();
    }

    protected function renderWidget(): string
    {
        if (!$this->validate()) {
            return '';
        }

        $content = $this->config['content'];
        $wrapperClass = $this->config['wrapper_class'] ?? '';

        if ($wrapperClass) {
            return "<div class=\"{$wrapperClass}\">{$content}</div>";
        }

        return $content;
    }
}
