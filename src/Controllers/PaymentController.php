<?php

declare(strict_types=1);

namespace Obong\Payment\Controllers;

use Obong\Payment\Core\Controller;
use Obong\Payment\Core\HttpException;
use Obong\Payment\Core\Request;

final class PaymentController extends Controller
{
    public function createToken(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        if ($actor['role'] !== 'SUPER_ADMIN') {
            throw new HttpException('Forbidden.', 403);
        }

        $rawInvoiceId = $request->body['invoice_id'] ?? null;
        if (!is_string($rawInvoiceId) && !is_int($rawInvoiceId)) {
            throw new HttpException('Select a valid invoice.', 422);
        }
        $invoiceId = $this->idFromPublicId($rawInvoiceId, 'inv');
        $rawAmount = $request->body['amount'] ?? null;
        if (!is_string($rawAmount) && !is_int($rawAmount) && !is_float($rawAmount)) {
            throw new HttpException('Enter a valid payment amount greater than zero.', 422);
        }
        $requestedAmount = filter_var($rawAmount, FILTER_VALIDATE_FLOAT);
        if ($requestedAmount === false || $requestedAmount <= 0) {
            throw new HttpException('Enter a valid payment amount greater than zero.', 422);
        }
        $amountCents = (int) round($requestedAmount * 100);
        if ($amountCents <= 0 || abs($requestedAmount - ($amountCents / 100)) > 0.000001) {
            throw new HttpException('Payment amount cannot have more than two decimal places.', 422);
        }

        $this->db->beginTransaction();
        try {
            $invoice = $this->one(
                'SELECT i.*, s.registration_number, p.name AS payment_type_name, a.name AS session_name FROM invoices i JOIN students s ON s.id = i.student_id JOIN payment_types p ON p.id = i.payment_type_id JOIN academic_sessions a ON a.id = i.academic_session_id WHERE i.id = ? FOR UPDATE',
                [$invoiceId]
            );
            if (!$invoice) {
                throw new HttpException('Invoice not found.', 404);
            }
            if (!in_array($invoice['status'], ['UNPAID', 'PARTIAL', 'OVERDUE'], true)) {
                throw new HttpException('This invoice is not payable.', 409);
            }

            $remainingCents = (int) round(((float) $invoice['amount'] - (float) $invoice['amount_paid']) * 100);
            if ($amountCents > $remainingCents) {
                throw new HttpException('Payment amount cannot exceed the outstanding balance.', 422);
            }

            $this->run(
                "UPDATE transactions SET status = 'ABANDONED' WHERE invoice_id = ? AND status = 'PENDING' AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)",
                [$invoiceId]
            );
            $pending = $this->one(
                "SELECT id FROM transactions WHERE invoice_id = ? AND status = 'PENDING' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) LIMIT 1",
                [$invoiceId]
            );
            if ($pending) {
                throw new HttpException('A Paystack payment attempt is already in progress for this invoice.', 409);
            }

            $plainToken = $this->newUniquePaymentToken();
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
            $currency = getenv('PAYSTACK_CURRENCY') ?: 'NGN';
            $this->run(
                'INSERT INTO payment_tokens (id, token_hash, invoice_id, student_id, issued_by, amount, currency, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->newId(),
                    hash('sha256', $plainToken),
                    $invoice['id'],
                    $invoice['student_id'],
                    $actor['id'],
                    $amountCents / 100,
                    $currency,
                    $expiresAt,
                ]
            );
            $this->audit($actor, 'Generated special payment token', 'Invoice', (string) $invoice['id'], [
                'amount' => $amountCents / 100,
                'expires_at' => $expiresAt,
            ]);
            $this->db->commit();

            return [
                'status' => 'success',
                'data' => [
                    'token' => $plainToken,
                    'amount' => $amountCents / 100,
                    'currency' => $currency,
                    'expiresAt' => gmdate(DATE_ATOM, strtotime($expiresAt . ' UTC')),
                    'studentId' => 'std_' . $invoice['student_id'],
                    'registrationNumber' => $invoice['registration_number'],
                    'invoiceId' => 'inv_' . $invoice['id'],
                    'paymentTypeName' => $invoice['payment_type_name'],
                    'session' => $invoice['session_name'],
                ],
            ];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function redeemToken(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'STUDENT');
        $rawToken = $request->body['token'] ?? null;
        if (!is_string($rawToken)) {
            throw new HttpException('Enter a valid payment token.', 422);
        }
        $token = strtoupper(trim($rawToken));
        if (!preg_match('/^(?:[A-HJ-NP-Z2-9]{10}|OBT-[A-F0-9]{64})$/', $token)) {
            throw new HttpException('Invalid payment token.', 422);
        }

        $this->db->beginTransaction();
        try {
            $paymentToken = $this->one(
                'SELECT * FROM payment_tokens WHERE token_hash = ? FOR UPDATE',
                [hash('sha256', $token)]
            );
            if (!$paymentToken || $paymentToken['student_id'] !== $actor['id']) {
                throw new HttpException('Invalid payment token for this student.', 404);
            }
            if ($paymentToken['redeemed_at'] !== null) {
                throw new HttpException('This payment token has already been used.', 409);
            }
            if (strtotime($paymentToken['expires_at'] . ' UTC') <= time()) {
                throw new HttpException('This payment token has expired.', 410);
            }

            $invoice = $this->one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$paymentToken['invoice_id']]);
            if (!$invoice || !in_array($invoice['status'], ['UNPAID', 'PARTIAL', 'OVERDUE'], true)) {
                throw new HttpException('This invoice is no longer payable.', 409);
            }
            $amountCents = (int) round((float) $paymentToken['amount'] * 100);
            $paidCents = (int) round((float) $invoice['amount_paid'] * 100);
            $totalCents = (int) round((float) $invoice['amount'] * 100);
            if ($amountCents > $totalCents - $paidCents) {
                throw new HttpException("The token amount exceeds this invoice's outstanding balance.", 409);
            }

            $transactionId = $this->newId();
            $reference = 'TOKEN-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(16)));
            $this->run(
                "INSERT INTO transactions (id, invoice_id, student_id, transaction_reference, amount, currency, gateway, channel, status, gateway_response, verified_at) VALUES (?, ?, ?, ?, ?, ?, 'TOKEN', 'ADMIN_TOKEN', 'PAID', ?, UTC_TIMESTAMP())",
                [
                    $transactionId,
                    $invoice['id'],
                    $actor['id'],
                    $reference,
                    $amountCents / 100,
                    $paymentToken['currency'],
                    json_encode(['method' => 'ADMIN_TOKEN'], JSON_UNESCAPED_SLASHES),
                ]
            );

            $newPaidCents = $paidCents + $amountCents;
            $invoiceStatus = $newPaidCents >= $totalCents ? 'PAID' : 'PARTIAL';
            $this->run(
                'UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?',
                [$newPaidCents / 100, $invoiceStatus, $invoice['id']]
            );
            $this->run(
                'UPDATE payment_tokens SET redeemed_at = UTC_TIMESTAMP(), transaction_id = ? WHERE id = ? AND redeemed_at IS NULL',
                [$transactionId, $paymentToken['id']]
            );
            $receiptNumber = $this->newUniqueReceiptNumber();
            $this->run(
                'INSERT INTO receipts (id, transaction_id, receipt_number, verification_code, issued_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                [$this->newId(), $transactionId, $receiptNumber, bin2hex(random_bytes(32))]
            );
            $this->audit($actor, 'Redeemed special payment token', 'Transaction', $reference, [
                'invoice_id' => $invoice['id'],
                'amount' => $amountCents / 100,
            ]);
            $this->db->commit();

            return [
                'status' => 'success',
                'data' => [
                    'transaction' => $this->transactionPayload($transactionId),
                    'invoiceId' => 'inv_' . $invoice['id'],
                    'amountPaid' => $newPaidCents / 100,
                    'remainingBalance' => ($totalCents - $newPaidCents) / 100,
                    'invoiceStatus' => $invoiceStatus,
                ],
            ];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function initialize(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'STUDENT');
        $invoiceId = $this->idFromPublicId($request->body['invoice_id'] ?? null, 'inv');
        $invoice = $this->one(
            'SELECT i.*, s.email, s.registration_number, p.allow_partial_payment FROM invoices i JOIN students s ON s.id = i.student_id JOIN payment_types p ON p.id = i.payment_type_id WHERE i.id = ? AND i.student_id = ? FOR UPDATE',
            [$invoiceId, $actor['id']]
        );
        if (!$invoice) {
            throw new HttpException('Invoice not found for this student.', 404);
        }
        if (!in_array($invoice['status'], ['UNPAID', 'PARTIAL', 'OVERDUE'], true)) {
            throw new HttpException('This invoice is not payable.', 409);
        }

        $pending = $this->one(
            "SELECT id FROM transactions WHERE invoice_id = ? AND status = 'PENDING' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) LIMIT 1",
            [$invoiceId]
        );
        if ($pending) {
            throw new HttpException('A payment attempt is already in progress for this invoice. Try again in 30 minutes if it was abandoned.', 409);
        }
        $this->run(
            "UPDATE transactions SET status = 'ABANDONED' WHERE invoice_id = ? AND status = 'PENDING' AND created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)",
            [$invoiceId]
        );

        $totalCents = (int) round((float) $invoice['amount'] * 100);
        $paidCents = (int) round((float) $invoice['amount_paid'] * 100);
        $remainingCents = $totalCents - $paidCents;
        if ($remainingCents <= 0) {
            throw new HttpException('This invoice has no outstanding balance.', 409);
        }

        $requestedAmount = array_key_exists('amount', $request->body)
            ? filter_var($request->body['amount'], FILTER_VALIDATE_FLOAT)
            : $remainingCents / 100;
        if ($requestedAmount === false || $requestedAmount <= 0) {
            throw new HttpException('Enter a valid payment amount greater than zero.', 422);
        }
        $requestedCents = (int) round($requestedAmount * 100);
        if (abs($requestedAmount - ($requestedCents / 100)) > 0.000001) {
            throw new HttpException('Payment amount cannot have more than two decimal places.', 422);
        }
        if ($requestedCents > $remainingCents) {
            throw new HttpException('Payment amount cannot exceed the outstanding balance.', 422);
        }
        if (!(bool) $invoice['allow_partial_payment'] && $requestedCents !== $remainingCents) {
            throw new HttpException('This fee requires full payment. Part payments are not enabled.', 422);
        }
        if ((bool) $invoice['allow_partial_payment'] && $paidCents === 0) {
            $minimumFirstPaymentCents = intdiv(($totalCents * 60) + 99, 100);
            if ($requestedCents < $minimumFirstPaymentCents) {
                throw new HttpException(
                    'The first part payment must be at least 60% of the total invoice amount ('
                    . number_format($minimumFirstPaymentCents / 100, 2, '.', '')
                    . ').',
                    422
                );
            }
        }
        $amount = $requestedCents / 100;

        $reference = 'OBONG-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(16)));
        $payload = [
            'email' => $invoice['email'],
            'amount' => (int) round($amount * 100),
            'currency' => getenv('PAYSTACK_CURRENCY') ?: 'NGN',
            'reference' => $reference,
            'metadata' => [
                'invoice_id' => $invoice['id'],
                'student_id' => $actor['id'],
                'registration_number' => $invoice['registration_number'],
            ],
        ];
        $callbackUrl = trim((string) ($request->body['callback_url'] ?? ''));
        if ($callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }

        $gatewayResponse = $this->paystackRequest('POST', '/transaction/initialize', $payload);

        $data = $gatewayResponse['data'] ?? [];
        if (empty($data['authorization_url'])) {
            throw new HttpException('Paystack did not return a checkout URL.', 502);
        }

        $this->run(
            "INSERT INTO transactions (id, invoice_id, student_id, transaction_reference, paystack_reference, amount, currency, gateway, status, gateway_response) VALUES (?, ?, ?, ?, ?, ?, ?, 'PAYSTACK', 'PENDING', ?)",
            [
                $this->newId(),
                $invoice['id'],
                $actor['id'],
                $reference,
                $data['reference'] ?? $reference,
                $amount,
                getenv('PAYSTACK_CURRENCY') ?: 'NGN',
                json_encode($gatewayResponse, JSON_UNESCAPED_SLASHES),
            ]
        );
        $this->audit($actor, 'Initialized Paystack payment', 'Invoice', (string) $invoice['id'], ['reference' => $reference]);

        return [
            'status' => 'success',
            'data' => [
                'authorization_url' => $data['authorization_url'],
                'access_code' => $data['access_code'] ?? null,
                'reference' => $reference,
            ],
        ];
    }

    public function verify(Request $request, array $params, ?array $actor): array
    {
        $reference = $params['reference'];
        $transaction = $this->one('SELECT * FROM transactions WHERE transaction_reference = ? OR paystack_reference = ?', [$reference, $reference]);
        if (!$transaction) {
            throw new HttpException('Transaction reference not found.', 404);
        }
        if ($actor !== null && $actor['type'] === 'STUDENT' && $transaction['student_id'] !== $actor['id']) {
            throw new HttpException('Forbidden.', 403);
        }

        if ($transaction['gateway'] === 'TOKEN') {
            return ['status' => 'success', 'data' => $this->transactionPayload($transaction['id'])];
        }

        $verification = $this->paystackRequest('GET', '/transaction/verify/' . rawurlencode($transaction['paystack_reference'] ?: $transaction['transaction_reference']));
        $gatewayData = $verification['data'] ?? [];
        $this->settle($transaction, $gatewayData, $verification);

        return ['status' => 'success', 'data' => $this->transactionPayload($transaction['id'])];
    }

    public function webhook(Request $request): array
    {
        $secret = getenv('PAYSTACK_SECRET_KEY') ?: '';
        $signature = $request->headers['x-paystack-signature'] ?? '';
        if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha512', $request->rawBody, $secret), $signature)) {
            throw new HttpException('Invalid Paystack webhook signature.', 401);
        }

        $event = (string) ($request->body['event'] ?? '');
        $data = $request->body['data'] ?? [];
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') {
            return ['status' => 'success', 'message' => 'Webhook ignored: no transaction reference.'];
        }

        $transaction = $this->one('SELECT * FROM transactions WHERE transaction_reference = ? OR paystack_reference = ?', [$reference, $reference]);
        if (!$transaction) {
            return ['status' => 'success', 'message' => 'Webhook acknowledged.'];
        }

        if ($event === 'charge.success') {
            $this->settle($transaction, $data, $request->body);
        } elseif ($event === 'charge.failed') {
            $this->run(
                "UPDATE transactions SET status = 'FAILED', gateway_response = ?, verified_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'PENDING'",
                [json_encode($request->body, JSON_UNESCAPED_SLASHES), $transaction['id']]
            );
        }

        return ['status' => 'success', 'message' => 'Webhook acknowledged.'];
    }

    private function settle(array $transaction, array $gatewayData, array $fullResponse): void
    {
        $status = strtolower((string) ($gatewayData['status'] ?? ''));
        $expectedKobo = (int) round((float) $transaction['amount'] * 100);
        $actualKobo = (int) (
            $gatewayData['requested_amount']
            ?? $gatewayData['amount']
            ?? 0
        );

        error_log(json_encode([
            'reference' => $transaction['transaction_reference'],
            'status' => $status,
            'expected_kobo' => $expectedKobo,
            'actual_kobo' => $actualKobo,
            'amount' => $gatewayData['amount'] ?? null,
            'requested_amount' => $gatewayData['requested_amount'] ?? null,
            'fees' => $gatewayData['fees'] ?? null,
        ], JSON_UNESCAPED_SLASHES));

        $successful = $status === 'success' && $actualKobo === $expectedKobo;

        $this->db->beginTransaction();
        try {
            $locked = $this->one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$transaction['id']]);
            if ($locked['status'] !== 'PENDING') {
                $this->db->commit();
                return;
            }

            $nextStatus = $successful ? 'PAID' : ($status === 'failed' ? 'FAILED' : 'PENDING');
            $this->run(
                'UPDATE transactions SET status = ?, channel = ?, gateway_response = ?, verified_at = UTC_TIMESTAMP() WHERE id = ?',
                [$nextStatus, $gatewayData['channel'] ?? null, json_encode($fullResponse, JSON_UNESCAPED_SLASHES), $transaction['id']]
            );

            if ($successful) {
                $invoice = $this->one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$transaction['invoice_id']]);
                $newPaid = min((float) $invoice['amount'], (float) $invoice['amount_paid'] + (float) $transaction['amount']);
                $invoiceStatus = $newPaid >= (float) $invoice['amount'] ? 'PAID' : 'PARTIAL';
                $this->run('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?', [$newPaid, $invoiceStatus, $invoice['id']]);

                $existingReceipt = $this->one('SELECT id FROM receipts WHERE transaction_id = ?', [$transaction['id']]);
                if (!$existingReceipt) {
                    $receiptNumber = $this->newUniqueReceiptNumber();
                    $this->run(
                        'INSERT INTO receipts (id, transaction_id, receipt_number, verification_code, issued_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                        [$this->newId(), $transaction['id'], $receiptNumber, bin2hex(random_bytes(32))]
                    );
                }
                $this->audit(['type' => 'SYSTEM', 'name' => 'Paystack'], 'Verified successful payment', 'Transaction', $transaction['transaction_reference']);
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function paystackRequest(string $method, string $path, ?array $payload = null): array
    {
        $secret = getenv('PAYSTACK_SECRET_KEY') ?: '';
        if (
            (!str_starts_with($secret, 'sk_test_') && !str_starts_with($secret, 'sk_live_'))
            || str_contains(strtolower($secret), 'replace_me')
        ) {
            throw new HttpException('Paystack secret key is not configured on the backend.', 503);
        }

        $headers = "Authorization: Bearer {$secret}\r\nContent-Type: application/json\r\n";
        $options = [
            'http' => [
                'method' => $method,
                'header' => $headers,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ];
        if ($payload !== null) {
            $options['http']['content'] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        $response = @file_get_contents('https://api.paystack.co' . $path, false, stream_context_create($options));
        if ($response === false) {
            throw new HttpException('Could not connect to Paystack.', 502);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new HttpException('Paystack returned an invalid response.', 502);
        }
        if (($decoded['status'] ?? false) !== true) {
            $gatewayMessage = trim((string) ($decoded['message'] ?? ''));
            $message = $gatewayMessage !== ''
                ? 'Paystack rejected the payment request: ' . $gatewayMessage
                : 'Paystack rejected the payment request.';
            throw new HttpException($message, 502);
        }
        return $decoded;
    }

    private function transactionPayload(string $id): array
    {
        $row = $this->one(
            'SELECT t.*, s.registration_number, s.first_name, s.other_names, s.surname, s.email, p.name AS payment_type_name, a.name AS session_name, r.receipt_number FROM transactions t JOIN students s ON s.id = t.student_id JOIN invoices i ON i.id = t.invoice_id JOIN payment_types p ON p.id = i.payment_type_id JOIN academic_sessions a ON a.id = i.academic_session_id LEFT JOIN receipts r ON r.transaction_id = t.id WHERE t.id = ?',
            [$id]
        );
        return [
            'id' => 'txn_' . $row['id'],
            'reference' => $row['transaction_reference'],
            'receiptNumber' => $row['receipt_number'],
            'paystackRef' => $row['paystack_reference'],
            'studentId' => 'std_' . $row['student_id'],
            'studentName' => trim(implode(' ', array_filter([$row['first_name'], $row['other_names'], $row['surname']]))),
            'regNumber' => $row['registration_number'],
            'paymentTypeName' => $row['payment_type_name'],
            'amount' => (float) $row['amount'],
            'session' => $row['session_name'],
            'gateway' => $row['gateway'] === 'TOKEN' ? 'Payment Token' : 'Paystack',
            'paymentSource' => $row['gateway'],
            'gatewayMode' => $row['gateway'] === 'PAYSTACK'
                ? (str_starts_with(getenv('PAYSTACK_SECRET_KEY') ?: '', 'sk_test_') ? 'TEST' : 'LIVE')
                : null,
            'channel' => $row['channel'],
            'status' => $row['status'],
            'date' => $row['created_at'],
            'verifiedBy' => !$row['verified_at'] ? null : ($row['gateway'] === 'TOKEN' ? 'Payment Token' : 'Paystack API'),
            'reconciled' => $row['reconciled_at'] !== null,
        ];
    }

    private function newUniquePaymentToken(): string
    {
        do {
            $token = $this->newShortCode();
            $existing = $this->one('SELECT id FROM payment_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        } while ($existing);

        return $token;
    }

    private function newUniqueReceiptNumber(): string
    {
        do {
            $receiptNumber = $this->newShortCode();
            $existing = $this->one('SELECT id FROM receipts WHERE receipt_number = ?', [$receiptNumber]);
        } while ($existing);

        return $receiptNumber;
    }

    private function newShortCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($index = 0; $index < 10; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}