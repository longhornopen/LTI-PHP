<?php
declare(strict_types=1);

namespace ceLTIc\LTI\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Class to implement the HTTP message interface using the Laravel HTTP facade
 */
class LaravelHttpClient implements ClientInterface
{

    /**
     * Timeout in seconds for outbound requests.
     */
    private const CONNECT_TIMEOUT = 30;

    /**
     * Send the request to the target URL.
     *
     * @param HttpMessage $message
     *
     * @return bool  True if the request was successful
     */
    public function send(HttpMessage $message): bool
    {
        $headers = $message->requestHeaders;

        if (count(preg_grep('/^Accept:/i', $headers)) === 0) {
            $headers[] = 'Accept: */*';
        }
        if (($message->getMethod() !== 'GET') && !is_null($message->request) &&
            (count(preg_grep('/^Content-Type:/i', $headers)) === 0)) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
        }

        $headerAssoc = $this->convertHeadersToAssoc($headers);

        $pendingRequest = Http::connectTimeout(self::CONNECT_TIMEOUT)->withHeaders($headerAssoc);

        $options = [];
        if (($message->getMethod() !== 'GET') && !is_null($message->request)) {
            $options['body'] = $message->request;
        }

        $message->requestHeaders = $this->formatRequestHeaders($message->getMethod(), $message->getUrl(), $headerAssoc);

        try {
            $response = $this->sendRequest($pendingRequest, $message->getMethod(), $message->getUrl(), $options);
            $psrResponse = $response->toPsrResponse();

            $message->status = $psrResponse->getStatusCode();
            $message->response = $response->body();
            $message->responseHeaders = $this->formatResponseHeaders(
                $psrResponse->getProtocolVersion(),
                $psrResponse->getStatusCode(),
                $psrResponse->getReasonPhrase(),
                $psrResponse->getHeaders()
            );
            $message->ok = ($message->status >= 100) && ($message->status < 400);

            if (!$message->ok) {
                $message->error = $message->responseHeaders[0] ?? 'HTTP request failed';
            }

            return $message->ok;
        } catch (\Throwable $exception) {
            $message->status = 0;
            $message->response = null;
            $message->responseHeaders = [];
            $message->error = $exception->getMessage();
            $message->ok = false;

            return false;
        }
    }

    /**
     * Convert header lines ("Name: value") into an associative array for the pending request.
     */
    private function convertHeadersToAssoc(array $headers): array
    {
        $converted = [];
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $converted[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $converted;
    }

    /**
     * Format response headers to the representation expected by HttpMessage.
     */
    private function formatResponseHeaders(string $protocol, int $status, string $reason, array $headers): array
    {
        $formatted = [sprintf('HTTP/%s %d %s', $protocol, $status, $reason)];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $formatted[] = $name . ': ' . $value;
            }
        }

        return $formatted;
    }

    /**
     * Format request headers for logging/debugging consistency with other clients.
     */
    private function formatRequestHeaders(string $method, string $url, array $headers): array
    {
        $formatted = [$method . ' ' . $url];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    /**
     * Execute the HTTP request using the provided pending request.
     */
    private function sendRequest(PendingRequest $pendingRequest, string $method, string $url, array $options): Response
    {
        return $pendingRequest->send($method, $url, $options);
    }
}
