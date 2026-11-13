<?php

use App\Services\AuthCodeEmailFactory;
use Mailtrap\Api\EmailPayloadTrait;
use Mailtrap\Mime\MailtrapEmail;
use Symfony\Component\Mime\Address;

it('builds a branded multipart authentication email with an inline logo', function (string $purpose, string $subject, string $heading, string $category): void {
    $message = app(AuthCodeEmailFactory::class)->make(
        'customer@example.test',
        '482619',
        $purpose,
        new Address('auth@example.test', 'Maik Cat'),
    );

    expect($message)->toBeInstanceOf(MailtrapEmail::class)
        ->and($message->getSubject())->toBe($subject)
        ->and($message->getTo()[0]->getAddress())->toBe('customer@example.test')
        ->and($message->getHtmlBody())->toContain($heading, '482619', '10 minutes', 'Never share this code', '#C55000', '#FAF8F6', '#E8DCCF', 'cid:maik-cat-email-logo.png')
        ->and($message->getTextBody())->toContain($heading, '482619', '10 minutes', 'Never share this code')
        ->and($message->getHtmlBody())->not->toContain('https://', '<script', '<form')
        ->and($message->getAttachments())->toHaveCount(1);

    // Exercise the installed SDK's actual JSON serialization, without network calls.
    $serializer = new class
    {
        use EmailPayloadTrait;

        public function serialize(MailtrapEmail $email): array
        {
            return $this->getPayload($email);
        }
    };

    $payload = $serializer->serialize($message);

    expect($payload['category'])->toBe($category)
        ->and($payload['html'])->toBe($message->getHtmlBody())
        ->and($payload['text'])->toBe($message->getTextBody())
        ->and($payload['attachments'][0]['disposition'])->toBe('inline')
        ->and($payload['attachments'][0]['content_id'])->toBe(AuthCodeEmailFactory::LOGO_CONTENT_ID)
        ->and($payload['attachments'][0]['type'])->toBe('image/png');

    $logo = base64_decode($payload['attachments'][0]['content'], true);
    expect($logo)->toBe(file_get_contents(resource_path('images/maik-cat-email-logo.png')));
    expect(strlen($logo))->toBeLessThan(50000);
})->with([
    ['registration', 'Verify your Maik Cat email', 'Verify your email', 'Email Verification'],
    ['password_reset', 'Reset your Maik Cat password', 'Reset your password', 'Password Reset'],
]);

it('rejects unsupported authentication email purposes', function (): void {
    expect(fn () => app(AuthCodeEmailFactory::class)->make(
        'customer@example.test',
        '482619',
        'unknown',
        new Address('auth@example.test', 'Maik Cat'),
    ))->toThrow(InvalidArgumentException::class);
});
