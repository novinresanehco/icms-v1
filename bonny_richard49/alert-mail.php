<?php

namespace App\Core\Notification\Analytics\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class AlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public array $alertData;
    public string $messageId;

    public function __construct(array $alertData)
    {
        $this->alertData = $alertData;
        $this->messageId = uniqid('alert_', true);
    }

    public function build()
    {
        $this->subject($this->alertData['subject'])
             ->view($this->alertData['template'])
             ->with([
                 'content' => $this->alertData['content'],
                 'data' => $this->alertData['data'],
                 'severity' => $this->alertData['severity'],
                 'metadata' => $this->alertData['metadata'],
                 'signature' => $this->alertData['signature'],
                 'logo_url' => $this->alertData['logo_url'],
                 'footer_text' => $this->alertData['footer_text']
             ]);

        // Add message ID for tracking
        $this->withSymfonyMessage(function($message) {
            $message->getHeaders()->addTextHeader('X-Alert-ID', $this->messageId);
        });

        return $this;
    }
}
