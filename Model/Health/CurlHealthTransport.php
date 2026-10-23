<?php

namespace Kiyoh\Reviews\Model\Health;

/**
 * Short-timeout POST used for Kiyoh Mobile health reports.
 */
class CurlHealthTransport implements HealthTransportInterface
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HealthTransportResponse
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

        $timeoutSeconds = max(1, $timeoutSeconds);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(1, $timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new \RuntimeException($error !== '' ? $error : 'health request failed');
        }

        $raw = (string) $raw;
        $headerText = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        return new HealthTransportResponse($status, $responseBody, $this->retryAfterHeader($headerText));
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
