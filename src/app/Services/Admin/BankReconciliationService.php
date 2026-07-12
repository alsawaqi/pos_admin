<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Bank;
use App\Models\Device;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Bank reconciliation for POS card payments.
 *
 * The bank-sheet parsing, normalization and terminal_id|auth_code matching are
 * ported logic-for-logic from the charity app's BankReconciliationPreviewService
 * (bank settlement formats differ per bank, keyed on bank_id: 2 = Bank Dhofar
 * xlsx sheet "Table 1"; 1 = Oman Arab Bank csv). The ONLY adaptation is the DB
 * side: we reconcile pos_payments (card tenders) instead of charity_transactions.
 *
 * Match key = normalized terminal_id + auth code; amount tolerance < 0.0005 OMR.
 * A payment's terminal_id/bank_id come from its own snapshot columns when set,
 * otherwise from the device behind the payment (pos_api may not have snapshotted
 * them yet).
 */
class BankReconciliationService
{
    public function preview(Bank $bank, string $statementDate, UploadedFile $file): array
    {
        [$statementStart, $statementEnd] = $this->statementDayWindow($statementDate);
        $config = $this->resolveBankConfig($bank);

        if (($config['file_type'] ?? 'spreadsheet') === 'csv') {
            $parsedRows = $this->parseCsvStatementRows($file, $config);
            $detectedStatementDate = $this->detectCsvStatementDate($parsedRows);
        } else {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getSheetByName($config['sheet_name']);
            if (! $sheet) {
                throw ValidationException::withMessages([
                    'file' => ["Sheet '{$config['sheet_name']}' was not found in the uploaded file."],
                ]);
            }
            $parsedRows = $this->parseStatementRows($sheet, $config);
            $detectedStatementDate = $this->detectStatementDate($sheet, $config);
        }

        if (empty($parsedRows)) {
            throw ValidationException::withMessages([
                'file' => ['No transaction rows were found in the uploaded file.'],
            ]);
        }

        $invalidRows = array_values(array_filter($parsedRows, fn ($row) => ! empty($row['errors'])));
        $validRows = array_values(array_filter($parsedRows, fn ($row) => empty($row['errors'])));

        $rowDatesDetected = collect($validRows)->pluck('date')->filter()->unique()->values()->all();

        // Bank Dhofar: trust the statement header date over the row cells.
        if (($config['parser_code'] ?? null) === 'bank_dhofar_v1' && $detectedStatementDate) {
            $validRows = array_map(function ($row) use ($detectedStatementDate) {
                $row['date'] = $detectedStatementDate;

                return $row;
            }, $validRows);
            $rowDatesDetected = [$detectedStatementDate];
        }

        if ($detectedStatementDate && $detectedStatementDate !== $statementDate) {
            $this->throwStatementDateMismatch($statementDate, $detectedStatementDate, $rowDatesDetected, $config, 'header_date_mismatch');
        }

        if (($config['strict_row_date_validation'] ?? true) === true) {
            $unexpectedDates = collect($rowDatesDetected)->reject(fn ($d) => $d === $statementDate)->values()->all();
            if (! empty($unexpectedDates)) {
                $this->throwStatementDateMismatch($statementDate, $detectedStatementDate, $rowDatesDetected, $config, 'row_date_mismatch');
            }
        }

        [$dbByKey, $dbSnapshot] = $this->loadCandidatePayments($bank, $statementStart, $statementEnd);

        $matched = [];
        $missingInDb = [];
        $amountMismatches = [];
        $usedDbIds = [];

        foreach ($validRows as $row) {
            $key = $this->buildKey($row['terminal_id'], $row['auth_code']);
            $candidates = array_values(array_filter(
                $dbByKey[$key] ?? [],
                fn ($candidate) => ! isset($usedDbIds[$candidate['id']])
            ));

            if (empty($candidates)) {
                $missingInDb[] = [
                    'statement' => $row,
                    'reason' => 'No POS card payment found for this bank/date by terminal_id + auth code.',
                ];

                continue;
            }

            $dbMatch = $candidates[0];
            $usedDbIds[$dbMatch['id']] = true;
            // A2 — the actual bank fee for this tender = gross − settled net,
            // when the bank's statement carries the net (null otherwise). Stored
            // on the payment at commit so settlement can pre-fill it. Floored at
            // 0: a fee is never negative, and a credit/refund row (net > gross)
            // must not store a negative fee that would poison the merchant
            // residual or trip the commit's min:0 rule and block the batch.
            $bankFee = ($row['net_amount'] ?? null) !== null
                ? max(0, round($row['gross_amount'] - $row['net_amount'], 3))
                : null;
            $comparison = ['statement' => $row, 'payment' => $dbMatch, 'bank_fee' => $bankFee];

            if ($this->sameMoney($row['gross_amount'], $dbMatch['amount'])) {
                $matched[] = $comparison;
            } else {
                $amountMismatches[] = $comparison + [
                    'amount_difference' => round($row['gross_amount'] - $dbMatch['amount'], 3),
                ];
            }
        }

        $dbOnly = array_values(array_filter($dbSnapshot, fn ($r) => ! isset($usedDbIds[$r['id']])));

        return [
            'bank' => ['id' => (int) $bank->id, 'name' => $bank->name],
            'statement_date' => $statementDate,
            'detected_statement_date' => $detectedStatementDate,
            'parser' => $config['parser_code'],
            'summary' => [
                'statement_rows' => count($validRows),
                'statement_amount' => $this->sumStatementAmount($validRows),
                'payment_rows' => count($dbSnapshot),
                'matched_rows' => count($matched),
                'matched_amount' => round(array_sum(array_map(fn ($r) => (float) $r['statement']['gross_amount'], $matched)), 3),
                'missing_in_db_rows' => count($missingInDb),
                'amount_mismatch_rows' => count($amountMismatches),
                'db_only_rows' => count($dbOnly),
                'invalid_rows' => count($invalidRows),
            ],
            'matched' => array_values($matched),
            'missing_in_db' => array_values($missingInDb),
            'amount_mismatches' => array_values($amountMismatches),
            'db_only' => array_values($dbOnly),
            'invalid_rows' => array_values($invalidRows),
        ];
    }

    /**
     * Load card payments for the statement day, resolve each one's terminal_id +
     * bank_id (payment snapshot first, then the device behind it), keep only this
     * bank's, and index them by terminal_id|auth_code.
     *
     * @return array{0: array<string, list<array<string, mixed>>>, 1: list<array<string, mixed>>}
     */
    private function loadCandidatePayments(Bank $bank, Carbon $statementStart, Carbon $statementEnd): array
    {
        $payments = Payment::query()
            ->where('method', 'card')
            ->where('captured_at', '>=', $statementStart)
            ->where('captured_at', '<', $statementEnd)
            ->get(['id', 'order_id', 'terminal_id', 'bank_id', 'device_id', 'softpos_auth_code', 'amount', 'status', 'pending_reconciliation', 'captured_at']);

        $orderDeviceIds = [];
        if ($payments->isNotEmpty()) {
            $orderDeviceIds = DB::table('pos_orders')
                ->whereIn('id', $payments->pluck('order_id')->filter()->unique()->values()->all())
                ->pluck('device_id', 'id')
                ->all();
        }

        $deviceIds = [];
        foreach ($payments as $p) {
            if ($p->device_id) {
                $deviceIds[] = (int) $p->device_id;
            }
            $orderDeviceId = $orderDeviceIds[$p->order_id] ?? null;
            if ($orderDeviceId) {
                $deviceIds[] = (int) $orderDeviceId;
            }
        }

        $devices = empty($deviceIds)
            ? collect()
            : Device::query()->whereIn('id', array_values(array_unique($deviceIds)))->get(['id', 'terminal_id', 'bank_id'])->keyBy('id');

        $dbByKey = [];
        $dbSnapshot = [];

        foreach ($payments as $p) {
            $device = $devices->get($p->device_id) ?? $devices->get($orderDeviceIds[$p->order_id] ?? null);
            $terminalId = $p->terminal_id ?: ($device->terminal_id ?? null);
            $bankId = $p->bank_id ?: ($device->bank_id ?? null);

            if ((int) $bankId !== (int) $bank->id) {
                continue;
            }

            $normTerminal = $this->normalizeString($terminalId);
            $normAuth = $this->normalizeString($p->softpos_auth_code);

            $entry = [
                'id' => (int) $p->id,
                'terminal_id' => $normTerminal,
                'auth_code' => $normAuth,
                'amount' => round((float) $p->amount, 3),
                'status' => $p->status instanceof \BackedEnum ? $p->status->value : (string) $p->status,
                'pending_reconciliation' => (bool) $p->pending_reconciliation,
                'captured_at' => optional($p->captured_at)->toIso8601String(),
                'matchable' => ! empty($normTerminal) && ! empty($normAuth),
            ];

            $dbSnapshot[] = $entry;
            if ($entry['matchable']) {
                $dbByKey[$this->buildKey($normTerminal, $normAuth)][] = $entry;
            }
        }

        return [$dbByKey, $dbSnapshot];
    }

    private function resolveBankConfig(Bank $bank): array
    {
        $bankName = strtolower(trim((string) ($bank->short_name ?: $bank->name)));

        if ((int) $bank->id === 2 || str_contains($bankName, 'dhofar')) {
            return [
                'parser_code' => 'bank_dhofar_v1',
                'file_type' => 'spreadsheet',
                'sheet_name' => 'Table 1',
                'strict_row_date_validation' => false,
                'statement_header' => [
                    'cell' => 'E4',
                    'formats' => ['d/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y', 'Y-m-d'],
                ],
                'columns' => [
                    'date' => 'DATE',
                    'terminal_id' => 'TERMINAL ID',
                    'auth_code' => 'AUTHO CODE',
                    'gross_amount' => 'GROSS AMOUNT',
                    'card_no' => 'CARD NO',
                ],
            ];
        }

        if ((int) $bank->id === 1 || str_contains($bankName, 'oman arab') || str_contains($bankName, 'oab')) {
            return [
                'parser_code' => 'bank_oab_csv_v1',
                'file_type' => 'csv',
                'strict_row_date_validation' => true,
                'columns' => [
                    'transaction_date' => 'TRANSACTION_DATE',
                    'terminal_id' => 'TERMINAL_ID',
                    'branch_id' => 'BRANCH_ID',
                    'card_no' => 'CARD_NUMBER',
                    'card_type' => 'CARD_TYPE',
                    'transaction_type' => 'TRANSACTION_TYPE',
                    'transaction_reference' => 'TRANSACTION_REFERENCE',
                    'rrn' => 'RETRIEVAL_REF_NUMBER',
                    'auth_code' => 'AUTH_CODE',
                    'gross_amount' => 'TRANSACTION_AMOUNT',
                    'discount_amount' => 'DISCOUNTRATE_AMOUNT',
                    'vat_amount' => 'VAT_AMOUNT',
                    'net_amount' => 'NET_AMOUNT',
                    'related_reference' => 'RELATED_REFERENCE',
                    'date' => 'SETTLEMENTDATE',
                ],
            ];
        }

        throw ValidationException::withMessages([
            'bank_id' => ['Reconciliation parser is not configured for the selected bank yet.'],
        ]);
    }

    private function parseStatementRows(Worksheet $sheet, array $config): array
    {
        [$headerRow, $columnMap] = $this->locateHeaderRow($sheet, $config['columns']);

        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $dateCell = $sheet->getCell([$columnMap['date'], $row]);
            $terminalCell = $sheet->getCell([$columnMap['terminal_id'], $row]);
            $authCell = $sheet->getCell([$columnMap['auth_code'], $row]);
            $amountCell = $sheet->getCell([$columnMap['gross_amount'], $row]);
            $cardCell = isset($columnMap['card_no']) ? $sheet->getCell([$columnMap['card_no'], $row]) : null;

            $rawDate = $dateCell->getValue();
            $rawTerminal = $terminalCell->getFormattedValue();
            $rawAuth = $authCell->getFormattedValue();
            $rawAmount = $amountCell->getFormattedValue();
            $rawCardNo = $cardCell ? $cardCell->getFormattedValue() : null;

            if ($this->isBlankRow([$rawDate, $rawTerminal, $rawAuth, $rawAmount, $rawCardNo])) {
                continue;
            }

            $normalizedDate = $this->normalizeDateValue($rawDate, $dateCell->getFormattedValue());
            $normalizedTid = $this->normalizeString($rawTerminal);
            $normalizedAuth = $this->normalizeString($rawAuth);
            $normalizedAmount = $this->normalizeAmount($amountCell->getValue(), $rawAmount);
            $normalizedCardNo = $this->normalizeCardNumber($rawCardNo);

            $errors = [];
            if (! $normalizedDate) {
                $errors[] = 'Invalid DATE';
            }
            if (! $normalizedTid) {
                $errors[] = 'Missing TERMINAL ID';
            }
            if (! $normalizedAuth) {
                $errors[] = 'Missing AUTHO CODE';
            }
            if ($normalizedAmount === null) {
                $errors[] = 'Invalid GROSS AMOUNT';
            }

            $rows[] = [
                'row_number' => $row,
                'date' => $normalizedDate,
                'terminal_id' => $normalizedTid,
                'auth_code' => $normalizedAuth,
                'gross_amount' => $normalizedAmount,
                // This bank's statement lists gross only (no fee/net), so the
                // per-transaction fee can't be captured — the operator enters it
                // manually in settlement.
                'net_amount' => null,
                'card_no' => $normalizedCardNo,
                'errors' => $errors,
            ];
        }

        return $rows;
    }

    private function parseCsvStatementRows(UploadedFile $file, array $config): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => ['Could not open the uploaded CSV file.']]);
        }

        $headerRow = fgetcsv($handle);
        if ($headerRow === false || $headerRow === null) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => ['The uploaded CSV file is empty.']]);
        }

        $headerMap = $this->locateCsvHeaderMap($headerRow, $config['columns']);
        $rows = [];
        $rowNumber = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($data === [null] || $data === null) {
                continue;
            }

            $rawSettlementDate = $this->csvValue($data, $headerMap['date']);
            $rawTransactionDate = $this->csvValue($data, $headerMap['transaction_date']);
            $rawTerminal = $this->csvValue($data, $headerMap['terminal_id']);
            $rawAuth = $this->csvValue($data, $headerMap['auth_code']);
            $rawAmount = $this->csvValue($data, $headerMap['gross_amount']);
            $rawCardNo = $this->csvValue($data, $headerMap['card_no']);
            $rawRrn = $this->csvValue($data, $headerMap['rrn']);
            // A2 — the settled NET amount (gross − fee) when the bank provides it.
            $rawNet = isset($headerMap['net_amount']) ? $this->csvValue($data, $headerMap['net_amount']) : null;

            if ($this->isBlankRow([$rawSettlementDate, $rawTransactionDate, $rawTerminal, $rawAuth, $rawAmount, $rawCardNo, $rawRrn])) {
                continue;
            }

            $normalizedSettlementDate = $this->normalizeDateValue($rawSettlementDate, $rawSettlementDate, ['n/j/Y', 'j/n/Y', 'm/d/Y', 'd/m/Y', 'Y-m-d']);
            $normalizedTid = $this->normalizeString($rawTerminal);
            $normalizedAuth = $this->normalizeString($rawAuth);
            $normalizedAmount = $this->normalizeAmount($rawAmount, $rawAmount);
            $normalizedCardNo = $this->normalizeCardNumber($rawCardNo);
            $normalizedRrn = $this->normalizeReference($rawRrn);
            $normalizedNet = ($rawNet !== null && trim((string) $rawNet) !== '') ? $this->normalizeAmount($rawNet, $rawNet) : null;

            $errors = [];
            if (! $normalizedSettlementDate) {
                $errors[] = 'Invalid SETTLEMENTDATE';
            }
            if (! $normalizedTid) {
                $errors[] = 'Missing TERMINAL_ID';
            }
            if (! $normalizedAuth) {
                $errors[] = 'Missing AUTH_CODE';
            }
            if ($normalizedAmount === null) {
                $errors[] = 'Invalid TRANSACTION_AMOUNT';
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'date' => $normalizedSettlementDate,
                'terminal_id' => $normalizedTid,
                'auth_code' => $normalizedAuth,
                'gross_amount' => $normalizedAmount,
                'net_amount' => $normalizedNet,
                'card_no' => $normalizedCardNo,
                'rrn' => $normalizedRrn,
                'settlement_date' => $normalizedSettlementDate,
                'errors' => $errors,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function detectCsvStatementDate(array $rows): ?string
    {
        $dates = collect($rows)->pluck('date')->filter()->unique()->values()->all();

        return count($dates) === 1 ? $dates[0] : null;
    }

    private function detectStatementDate(Worksheet $sheet, array $config): ?string
    {
        $headerConfig = $config['statement_header'] ?? null;
        if (! is_array($headerConfig) || empty($headerConfig['cell'])) {
            return null;
        }

        $cell = $sheet->getCell($headerConfig['cell']);
        $rawValue = $cell->getValue();
        $formattedValue = trim((string) $cell->getFormattedValue());
        $formats = $headerConfig['formats'] ?? ['d/m/Y', 'd-m-Y', 'm/d/Y', 'm-d-Y', 'Y-m-d'];

        if (preg_match('/(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4})/', $formattedValue, $matches)) {
            $fromText = $this->normalizeDateValue($matches[1], $matches[1], $formats);
            if ($fromText) {
                return $fromText;
            }
        }

        return $this->normalizeDateValue($rawValue, $formattedValue, $formats);
    }

    private function locateHeaderRow(Worksheet $sheet, array $requiredColumns): array
    {
        $highestRow = min(30, $sheet->getHighestDataRow());

        $normalizedTargets = [];
        foreach ($requiredColumns as $key => $label) {
            $normalizedTargets[$key] = $this->normalizeHeader($label);
        }

        for ($row = 1; $row <= $highestRow; $row++) {
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn($row));
            $found = [];

            for ($col = 1; $col <= $highestColumn; $col++) {
                $value = $this->normalizeHeader((string) $sheet->getCell([$col, $row])->getFormattedValue());
                foreach ($normalizedTargets as $key => $target) {
                    if ($value === $target) {
                        $found[$key] = $col;
                    }
                }
            }

            if (count($found) === count($normalizedTargets)) {
                return [$row, $found];
            }
        }

        throw ValidationException::withMessages([
            'file' => ['Could not find the required header row in the uploaded file.'],
        ]);
    }

    private function locateCsvHeaderMap(array $headerRow, array $requiredColumns): array
    {
        $normalizedHeaders = [];
        foreach ($headerRow as $index => $label) {
            $normalizedHeaders[$index] = $this->normalizeHeader((string) $label);
        }

        $found = [];
        foreach ($requiredColumns as $key => $label) {
            $target = $this->normalizeHeader($label);
            $index = array_search($target, $normalizedHeaders, true);
            if ($index === false) {
                throw ValidationException::withMessages([
                    'file' => ["Required CSV column '{$label}' was not found in the uploaded file."],
                ]);
            }
            $found[$key] = $index;
        }

        return $found;
    }

    private function csvValue(array $row, int $index): ?string
    {
        $value = $row[$index] ?? null;
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeHeader(?string $value): string
    {
        $value = (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value);
        $value = strtoupper($value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $value ?? '';
    }

    private function normalizeString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', '', $value);
        $value = strtoupper($value);

        return $value === '' ? null : $value;
    }

    private function normalizeReference($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        $value = preg_replace('/\.0$/', '', $value);
        $value = preg_replace('/\s+/', '', $value);

        return $value === '' ? null : $value;
    }

    private function normalizeCardNumber($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        $value = preg_replace('/\s+/', '', $value);

        return $value === '' ? null : $value;
    }

    private function normalizeAmount($rawValue, $formattedValue): ?float
    {
        if (is_numeric($rawValue)) {
            return round((float) $rawValue, 3);
        }

        $value = trim((string) $formattedValue);
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/[^0-9\.\-]/', '', $value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 3);
    }

    private function normalizeDateValue($rawValue, $formattedValue, array $preferredFormats = []): ?string
    {
        try {
            if ($rawValue instanceof \DateTimeInterface) {
                return Carbon::instance($rawValue)->toDateString();
            }
            if (is_numeric($rawValue)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $rawValue))->toDateString();
            }

            $formatted = trim((string) $formattedValue);
            if ($formatted === '') {
                return null;
            }

            $formats = array_values(array_unique(array_merge($preferredFormats, [
                'Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'd.m.Y', 'n/j/Y', 'j/n/Y',
            ])));

            foreach ($formats as $format) {
                try {
                    return Carbon::createFromFormat($format, $formatted)->toDateString();
                } catch (\Throwable $e) {
                    // keep trying
                }
            }

            return Carbon::parse($formatted)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function buildKey(?string $terminalId, ?string $authCode): string
    {
        return ($terminalId ?? '').'|'.($authCode ?? '');
    }

    private function sameMoney(?float $left, ?float $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return abs($left - $right) < 0.0005;
    }

    private function sumStatementAmount(array $rows): float
    {
        return round(array_sum(array_map(fn ($row) => (float) ($row['gross_amount'] ?? 0), $rows)), 3);
    }

    private function throwStatementDateMismatch(string $selectedDate, ?string $detectedStatementDate, array $rowDatesDetected, array $config, string $reason): void
    {
        $rowDatesDetected = array_values(array_unique(array_filter($rowDatesDetected)));
        $statementHeaderCell = data_get($config, 'statement_header.cell');
        $suggestedDate = $detectedStatementDate ?: ($rowDatesDetected[0] ?? null);

        throw new HttpResponseException(response()->json([
            'message' => 'The selected date does not match the uploaded bank statement.',
            'errors' => [
                'statement_date' => array_values(array_filter([
                    'The selected date does not match the uploaded bank statement.',
                    "Selected date: {$selectedDate}",
                    $detectedStatementDate ? "Detected statement date: {$detectedStatementDate}" : null,
                    ! empty($rowDatesDetected) ? 'Detected row dates: '.implode(', ', $rowDatesDetected) : null,
                    $suggestedDate ? "Suggested date to use: {$suggestedDate}" : null,
                ])),
            ],
            'meta' => [
                'reason' => $reason,
                'selected_date' => $selectedDate,
                'detected_statement_date' => $detectedStatementDate,
                'row_dates_detected' => $rowDatesDetected,
                'statement_header_cell' => $statementHeaderCell,
                'suggested_date' => $suggestedDate,
            ],
        ], 422));
    }

    private function statementDayWindow(string $statementDate): array
    {
        $timezone = config('app.timezone', 'Asia/Muscat');
        $start = Carbon::createFromFormat('Y-m-d', $statementDate, $timezone)->startOfDay();
        $end = $start->copy()->addDay();

        return [$start, $end];
    }
}
