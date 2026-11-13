<?php

namespace App\Services;

use InvalidArgumentException;
use Mailtrap\Mime\MailtrapEmail;
use Symfony\Component\Mime\Address;

class AuthCodeEmailFactory
{
    public const LOGO_CONTENT_ID = 'maik-cat-email-logo.png';

    public function make(string $email, string $code, string $purpose, Address $from): MailtrapEmail
    {
        $content = match ($purpose) {
            'registration' => [
                'subject' => 'Verify your Maik Cat email',
                'heading' => 'Verify your email',
                'eyebrow' => 'ACCOUNT VERIFICATION',
                'intro' => 'Welcome to Maik Cat. Use the code below to verify your email and finish creating your account.',
                'instruction' => 'Return to the Maik Cat app, enter this code and select Verify and create account.',
                'category' => 'Email Verification',
            ],
            'password_reset' => [
                'subject' => 'Reset your Maik Cat password',
                'heading' => 'Reset your password',
                'eyebrow' => 'ACCOUNT SECURITY',
                'intro' => 'We received a request to reset your Maik Cat password. Use the code below to continue securely.',
                'instruction' => 'Return to the Maik Cat app, enter this code and choose your new password.',
                'category' => 'Password Reset',
            ],
            default => throw new InvalidArgumentException('Unsupported authentication email purpose.'),
        };

        $data = [
            ...$content,
            'code' => $code,
            'logoContentId' => self::LOGO_CONTENT_ID,
            'expiryMinutes' => 10,
        ];

        return (new MailtrapEmail)
            ->from($from)
            ->to(new Address($email))
            ->subject($content['subject'])
            ->category($content['category'])
            ->text(view('emails.auth.code-text', $data)->render())
            ->html(view('emails.auth.code', $data)->render())
            ->embedFromPath(resource_path('images/maik-cat-email-logo.png'), self::LOGO_CONTENT_ID, 'image/png');
    }
}
