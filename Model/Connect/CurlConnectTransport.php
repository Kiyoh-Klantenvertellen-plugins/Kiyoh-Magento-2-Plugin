<?php

namespace Kiyoh\Reviews\Model\Connect;

use Kiyoh\Reviews\Model\ModuleMeta;

/**
 * TLS-verified HTTP client for Kiyoh-Connect. Timeouts: connect 5s, read 30s.
 */
class CurlConnectTransport implements ConnectTransportInterface
{
    private const MAX_BODY_BYTES = 1000000;

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): ConnectHttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL is not available');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'User-Agent: Kiyoh-Magento/' . ModuleMeta::VERSION;

        $timeoutSeconds = max(1, $timeoutSeconds);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new \RuntimeException($error !== '' ? $error : 'Kiyoh-Connect request failed');
        }

        $raw = (string) $raw;
        $headerText = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);
        if (strlen($responseBody) > self::MAX_BODY_BYTES) {
            throw new \RuntimeException('Kiyoh-Connect response was too large');
        }

        return new ConnectHttpResponse($status, $responseBody, $this->retryAfterHeader($headerText));
    }

    private function retryAfterHeader(string $headerText): ?string
    {
        $value = null;
        $lines = preg_split("/\r\n|\n|\r/", $headerText);
        if (!is_array($lines)) {
            return null;
        }
        foreach ($lines as $line) {
            if (stripos($line, 'Retry-After:') === 0) {
                $value = trim(substr($line, strlen('Retry-After:')));
            }
        }

        return $value === '' ? null : $value;
    }
}
