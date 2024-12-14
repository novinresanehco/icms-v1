<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Validation rules for settings
     *
     * @var array<string, string>
     */
    public static array $rules = [
        'key' => 'required|string|max:255|unique:settings,key',
        'value' => 'required|string',
        'type' => 'required|string|in:string,boolean,integer,float,json',
        'group' => 'required|string|max:255',
        'description' => 'nullable|string'
    ];
}