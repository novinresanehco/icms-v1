<?php

namespace Database\Factories\Notification;

use App\Core\Notification\Models\{
    Notification,
    NotificationTemplate,
    NotificationPreference
};
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'type' => $this->faker->randomElement(['alert', 'info', 'success', 'warning']),
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => $this->faker->numberBetween(1, 100),
            'data' => [
                'title' => $this->faker->sentence,
                'message' => $this->faker->paragraph,
                'action_url' => $this->faker->url,
                'action_text' => 'View Details'
            ],
            'read_at' => $this->faker->optional(0.3)->dateTime,
            'status' => $this->faker->randomElement(['pending', 'sent', 'failed', 'delivered']),
            'scheduled_at' => $this->faker->optional(0.2)->dateTimeBetween('now', '+1 week'),
            'created_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'updated_at' => function (array $attributes) {
                return $this->faker->dateTimeBetween($attributes['created_at'], 'now');
            }
        ];
    }

    public function unread(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'read_at' => null
            ];
        });
    }

    public function read(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'read_at' => $this->faker->dateTimeBetween('-1 week', 'now')
            ];
        });
    }

    public function scheduled(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'scheduled',
                'scheduled_at' => $this->faker->dateTimeBetween('now', '+1 week')
            ];
        });
    }

    public function failed(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'failed',
                'data' => array_merge(
                    $attributes['data'] ?? [],
                    ['error' => $this->faker->sentence]
                )
            ];
        });
    }
}

class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'type' => $this->faker->randomElement(['system', 'marketing', 'transactional']),
            'channels' => $this->faker->randomElements(['mail', 'database', 'slack', 'sms'], 2),
            'content' => [
                'email' => [
                    'subject' => $this->faker->sentence,
                    'body' => $this->faker->paragraphs(3, true),
                    'template' => 'notifications.email.default'
                ],
                'sms' => [
                    'message' => $this->faker->sentence
                ],
                'slack' => [
                    'message' => $this->faker->sentence,
                    'channel' => '#notifications'
                ]
            ],
            'metadata' => [
                'category' => $this->faker->word,
                'priority' => $this->faker->randomElement(['high', 'medium', 'low']),
                'tags' => $this->faker->words(3)
            ],
            'validation_rules' => [
                'user_id' => 'required|integer',
                'data.*' => 'required|string'
            ],
            'active' => true,
            'created_at' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'updated_at' => function (array $attributes) {
                return $this->faker->dateTimeBetween($attributes['created_at'], 'now');
            }
        ];
    }

    public function inactive(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'active' => false
            ];
        });
    }

    public function emailOnly(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'channels' => ['mail'],
                'content' => [
                    'email' => $attributes['content']['email']
                ]
            ];
        });
    }

    public function withVariables(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'content' => [
                    'email' => [
                        'subject' => 'Hello, {{ user.name }}',
                        'body' => 'Your order #{{ order.id }} has been {{ order.status }}.',
                        'template' => 'notifications.email.default'
                    ]
                ]
            ];
        });
    }
}

class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->numberBetween(1, 100),
            'channel' => $this->faker->randomElement(['mail', 'sms', 'slack', 'push']),
            'enabled' => $this->faker->boolean(80),
            'settings' => [
                'frequency' => $this->faker->randomElement(['instant', 'daily', 'weekly']),
                'quiet_hours' => [
                    'start' => '22:00',
                    'end' => '08:00'
                ],
                'categories' => $this->faker->randomElements(['system', 'marketing', 'security'], 2)
            ],
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'updated_at' => function (array $attributes) {
                return $this->faker->dateTimeBetween($attributes['created_at'], 'now');
            }
        ];
    }

    public function disabled(): self
    {
        return $this->state(function (array $attributes) {
            return [
                'enabled' => false
            ];
        });
    }

    public function forChannel(string $channel): self
    {
        return $this->state(function (array $attributes) use ($channel) {
            return [
                'channel' => $channel,
                'settings' => array_merge(
                    $attributes['settings'] ?? [],
                    $this->getChannelSpecificSettings($channel)
                )
            ];
        });
    }

    protected function getChannelSpecificSettings(string $channel): array
    {
        return match($channel) {
            'email' => [
                'format' => 'html',
                'digest' => true
            ],
            'sms' => [
                'include_urls' => false,
                'max_length' => 160
            ],
            'slack' => [
                'channel' => '#notifications',
                'mention' => '@here'
            ],
            'push' => [
                'sound' => true,
                'badge' => true,
                'priority' => 'high'
            ],
            default => []
        };
    }
}