<?php

declare(strict_types=1);

namespace Obong\Payment\Controllers;

use Obong\Payment\Core\Controller;
use Obong\Payment\Core\HttpException;
use Obong\Payment\Core\Request;

final class TransactionController extends Controller
{
    public function index(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor);
        $conditions = [];
        $values = [];
        if ($actor['type'] === 'STUDENT') {
            $conditions[] = 't.student_id = ?';
            $values[] = $actor['id'];
        }
        foreach (['status' => 't.status', 'session' => 'a.name', 'paymentType' => 'p.name'] as $key => $column) {
            $value = trim((string) ($request->query[$key] ?? ''));
            if ($value !== '' && $value !== 'ALL') {
                $conditions[] = "{$column} = ?";
                $values[] = $value;
            }
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $date = trim((string) ($request->query[$key] ?? ''));
            if ($date !== '') {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                    throw new HttpException("Invalid {$key} date filter.", 422);
                }
                $conditions[] = "t.created_at {$operator} ?";
                $values[] = $key === 'to' ? $parsed->modify('+1 day')->format('Y-m-d') : $date;
            }
        }
        $search = trim((string) ($request->query['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(t.transaction_reference LIKE ? OR t.paystack_reference LIKE ? OR s.registration_number LIKE ? OR r.receipt_number LIKE ? OR CONCAT(s.first_name, \' \', s.surname) LIKE ?)';
            array_push($values, ...array_fill(0, 5, '%' . $search . '%'));
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $rows = $this->all(
            "SELECT t.*, s.registration_number, s.first_name, s.other_names, s.surname, p.name AS payment_type_name, a.name AS session_name, r.receipt_number FROM transactions t JOIN students s ON s.id = t.student_id JOIN invoices i ON i.id = t.invoice_id JOIN payment_types p ON p.id = i.payment_type_id JOIN academic_sessions a ON a.id = i.academic_session_id LEFT JOIN receipts r ON r.transaction_id = t.id {$where} ORDER BY t.created_at DESC LIMIT 500",
            $values
        );

        return array_map(fn (array $row): array => $this->payload($row), $rows);
    }

    public function show(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor);
        $row = $this->find($params['reference']);
        if (!$row || ($actor['type'] === 'STUDENT' && $row['student_id'] !== $actor['id'])) {
            throw new HttpException('Transaction not found.', 404);
        }
        return $this->payload($row);
    }

    private function find(string $reference): ?array
    {
        return $this->one(
            'SELECT t.*, s.registration_number, s.first_name, s.other_names, s.surname, p.name AS payment_type_name, a.name AS session_name, r.receipt_number FROM transactions t JOIN students s ON s.id = t.student_id JOIN invoices i ON i.id = t.invoice_id JOIN payment_types p ON p.id = i.payment_type_id JOIN academic_sessions a ON a.id = i.academic_session_id LEFT JOIN receipts r ON r.transaction_id = t.id WHERE t.transaction_reference = ? OR t.paystack_reference = ? OR r.receipt_number = ? LIMIT 1',
            [$reference, $reference, $reference]
        );
    }

    private function payload(array $row): array
    {
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
            'gateway' => 'Paystack',
            'channel' => $row['channel'],
            'status' => $row['status'],
            'date' => $row['created_at'],
            'verifiedBy' => $row['verified_at'] ? 'Paystack API' : null,
            'reconciled' => $row['reconciled_at'] !== null,
        ];
    }
}