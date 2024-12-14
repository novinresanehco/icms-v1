// resources/views/notifications/email/default.blade.php
@component('mail::message')
# {{ $title }}

{{ $content }}

@if(isset($actionText) && isset($actionUrl))
@component('mail::button', ['url' => $actionUrl])
{{ $actionText }}
@endcomponent
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent

// resources/views/notifications/slack/default.blade.php
{
    "blocks": [
        {
            "type": "section",
            "text": {
                "type": "mrkdwn",
                "text": "*{{ $title }}*\n\n{{ $content }}"
            }
        }
        @if(isset($fields))
        ,{
            "type": "section",
            "fields": [
                @foreach($fields as $field)
                {
                    "type": "mrkdwn",
                    "text": "*{{ $field['title'] }}*\n{{ $field['value'] }}"
                }@if(!$loop->last),@endif
                @endforeach
            ]
        }
        @endif
        @if(isset($actionText) && isset($actionUrl))
        ,{
            "type": "actions",
            "elements": [
                {
                    "type": "button",
                    "text": {
                        "type": "plain_text",
                        "text": "{{ $actionText }}"
                    },
                    "url": "{{ $actionUrl }}"
                }
            ]
        }
        @endif
    ]
}