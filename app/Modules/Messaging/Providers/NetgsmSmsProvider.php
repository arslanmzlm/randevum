<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Contracts\SmsProviderInterface;
use App\Modules\Messaging\Data\SmsResponse;
use Illuminate\Support\Facades\Http;

/**
 * Netgsm REST provider (MVP). NOTE: the endpoint, payload and success codes below
 * follow Netgsm REST v2 — verify them against current Netgsm docs before production.
 */
final class NetgsmSmsProvider implements SmsProviderInterface
{
    /**
     * @param  array{base_url: string, usercode: ?string, password: ?string, header: ?string}  $config
     */
    public function __construct(private array $config) {}

    public function send(string $phone, string $body): SmsResponse
    {
        $response = Http::baseUrl($this->config['base_url'])
            ->withBasicAuth((string) $this->config['usercode'], (string) $this->config['password'])
            ->acceptJson()
            ->post('/sms/rest/v2/send', [
                'msgheader' => $this->config['header'],
                'encoding' => 'TR',
                'messages' => [
                    ['msg' => $body, 'no' => ltrim($phone, '+')],
                ],
            ]);

        if ($response->failed()) {
            return SmsResponse::failure("Netgsm HTTP {$response->status()}");
        }

        $code = (string) $response->json('code');
        $jobId = $response->json('jobid');
        $reference = $jobId !== null ? (string) $jobId : null;

        // Netgsm success codes: 00 queued, 01/02 partial accept; anything else is an error.
        return in_array($code, ['00', '01', '02'], true)
            ? SmsResponse::success($reference)
            : SmsResponse::failure("Netgsm error code {$code}", $reference);
    }
}
