<?php

namespace App\Services\Ibkr;

use App\Data\Ibkr\FlexStatementMetadata;
use App\Data\Ibkr\IbkrAccountSnapshot;
use App\Data\Ibkr\IbkrCashTransaction;
use App\Data\Ibkr\IbkrOpenPosition;
use RuntimeException;
use SimpleXMLElement;

class FlexStatementParser
{
    public function parse(string $xmlBody, ?string $expectedBaseCurrency = null): IbkrAccountSnapshot
    {
        $expectedBaseCurrency ??= strtoupper((string) config('vestix.ibkr.expected_base_currency', 'USD'));

        $xml = @simplexml_load_string($xmlBody);

        if ($xml === false) {
            throw new RuntimeException('Unable to parse IBKR Flex statement XML.');
        }

        $statement = $this->resolveFlexStatement($xml);
        $baseCurrency = $this->resolveBaseCurrency($statement);

        if ($baseCurrency !== $expectedBaseCurrency) {
            throw new RuntimeException(
                "IBKR Flex base currency mismatch: expected {$expectedBaseCurrency}, got {$baseCurrency}. "
                .'Configure the Flex Query / account reporting in USD to protect Alpha Tracker stats.',
            );
        }

        $netLiquidation = $this->resolveNetLiquidation($statement);
        $availableFunds = $this->resolveAvailableFunds($statement, $netLiquidation);
        $settledCash = $this->resolveSettledCash($statement, $availableFunds);

        return new IbkrAccountSnapshot(
            netLiquidation: round($netLiquidation, 2),
            availableFunds: round($availableFunds, 2),
            settledCash: round($settledCash, 2),
            baseCurrency: $baseCurrency,
            openPositions: $this->parseOpenPositions($statement),
            openOrders: [],
            cashTransactions: $this->parseCashTransactions($statement),
            metadata: $this->parseMetadata($statement),
        );
    }

    private function parseMetadata(SimpleXMLElement $statement): FlexStatementMetadata
    {
        return new FlexStatementMetadata(
            accountId: $this->nullableAttribute($statement, 'accountId'),
            fromDate: $this->nullableAttribute($statement, 'fromDate'),
            toDate: $this->nullableAttribute($statement, 'toDate'),
            period: $this->nullableAttribute($statement, 'period'),
            whenGenerated: $this->nullableAttribute($statement, 'whenGenerated'),
        );
    }

    private function nullableAttribute(SimpleXMLElement $node, string $attribute): ?string
    {
        $value = trim((string) ($node[$attribute] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function resolveFlexStatement(SimpleXMLElement $xml): SimpleXMLElement
    {
        if ($xml->getName() === 'FlexStatement') {
            return $xml;
        }

        if (isset($xml->FlexStatements->FlexStatement)) {
            $statements = $xml->FlexStatements->FlexStatement;

            return is_array($statements) || $statements instanceof \Traversable
                ? ($statements[0] ?? $statements)
                : $statements;
        }

        if (isset($xml->FlexStatement)) {
            return $xml->FlexStatement;
        }

        throw new RuntimeException('IBKR Flex XML does not contain a FlexStatement node.');
    }

    private function resolveBaseCurrency(SimpleXMLElement $statement): string
    {
        $candidates = [
            (string) ($statement['currency'] ?? ''),
            (string) ($statement->AccountInformation['currency'] ?? ''),
            (string) ($statement->AccountInformation['currencyPrimary'] ?? ''),
            (string) ($statement->EquitySummaryInBase['currency'] ?? ''),
            (string) ($statement->CashReport->CashReportCurrency['currency'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $currency = strtoupper(trim($candidate));

            if ($currency !== '' && strlen($currency) === 3) {
                return $currency;
            }
        }

        throw new RuntimeException('IBKR Flex statement is missing a base currency.');
    }

    private function resolveNetLiquidation(SimpleXMLElement $statement): float
    {
        $latestEquity = $this->latestEquitySummaryRow($statement);

        $paths = [
            $latestEquity['total'] ?? null,
            $statement->EquitySummaryInBase['total'] ?? null,
            $statement->EquitySummaryInBase['endingValue'] ?? null,
            $statement->ChangeInNAV['endingValue'] ?? null,
            $statement->AccountInformation['netLiquidation'] ?? null,
        ];

        foreach ($paths as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return (float) $value;
            }
        }

        throw new RuntimeException('IBKR Flex statement is missing Net Liquidation / Equity Summary.');
    }

    private function resolveAvailableFunds(SimpleXMLElement $statement, float $fallback): float
    {
        $latestEquity = $this->latestEquitySummaryRow($statement);

        $paths = [
            $statement->EquitySummaryInBase['availableFunds'] ?? null,
            $statement->AccountInformation['availableFunds'] ?? null,
            $statement->CashReport->CashReportCurrency['endingCash'] ?? null,
            // Activity Flex often omits CashReport; equity summary cash is the next-best proxy.
            $latestEquity['cash'] ?? null,
        ];

        foreach ($paths as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return (float) $value;
            }
        }

        return $fallback;
    }

    private function resolveSettledCash(SimpleXMLElement $statement, float $fallback): float
    {
        $cashReport = $statement->CashReport->CashReportCurrency ?? null;

        if ($cashReport instanceof SimpleXMLElement) {
            // Prefer BASE currency row when multiple currencies exist.
            foreach ($cashReport as $row) {
                $currency = strtoupper((string) ($row['currency'] ?? ''));
                $level = strtoupper((string) ($row['levelOfDetail'] ?? ''));

                if ($currency === 'BASE' || $level === 'BASE' || $level === 'CURRENCY' || $level === 'BASECURRENCY') {
                    $settled = (string) ($row['endingSettledCash'] ?? '');

                    if ($settled !== '') {
                        return (float) $settled;
                    }
                }
            }

            $first = $cashReport[0] ?? $cashReport;
            $settled = (string) ($first['endingSettledCash'] ?? '');

            if ($settled !== '') {
                return (float) $settled;
            }
        }

        $direct = (string) ($statement->AccountInformation['settledCash'] ?? '');

        if ($direct !== '') {
            return (float) $direct;
        }

        $latestEquity = $this->latestEquitySummaryRow($statement);
        $cash = (string) ($latestEquity['cash'] ?? '');

        if ($cash !== '') {
            return (float) $cash;
        }

        return $fallback;
    }

    /**
     * Real Activity Flex queries nest daily rows under EquitySummaryInBase.
     * Prefer the latest reportDate (end of statement period).
     */
    private function latestEquitySummaryRow(SimpleXMLElement $statement): ?SimpleXMLElement
    {
        $rows = $statement->EquitySummaryInBase->EquitySummaryByReportDateInBase ?? null;

        if (! $rows instanceof SimpleXMLElement) {
            return null;
        }

        $latest = null;
        $latestDate = '';

        foreach ($rows as $row) {
            $reportDate = (string) ($row['reportDate'] ?? '');

            if ($reportDate === '') {
                continue;
            }

            if ($latest === null || $reportDate >= $latestDate) {
                $latest = $row;
                $latestDate = $reportDate;
            }
        }

        return $latest;
    }

    /**
     * @return list<IbkrOpenPosition>
     */
    private function parseOpenPositions(SimpleXMLElement $statement): array
    {
        $positions = [];
        $nodes = $statement->OpenPositions->OpenPosition ?? [];

        foreach ($nodes as $node) {
            $symbol = trim((string) ($node['symbol'] ?? ''));
            $quantity = (float) ($node['position'] ?? $node['quantity'] ?? 0);

            if ($symbol === '' || $quantity == 0.0) {
                continue;
            }

            $positions[] = new IbkrOpenPosition($symbol, $quantity);
        }

        return $positions;
    }

    /**
     * @return list<IbkrCashTransaction>
     */
    private function parseCashTransactions(SimpleXMLElement $statement): array
    {
        $transactions = [];
        $nodes = $statement->CashTransactions->CashTransaction ?? [];

        foreach ($nodes as $node) {
            $type = trim((string) ($node['type'] ?? ''));
            $amount = (float) ($node['amount'] ?? 0);
            $currency = strtoupper(trim((string) ($node['currency'] ?? 'USD')));
            $dateRaw = (string) ($node['reportDate'] ?? $node['dateTime'] ?? $node['settleDate'] ?? '');
            $date = $this->normalizeDate($dateRaw);
            $transactionId = trim((string) ($node['transactionID'] ?? $node['transactionId'] ?? ''));
            $description = trim((string) ($node['description'] ?? '')) ?: null;
            $fxRateToBase = $this->nullableFloat($node['fxRateToBase'] ?? $node['fxRateToBaseCurrency'] ?? null);

            if ($type === '' || $amount == 0.0 || $date === null) {
                continue;
            }

            $externalId = $transactionId !== ''
                ? $transactionId
                : hash('sha256', implode('|', [$date, $type, $amount, $currency, $description ?? '']));

            $transactions[] = new IbkrCashTransaction(
                externalId: $externalId,
                type: $type,
                amount: $amount,
                currency: $currency,
                date: $date,
                description: $description,
                fxRateToBase: $fxRateToBase,
            );
        }

        return $transactions;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $rate = (float) $raw;

        return $rate > 0 ? $rate : null;
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // reportDate often YYYYMMDD; dateTime may be YYYYMMDD;HHMMSS
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
