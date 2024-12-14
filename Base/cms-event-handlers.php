<?php

namespace App\Events;

use App\Models\Content;
use Illuminate\Queue\SerializesModels;

class ContentPublished
{
    use SerializesModels;
    
    public $content;
    
    public function __construct(Content $content)
    {
        $this->content = $content;
    }
}

namespace App\Events;

use App\Models\Content;
use Illuminate\Queue\SerializesModels;

class ContentUpdated
{
    use SerializesModels;
    
    public $content;
    public $oldContent;
    
    public function __construct(Content $content, array $oldContent)
    {
        $this->content = $content;
        $this->oldContent = $oldContent;
    }
}

namespace App\Listeners;

use App\Events\ContentPublished;
use App\Services\NotificationService;
use App\Services\SearchService;
use App\Services\SitemapService;

class ContentPublishedListener
{
    protected $notificationService;
    protected $searchService;
    protected $sitemapService;
    
    public function __construct(
        NotificationService $notificationService,
        SearchService $searchService,
        SitemapService $sitemapService
    ) {
        $this->notificationService = $notificationService;
        $this->searchService = $searchService;
        $this->sitemapService = $sitemapService;
    }
    
    public function handle(ContentPublished $event)
    {
        // Index content in search
        $this->searchService->indexContent($event->content);
        
        // Update sitemap
        $this->sitemapService->addContent($event->content);
        
        // Notify subscribers
        $this->notificationService->notifySubscribers($event->content);
        
        // Clear relevant caches
        Cache::tags(['content', 'sitemap'])->flush();
    }
}

namespace App\Services;

use App\Models\Content;
use App\Models\User;
use App\Notifications\NewContentNotification;

class NotificationService
{
    public function notifySubscribers(Content $content)
    {
        $subscribers = User::whereHas('subscriptions', function($query) use ($content) {
            $query->where('category_id', $content->category_id);
        })->get();
        
        foreach ($subscribers as $subscriber) {
            $subscriber->notify(new NewContentNotification($content));
        }
    }
    
    public function notifyAuthors(Content $content)
    {
        if ($content->status === 'pending') {
            $editors = User::role('editor')->get();
            foreach ($editors as $editor) {
                $editor->notify(new ContentPendingReviewNotification($content));
            }
        }
    }
}

namespace App\Services;

class SearchService
{
    protected $searchClient;
    
    public function __construct()
    {
        $this->searchClient = app('elasticsearch');
    }
    
    public function indexContent(Content $content)
    {
        $this->searchClient->index([
            'index' => 'content',
            'id' => $content->id,
            'body' => [
                'title' => $content->title,
                'content' => strip_tags($content->content),
                'excerpt' => $content->excerpt,
                'category' => $content->category->name,
                'tags' => $content->tags->pluck('name')->toArray(),
                'author' => $content->author->name,
                'published_at' => $content->published_at->timestamp,
                'status' => $content->status
            ]
        ]);
    }
    
    public function removeContent(Content $content)
    {
        $this->searchClient->delete([
            'index' => 'content',
            'id' => $content->id
        ]);
    }
}

namespace App\Services;

use App\Models\Content;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class SitemapService
{
    protected $sitemap;
    
    public function __construct()
    {
        $this->sitemap = Sitemap::create();
    }
    
    public function generate()
    {
        Content::published()->chunk(100, function($contents) {
            foreach ($contents as $content) {
                $this->addContent($content);
            }
        });
        
        $this->sitemap->writeToFile(public_path('sitemap.xml'));
    }
    
    public function addContent(Content $content)
    {
        $this->sitemap->add(
            Url::create(route('content.show', $content->slug))
                ->setLastModificationDate($content->updated_at)
                ->setChangeFrequency('daily')
                ->setPriority(0.8)
        );
        
        $this->sitemap->writeToFile(public_path('sitemap.xml'));
    }
}

namespace App\Notifications;

use App\Models\Content;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class NewContentNotification extends Notification
{
    protected $content;
    
    public function __construct(Content $content)
    {
        $this->content = $content;
    }
    
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }
    
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('New Content: ' . $this->content->title)
            ->line('New content has been published in your subscribed category.')
            ->line('Title: ' . $this->content->title)
            ->action('Read More', route('content.show', $this->content->slug));
    }
    
    public function toArray($notifiable)
    {
        return [
            'content_id' => $this->content->id,
            'title' => $this->content->title,
            'category' => $this->content->category->name
        ];
    }
}
