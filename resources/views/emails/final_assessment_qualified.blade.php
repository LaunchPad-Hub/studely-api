@component('mail::message')
# Hello {{ $student->user->name }},

Congratulations! Based on your training performance, you have successfully qualified for the **Final Assessment**.

You can now access the final assessment from your student dashboard. Please ensure you are ready before starting, as this will determine your final certification status.

@component('mail::button', ['url' => $url])
Go to Dashboard
@endcomponent

Good luck, we are rooting for you!

Thanks,<br>
{{ config('app.name') }}
@endcomponent
