<?php

namespace App\Services\Ibkr;

use App\Data\Ibkr\IbkrOpenOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Read-only Client Portal open/working orders.
 * Requires a reachable CP Gateway session (IBKR_CP_BASE_URL).
 */
class ClientPortalOpenOrdersClient
{
    /**
     * @return list<IbkrOpenOrder>
     */
    public function fetchOpenOrders(): array
    {
        if (! (bool) config('vestix.ibkr.client_portal.enabled', false)) {
            return [];
        }

        $baseUrl = rtrim((string) config('vestix.ibkr.client_portal.base_url', ''), '/');

        if ($baseUrl === '') {
            throw new RuntimeException(
                'IBKR Client Portal is enabled but IBKR_CP_BASE_URL is empty.',
            );
        }

        try {
            $response = Http::timeout((int) config('vestix.ibkr.client_portal.timeout_seconds', 15))
                ->acceptJson()
                ->get("{$baseUrl}/v1/api/iserver/account/orders", [
                    'force' => 'true',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'IBKR Client Portal open-orders connection failed: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException(
                'IBKR Client Portal session is not authenticated. Log in to the CP Gateway.',
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'IBKR Client Portal open-orders HTTP '.$response->status().': '.$response->body(),
            );
        }

        $payload = $response->json();
        $orders = $payload['orders'] ?? $payload;

        if (! is_array($orders)) {
            Log::warning('IBKR Client Portal open-orders response missing orders array.');

            return [];
        }

        $parsed = [];

        foreach ($orders as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = (string) ($row['status'] ?? $row['order_status'] ?? '');
            if (! $this->isWorkingStatus($status)) {
                continue;
            }

            $symbol = (string) ($row['ticker'] ?? $row['symbol'] ?? $row['contractDescription'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $parsed[] = new IbkrOpenOrder(
                symbol: strtoupper($symbol),
                quantity: (float) ($row['totalSize'] ?? $row['quantity'] ?? $row['remainingQuantity'] ?? 0),
                side: strtoupper((string) ($row['side'] ?? '')),
                orderType: strtoupper((string) ($row['orderType'] ?? $row['origOrderType'] ?? '')),
                status: $status,
                limitPrice: isset($row['price']) ? (float) $row['price'] : (isset($row['limit_price']) ? (float) $row['limit_price'] : null),
                stopPrice: isset($row['auxPrice']) ? (float) $row['auxPrice'] : (isset($row['stop_price']) ? (float) $row['stop_price'] : null),
                brokerOrderId: isset($row['orderId']) ? (string) $row['orderId'] : (isset($row['order_id']) ? (string) $row['order_id'] : null),
            );
        }

        return $parsed;
    }

    private function isWorkingStatus(string $status): bool
    {
        $normalized = strtolower(trim($status));

        if ($normalized === '') {
            return false;
        }

        $working = [
            'pendingsubmit',
            'preSubmitted',
            'presubmitted',
            'submitted',
            'pendingcancel',
            'pending submit',
            'inactive',
        ];

        foreach ($working as $candidate) {
            if ($normalized === strtolower($candidate)) {
                return true;
            }
        }

        // CP sometimes returns "PendingSubmit", "Submitted", etc.
        return str_contains($normalized, 'submit')
            || str_contains($normalized, 'pending')
            || str_contains($normalized, 'working');
    }
}
