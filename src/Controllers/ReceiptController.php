<?php

declare(strict_types=1);

namespace Obong\Payment\Controllers;

use Obong\Payment\Core\Controller;
use Obong\Payment\Core\HttpException;
use Obong\Payment\Core\Request;

final class ReceiptController extends Controller
{
    public function show(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor);
        $receipt = $this->find($params['receiptOrReference']);
        if (!$receipt || ($actor['type'] === 'STUDENT' && (int) $receipt['student_id'] !== (int) $actor['id'])) {
            throw new HttpException('Official receipt not found.', 404);
        }
        return $this->receiptPayload($receipt);
    }

    public function verifyPublic(Request $request): array
    {
        $query = trim((string) ($request->query['q'] ?? ''));
        if ($query === '') {
            throw new HttpException('Enter a receipt number or transaction reference.', 422);
        }
        $receipt = $this->find($query);
        if (!$receipt) {
            throw new HttpException('No matching university payment receipt was found.', 404);
        }

        return [
            'isValid' => $receipt['status'] === 'PAID',
            'receiptNumber' => $receipt['receipt_number'],
            'studentName' => $receipt['student_name'],
            'regNumber' => $receipt['registration_number'],
            'paymentType' => $receipt['payment_type_name'],
            'amount' => (float) $receipt['amount'],
            'session' => $receipt['session_name'],
            'paymentDate' => $receipt['created_at'],
            'status' => $receipt['status'] === 'PAID' ? 'VALID' : $receipt['status'],
        ];
    }

    private function find(string $identifier): ?array
    {
        return $this->one(
            'SELECT r.receipt_number, r.verification_code, t.id AS transaction_id, t.transaction_reference, t.paystack_reference, t.amount, t.channel, t.status, t.created_at, s.id AS student_id, s.registration_number, s.first_name, s.other_names, s.surname, p.name AS payment_type_name, a.name AS session_name, d.name AS department_name, f.name AS faculty_name, pr.name AS programme_name, s.level, t.verified_at FROM receipts r JOIN transactions t ON t.id = r.transaction_id JOIN students s ON s.id = t.student_id JOIN invoices i ON i.id = t.invoice_id JOIN payment_types p ON p.id = i.payment_type_id JOIN academic_sessions a ON a.id = i.academic_session_id JOIN programmes pr ON pr.id = s.programme_id JOIN departments d ON d.id = pr.department_id JOIN faculties f ON f.id = d.faculty_id WHERE r.receipt_number = ? OR t.transaction_reference = ? OR t.paystack_reference = ? OR r.verification_code = ? LIMIT 1',
            [$identifier, $identifier, $identifier, $identifier]
        );
    }

    private function receiptPayload(array $row): array
    {
        return [
            'receiptNumber' => $row['receipt_number'],
            'transactionReference' => $row['transaction_reference'],
            'paystackReference' => $row['paystack_reference'],
            'studentName' => trim(implode(' ', array_filter([$row['first_name'], $row['other_names'], $row['surname']]))),
            'regNumber' => $row['registration_number'],
            'faculty' => $row['faculty_name'],
            'department' => $row['department_name'],
            'programme' => $row['programme_name'],
            'level' => $row['level'],
            'paymentType' => $row['payment_type_name'],
            'session' => $row['session_name'],
            'amount' => (float) $row['amount'],
            'paymentDate' => $row['created_at'],
            'status' => $row['status'],
            'channel' => $row['channel'] ?: 'Paystack',
            'verifiedBy' => $row['verified_at'] ? 'Paystack API' : 'Pending verification',
        ];
    }
}