# PHP Backend Structure

Native PHP 8.2+, PDO, and MySQL 8 API starter intended to replace the browser's localStorage mock database. Keep the Paystack secret key on this server only. The React app's `VITE_API_URL` should point to this API's `/api/v1` base URL.

## Layout

```text
backend/
  public/
    index.php                 # Web-server entry point
  routes/
    api.php                   # API route map
  src/
    Controllers/              # Auth, student, admin, payment, receipt handlers
    Core/                     # Router, request, response, PDO, auth helpers
  database/
    schema.sql                # MySQL tables, indexes, and foreign keys
    seeds/                    # Optional local-only seed data
  tests/
    check-route-map.php       # Checks route/controller registration
  .env.example
  composer.json
```

## Main Relationships

- A faculty has many departments; a department has many programmes and students.
- An academic session has many students, payment types, and invoices.
- A payment type has many invoices. Each invoice belongs to one student, payment type, and session; its amount is a snapshot so later fee edits do not rewrite an existing bill.
- An invoice can have multiple transaction attempts. Each transaction belongs to one student and invoice and stores the gateway reference, amount, status, and verification timestamps.
- A successful transaction can have one receipt. A receipt has a unique public verification code and receipt number.
- An administrator can create audit log records. Student-originated events retain an actor type and ID without requiring an administrator row.
- Settings are stored as JSON values; credentials and Paystack secret keys must not be stored in the settings table.

## API Route Map

All routes are under `/api/v1`. Protect student routes with the authenticated student's identity and admin routes with role authorization; never trust a `student_id` supplied by the browser when an access token already identifies the student.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/auth/student/login` | Student sign-in by registration number |
| POST | `/auth/admin/login` | Administrator sign-in |
| POST | `/auth/student/register` | Create a student account and initial invoices |
| POST | `/auth/forgot-password` | Start password reset |
| POST | `/auth/reset-password` | Complete password reset |
| POST | `/auth/logout` | Revoke the current bearer token |
| GET | `/student/profile` | Current student profile |
| PUT | `/student/profile` or `/student/profile/{id}` | Update editable profile fields; authenticated identity remains authoritative |
| GET | `/student/invoices` | Current student's invoices |
| GET | `/student/payment-summary` | Student payment totals |
| POST | `/payments/initialize` | Validate invoice and initialize Paystack transaction |
| GET | `/payments/verify/{reference}` | Verify reference server-to-server with Paystack |
| POST | `/payments/webhook` | Validate Paystack signature and process event idempotently |
| GET | `/transactions` | Filter transaction list |
| GET | `/transactions/{reference}` | Transaction detail |
| GET | `/receipts/{receiptOrReference}` | Authorized receipt lookup |
| GET | `/public/verify-receipt?q={query}` | Limited public receipt verification |
| GET | `/admin/stats` | Dashboard totals |
| GET/POST | `/admin/students` | List/create student records |
| GET | `/admin/students/{id}` | Student detail and ledger |
| GET/POST | `/admin/administrators` | List/create administrators |
| PATCH | `/admin/administrators/{id}/role` | Change administrator role |
| PATCH | `/admin/administrators/{id}/toggle` | Activate/deactivate administrator |
| GET/POST | `/admin/academic-sessions` | List/create academic sessions |
| PATCH | `/admin/academic-sessions/{id}/toggle` | Activate/close a session |
| GET | `/admin/audit-logs` | Search audit history |
| GET/PUT | `/admin/settings` | Read/update non-secret portal settings |
| GET | `/admin/faculties` | List faculties for fee configuration |
| GET | `/payment-types` | List payment types |
| POST | `/payment-types` | Create payment type and issue eligible faculty/department/level invoices |
| PUT | `/payment-types/{id}` | Update payment type |
| DELETE | `/payment-types/{id}` | Delete a payment type that has no issued invoices |
| POST | `/admin/verify-payment` | Find a transaction by reference, receipt, or registration number |
| GET | `/reconciliation` | Compare local transactions with Paystack |
| GET | `/reports/summary` | Financial report aggregates |

## XAMPP Setup

1. Copy the project backend to `C:\xampp\htdocs\Obong_Payment\backend` so `backend/public/index.php` is inside Apache's document root.
2. In `C:\xampp\apache\conf\httpd.conf`, ensure `mod_rewrite` is enabled and the `htdocs` directory allows overrides (`AllowOverride All`). Restart Apache after changing Apache configuration.
3. In `C:\xampp\php\php.ini`, enable `extension=pdo_mysql` and confirm `extension_dir` points to PHP's `ext` directory. Restart Apache after changing PHP configuration.
4. Copy `backend/.env.example` to `backend/.env`; set MySQL credentials and the Paystack test secret/public keys. Set `FRONTEND_URL` to the exact Vite origin in your browser (for example, `http://localhost:3000` or `http://127.0.0.1:3000`).
5. Start MySQL in the XAMPP Control Panel. In phpMyAdmin, import `backend/database/schema.sql`, then add the university's faculties, departments, active academic session, payment types, and initial administrator account.
6. Copy the repository's `.env.local.example` to `.env.local` and set `VITE_API_URL=http://localhost/Obong_Payment/backend/public/api/v1` (adjust `Obong_Payment` if the XAMPP folder has a different name).
7. From the project root, run `npm run dev`. The frontend runs at Vite's local URL and calls the XAMPP API. To verify Apache routing, open `http://localhost/Obong_Payment/backend/public/health`; it should return JSON with `status: success`.

The API also works with PHP's built-in server: `php -S 127.0.0.1:8000 -t backend/public backend/public/index.php`, with `VITE_API_URL=http://127.0.0.1:8000/api/v1`.

Check route/controller wiring with `php backend/tests/check-route-map.php`; lint PHP with `php -l` on the backend files. Authentication, role checks, password hashing, server-side Paystack initialization/verification, webhook signature validation, transaction settlement, and receipt issuance are implemented. Before production, verify student registrations against the Registrar's authoritative roster; password reset delivery still needs an email provider, and reconciliation currently summarizes local states rather than re-verifying every transaction with Paystack. Exercise all financial/admin paths against configured MySQL and Paystack test accounts before production use.

For an existing database, apply `database/migrations/20261003_add_faculty_scope_to_payment_types.sql`, `database/migrations/20261003_add_department_scope_to_payment_types.sql`, and `database/migrations/20261003_add_payment_type_installments.sql` once before deploying faculty-, department-, or installment-specific payment types. Existing payment types remain full-payment-only by default; an administrator can enable part payments per fee. The 60% minimum applies to the first payment only for fee types with part payments enabled. New configurations can target a faculty, an optional department within that faculty, and a level. The generated scope keys permit the same fee code for non-overlapping faculty/department/level schedules.

Payment type edits affect the fee configuration and future invoices; existing invoice amounts remain snapshots. A payment type with any issued invoice cannot be permanently deleted, preserving billing and payment history. Set its status to inactive to stop issuing new invoices.