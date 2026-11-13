<?php

namespace App\Services;

use App\Exceptions\AuthEmailDeliveryException;
use Mailtrap\Config;
use Mailtrap\MailtrapSendingClient;
use Psr\Http\Client\NetworkExceptionInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Mime\Address;

class AuthEmailSender
{
    public function __construct(private readonly AuthCodeEmailFactory $emails) {}

    public function sendCode(string $email, string $code, string $purpose): ?string
    {
        $key = (string) config('services.mailtrap.api_key');
        $from = (string) config('services.mailtrap.from_address');

        if ($key === '' || $from === '') {
            throw new AuthEmailDeliveryException('configuration');
        }

        $message = $this->emails->make(
            $email,
            $code,
            $purpose,
            new Address($from, (string) config('services.mailtrap.from_name')),
        );

        $config = (new Config($key))
            ->setHttpClient(new Psr18Client(HttpClient::create([
                'timeout' => 10,
                'max_duration' => 15,
                'max_redirects' => 0,
            ])))
            ->setResponseThrowOnError(false);
        try {
            $response = (new MailtrapSendingClient($config))->emails()->send($message);
            $status = $response->getStatusCode();
            $result = json_decode((string) $response->getBody(), true);
        } catch (NetworkExceptionInterface $exception) {
            $category = str_contains(strtolower($exception->getMessage()), 'timeout') ? 'timeout' : 'network';

            throw new AuthEmailDeliveryException($category, retryable: true);
        }

        if ($status < 200 || $status >= 300 || ($result['success'] ?? false) !== true) {
            $retryAfter = $response->getHeaderLine('Retry-After');
            $retryAfterSeconds = match (true) {
                ctype_digit($retryAfter) => (int) $retryAfter,
                $retryAfter !== '' && ($date = strtotime($retryAfter)) !== false => max(0, $date - time()),
                default => null,
            };

            throw new AuthEmailDeliveryException(
                $status === 429 ? 'rate_limited' : ($status >= 500 ? 'provider_unavailable' : 'provider_rejected'),
                retryable: $status === 429 || $status >= 500,
                httpStatus: $status,
                retryAfter: $retryAfterSeconds,
            );
        }

        $messageId = $result['message_ids'][0] ?? null;

        return is_string($messageId) && preg_match('/\A[a-f0-9-]{36}\z/i', $messageId) ? $messageId : null;
    }
}
