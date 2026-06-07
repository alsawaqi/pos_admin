{{--
    Welcome email body for a freshly-invited merchant portal user.

    Variables passed by MerchantPortalWelcomeMail::content():
      - $recipientName  : the new user's display name
      - $companyName    : merchant trade name they're being added to
      - $setupUrl       : full URL to /setup-password with the raw
                          token + email as query params (only place
                          the raw token ever appears)
      - $expiresAt      : DateTimeInterface of the link's expiry
--}}
<x-mail::message>
# Welcome to MITHQAL POS, {{ $recipientName }}

You have been invited to manage **{{ $companyName }}** through the MITHQAL Merchant Portal.

Click the button below to set your password and finish setting up your account. This link expires on {{ $expiresAt->format('F j, Y \a\t H:i') }} UTC.

<x-mail::button :url="$setupUrl">
Set up my account
</x-mail::button>

If the button does not work, copy and paste this URL into your browser:

[{{ $setupUrl }}]({{ $setupUrl }})

If you were not expecting this invitation, you can safely ignore this email — no account will be created until the link is used.

Thanks,
The MITHQAL Team
</x-mail::message>
