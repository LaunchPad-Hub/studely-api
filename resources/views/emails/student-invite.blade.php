@component('mail::message')
# Hello {{ $user->name }},

You have been invited to join Launch Pad.

Please click the button below to set your password and access your dashboard.

@component('mail::button', ['url' => $url])
Accept Invitation
@endcomponent

If you did not create this account, no further action is required.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
