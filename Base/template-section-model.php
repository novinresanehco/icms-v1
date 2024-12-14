<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateSection extends Model
{
    protected $fillable = [
        'template_id',
        'key',
        'name',
        'content',
        'settings',
        'order'
    ];

    protected $casts = [
        'settings' => 'array',
        'order' => 'integer'
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function compile(array $data = []): string
    {
        return view($this->getViewPath(), array_merge([
            'section' => $this,
            'settings' => $this->settings
        ], $data))->render();
    }

    public function getViewPath(): string
    {
        return "themes.{$this->template->theme}.sections.{$this->key}";
    }
}
