<?php

declare(strict_types=1);

namespace Obong\Payment\Controllers;

use Obong\Payment\Core\Controller;
use Obong\Payment\Core\HttpException;
use Obong\Payment\Core\Request;

final class StudentController extends Controller
{
    public function profile(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'STUDENT');
        $student = $this->studentRecord($actor['id']);
        return $this->studentPayload($student);
    }

    public function updateProfile(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'STUDENT');
        $email = strtolower(trim((string) ($request->body['email'] ?? '')));
        $phone = trim((string) ($request->body['phone'] ?? ''));
        $regNumber = trim((string) ($request->body['regNumber'] ?? $request->body['reg_number'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Enter a valid email address.', 422);
        }

        try {
            $this->run(
                'UPDATE students SET registration_number = COALESCE(NULLIF(?, \'\'), registration_number), email = COALESCE(NULLIF(?, \'\'), email), phone = ? WHERE id = ?',
                [$regNumber, $email, $phone !== '' ? $phone : null, $actor['id']]
            );
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException('Registration number or email is already in use.', 409);
            }
            throw $exception;
        }
        $student = $this->studentRecord($actor['id']);
        $this->audit($actor, 'Updated student profile', 'Student', $student['registration_number']);
        return $this->studentPayload($student);
    }

    public function invoices(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'STUDENT');
        $this->syncMandatoryInvoices($actor['id']);
        $rows = $this->all(
            'SELECT i.*, s.registration_number, a.name AS session_name, p.id AS type_id, p.name AS payment_type_name, p.allow_partial_payment FROM invoices i JOIN students s ON s.id = i.student_id JOIN academic_sessions a ON a.id = i.academic_session_id JOIN payment_types p ON p.id = i.payment_type_id WHERE i.student_id = ? ORDER BY i.created_at DESC',
            [$actor['id']]
        );

        return array_map(static fn (array $invoice): array => [
            'id' => 'inv_' . $invoice['id'],
            'studentId' => 'std_' . $invoice['student_id'],
            'regNumber' => $invoice['registration_number'],
            'paymentTypeId' => 'pt_' . $invoice['type_id'],
            'paymentTypeName' => $invoice['payment_type_name'],
            'session' => $invoice['session_name'],
            'amount' => (float) $invoice['amount'],
            'amountPaid' => (float) $invoice['amount_paid'],
            'allowPartialPayment' => (bool) $invoice['allow_partial_payment'],
            'status' => $invoice['status'],
            'dueDate' => $invoice['due_date'],
        ], $rows);
    }

    public function paymentSummary(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'STUDENT');
        $this->syncMandatoryInvoices($actor['id']);
        $summary = $this->one(
            "SELECT COALESCE((SELECT SUM(amount - amount_paid) FROM invoices WHERE student_id = ? AND status IN ('UNPAID', 'PARTIAL', 'OVERDUE')), 0) AS outstanding_payments, COALESCE((SELECT SUM(amount) FROM transactions WHERE student_id = ? AND status = 'PAID'), 0) AS total_paid, (SELECT COUNT(*) FROM transactions WHERE student_id = ?) AS transaction_count, (SELECT COUNT(*) FROM receipts r JOIN transactions t ON t.id = r.transaction_id WHERE t.student_id = ?) AS receipt_count",
            [$actor['id'], $actor['id'], $actor['id'], $actor['id']]
        );

        return [
            'outstandingPayments' => (float) $summary['outstanding_payments'],
            'totalPaid' => (float) $summary['total_paid'],
            'transactionCount' => (int) $summary['transaction_count'],
            'receiptCount' => (int) $summary['receipt_count'],
        ];
    }

    private function syncMandatoryInvoices(string $studentId): void
    {
        $this->run(
            "INSERT IGNORE INTO invoices (id, student_id, payment_type_id, academic_session_id, amount, status) SELECT UUID(), s.id, p.id, p.academic_session_id, COALESCE(fs.amount, p.amount), 'UNPAID' FROM students s JOIN programmes pr ON pr.id = s.programme_id JOIN departments d ON d.id = pr.department_id JOIN payment_types p ON p.academic_session_id = s.academic_session_id LEFT JOIN payment_type_fee_schedules fs ON fs.payment_type_id = p.id AND fs.department_id = d.id AND fs.applicable_level = s.level WHERE s.id = ? AND p.status = 'ACTIVE' AND p.is_mandatory = 1 AND (fs.id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM payment_type_fee_schedules any_fs WHERE any_fs.payment_type_id = p.id)) AND (p.faculty_id IS NULL OR p.faculty_id = d.faculty_id) AND (p.department_id IS NULL OR p.department_id = d.id) AND (p.applicable_level IN ('ALL', 'All Levels') OR p.applicable_level = s.level)",
            [$studentId]
        );
    }

    private function studentRecord(string $id): array
    {
        $student = $this->one(
            'SELECT s.*, a.name AS session_name, p.name AS programme_name, p.study_mode, d.name AS department_name, f.name AS faculty_name FROM students s JOIN academic_sessions a ON a.id = s.academic_session_id JOIN programmes p ON p.id = s.programme_id JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id WHERE s.id = ?',
            [$id]
        );
        if (!$student) {
            throw new HttpException('Student not found.', 404);
        }
        return $student;
    }

    private function studentPayload(array $student): array
    {
        return [
            'id' => 'std_' . $student['id'],
            'regNumber' => $student['registration_number'],
            'surname' => $student['surname'],
            'firstName' => $student['first_name'],
            'otherNames' => $student['other_names'] ?? '',
            'fullName' => trim(implode(' ', array_filter([$student['first_name'], $student['other_names'], $student['surname']]))),
            'email' => $student['email'],
            'phone' => $student['phone'],
            'faculty' => $student['faculty_name'],
            'department' => $student['department_name'],
            'programme' => $student['programme_name'],
            'studyMode' => $student['study_mode'],
            'level' => $student['level'],
            'session' => $student['session_name'],
            'currentSession' => $student['session_name'],
            'accountStatus' => $student['account_status'],
            'createdAt' => $student['created_at'],
        ];
    }
}