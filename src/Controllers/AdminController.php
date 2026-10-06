<?php

declare(strict_types=1);

namespace Obong\Payment\Controllers;

use Obong\Payment\Core\Controller;
use Obong\Payment\Core\HttpException;
use Obong\Payment\Core\Request;

final class AdminController extends Controller
{
    private const GLOBAL_SCOPE_ID = '00000000-0000-0000-0000-000000000000';

    public function stats(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        $stats = $this->one(
            "SELECT COALESCE(SUM(CASE WHEN status = 'PAID' THEN amount ELSE 0 END), 0) AS total_collection, COALESCE(SUM(CASE WHEN status = 'PAID' AND DATE(created_at) = UTC_DATE() THEN amount ELSE 0 END), 0) AS today_collection, SUM(status = 'PAID') AS successful_transactions, SUM(status = 'PENDING') AS pending_transactions, SUM(status = 'FAILED') AS failed_transactions FROM transactions"
        );
        $activeStudents = $this->one("SELECT COUNT(*) AS total FROM students WHERE account_status = 'ACTIVE'");
        $session = $this->one("SELECT name FROM academic_sessions WHERE status = 'ACTIVE' ORDER BY id DESC LIMIT 1");

        return [
            'totalCollection' => (float) $stats['total_collection'],
            'todayCollection' => (float) $stats['today_collection'],
            'successfulTransactions' => (int) $stats['successful_transactions'],
            'pendingTransactions' => (int) $stats['pending_transactions'],
            'failedTransactions' => (int) $stats['failed_transactions'],
            'activeStudents' => (int) $activeStudents['total'],
            'currentSession' => $session['name'] ?? null,
        ];
    }

    public function students(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        $conditions = [];
        $values = [];
        $search = trim((string) ($request->query['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(s.registration_number LIKE ? OR s.email LIKE ? OR CONCAT(s.first_name, \' \', s.surname) LIKE ?)';
            array_push($values, ...array_fill(0, 3, '%' . $search . '%'));
        }
        if (!empty($request->query['level']) && $request->query['level'] !== 'ALL') {
            $conditions[] = 's.level = ?';
            $values[] = $request->query['level'];
        }
        if (!empty($request->query['faculty']) && $request->query['faculty'] !== 'ALL') {
            $conditions[] = 'f.name = ?';
            $values[] = $request->query['faculty'];
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $rows = $this->all(
            "SELECT s.*, p.name AS programme_name, p.study_mode, d.name AS department_name, f.name AS faculty_name, a.name AS session_name FROM students s JOIN programmes p ON p.id = s.programme_id JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id JOIN academic_sessions a ON a.id = s.academic_session_id {$where} ORDER BY s.created_at DESC LIMIT 500",
            $values
        );
        return array_map(fn (array $student): array => $this->studentPayload($student), $rows);
    }

    public function student(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        $student = $this->findStudent($params['id']);
        if (!$student) {
            throw new HttpException('Student not found.', 404);
        }
        $payload = $this->studentPayload($student);
        $payload['invoices'] = $this->all(
            'SELECT i.id, i.amount, i.amount_paid, i.status, i.due_date, a.name AS session_name, p.id AS payment_type_id, p.name AS payment_type_name FROM invoices i JOIN academic_sessions a ON a.id = i.academic_session_id JOIN payment_types p ON p.id = i.payment_type_id WHERE i.student_id = ? ORDER BY i.created_at DESC',
            [$student['id']]
        );
        $payload['invoices'] = array_map(static fn (array $invoice): array => [
            'id' => 'inv_' . $invoice['id'],
            'paymentTypeId' => 'pt_' . $invoice['payment_type_id'],
            'paymentTypeName' => $invoice['payment_type_name'],
            'session' => $invoice['session_name'],
            'amount' => (float) $invoice['amount'],
            'amountPaid' => (float) $invoice['amount_paid'],
            'status' => $invoice['status'],
            'dueDate' => $invoice['due_date'],
        ], $payload['invoices']);
        $payload['transactions'] = $this->all(
            'SELECT t.id, t.transaction_reference, t.paystack_reference, t.amount, t.status, t.channel, t.gateway, t.created_at, r.receipt_number FROM transactions t LEFT JOIN receipts r ON r.transaction_id = t.id WHERE t.student_id = ? ORDER BY t.created_at DESC',
            [$student['id']]
        );
        $payload['transactions'] = array_map(static fn (array $transaction): array => [
            'id' => 'txn_' . $transaction['id'],
            'reference' => $transaction['transaction_reference'],
            'paystackRef' => $transaction['paystack_reference'],
            'gateway' => $transaction['gateway'] === 'TOKEN' ? 'Payment Token' : 'Paystack',
            'paymentSource' => $transaction['gateway'],
            'receiptNumber' => $transaction['receipt_number'],
            'amount' => (float) $transaction['amount'],
            'status' => $transaction['status'],
            'channel' => $transaction['channel'],
            'date' => $transaction['created_at'],
        ], $payload['transactions']);
        $this->audit($actor, 'Viewed student record', 'Student', $student['registration_number']);
        return $payload;
    }

    public function createStudent(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $body = $request->body;
        $programmeId = $body['programmeId'] ?? $body['programme_id'] ?? null;
        $sessionId = $body['academicSessionId'] ?? $body['academic_session_id'] ?? null;
        if ($programmeId !== null) {
            $programmeId = $this->normalizeUuid($programmeId);
        }
        if ($sessionId !== null) {
            $sessionId = $this->normalizeUuid($sessionId);
        }
        foreach (['regNumber', 'firstName', 'surname', 'email', 'level'] as $field) {
            if (trim((string) ($body[$field] ?? '')) === '') {
                throw new HttpException("{$field} is required.", 422);
            }
        }
        if (!$programmeId || !filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('A valid programme and email are required.', 422);
        }
        $session = $sessionId
            ? $this->one('SELECT * FROM academic_sessions WHERE id = ?', [$sessionId])
            : $this->one("SELECT * FROM academic_sessions WHERE status = 'ACTIVE' ORDER BY id DESC LIMIT 1");
        $programme = $this->one('SELECT id FROM programmes WHERE id = ?', [$programmeId]);
        if (!$session || !$programme) {
            throw new HttpException('Academic session or programme not found.', 422);
        }
        if ($programmeId === false || $sessionId === false) {
            throw new HttpException('A valid programme and academic session are required.', 422);
        }
        $studentId = $this->newId();
        $this->run(
            'INSERT INTO students (id, academic_session_id, programme_id, registration_number, surname, first_name, other_names, email, phone, level, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$studentId, $session['id'], $programme['id'], trim($body['regNumber']), trim($body['surname']), trim($body['firstName']), trim((string) ($body['otherNames'] ?? '')) ?: null, strtolower(trim($body['email'])), trim((string) ($body['phone'] ?? '')) ?: null, trim($body['level']), password_hash((string) ($body['password'] ?? bin2hex(random_bytes(12))), PASSWORD_DEFAULT)]
        );
        $student = $this->findStudent($studentId);
        $this->audit($actor, 'Created student account', 'Student', $student['registration_number']);
        return $this->studentPayload($student);
    }

    public function administrators(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        return array_map(static fn (array $admin): array => [
            'id' => 'adm_' . $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
            'role' => $admin['role'],
            'department' => $admin['department'],
            'status' => $admin['status'],
            'lastLogin' => $admin['last_login_at'],
            'createdAt' => $admin['created_at'],
        ], $this->all('SELECT * FROM administrators ORDER BY created_at DESC'));
    }

    public function createAdministrator(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $body = $request->body;
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $role = strtoupper(trim((string) ($body['role'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, ['SUPER_ADMIN', 'BURSAR', 'REGISTRAR'], true)) {
            throw new HttpException('Name, valid email, role, and password of at least 8 characters are required.', 422);
        }
        $id = $this->newId();
        $this->run('INSERT INTO administrators (id, name, email, password_hash, role, department) VALUES (?, ?, ?, ?, ?, ?)', [$id, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role, trim((string) ($body['department'] ?? '')) ?: null]);
        $this->audit($actor, 'Created administrator', 'Administrator', $email);
        return ['id' => 'adm_' . $id, 'name' => $name, 'email' => $email, 'role' => $role, 'department' => $body['department'] ?? null, 'status' => 'ACTIVE', 'lastLogin' => null, 'createdAt' => gmdate('Y-m-d')];
    }

    public function updateAdministratorRole(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $id = $this->idFromPublicId($params['id'], 'adm');
        $role = strtoupper(trim((string) ($request->body['role'] ?? '')));
        if (!in_array($role, ['SUPER_ADMIN', 'BURSAR', 'REGISTRAR'], true)) {
            throw new HttpException('Invalid administrator role.', 422);
        }
        $changed = $this->run('UPDATE administrators SET role = ? WHERE id = ?', [$role, $id]);
        if (!$changed && !$this->one('SELECT id FROM administrators WHERE id = ?', [$id])) {
            throw new HttpException('Administrator not found.', 404);
        }
        $this->audit($actor, 'Changed administrator role', 'Administrator', (string) $id, ['role' => $role]);
        return ['status' => 'success', 'role' => $role];
    }

    public function toggleAdministrator(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $id = $this->idFromPublicId($params['id'], 'adm');
        $admin = $this->one('SELECT * FROM administrators WHERE id = ?', [$id]);
        if (!$admin) {
            throw new HttpException('Administrator not found.', 404);
        }
        $status = $admin['status'] === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $this->run('UPDATE administrators SET status = ? WHERE id = ?', [$status, $id]);
        $this->audit($actor, 'Changed administrator status', 'Administrator', (string) $id, ['status' => $status]);
        return ['id' => 'adm_' . $id, 'status' => $status];
    }

    public function sessions(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        return array_map(static fn (array $session): array => [
            'id' => 'sess_' . $session['id'],
            'name' => $session['name'],
            'status' => $session['status'],
            'startDate' => $session['starts_on'],
            'endDate' => $session['ends_on'],
        ], $this->all('SELECT * FROM academic_sessions ORDER BY starts_on DESC'));
    }

    public function createSession(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $body = $request->body;
        $name = trim((string) ($body['name'] ?? ''));
        $start = (string) ($body['startDate'] ?? $body['starts_on'] ?? '');
        $end = (string) ($body['endDate'] ?? $body['ends_on'] ?? '');
        if ($name === '' || !$this->validDate($start) || !$this->validDate($end) || $start > $end) {
            throw new HttpException('Provide a session name and valid start/end dates.', 422);
        }
        $status = strtoupper((string) ($body['status'] ?? 'CLOSED')) === 'ACTIVE' ? 'ACTIVE' : 'CLOSED';
        $this->db->beginTransaction();
        try {
            if ($status === 'ACTIVE') {
                $this->run("UPDATE academic_sessions SET status = 'CLOSED'");
            }
            $id = $this->newId();
            $this->run('INSERT INTO academic_sessions (id, name, starts_on, ends_on, status) VALUES (?, ?, ?, ?, ?)', [$id, $name, $start, $end, $status]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        $this->audit($actor, 'Created academic session', 'Academic Session', $name);
        return ['id' => 'sess_' . $id, 'name' => $name, 'status' => $status, 'startDate' => $start, 'endDate' => $end];
    }

    public function toggleSession(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $id = $this->idFromPublicId($params['id'], 'sess');
        $session = $this->one('SELECT * FROM academic_sessions WHERE id = ?', [$id]);
        if (!$session) {
            throw new HttpException('Academic session not found.', 404);
        }
        $status = $session['status'] === 'ACTIVE' ? 'CLOSED' : 'ACTIVE';
        $this->db->beginTransaction();
        try {
            if ($status === 'ACTIVE') {
                $this->run("UPDATE academic_sessions SET status = 'CLOSED'");
            }
            $this->run('UPDATE academic_sessions SET status = ? WHERE id = ?', [$status, $id]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        $this->audit($actor, 'Changed academic session status', 'Academic Session', $session['name'], ['status' => $status]);
        return ['id' => 'sess_' . $id, 'name' => $session['name'], 'status' => $status];
    }

    public function auditLogs(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        $conditions = [];
        $values = [];
        $search = trim((string) ($request->query['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(actor_name LIKE ? OR action LIKE ? OR entity_reference LIKE ?)';
            array_push($values, ...array_fill(0, 3, '%' . $search . '%'));
        }
        $role = trim((string) ($request->query['role'] ?? ''));
        if ($role !== '' && $role !== 'ALL') {
            $conditions[] = 'actor_role = ?';
            $values[] = $role;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return array_map(static fn (array $log): array => [
            'id' => 'aud_' . $log['id'],
            'date' => $log['created_at'],
            'user' => $log['actor_name'],
            'role' => $log['actor_role'],
            'action' => $log['action'],
            'entity' => $log['entity_type'],
            'reference' => $log['entity_reference'],
            'ipAddress' => $log['ip_address'],
        ], $this->all("SELECT * FROM audit_logs {$where} ORDER BY created_at DESC LIMIT 500", $values));
    }

    public function settings(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        $settings = [];
        foreach ($this->all('SELECT setting_key, setting_value FROM app_settings') as $row) {
            $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
        }
        $activeSession = $this->one("SELECT name FROM academic_sessions WHERE status = 'ACTIVE' ORDER BY id DESC LIMIT 1");
        $settings['activeSession'] ??= $activeSession['name'] ?? null;
        $settings['paystackPublicKey'] = getenv('PAYSTACK_PUBLIC_KEY') ?: '';
        $settings['currency'] ??= getenv('PAYSTACK_CURRENCY') ?: 'NGN';
        return $settings;
    }

    public function updateSettings(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $blocked = ['paystackSecretKey', 'PAYSTACK_SECRET_KEY', 'secretKey'];
        foreach ($blocked as $key) {
            if (array_key_exists($key, $request->body)) {
                throw new HttpException('Secret credentials cannot be stored as portal settings.', 422);
            }
        }
        $allowed = ['universityName', 'shortName', 'address', 'contactEmail', 'contactPhone', 'paymentInstructions', 'receiptPrefix', 'transactionPrefix', 'notifyStudentOnPayment', 'notifyBursarOnLargePayment'];
        foreach ($request->body as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $this->run(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
                [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $actor['id']]
            );
        }
        $this->audit($actor, 'Updated portal settings', 'Settings');
        return $this->settings($request, $params, $actor);
    }

    public function paymentTypes(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor);
        $rows = $this->all('SELECT p.*, s.name AS session_name, f.name AS faculty_name, d.name AS department_name, (SELECT COUNT(*) FROM invoices i WHERE i.payment_type_id = p.id) AS invoice_count FROM payment_types p JOIN academic_sessions s ON s.id = p.academic_session_id LEFT JOIN faculties f ON f.id = p.faculty_id LEFT JOIN departments d ON d.id = p.department_id ORDER BY s.starts_on DESC, p.name');
        $types = [];
        foreach ($rows as $type) {
            $schedules = $this->all(
                'SELECT fs.department_id, fs.applicable_level, fs.amount, d.name AS department_name, f.name AS faculty_name FROM payment_type_fee_schedules fs JOIN departments d ON d.id = fs.department_id JOIN faculties f ON f.id = d.faculty_id WHERE fs.payment_type_id = ? ORDER BY f.name, d.name, fs.applicable_level',
                [$type['id']]
            );
            $typeSchedules = array_map(static fn (array $schedule): array => [
                'departmentId' => (string) $schedule['department_id'],
                'faculty' => $schedule['faculty_name'],
                'department' => $schedule['department_name'],
                'level' => $schedule['applicable_level'],
                'amount' => (float) $schedule['amount'],
            ], $schedules);
            $amounts = array_column($typeSchedules, 'amount');
            $types[] = [
            'id' => 'pt_' . $type['id'],
            'name' => $type['name'],
            'code' => $type['code'],
            'amount' => $amounts ? min($amounts) : (float) $type['amount'],
            'amountRange' => $amounts ? ['min' => min($amounts), 'max' => max($amounts)] : null,
            'session' => $type['session_name'],
            'facultyId' => $type['faculty_id'] === null ? null : (string) $type['faculty_id'],
            'faculty' => $type['faculty_name'] ?? 'All Faculties',
            'departmentId' => $type['department_id'] === null ? null : (string) $type['department_id'],
            'department' => $type['department_name'] ?? 'All Departments',
            'invoiceCount' => (int) $type['invoice_count'],
            'applicableLevel' => $type['applicable_level'],
            'isMandatory' => (bool) $type['is_mandatory'],
            'allowPartialPayment' => (bool) $type['allow_partial_payment'],
            'status' => $type['status'],
            'description' => $type['description'],
            'schedules' => $typeSchedules,
            ];
        }
        return $types;
    }

    public function faculties(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        return $this->all('SELECT id, name FROM faculties ORDER BY name');
    }

    public function createPaymentType(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $body = $request->body;
        $name = trim((string) ($body['name'] ?? ''));
        $hasSchedules = array_key_exists('schedules', $body);
        $amount = $hasSchedules ? 0.0 : filter_var($body['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($name === '') {
            throw new HttpException('Payment type name is required.', 422);
        }
        if (!$hasSchedules && ($amount === false || $amount <= 0)) {
            throw new HttpException('Provide a positive fee amount or at least one fee schedule.', 422);
        }
        $session = $this->resolvePaymentTypeSession($body);
        if (!$session) {
            throw new HttpException('Select a valid academic session.', 422);
        }
        $code = strtoupper(trim((string) ($body['code'] ?? preg_replace('/[^A-Z0-9]+/', '_', strtoupper($name)))));
        $applicableLevel = trim((string) ($body['applicableLevel'] ?? 'All Levels'));
        $isMandatory = !empty($body['isMandatory']);
        $allowPartialPayment = !empty($body['allowPartialPayment']);
        $status = strtoupper((string) ($body['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';

        $this->db->beginTransaction();
        try {
            if ($hasSchedules) {
                $facultyId = null;
                $departmentId = null;
                $applicableLevel = 'ALL';
            } else {
                [$facultyId, $departmentId] = $this->resolvePaymentTypeScope($body);
            }
            $this->assertPaymentTypeScopeAvailable($session['id'], $code, $facultyId, $departmentId, $applicableLevel);
            $paymentTypeId = $this->newId();
            $this->run(
                'INSERT INTO payment_types (id, academic_session_id, faculty_id, department_id, faculty_scope_id, department_scope_id, name, code, amount, applicable_level, is_mandatory, allow_partial_payment, status, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$paymentTypeId, $session['id'], $facultyId, $departmentId, $facultyId ?? self::GLOBAL_SCOPE_ID, $departmentId ?? self::GLOBAL_SCOPE_ID, $name, $code, $amount, $applicableLevel, $isMandatory, $allowPartialPayment, $status, trim((string) ($body['description'] ?? '')) ?: null, $actor['id']]
            );

            if ($hasSchedules) {
                $this->replacePaymentTypeFeeSchedules($paymentTypeId, $body['schedules']);
            }

            if ($hasSchedules && $status === 'ACTIVE' && $isMandatory) {
                $this->issueScheduledInvoices($paymentTypeId, $session['id']);
            } elseif ($status === 'ACTIVE' && $isMandatory) {
                $appliesToAllLevels = in_array($applicableLevel, ['ALL', 'All Levels'], true);
                $this->run(
                    "INSERT IGNORE INTO invoices (id, student_id, payment_type_id, academic_session_id, amount, status) SELECT UUID(), s.id, ?, ?, ?, 'UNPAID' FROM students s JOIN programmes pr ON pr.id = s.programme_id JOIN departments d ON d.id = pr.department_id WHERE s.academic_session_id = ? AND (? IS NULL OR d.faculty_id = ?) AND (? IS NULL OR d.id = ?) AND (? = 1 OR s.level = ?)",
                    [$paymentTypeId, $session['id'], $amount, $session['id'], $facultyId, $facultyId, $departmentId, $departmentId, (int) $appliesToAllLevels, $applicableLevel]
                );
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        $this->audit($actor, 'Created payment type', 'Payment Type', $code);
        $faculty = $facultyId === null ? null : $this->one('SELECT name FROM faculties WHERE id = ?', [$facultyId]);
        $department = $departmentId === null ? null : $this->one('SELECT name FROM departments WHERE id = ?', [$departmentId]);
        return ['id' => 'pt_' . $paymentTypeId, 'name' => $name, 'code' => $code, 'amount' => (float) $amount, 'session' => $session['name'], 'facultyId' => $facultyId === null ? null : (string) $facultyId, 'faculty' => $faculty['name'] ?? 'All Faculties', 'departmentId' => $departmentId === null ? null : (string) $departmentId, 'department' => $department['name'] ?? 'All Departments', 'applicableLevel' => $applicableLevel, 'isMandatory' => $isMandatory, 'allowPartialPayment' => $allowPartialPayment, 'status' => $status, 'description' => $body['description'] ?? '', 'schedules' => $hasSchedules ? $this->paymentTypeSchedules($paymentTypeId) : []];
    }

    public function updatePaymentType(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $id = $this->idFromPublicId($params['id'], 'pt');
        $current = $this->one('SELECT p.*, s.name AS session_name FROM payment_types p JOIN academic_sessions s ON s.id = p.academic_session_id WHERE p.id = ?', [$id]);
        if (!$current) {
            throw new HttpException('Payment type not found.', 404);
        }
        $body = $request->body;
        $hasSchedules = array_key_exists('schedules', $body);
        $amount = $hasSchedules
            ? 0.0
            : (isset($body['amount']) ? filter_var($body['amount'], FILTER_VALIDATE_FLOAT) : (float) $current['amount']);
        if ($amount === false || $amount < 0) {
            throw new HttpException('Payment amount must be zero or greater.', 422);
        }
        $code = strtoupper(trim((string) ($body['code'] ?? $current['code'])));
        if ($code === '' || strlen($code) > 48) {
            throw new HttpException('Fee code must contain between 1 and 48 characters.', 422);
        }
        $session = $this->resolvePaymentTypeSession($body, $current['academic_session_id']);
        if (!$session) {
            throw new HttpException('Academic session not found.', 422);
        }
        $name = trim((string) ($body['name'] ?? $current['name']));
        $level = trim((string) ($body['applicableLevel'] ?? $current['applicable_level']));
        $mandatory = array_key_exists('isMandatory', $body) ? (int) (bool) $body['isMandatory'] : (int) $current['is_mandatory'];
        $allowPartialPayment = array_key_exists('allowPartialPayment', $body)
            ? (int) (bool) $body['allowPartialPayment']
            : (int) $current['allow_partial_payment'];
        $status = strtoupper((string) ($body['status'] ?? $current['status'])) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $description = $body['description'] ?? $current['description'];
        $this->db->beginTransaction();
        try {
            if ($hasSchedules) {
                $facultyId = null;
                $departmentId = null;
                $level = 'ALL';
            } else {
                [$facultyId, $departmentId] = $this->resolvePaymentTypeScope($body, $current);
            }
            $this->assertPaymentTypeScopeAvailable($session['id'], $code, $facultyId, $departmentId, $level, $id);
            $this->run('UPDATE payment_types SET academic_session_id = ?, faculty_id = ?, department_id = ?, faculty_scope_id = ?, department_scope_id = ?, name = ?, code = ?, amount = ?, applicable_level = ?, is_mandatory = ?, allow_partial_payment = ?, status = ?, description = ? WHERE id = ?', [$session['id'], $facultyId, $departmentId, $facultyId ?? self::GLOBAL_SCOPE_ID, $departmentId ?? self::GLOBAL_SCOPE_ID, $name, $code, $amount, $level, $mandatory, $allowPartialPayment, $status, $description, $id]);
            if ($hasSchedules) {
                $this->replacePaymentTypeFeeSchedules($id, $body['schedules']);
            }
            if ($hasSchedules && $status === 'ACTIVE' && $mandatory) {
                $this->issueScheduledInvoices($id, $session['id']);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        $this->audit($actor, 'Updated payment type', 'Payment Type', $code, ['previousCode' => $current['code']]);
        $faculty = $facultyId === null ? null : $this->one('SELECT name FROM faculties WHERE id = ?', [$facultyId]);
        $department = $departmentId === null ? null : $this->one('SELECT name FROM departments WHERE id = ?', [$departmentId]);
        return ['id' => 'pt_' . $id, 'name' => $name, 'code' => $code, 'amount' => (float) $amount, 'session' => $session['name'], 'facultyId' => $facultyId === null ? null : (string) $facultyId, 'faculty' => $faculty['name'] ?? 'All Faculties', 'departmentId' => $departmentId === null ? null : (string) $departmentId, 'department' => $department['name'] ?? 'All Departments', 'applicableLevel' => $level, 'isMandatory' => (bool) $mandatory, 'allowPartialPayment' => (bool) $allowPartialPayment, 'status' => $status, 'description' => $description, 'schedules' => $hasSchedules ? $this->paymentTypeSchedules($id) : []];
    }

    private function replacePaymentTypeFeeSchedules(string $paymentTypeId, mixed $scheduleInput): void
    {
        if (!is_array($scheduleInput) || $scheduleInput === []) {
            throw new HttpException('Add at least one department and level fee amount.', 422);
        }

        $validated = [];
        $seen = [];
        $validLevels = ['100', '200', '300', '400', '500', 'Postgraduate'];
        foreach ($scheduleInput as $schedule) {
            if (!is_array($schedule)) {
                throw new HttpException('Each fee schedule must include faculty, department, level, and amount.', 422);
            }
            $facultyName = trim((string) ($schedule['faculty'] ?? $schedule['facultyName'] ?? ''));
            $departmentName = trim((string) ($schedule['department'] ?? $schedule['departmentName'] ?? ''));
            $level = trim((string) ($schedule['level'] ?? ''));
            $amount = filter_var($schedule['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($facultyName === '' || $departmentName === '' || !in_array($level, $validLevels, true) || $amount === false || $amount <= 0) {
                throw new HttpException('Each fee schedule requires a valid faculty, department, level, and positive amount.', 422);
            }

            $facultyId = $this->findOrCreateFaculty($facultyName)['id'];
            $department = $this->findOrCreateDepartment($facultyId, $departmentName);
            $key = $department['id'] . ':' . $level;
            if (isset($seen[$key])) {
                throw new HttpException('A department and level can only have one amount in a fee schedule.', 422);
            }
            $seen[$key] = true;
            $validated[] = [$department['id'], $level, $amount];
        }

        $this->run('DELETE FROM payment_type_fee_schedules WHERE payment_type_id = ?', [$paymentTypeId]);
        foreach ($validated as [$departmentId, $level, $amount]) {
            $this->run(
                'INSERT INTO payment_type_fee_schedules (id, payment_type_id, department_id, applicable_level, amount) VALUES (?, ?, ?, ?, ?)',
                [$this->newId(), $paymentTypeId, $departmentId, $level, $amount]
            );
        }
    }

    private function resolvePaymentTypeSession(array $body, ?string $defaultSessionId = null): ?array
    {
        $sessionValue = $body['sessionId']
            ?? $body['academicSessionId']
            ?? $body['academic_session_id']
            ?? $body['session']
            ?? null;

        if ($sessionValue !== null && !is_string($sessionValue) && !is_int($sessionValue)) {
            return null;
        }
        if ($sessionValue === null || trim((string) $sessionValue) === '') {
            return $defaultSessionId !== null
                ? $this->one('SELECT id, name FROM academic_sessions WHERE id = ?', [$defaultSessionId])
                : $this->one("SELECT id, name FROM academic_sessions WHERE status = 'ACTIVE' ORDER BY id DESC LIMIT 1");
        }

        $sessionValue = trim((string) $sessionValue);
        $sessionId = $this->normalizeUuid($sessionValue);
        return $sessionId !== false
            ? $this->one('SELECT id, name FROM academic_sessions WHERE id = ?', [$sessionId])
            : $this->one('SELECT id, name FROM academic_sessions WHERE name = ?', [$sessionValue]);
    }

    private function paymentTypeSchedules(string $paymentTypeId): array
    {
        return array_map(static fn (array $schedule): array => [
            'departmentId' => (string) $schedule['department_id'],
            'faculty' => $schedule['faculty_name'],
            'department' => $schedule['department_name'],
            'level' => $schedule['applicable_level'],
            'amount' => (float) $schedule['amount'],
        ], $this->all(
            'SELECT fs.department_id, fs.applicable_level, fs.amount, d.name AS department_name, f.name AS faculty_name FROM payment_type_fee_schedules fs JOIN departments d ON d.id = fs.department_id JOIN faculties f ON f.id = d.faculty_id WHERE fs.payment_type_id = ? ORDER BY f.name, d.name, fs.applicable_level',
            [$paymentTypeId]
        ));
    }

    private function issueScheduledInvoices(string $paymentTypeId, string $sessionId): void
    {
        $this->run(
            "INSERT IGNORE INTO invoices (id, student_id, payment_type_id, academic_session_id, amount, status) SELECT UUID(), s.id, ?, s.academic_session_id, fs.amount, 'UNPAID' FROM students s JOIN programmes pr ON pr.id = s.programme_id JOIN departments d ON d.id = pr.department_id JOIN payment_type_fee_schedules fs ON fs.department_id = d.id AND fs.applicable_level = s.level WHERE s.academic_session_id = ? AND fs.payment_type_id = ?",
            [$paymentTypeId, $sessionId, $paymentTypeId]
        );
    }

    public function deletePaymentType(Request $request, array $params, ?array $actor): array
    {
        $actor = $this->requireActor($actor, 'ADMIN');
        $id = $this->idFromPublicId($params['id'], 'pt');
        $this->db->beginTransaction();
        try {
            $paymentType = $this->one('SELECT id, name, code FROM payment_types WHERE id = ? FOR UPDATE', [$id]);
            if (!$paymentType) {
                throw new HttpException('Payment type not found.', 404);
            }

            $invoiceCount = $this->one('SELECT COUNT(*) AS total FROM invoices WHERE payment_type_id = ?', [$id]);
            if ((int) $invoiceCount['total'] > 0) {
                throw new HttpException(
                    'This payment type has issued invoices and cannot be deleted. Set its status to inactive to stop issuing new invoices.',
                    409
                );
            }

            $this->run('DELETE FROM payment_types WHERE id = ?', [$id]);
            $this->audit($actor, 'Deleted payment type', 'Payment Type', $paymentType['code'], [
                'name' => $paymentType['name'],
            ]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return ['status' => 'success', 'message' => 'Payment type deleted.'];
    }

    private function resolvePaymentTypeScope(array $body, ?array $current = null): array
    {
        if (array_key_exists('facultyName', $body)) {
            $facultyName = trim((string) ($body['facultyName'] ?? ''));
            $facultyId = $facultyName === '' || strtoupper($facultyName) === 'ALL'
                ? null
                : $this->findOrCreateFaculty($facultyName)['id'];
        } elseif (array_key_exists('facultyId', $body)) {
            $rawFacultyId = $body['facultyId'];
            $facultyId = $rawFacultyId === null || $rawFacultyId === '' || $rawFacultyId === 'ALL'
                ? null
                : $this->normalizeUuid($rawFacultyId);
            if ($facultyId === false || ($facultyId !== null && !$this->one('SELECT id FROM faculties WHERE id = ?', [$facultyId]))) {
                throw new HttpException('Select a valid faculty or All Faculties.', 422);
            }
        } else {
            $facultyId = $current['faculty_id'] ?? null;
        }

        if (array_key_exists('departmentName', $body)) {
            $departmentName = trim((string) ($body['departmentName'] ?? ''));
            if ($departmentName === '' || strtoupper($departmentName) === 'ALL') {
                $departmentId = null;
            } else {
                if ($facultyId === null) {
                    throw new HttpException('Select a faculty before assigning a department-specific fee.', 422);
                }
                $departmentId = $this->findOrCreateDepartment($facultyId, $departmentName)['id'];
            }
        } elseif (array_key_exists('departmentId', $body)) {
            $rawDepartmentId = $body['departmentId'];
            $departmentId = $rawDepartmentId === null || $rawDepartmentId === '' || $rawDepartmentId === 'ALL'
                ? null
                : $this->normalizeUuid($rawDepartmentId);
            if ($departmentId === false) {
                throw new HttpException('Select a valid department or All Departments.', 422);
            }
        } else {
            $departmentId = $current['department_id'] ?? null;
        }

        if ($departmentId !== null) {
            $department = $this->one('SELECT id, faculty_id FROM departments WHERE id = ?', [$departmentId]);
            if (!$department || $facultyId === null || $department['faculty_id'] !== $facultyId) {
                throw new HttpException('The selected department must belong to the selected faculty.', 422);
            }
        }

        return [$facultyId, $departmentId];
    }

    private function assertPaymentTypeScopeAvailable(
        string $sessionId,
        string $code,
        ?string $facultyId,
        ?string $departmentId,
        string $level,
        ?string $excludeId = null
    ): void {
        $appliesToAllLevels = in_array($level, ['ALL', 'All Levels'], true);
        $overlap = $this->one(
            "SELECT id FROM payment_types WHERE academic_session_id = ? AND code = ? AND (? IS NULL OR id <> ?) AND (faculty_id IS NULL OR ? IS NULL OR faculty_id = ?) AND (department_id IS NULL OR ? IS NULL OR department_id = ?) AND (applicable_level IN ('ALL', 'All Levels') OR ? = 1 OR applicable_level = ?) LIMIT 1",
            [$sessionId, $code, $excludeId, $excludeId, $facultyId, $facultyId, $departmentId, $departmentId, (int) $appliesToAllLevels, $level]
        );
        if ($overlap) {
            throw new HttpException(
                'This fee code already has a configuration that overlaps the selected faculty, department, and level.',
                409
            );
        }
    }

    private function findOrCreateFaculty(string $name): array
    {
        $faculty = $this->one('SELECT id, name FROM faculties WHERE name = ?', [$name]);
        if ($faculty) {
            return $faculty;
        }

        $code = $this->referenceCode('FAC', $name);
        $this->run(
            'INSERT INTO faculties (id, name, code) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$this->newId(), $name, $code]
        );

        return $this->one('SELECT id, name FROM faculties WHERE name = ?', [$name])
            ?? throw new \RuntimeException('Unable to resolve the selected faculty.');
    }

    private function findOrCreateDepartment(string $facultyId, string $name): array
    {
        $department = $this->one(
            'SELECT id, name, faculty_id FROM departments WHERE faculty_id = ? AND name = ?',
            [$facultyId, $name]
        );
        if ($department) {
            return $department;
        }

        $code = $this->referenceCode('DEP', $name, (string) $facultyId);
        $this->run(
            'INSERT INTO departments (id, faculty_id, name, code) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$this->newId(), $facultyId, $name, $code]
        );

        return $this->one(
            'SELECT id, name, faculty_id FROM departments WHERE faculty_id = ? AND name = ?',
            [$facultyId, $name]
        ) ?? throw new \RuntimeException('Unable to resolve the selected department.');
    }

    private function referenceCode(string $prefix, string $name, string $scope = ''): string
    {
        $slug = trim(preg_replace('/[^A-Z0-9]+/', '-', strtoupper($name)), '-');
        $suffix = strtoupper(substr(hash('sha256', $scope . "\0" . strtolower($name)), 0, 8));
        $slugLength = 32 - strlen($prefix) - strlen($suffix) - 1;

        return $prefix . substr($slug !== '' ? $slug : 'ITEM', 0, $slugLength) . '-' . $suffix;
    }

    private function normalizeUuid(mixed $value): string|false
    {
        $value = (string) $value;
        if (preg_match('/^(?:fac|dep|prg|sess)_([0-9a-f-]{36})$/i', $value, $matches)) {
            $value = $matches[1];
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)
            ? strtolower($value)
            : false;
    }

    public function verifyPayment(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        $query = trim((string) ($request->body['query'] ?? ''));
        $paymentTypeId = $this->idFromPublicId($request->body['paymentTypeId'] ?? null, 'pt');
        if ($query === '') {
            throw new HttpException('Select a fee and enter a registration number, transaction reference, or receipt number.', 422);
        }
        if (!$this->one('SELECT id FROM payment_types WHERE id = ?', [$paymentTypeId])) {
            throw new HttpException('Selected payment type was not found.', 404);
        }
        $row = $this->one(
            'SELECT t.id, t.transaction_reference, t.paystack_reference, t.amount, t.status, t.channel, t.gateway, t.created_at, s.id AS student_id, s.registration_number, s.first_name, s.other_names, s.surname, p.name AS payment_type_name, a.name AS session_name, r.receipt_number FROM transactions t JOIN students s ON s.id = t.student_id JOIN invoices i ON i.id = t.invoice_id JOIN payment_types p ON p.id = i.payment_type_id JOIN academic_sessions a ON a.id = i.academic_session_id LEFT JOIN receipts r ON r.transaction_id = t.id WHERE p.id = ? AND (LOWER(t.transaction_reference) = LOWER(?) OR LOWER(t.paystack_reference) = LOWER(?) OR LOWER(r.receipt_number) = LOWER(?) OR LOWER(s.registration_number) = LOWER(?)) ORDER BY t.created_at DESC LIMIT 1',
            [$paymentTypeId, $query, $query, $query, $query]
        );
        if (!$row) {
            throw new HttpException('No payment record matches that query.', 404);
        }
        return [
            'id' => 'txn_' . $row['id'],
            'reference' => $row['transaction_reference'],
            'paystackRef' => $row['paystack_reference'],
            'gateway' => $row['gateway'] === 'TOKEN' ? 'Payment Token' : 'Paystack',
            'paymentSource' => $row['gateway'],
            'receiptNumber' => $row['receipt_number'],
            'studentId' => 'std_' . $row['student_id'],
            'studentName' => trim(implode(' ', array_filter([$row['first_name'], $row['other_names'], $row['surname']]))),
            'regNumber' => $row['registration_number'],
            'paymentTypeName' => $row['payment_type_name'],
            'amount' => (float) $row['amount'],
            'session' => $row['session_name'],
            'status' => $row['status'],
            'channel' => $row['channel'],
            'date' => $row['created_at'],
        ];
    }

    public function reconciliation(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        $summary = $this->one("SELECT SUM(status = 'PAID') AS matched_count, SUM(status IN ('FAILED', 'REVERSED')) AS unmatched_count, SUM(status = 'PENDING') AS pending_count FROM transactions WHERE gateway = 'PAYSTACK'");
        $rows = $this->all("SELECT transaction_reference, paystack_reference, amount, status, created_at FROM transactions WHERE gateway = 'PAYSTACK' ORDER BY created_at DESC LIMIT 500");
        $records = array_map(static fn (array $row): array => [
            'reference' => $row['transaction_reference'],
            'paystackRef' => $row['paystack_reference'],
            'amount' => (float) $row['amount'],
            'localStatus' => $row['status'],
            'gatewayStatus' => match ($row['status']) { 'PAID' => 'SUCCESS', 'FAILED' => 'FAILED', default => 'PENDING' },
            'matchStatus' => match ($row['status']) { 'PAID' => 'MATCHED', 'FAILED', 'REVERSED' => 'UNMATCHED', default => 'PENDING' },
            'date' => $row['created_at'],
        ], $rows);
        return [
            'matchedCount' => (int) $summary['matched_count'],
            'unmatchedCount' => (int) $summary['unmatched_count'],
            'pendingCount' => (int) $summary['pending_count'],
            'discrepancyTotal' => 0,
            'lastReconciledAt' => null,
            'records' => $records,
        ];
    }

    public function reportSummary(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor, 'ADMIN');
        $conditions = ["t.status = 'PAID'"];
        $values = [];
        $session = trim((string) ($request->query['session'] ?? ''));
        if ($session !== '' && $session !== 'ALL') {
            $conditions[] = 'a.name = ?';
            $values[] = $session;
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            $date = trim((string) ($request->query[$key] ?? ''));
            if ($date !== '') {
                if (!$this->validDate($date)) {
                    throw new HttpException("Invalid {$key} date filter.", 422);
                }
                $conditions[] = "t.created_at {$operator} ?";
                $values[] = $key === 'to' ? (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') : $date;
            }
        }
        $where = implode(' AND ', $conditions);
        $days = $this->all("SELECT DATE_FORMAT(t.created_at, '%a') AS day, DATE(t.created_at) AS payment_date, SUM(t.amount) AS amount, COUNT(*) AS count FROM transactions t JOIN invoices i ON i.id = t.invoice_id JOIN academic_sessions a ON a.id = i.academic_session_id WHERE {$where} GROUP BY DATE(t.created_at) ORDER BY DATE(t.created_at)", $values);
        $byType = $this->all("SELECT p.name, SUM(t.amount) AS amount FROM transactions t JOIN invoices i ON i.id = t.invoice_id JOIN academic_sessions a ON a.id = i.academic_session_id JOIN payment_types p ON p.id = i.payment_type_id WHERE {$where} GROUP BY p.id, p.name ORDER BY amount DESC", $values);
        $total = array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $byType));
        $departments = $this->all("SELECT d.name AS department, SUM(t.amount) AS total, COUNT(DISTINCT t.student_id) AS students_count FROM transactions t JOIN invoices i ON i.id = t.invoice_id JOIN academic_sessions a ON a.id = i.academic_session_id JOIN students s ON s.id = t.student_id JOIN programmes p ON p.id = s.programme_id JOIN departments d ON d.id = p.department_id WHERE {$where} GROUP BY d.id, d.name ORDER BY total DESC LIMIT 10", $values);
        $invoiceConditions = ["i.status IN ('UNPAID', 'PARTIAL', 'OVERDUE')"];
        $invoiceValues = [];
        if ($session !== '' && $session !== 'ALL') {
            $invoiceConditions[] = 'a.name = ?';
            $invoiceValues[] = $session;
        }
        $outstanding = $this->one('SELECT COALESCE(SUM(i.amount - i.amount_paid), 0) AS total, COUNT(DISTINCT i.student_id) AS students FROM invoices i JOIN academic_sessions a ON a.id = i.academic_session_id WHERE ' . implode(' AND ', $invoiceConditions), $invoiceValues);
        return [
            'dailyCollection' => array_map(static fn (array $row): array => ['day' => $row['day'], 'amount' => (float) $row['amount'], 'count' => (int) $row['count'], 'date' => $row['payment_date']], $days),
            'paymentTypeBreakdown' => array_map(static fn (array $row): array => ['name' => $row['name'], 'amount' => (float) $row['amount'], 'percentage' => $total > 0 ? (int) round(((float) $row['amount'] / $total) * 100) : 0], $byType),
            'departmentBreakdown' => array_map(static fn (array $row): array => ['department' => $row['department'], 'total' => (float) $row['total'], 'studentsCount' => (int) $row['students_count']], $departments),
            'outstandingTotal' => (float) $outstanding['total'],
            'unpaidStudentsCount' => (int) $outstanding['students'],
        ];
    }

    private function findStudent(string $identifier): ?array
    {
        $sql = 'SELECT s.*, p.name AS programme_name, p.study_mode, d.name AS department_name, f.name AS faculty_name, a.name AS session_name FROM students s JOIN programmes p ON p.id = s.programme_id JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id JOIN academic_sessions a ON a.id = s.academic_session_id WHERE ';
        $id = preg_match('/^std_(.+)$/', $identifier, $matches) ? $matches[1] : $identifier;
        return preg_match('/^[0-9a-f-]{36}$/i', $id)
            ? $this->one($sql . 's.id = ?', [$id])
            : $this->one($sql . 's.registration_number = ?', [$identifier]);
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
            'accountStatus' => $student['account_status'],
            'createdAt' => $student['created_at'],
        ];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}