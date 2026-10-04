<?php

declare(strict_types=1);

namespace Obong\Payment\Controllers;

use Obong\Payment\Core\Controller;
use Obong\Payment\Core\HttpException;
use Obong\Payment\Core\Request;

final class AuthController extends Controller
{
    public function studentLogin(Request $request): array
    {
        $identifier = '';
        foreach (['identifier', 'email', 'regNumber', 'reg_number'] as $field) {
            $value = $request->body[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $identifier = trim($value);
                break;
            }
        }
        $password = (string) ($request->body['password'] ?? '');
        if ($identifier === '' || $password === '') {
            throw new HttpException('Email or registration number and password are required.', 422);
        }

        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $student = $this->one(
            'SELECT s.*, a.name AS session_name, p.name AS programme_name, p.study_mode, d.name AS department_name, f.name AS faculty_name FROM students s JOIN academic_sessions a ON a.id = s.academic_session_id JOIN programmes p ON p.id = s.programme_id JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id WHERE LOWER(s.' . ($isEmail ? 'email' : 'registration_number') . ') = LOWER(?)',
            [$identifier]
        );
        if (!$student || !password_verify($password, $student['password_hash'])) {
            throw new HttpException('Invalid email, registration number, or password.', 401);
        }
        if ($student['account_status'] !== 'ACTIVE') {
            throw new HttpException('This student account is inactive.', 403);
        }

        return $this->loginResponse('STUDENT', $student, $this->studentPayload($student));
    }

    public function adminLogin(Request $request): array
    {
        $email = strtolower(trim((string) ($request->body['email'] ?? '')));
        $password = (string) ($request->body['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new HttpException('A valid email and password are required.', 422);
        }

        $admin = $this->one('SELECT * FROM administrators WHERE LOWER(email) = ?', [$email]);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            throw new HttpException('Invalid email or password.', 401);
        }
        if ($admin['status'] !== 'ACTIVE') {
            throw new HttpException('This administrator account is inactive.', 403);
        }

        $this->run('UPDATE administrators SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$admin['id']]);
        $admin['last_login_at'] = gmdate('Y-m-d H:i:s');
        $user = [
            'id' => 'adm_' . $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
            'role' => $admin['role'],
            'department' => $admin['department'],
            'status' => $admin['status'],
            'lastLogin' => $admin['last_login_at'],
        ];

        return $this->loginResponse('ADMIN', $admin, $user);
    }

    public function studentRegister(Request $request): array
    {
        $body = $request->body;
        $required = ['firstName', 'surname', 'email', 'password', 'level'];
        foreach ($required as $field) {
            if (trim((string) ($body[$field] ?? '')) === '') {
                throw new HttpException("{$field} is required.", 422);
            }
        }
        $regNumber = trim((string) ($body['regNumber'] ?? $body['reg_number'] ?? ''));
        if ($regNumber === '') {
            $regNumber = 'PENDING-' . bin2hex(random_bytes(16));
        }
        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Enter a valid email address.', 422);
        }
        if (strlen((string) $body['password']) < 6) {
            throw new HttpException('Password must contain at least 6 characters.', 422);
        }

        $programmeId = $body['programmeId'] ?? $body['programme_id'] ?? null;
        if (!$programmeId && isset($body['programme']) && is_numeric($body['programme'])) {
            $programmeId = $body['programme'];
        }
        if (is_string($programmeId) && preg_match('/^prg_([0-9a-f-]{36})$/i', $programmeId, $matches)) {
            $programmeId = $matches[1];
        }
        $studyMode = null;
        if (!$programmeId) {
            $facultyName = trim((string) ($body['faculty'] ?? ''));
            $departmentName = trim((string) ($body['department'] ?? ''));
            $studyMode = strtoupper(str_replace(
                [' ', '-'],
                '_',
                trim((string) ($body['studyMode'] ?? $body['study_mode'] ?? $body['programme'] ?? ''))
            ));
            $studyMode = match ($studyMode) {
                'FULL_TIME', 'FULLTIME' => 'FULL_TIME',
                'PART_TIME', 'PARTTIME' => 'PART_TIME',
                default => null,
            };
            if ($facultyName === '' || $departmentName === '' || !$studyMode) {
                throw new HttpException('Select a valid faculty, department, and study mode.', 422);
            }
        }

        $session = $this->one("SELECT * FROM academic_sessions WHERE status = 'ACTIVE' ORDER BY id DESC LIMIT 1");
        if (!$session) {
            throw new HttpException('Student registration is unavailable because there is no active academic session. Ask an administrator to activate an academic session.', 422);
        }

        try {
            $this->db->beginTransaction();
            if (!$programmeId) {
                $faculty = $this->findOrCreateFaculty($facultyName);
                $department = $this->findOrCreateDepartment($faculty['id'], $departmentName);
                $programme = $this->findOrCreateProgramme($department, $studyMode);
                $programmeId = $programme['id'];
            }

            $programme = $this->one(
                'SELECT p.id, d.id AS department_id, d.faculty_id FROM programmes p JOIN departments d ON d.id = p.department_id WHERE p.id = ?',
                [$programmeId]
            );
            if (!$programme) {
                throw new HttpException('The selected programme was not found. Please select a valid programme and try again.', 422);
            }

            $studentId = $this->newId();
            $this->run(
                'INSERT INTO students (id, academic_session_id, programme_id, registration_number, surname, first_name, other_names, email, phone, level, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $studentId,
                    $session['id'],
                    $programme['id'],
                    $regNumber,
                    trim($body['surname']),
                    trim($body['firstName']),
                    trim((string) ($body['otherNames'] ?? '')) ?: null,
                    strtolower(trim($body['email'])),
                    trim((string) ($body['phone'] ?? '')) ?: null,
                    trim($body['level']),
                    password_hash($body['password'], PASSWORD_DEFAULT),
                ]
            );
            $types = $this->all(
                "SELECT p.id, COALESCE(fs.amount, p.amount) AS amount FROM payment_types p LEFT JOIN payment_type_fee_schedules fs ON fs.payment_type_id = p.id AND fs.department_id = ? AND fs.applicable_level = ? WHERE p.academic_session_id = ? AND p.status = 'ACTIVE' AND p.is_mandatory = 1 AND (fs.id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM payment_type_fee_schedules any_fs WHERE any_fs.payment_type_id = p.id)) AND (p.faculty_id IS NULL OR p.faculty_id = ?) AND (p.department_id IS NULL OR p.department_id = ?) AND (p.applicable_level IN ('ALL', 'All Levels') OR p.applicable_level = ?)",
                [$programme['department_id'], trim($body['level']), $session['id'], $programme['faculty_id'], $programme['department_id'], trim($body['level'])]
            );
            foreach ($types as $type) {
                $this->run(
                    "INSERT INTO invoices (id, student_id, payment_type_id, academic_session_id, amount, status) VALUES (?, ?, ?, ?, ?, 'UNPAID')",
                    [$this->newId(), $studentId, $type['id'], $session['id'], $type['amount']]
                );
            }
            $this->db->commit();
        } catch (\PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new HttpException('Registration number or email is already in use.', 409);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $student = $this->one(
            'SELECT s.*, a.name AS session_name, p.name AS programme_name, p.study_mode, d.name AS department_name, f.name AS faculty_name FROM students s JOIN academic_sessions a ON a.id = s.academic_session_id JOIN programmes p ON p.id = s.programme_id JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id WHERE s.id = ?',
            [$studentId]
        );
        $this->audit(['type' => 'SYSTEM', 'name' => 'System'], 'Registered student account', 'Student', $student['registration_number']);
        $response = $this->loginResponse('STUDENT', $student, $this->studentPayload($student));
        $response['message'] = 'Student account successfully created. You can now proceed to pay school fees.';
        return $response;
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
            ?? throw new \RuntimeException('Unable to resolve the submitted faculty.');
    }

    private function findOrCreateDepartment(string $facultyId, string $name): array
    {
        $department = $this->one(
            'SELECT id, name, code FROM departments WHERE faculty_id = ? AND name = ?',
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
            'SELECT id, name, code FROM departments WHERE faculty_id = ? AND name = ?',
            [$facultyId, $name]
        ) ?? throw new \RuntimeException('Unable to resolve the submitted department.');
    }

    private function findOrCreateProgramme(array $department, string $studyMode): array
    {
        $programme = $this->one(
            'SELECT id FROM programmes WHERE department_id = ? AND study_mode = ?',
            [$department['id'], $studyMode]
        );
        if ($programme) {
            return $programme;
        }

        $modeLabel = $studyMode === 'FULL_TIME' ? 'Full Time' : 'Part Time';
        $programmeName = $department['name'] . ' - ' . $modeLabel;
        $programmeCode = strtoupper(substr(preg_replace('/[^A-Z0-9]+/', '-', $department['code']), 0, 20))
            . '-' . ($studyMode === 'FULL_TIME' ? 'FT' : 'PT');
        $this->run(
            'INSERT INTO programmes (id, department_id, name, code, study_mode) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$this->newId(), $department['id'], $programmeName, $programmeCode, $studyMode]
        );

        return $this->one(
            'SELECT id FROM programmes WHERE department_id = ? AND study_mode = ?',
            [$department['id'], $studyMode]
        ) ?? throw new \RuntimeException('Unable to resolve the selected study programme.');
    }

    private function referenceCode(string $prefix, string $name, string $scope = ''): string
    {
        $slug = trim(preg_replace('/[^A-Z0-9]+/', '-', strtoupper($name)), '-');
        $suffix = strtoupper(substr(hash('sha256', $scope . "\0" . strtolower($name)), 0, 8));
        $slugLength = 32 - strlen($prefix) - strlen($suffix) - 1;

        return $prefix . substr($slug !== '' ? $slug : 'ITEM', 0, $slugLength) . '-' . $suffix;
    }

    public function forgotPassword(Request $request): array
    {
        $identifier = trim((string) ($request->body['identifier'] ?? ''));
        if ($identifier === '') {
            throw new HttpException('Email address or registration number is required.', 422);
        }

        $student = $this->one('SELECT id FROM students WHERE email = ? OR registration_number = ?', [$identifier, $identifier]);
        $admin = $student ? null : $this->one('SELECT id FROM administrators WHERE email = ?', [strtolower($identifier)]);
        $record = $student ?: $admin;
        if ($record) {
            $type = $student ? 'STUDENT' : 'ADMIN';
            $token = bin2hex(random_bytes(32));
            $this->run(
                'INSERT INTO password_reset_tokens (id, identity_type, identity_id, token_hash, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))',
                [$this->newId(), $type, $record['id'], hash('sha256', $token)]
            );
            error_log("Password reset delivery is not configured for {$type} id {$record['id']}.");
        }

        return [
            'status' => 'success',
            'message' => 'If the account exists, password reset instructions will be sent to its registered email address.',
        ];
    }

    public function resetPassword(Request $request): array
    {
        $token = trim((string) ($request->body['token'] ?? ''));
        $password = (string) ($request->body['password'] ?? '');
        if ($token === '' || strlen($password) < 8) {
            throw new HttpException('A valid reset token and password of at least 8 characters are required.', 422);
        }

        $reset = $this->one(
            'SELECT * FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
        if (!$reset) {
            throw new HttpException('Password reset token is invalid or expired.', 400);
        }

        $table = $reset['identity_type'] === 'STUDENT' ? 'students' : 'administrators';
        $this->db->beginTransaction();
        try {
            $this->run("UPDATE {$table} SET password_hash = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $reset['identity_id']]);
            $this->run('UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$reset['id']]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return ['status' => 'success', 'message' => 'Your password has been reset successfully.'];
    }

    public function logout(Request $request, array $params, ?array $actor): array
    {
        $this->requireActor($actor);
        $token = $request->bearerToken();
        if ($token) {
            $this->run('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        }
        return ['status' => 'success', 'message' => 'Signed out successfully.'];
    }

    private function loginResponse(string $type, array $record, array $user): array
    {
        $token = bin2hex(random_bytes(32));
        $this->run(
            'INSERT INTO api_tokens (id, actor_type, actor_id, token_hash, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 12 HOUR))',
            [$this->newId(), $type, $record['id'], hash('sha256', $token)]
        );

        return [
            'status' => 'success',
            'token' => $token,
            'role' => $type === 'STUDENT' ? 'STUDENT' : $record['role'],
            'user' => $user,
        ];
    }

    private function studentPayload(array $student): array
    {
        $fullName = trim(implode(' ', array_filter([
            $student['first_name'],
            $student['other_names'],
            $student['surname'],
        ])));

        return [
            'id' => 'std_' . $student['id'],
            'regNumber' => $student['registration_number'],
            'surname' => $student['surname'],
            'firstName' => $student['first_name'],
            'otherNames' => $student['other_names'] ?? '',
            'fullName' => $fullName,
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