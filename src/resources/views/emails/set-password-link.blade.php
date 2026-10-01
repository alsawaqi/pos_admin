{{--
    Set-password / reset-password link (LAUNCH-P1 P1-2, P1-8).

    Variables passed by SetPasswordLinkMail::content():
      - $recipientName : the user's display name
      - $portalName    : "MITHQAL Merchant Portal" or "MITHQAL POS Admin"
      - $companyName   : merchant name for a portal invite, else null
      - $purpose       : invite | reset | forgot
      - $url           : the set-password link (only place the raw token
                         appears besides the admin's one-time copy)
      - $expiresAt     : CarbonInterface, the link's expiry (UTC)
--}}
<x-mail::message>
@if ($purpose === 'invite')
# Welcome to MITHQAL, {{ $recipientName }}

@if ($companyName)
An account was created for you to manage **{{ $companyName }}** in the {{ $portalName }}.
@else
An account was created for you in the {{ $portalName }}.
@endif

Click the button below to choose your password. The link works once and expires on {{ $expiresAt->format('F j, Y \a\t H:i') }} UTC.

<x-mail::button :url="$url">
Set my password
</x-mail::button>
@else
# Reset your password, {{ $recipientName }}

@if ($purpose === 'reset')
A MITHQAL administrator asked for your {{ $portalName }} password to be reset.
@else
We received a request to reset your {{ $portalName }} password.
@endif

Click the button below to choose a new password. The link works once and expires on {{ $expiresAt->format('F j, Y \a\t H:i') }} UTC.

<x-mail::button :url="$url">
Choose a new password
</x-mail::button>
@endif

If the button does not work, copy and paste this address into your browser:

[{{ $url }}]({{ $url }})

If you were not expecting this email, you can ignore it. Nothing changes until the link is used.

Thanks,
The MITHQAL Team
</x-mail::message>
