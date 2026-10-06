<?php

declare(strict_types=1);

namespace Obong\Payment\Controllers;

use Obong\Payment\Core\Controller;
use Obong\Payment\Core\HttpException;
use Obong\Payment\Core\Request;
use Obong\Payment\Core\Response;
use Obong\Payment\Core\SmtpMailer;

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
            throw new HttpException(
                'Email or registration number and password are required.',
                422
            );
        }

        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;

        $student = $this->one(
            'SELECT s.*, a.name AS session_name, p.name AS programme_name, p.study_mode, d.name AS department_name, f.name AS faculty_name FROM students s JOIN academic_sessions a ON a.id = s.academic_session_id JOIN programmes p ON p.id = s.programme_id JOIN departments d ON d.id = p.department_id JOIN faculties f ON f.id = d.faculty_id WHERE LOWER(s.' . ($isEmail ? 'email' : 'registration_number') . ') = LOWER(?)',
            [$identifier]
        );

        if (!$student || !password_verify($password, $student['password_hash'])) {
            throw new HttpException(
                'Invalid email, registration number, or password.',
                401
            );
        }

        if ($student['email_verified_at'] === null) {
            throw new HttpException(
                'Verify your email address before signing in. Request a new verification email if needed.',
                403
            );
        }

        if ($student['account_status'] !== 'ACTIVE') {
            throw new HttpException(
                'This student account is inactive.',
                403
            );
        }

        return $this->loginResponse(
            'STUDENT',
            $student,
            $this->studentPayload($student)
        );
    }

    public function adminLogin(Request $request): array
    {
        $email = strtolower(trim((string) ($request->body['email'] ?? '')));
        $password = (string) ($request->body['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new HttpException(
                'A valid email and password are required.',
                422
            );
        }

        $admin = $this->one(
            'SELECT * FROM administrators WHERE LOWER(email) = ?',
            [$email]
        );

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            throw new HttpException(
                'Invalid email or password.',
                401
            );
        }

        if ($admin['status'] !== 'ACTIVE') {
            throw new HttpException(
                'This administrator account is inactive.',
                403
            );
        }

        $this->run(
            'UPDATE administrators SET last_login_at = UTC_TIMESTAMP() WHERE id = ?',
            [$admin['id']]
        );

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

        $required = [
            'firstName',
            'surname',
            'email',
            'password',
            'level'
        ];

        foreach ($required as $field) {
            if (trim((string) ($body[$field] ?? '')) === '') {
                throw new HttpException(
                    "{$field} is required.",
                    422
                );
            }
        }

        $regNumber = trim(
            (string) ($body['regNumber'] ?? $body['reg_number'] ?? '')
        );

        if ($regNumber === '') {
            $regNumber = 'PENDING-' . bin2hex(random_bytes(16));
        }

        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(
                'Enter a valid email address.',
                422
            );
        }

        if (strlen((string) $body['password']) < 6) {
            throw new HttpException(
                'Password must contain at least 6 characters.',
                422
            );
        }

        $programmeId = $body['programmeId'] ?? $body['programme_id'] ?? null;

        if (
            !$programmeId &&
            isset($body['programme']) &&
            is_numeric($body['programme'])
        ) {
            $programmeId = $body['programme'];
        }

        if (
            is_string($programmeId) &&
            preg_match(
                '/^prg_([0-9a-f-]{36})$/i',
                $programmeId,
                $matches
            )
        ) {
            $programmeId = $matches[1];
        }

        $studyMode = null;

        if (!$programmeId) {
            $facultyName = trim(
                (string) ($body['faculty'] ?? '')
            );

            $departmentName = trim(
                (string) ($body['department'] ?? '')
            );

            $studyMode = strtoupper(
                str_replace(
                    [' ', '-'],
                    '_',
                    trim(
                        (string) (
                            $body['studyMode']
                            ?? $body['study_mode']
                            ?? $body['programme']
                            ?? ''
                        )
                    )
                )
            );

            $studyMode = match ($studyMode) {
                'FULL_TIME',
                'FULLTIME' => 'FULL_TIME',

                'PART_TIME',
                'PARTTIME' => 'PART_TIME',

                default => null,
            };

            if (
                $facultyName === '' ||
                $departmentName === '' ||
                !$studyMode
            ) {
                throw new HttpException(
                    'Select a valid faculty, department, and study mode.',
                    422
                );
            }
        }

        $session = $this->one(
            "SELECT * FROM academic_sessions WHERE status = 'ACTIVE' ORDER BY id DESC LIMIT 1"
        );

        if (!$session) {
            throw new HttpException(
                'Student registration is unavailable because there is no active academic session. Ask an administrator to activate an academic session.',
                422
            );
        }

        try {
            $this->db->beginTransaction();

            if (!$programmeId) {
                $faculty = $this->findOrCreateFaculty($facultyName);

                $department = $this->findOrCreateDepartment(
                    $faculty['id'],
                    $departmentName
                );

                $programme = $this->findOrCreateProgramme(
                    $department,
                    $studyMode
                );

                $programmeId = $programme['id'];
            }

            $programme = $this->one(
                'SELECT p.id, d.id AS department_id, d.faculty_id FROM programmes p JOIN departments d ON d.id = p.department_id WHERE p.id = ?',
                [$programmeId]
            );

            if (!$programme) {
                throw new HttpException(
                    'The selected programme was not found. Please select a valid programme and try again.',
                    422
                );
            }

            $studentId = $this->newId();

            $this->run(
                'INSERT INTO students (id, academic_session_id, programme_id, registration_number, surname, first_name, other_names, email, phone, level, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)',
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
                    password_hash(
                        $body['password'],
                        PASSWORD_DEFAULT
                    ),
                ]
            );

            $types = $this->all(
                "SELECT p.id, COALESCE(fs.amount, p.amount) AS amount
                 FROM payment_types p
                 LEFT JOIN payment_type_fee_schedules fs
                    ON fs.payment_type_id = p.id
                    AND fs.department_id = ?
                    AND fs.applicable_level = ?
                 WHERE p.academic_session_id = ?
                   AND p.status = 'ACTIVE'
                   AND p.is_mandatory = 1
                   AND (
                        fs.id IS NOT NULL
                        OR NOT EXISTS (
                            SELECT 1
                            FROM payment_type_fee_schedules any_fs
                            WHERE any_fs.payment_type_id = p.id
                        )
                   )
                   AND (p.faculty_id IS NULL OR p.faculty_id = ?)
                   AND (p.department_id IS NULL OR p.department_id = ?)
                   AND (
                        p.applicable_level IN ('ALL', 'All Levels')
                        OR p.applicable_level = ?
                   )",
                [
                    $programme['department_id'],
                    trim($body['level']),
                    $session['id'],
                    $programme['faculty_id'],
                    $programme['department_id'],
                    trim($body['level'])
                ]
            );

            foreach ($types as $type) {
                $this->run(
                    "INSERT INTO invoices
                        (id, student_id, payment_type_id, academic_session_id, amount, status)
                     VALUES (?, ?, ?, ?, ?, 'UNPAID')",
                    [
                        $this->newId(),
                        $studentId,
                        $type['id'],
                        $session['id'],
                        $type['amount']
                    ]
                );
            }

            $this->db->commit();

        } catch (\PDOException $exception) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ((string) $exception->getCode() === '23000') {
                throw new HttpException(
                    'Registration number or email is already in use.',
                    409
                );
            }

            throw $exception;

        } catch (\Throwable $exception) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        $student = $this->one(
            'SELECT s.*, a.name AS session_name, p.name AS programme_name, p.study_mode, d.name AS department_name, f.name AS faculty_name
             FROM students s
             JOIN academic_sessions a ON a.id = s.academic_session_id
             JOIN programmes p ON p.id = s.programme_id
             JOIN departments d ON d.id = p.department_id
             JOIN faculties f ON f.id = d.faculty_id
             WHERE s.id = ?',
            [$studentId]
        );

        $this->audit(
            ['type' => 'SYSTEM', 'name' => 'System'],
            'Registered student account',
            'Student',
            $student['registration_number']
        );

        $this->sendVerificationEmail($student);

        return [
            'status' => 'success',
            'message' => 'Your account was created. Check your email for a verification link before signing in.',
            'email' => $student['email'],
        ];
    }

    public function verifyStudentEmail(Request $request): array
    {
        $token = trim(
            (string) ($request->query['token'] ?? '')
        );

        /*
         * Browser/email-link request:
         * Redirect the user to the React verification page.
         *
         * API requests are still processed normally below.
         */
        if (
            str_contains(
                $request->headers['accept'] ?? '',
                'text/html'
            )
        ) {
            $frontendUrl = rtrim(
                getenv('FRONTEND_URL')
                    ?: 'https://payments.obonguniversity.com',
                '/'
            );

            Response::redirect(
                $frontendUrl
                . '/verify-email?token='
                . rawurlencode($token)
            );
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new HttpException(
                'Email verification link is invalid or expired.',
                400
            );
        }

        $this->db->beginTransaction();

        try {
            $verification = $this->one(
                'SELECT *
                 FROM email_verification_tokens
                 WHERE token_hash = ?
                   AND used_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()
                 FOR UPDATE',
                [hash('sha256', $token)]
            );

            if (!$verification) {
                throw new HttpException(
                    'Email verification link is invalid or expired.',
                    400
                );
            }

            $this->run(
                'UPDATE students
                 SET email_verified_at = COALESCE(
                     email_verified_at,
                     UTC_TIMESTAMP()
                 )
                 WHERE id = ?',
                [$verification['student_id']]
            );

            $this->run(
                'UPDATE email_verification_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE student_id = ?
                   AND used_at IS NULL',
                [$verification['student_id']]
            );

            $this->db->commit();

        } catch (\Throwable $exception) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        return [
            'status' => 'success',
            'message' => 'Your email address has been verified. You can now sign in.'
        ];
    }

    public function resendStudentEmailVerification(
        Request $request
    ): array {
        $email = strtolower(
            trim((string) ($request->body['email'] ?? ''))
        );

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(
                'Enter a valid email address.',
                422
            );
        }

        $student = $this->one(
            'SELECT id, email, first_name, surname, email_verified_at
             FROM students
             WHERE LOWER(email) = LOWER(?)',
            [$email]
        );

        if ($student) {
            $recent = $this->one(
                'SELECT id
                 FROM email_verification_tokens
                 WHERE student_id = ?
                   AND sent_at > DATE_SUB(
                       UTC_TIMESTAMP(),
                       INTERVAL 1 MINUTE
                   )
                 LIMIT 1',
                [$student['id']]
            );

            if (!$recent) {
                $this->sendVerificationEmail($student);
            }
        }

        return [
            'status' => 'success',
            'message' => 'If the address belongs to an unverified student account, a verification email will be sent.',
        ];
    }

    private function sendVerificationEmail(array $student): void
    {
        /*
         * The email now points to the React frontend instead of
         * directly to the API verification endpoint.
         */
        $frontendUrl = rtrim(
            getenv('FRONTEND_URL')
                ?: 'https://payments.obonguniversity.com',
            '/'
        );

        if ($frontendUrl === '') {
            throw new HttpException(
                'Email verification is not configured on the server.',
                503
            );
        }

        $this->run(
            'UPDATE email_verification_tokens
             SET used_at = UTC_TIMESTAMP()
             WHERE student_id = ?
               AND used_at IS NULL',
            [$student['id']]
        );

        $token = bin2hex(random_bytes(32));

        $this->run(
            'INSERT INTO email_verification_tokens
                (id, student_id, token_hash, expires_at)
             VALUES (
                ?,
                ?,
                ?,
                DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
             )',
            [
                $this->newId(),
                $student['id'],
                hash('sha256', $token)
            ]
        );

        $link = $frontendUrl
            . '/verify-email?token='
            . rawurlencode($token);

        $name = htmlspecialchars(
            trim(
                ($student['first_name'] ?? '')
                . ' '
                . ($student['surname'] ?? '')
            ),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $safeLink = htmlspecialchars(
            $link,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Verify your email address</title></head>'
            . '<body style="margin:0;padding:0;background-color:#f3f7f3;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f7f3;padding:32px 12px;">'
            . '<tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="height:8px;background-color:#08752b;font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:36px 32px 32px;">'
            . '<p style="margin:0 0 12px;color:#08752b;font-size:13px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Obong University</p>'
            . '<h1 style="margin:0 0 20px;color:#111827;font-size:25px;line-height:1.3;">Verify your email address</h1>'
            . '<p style="margin:0 0 14px;font-size:16px;line-height:1.6;">Hello ' . $name . ',</p>'
            . '<p style="margin:0 0 26px;color:#4b5563;font-size:15px;line-height:1.7;">Please confirm your email address to activate sign-in to your student account.</p>'
            . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 26px;"><tr><td align="center" style="border-radius:7px;background-color:#08752b;">'
            . '<a href="' . $safeLink . '" style="display:inline-block;padding:14px 26px;border:1px solid #08752b;border-radius:7px;background-color:#08752b;color:#ffffff;font-size:15px;font-weight:bold;line-height:1.2;text-decoration:none;">Verify email address</a>'
            . '</td></tr></table>'
            . '<p style="margin:0 0 10px;color:#6b7280;font-size:13px;line-height:1.6;">This verification link expires in 24 hours. If the button does not work, copy and paste this link into your browser:</p>'
            . '<p style="margin:0 0 24px;font-size:13px;line-height:1.6;word-break:break-all;"><a href="' . $safeLink . '" style="color:#08752b;">' . $safeLink . '</a></p>'
            . '<p style="margin:0;padding-top:20px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;line-height:1.6;">If you did not create this account, you can safely ignore this email.</p>'
            . '</td></tr></table>'
            . '<p style="margin:18px 0 0;color:#9ca3af;font-size:11px;line-height:1.5;text-align:center;">This is an automated message from Obong University. Please do not reply.</p>'
            . '</td></tr></table></body></html>';
            
        $text =
            "Hello {$student['first_name']},\r\n\r\n"
            . "Verify your email address to activate sign-in to your student account:\r\n"
            . "{$link}\r\n\r\n"
            . "This link expires in 24 hours. If you did not create this account, you can ignore this email.";

        try {

            (new SmtpMailer())->send(
                (string) $student['email'],
                'Verify your student account email',
                $text,
                $html
            );

            $this->run(
                'UPDATE email_verification_tokens
                 SET sent_at = UTC_TIMESTAMP()
                 WHERE token_hash = ?',
                [hash('sha256', $token)]
            );

        } catch (\RuntimeException $exception) {

            $this->run(
                'UPDATE email_verification_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE token_hash = ?',
                [hash('sha256', $token)]
            );

            error_log(
                'Student verification email delivery failed: '
                . $exception->getMessage()
            );

            throw new HttpException(
                'The account was created, but the verification email could not be sent. Please try requesting another verification email shortly.',
                503
            );
        }
    }

    private function findOrCreateFaculty(string $name): array
    {
        $faculty = $this->one(
            'SELECT id, name FROM faculties WHERE name = ?',
            [$name]
        );

        if ($faculty) {
            return $faculty;
        }

        $code = $this->referenceCode(
            'FAC',
            $name
        );

        $this->run(
            'INSERT INTO faculties
                (id, name, code)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [
                $this->newId(),
                $name,
                $code
            ]
        );

        return $this->one(
            'SELECT id, name FROM faculties WHERE name = ?',
            [$name]
        ) ?? throw new \RuntimeException(
            'Unable to resolve the submitted faculty.'
        );
    }

    private function findOrCreateDepartment(
        string $facultyId,
        string $name
    ): array {
        $department = $this->one(
            'SELECT id, name, code
             FROM departments
             WHERE faculty_id = ?
               AND name = ?',
            [
                $facultyId,
                $name
            ]
        );

        if ($department) {
            return $department;
        }

        $code = $this->referenceCode(
            'DEP',
            $name,
            (string) $facultyId
        );

        $this->run(
            'INSERT INTO departments
                (id, faculty_id, name, code)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [
                $this->newId(),
                $facultyId,
                $name,
                $code
            ]
        );

        return $this->one(
            'SELECT id, name, code
             FROM departments
             WHERE faculty_id = ?
               AND name = ?',
            [
                $facultyId,
                $name
            ]
        ) ?? throw new \RuntimeException(
            'Unable to resolve the submitted department.'
        );
    }

    private function findOrCreateProgramme(
        array $department,
        string $studyMode
    ): array {
        $programme = $this->one(
            'SELECT id
             FROM programmes
             WHERE department_id = ?
               AND study_mode = ?',
            [
                $department['id'],
                $studyMode
            ]
        );

        if ($programme) {
            return $programme;
        }

        $modeLabel = $studyMode === 'FULL_TIME'
            ? 'Full Time'
            : 'Part Time';

        $programmeName =
            $department['name']
            . ' - '
            . $modeLabel;

        $programmeCode =
            strtoupper(
                substr(
                    preg_replace(
                        '/[^A-Z0-9]+/',
                        '-',
                        $department['code']
                    ),
                    0,
                    20
                )
            )
            . '-'
            . (
                $studyMode === 'FULL_TIME'
                    ? 'FT'
                    : 'PT'
            );

        $this->run(
            'INSERT INTO programmes
                (id, department_id, name, code, study_mode)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [
                $this->newId(),
                $department['id'],
                $programmeName,
                $programmeCode,
                $studyMode
            ]
        );

        return $this->one(
            'SELECT id
             FROM programmes
             WHERE department_id = ?
               AND study_mode = ?',
            [
                $department['id'],
                $studyMode
            ]
        ) ?? throw new \RuntimeException(
            'Unable to resolve the selected study programme.'
        );
    }

    private function referenceCode(
        string $prefix,
        string $name,
        string $scope = ''
    ): string {
        $slug = trim(
            preg_replace(
                '/[^A-Z0-9]+/',
                '-',
                strtoupper($name)
            ),
            '-'
        );

        $suffix = strtoupper(
            substr(
                hash(
                    'sha256',
                    $scope . "\0" . strtolower($name)
                ),
                0,
                8
            )
        );

        $slugLength =
            32
            - strlen($prefix)
            - strlen($suffix)
            - 1;

        return
            $prefix
            . substr(
                $slug !== '' ? $slug : 'ITEM',
                0,
                $slugLength
            )
            . '-'
            . $suffix;
    }

    public function forgotPassword(Request $request): array
    {
        $identifier = trim(
            (string) ($request->body['identifier'] ?? '')
        );

        if ($identifier === '') {
            throw new HttpException(
                'Email address or registration number is required.',
                422
            );
        }

        $student = $this->one(
            'SELECT id
             FROM students
             WHERE email = ?
                OR registration_number = ?',
            [
                $identifier,
                $identifier
            ]
        );

        $admin = $student
            ? null
            : $this->one(
                'SELECT id
                 FROM administrators
                 WHERE email = ?',
                [strtolower($identifier)]
            );

        $record = $student ?: $admin;

        if ($record) {
            $type = $student
                ? 'STUDENT'
                : 'ADMIN';

            $token = bin2hex(
                random_bytes(32)
            );

            $this->run(
                'INSERT INTO password_reset_tokens
                    (id, identity_type, identity_id, token_hash, expires_at)
                 VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
                 )',
                [
                    $this->newId(),
                    $type,
                    $record['id'],
                    hash('sha256', $token)
                ]
            );

            error_log(
                "Password reset delivery is not configured for {$type} id {$record['id']}."
            );
        }

        return [
            'status' => 'success',
            'message' => 'If the account exists, password reset instructions will be sent to its registered email address.',
        ];
    }

    public function resetPassword(Request $request): array
    {
        $token = trim(
            (string) ($request->body['token'] ?? '')
        );

        $password = (string) (
            $request->body['password'] ?? ''
        );

        if (
            $token === ''
            || strlen($password) < 8
        ) {
            throw new HttpException(
                'A valid reset token and password of at least 8 characters are required.',
                422
            );
        }

        $reset = $this->one(
            'SELECT *
             FROM password_reset_tokens
             WHERE token_hash = ?
               AND used_at IS NULL
               AND expires_at > UTC_TIMESTAMP()',
            [
                hash('sha256', $token)
            ]
        );

        if (!$reset) {
            throw new HttpException(
                'Password reset token is invalid or expired.',
                400
            );
        }

        $table =
            $reset['identity_type'] === 'STUDENT'
                ? 'students'
                : 'administrators';

        $this->db->beginTransaction();

        try {

            $this->run(
                "UPDATE {$table}
                 SET password_hash = ?
                 WHERE id = ?",
                [
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    ),
                    $reset['identity_id']
                ]
            );

            $this->run(
                'UPDATE password_reset_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE id = ?',
                [$reset['id']]
            );

            $this->db->commit();

        } catch (\Throwable $exception) {

            $this->db->rollBack();

            throw $exception;
        }

        return [
            'status' => 'success',
            'message' => 'Your password has been reset successfully.'
        ];
    }

    public function logout(
        Request $request,
        array $params,
        ?array $actor
    ): array {
        $this->requireActor($actor);

        $token = $request->bearerToken();

        if ($token) {
            $this->run(
                'DELETE FROM api_tokens WHERE token_hash = ?',
                [hash('sha256', $token)]
            );
        }

        return [
            'status' => 'success',
            'message' => 'Signed out successfully.'
        ];
    }

    private function loginResponse(
        string $type,
        array $record,
        array $user
    ): array {
        $token = bin2hex(
            random_bytes(32)
        );

        $this->run(
            'INSERT INTO api_tokens
                (id, actor_type, actor_id, token_hash, expires_at)
             VALUES (
                ?,
                ?,
                ?,
                ?,
                DATE_ADD(UTC_TIMESTAMP(), INTERVAL 12 HOUR)
             )',
            [
                $this->newId(),
                $type,
                $record['id'],
                hash('sha256', $token)
            ]
        );

        return [
            'status' => 'success',
            'token' => $token,
            'role' =>
                $type === 'STUDENT'
                    ? 'STUDENT'
                    : $record['role'],
            'user' => $user,
        ];
    }

    private function studentPayload(
        array $student
    ): array {
        $fullName = trim(
            implode(
                ' ',
                array_filter([
                    $student['first_name'],
                    $student['other_names'],
                    $student['surname'],
                ])
            )
        );

        return [
            'id' => 'std_' . $student['id'],
            'regNumber' => $student['registration_number'],
            'surname' => $student['surname'],
            'firstName' => $student['first_name'],
            'otherNames' => $student['other_names'] ?? '',
            'fullName' => $fullName,
            'email' => $student['email'],
            'phone' => $student['phone'],
            'emailVerified' =>
                $student['email_verified_at'] !== null,
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