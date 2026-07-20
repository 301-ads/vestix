<?php

namespace App\Services\Ibkr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * IBKR Flex Web Service: SendRequest → poll GetStatement → raw XML statement.
 */
class FlexWebServiceClient
{
    public function fetchStatementXml(?string $token = null, ?string $queryId = null): string
    {
        $token ??= (string) config('vestix.ibkr.flex.token', '');
        $queryId ??= (string) config('vestix.ibkr.flex.query_id', '');

        if ($token === '' || $queryId === '') {
            throw new RuntimeException(
                'IBKR Flex is not configured. Set IBKR_FLEX_TOKEN and IBKR_FLEX_QUERY_ID.',
            );
        }

        $referenceCode = $this->sendRequest($token, $queryId);

        return $this->getStatement($token, $referenceCode);
    }

    private function sendRequest(string $token, string $queryId): string
    {
        $baseUrl = rtrim((string) config('vestix.ibkr.flex.base_url'), '/');
        $url = "{$baseUrl}/FlexStatementService.SendRequest";
        $attempts = max(1, (int) config('vestix.ibkr.flex.send_request_attempts', 3));
        $delayMs = max(250, (int) config('vestix.ibkr.flex.poll_delay_ms', 1500));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout((int) config('vestix.ibkr.flex.timeout_seconds', 30))
                    ->get($url, [
                        't' => $token,
                        'q' => $queryId,
                        'v' => '3',
                    ]);
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    $this->sleepBeforeRetry($delayMs, $attempt);

                    continue;
                }

                throw new RuntimeException('IBKR Flex SendRequest connection failed: '.$exception->getMessage(), 0, $exception);
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    'IBKR Flex SendRequest HTTP '.$response->status().': '.$response->body(),
                );
            }

            $xml = @simplexml_load_string($response->body());

            if ($xml === false) {
                throw new RuntimeException('IBKR Flex SendRequest returned invalid XML.');
            }

            $status = strtolower(trim((string) ($xml->Status ?? '')));

            if ($status !== 'success') {
                $errorCode = (string) ($xml->ErrorCode ?? '');
                $errorMessage = (string) ($xml->ErrorMessage ?? $xml->Status ?? 'Unknown error');

                if ($this->isRetryableFlexError($errorCode, $errorMessage) && $attempt < $attempts) {
                    $this->sleepBeforeRetry($delayMs, $attempt);

                    continue;
                }

                throw new RuntimeException(
                    "IBKR Flex SendRequest failed ({$errorCode}): {$errorMessage}",
                );
            }

            $referenceCode = trim((string) ($xml->ReferenceCode ?? ''));

            if ($referenceCode === '') {
                throw new RuntimeException('IBKR Flex SendRequest succeeded without a ReferenceCode.');
            }

            return $referenceCode;
        }

        throw new RuntimeException('IBKR Flex SendRequest exhausted retry attempts.');
    }

    private function getStatement(string $token, string $referenceCode): string
    {
        $baseUrl = rtrim((string) config('vestix.ibkr.flex.base_url'), '/');
        $url = "{$baseUrl}/FlexStatementService.GetStatement";
        $attempts = max(1, (int) config('vestix.ibkr.flex.poll_attempts', 8));
        $delayMs = max(250, (int) config('vestix.ibkr.flex.poll_delay_ms', 1500));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout((int) config('vestix.ibkr.flex.timeout_seconds', 30))
                    ->get($url, [
                        't' => $token,
                        'q' => $referenceCode,
                        'v' => '3',
                    ]);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('IBKR Flex GetStatement connection failed: '.$exception->getMessage(), 0, $exception);
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    'IBKR Flex GetStatement HTTP '.$response->status().': '.$response->body(),
                );
            }

            $body = $response->body();
            $xml = @simplexml_load_string($body);

            if ($xml === false) {
                throw new RuntimeException('IBKR Flex GetStatement returned invalid XML.');
            }

            // Error wrapper while statement is generating.
            if (isset($xml->Status) && strtolower(trim((string) $xml->Status)) !== 'success') {
                $errorCode = (string) ($xml->ErrorCode ?? '');
                $errorMessage = (string) ($xml->ErrorMessage ?? $xml->Status ?? 'Statement not ready');

                if ($this->isRetryableFlexError($errorCode, $errorMessage) && $attempt < $attempts) {
                    $this->sleepBeforeRetry($delayMs, $attempt);

                    continue;
                }

                throw new RuntimeException(
                    "IBKR Flex GetStatement failed ({$errorCode}): {$errorMessage}",
                );
            }

            // Full statement payload.
            if (isset($xml->FlexStatements) || isset($xml->FlexStatement) || $xml->getName() === 'FlexQueryResponse') {
                return $body;
            }

            if ($attempt < $attempts) {
                $this->sleepBeforeRetry($delayMs, $attempt);

                continue;
            }

            throw new RuntimeException('IBKR Flex GetStatement did not return a Flex statement in time.');
        }

        throw new RuntimeException('IBKR Flex GetStatement exhausted poll attempts.');
    }

    private function sleepBeforeRetry(int $baseDelayMs, int $attempt): void
    {
        // Exponential backoff: 1.5s, 3s, 6s … caps IBKR hammering after 1001 bursts.
        $multiplier = 2 ** max(0, $attempt - 1);
        usleep($baseDelayMs * $multiplier * 1000);
    }

    private function isRetryableFlexError(string $errorCode, string $errorMessage): bool
    {
        // Hard stops — retrying makes IBKR return 1025 (rate limit).
        $nonRetryableCodes = ['1012', '1013', '1015', '1025'];
        if (in_array($errorCode, $nonRetryableCodes, true)) {
            return false;
        }

        // 1001 = statement could not be generated at this time (transient IBKR-side load).
        $retryableCodes = ['1001', '1009', '1018', '1019', '1020'];
        if (in_array($errorCode, $retryableCodes, true)) {
            return true;
        }

        $lower = strtolower($errorMessage);

        if (str_contains($lower, 'too many failed attempts')) {
            return false;
        }

        return str_contains($lower, 'not ready')
            || str_contains($lower, 'incomplete')
            || str_contains($lower, 'generation in progress')
            || str_contains($lower, 'try again');
    }
}
