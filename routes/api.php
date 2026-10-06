<?php

declare(strict_types=1);

/*
 * Route registration outline for the API router. Keep controllers thin and
 * put database queries in repositories and payment rules in services.
 * See backend/README.md for the complete method/path contract.
 */

return [
    ['GET', '/health', 'HealthController@show'],
    ['POST', '/api/v1/auth/student/login', 'AuthController@studentLogin'],
    ['POST', '/api/v1/auth/admin/login', 'AuthController@adminLogin'],
    ['POST', '/api/v1/auth/student/register', 'AuthController@studentRegister'],
    ['GET', '/api/v1/auth/student/verify-email', 'AuthController@verifyStudentEmail'],
    ['POST', '/api/v1/auth/student/resend-verification', 'AuthController@resendStudentEmailVerification'],
    ['POST', '/api/v1/auth/forgot-password', 'AuthController@forgotPassword'],
    ['POST', '/api/v1/auth/reset-password', 'AuthController@resetPassword'],
    ['POST', '/api/v1/auth/logout', 'AuthController@logout', ['authenticated' => true]],
    ['GET', '/api/v1/student/profile', 'StudentController@profile', ['type' => 'STUDENT']],
    ['PUT', '/api/v1/student/profile', 'StudentController@updateProfile', ['type' => 'STUDENT']],
    ['PUT', '/api/v1/student/profile/{id}', 'StudentController@updateProfile', ['type' => 'STUDENT']],
    ['GET', '/api/v1/student/invoices', 'StudentController@invoices', ['type' => 'STUDENT']],
    ['GET', '/api/v1/student/payment-summary', 'StudentController@paymentSummary', ['type' => 'STUDENT']],
    ['POST', '/api/v1/payments/initialize', 'PaymentController@initialize', ['type' => 'STUDENT']],
    ['POST', '/api/v1/payments/redeem-token', 'PaymentController@redeemToken', ['type' => 'STUDENT']],
    ['GET', '/api/v1/payments/verify/{reference}', 'PaymentController@verify'],
    ['POST', '/api/v1/payments/webhook', 'PaymentController@webhook'],
    ['GET', '/api/v1/transactions', 'TransactionController@index', ['authenticated' => true]],
    ['GET', '/api/v1/transactions/{reference}', 'TransactionController@show', ['authenticated' => true]],
    ['GET', '/api/v1/receipts/{receiptOrReference}', 'ReceiptController@show', ['authenticated' => true]],
    ['GET', '/api/v1/public/verify-receipt', 'ReceiptController@verifyPublic'],
    ['GET', '/api/v1/admin/stats', 'AdminController@stats', ['type' => 'ADMIN']],
    ['GET', '/api/v1/admin/students', 'AdminController@students', ['type' => 'ADMIN']],
    ['POST', '/api/v1/admin/students', 'AdminController@createStudent', ['type' => 'ADMIN']],
    ['GET', '/api/v1/admin/students/{id}', 'AdminController@student', ['type' => 'ADMIN']],
    ['GET', '/api/v1/admin/administrators', 'AdminController@administrators', ['type' => 'ADMIN']],
    ['POST', '/api/v1/admin/administrators', 'AdminController@createAdministrator', ['roles' => ['SUPER_ADMIN']]],
    ['PATCH', '/api/v1/admin/administrators/{id}/role', 'AdminController@updateAdministratorRole', ['roles' => ['SUPER_ADMIN']]],
    ['PATCH', '/api/v1/admin/administrators/{id}/toggle', 'AdminController@toggleAdministrator', ['roles' => ['SUPER_ADMIN']]],
    ['GET', '/api/v1/admin/academic-sessions', 'AdminController@sessions', ['type' => 'ADMIN']],
    ['POST', '/api/v1/admin/academic-sessions', 'AdminController@createSession', ['roles' => ['SUPER_ADMIN']]],
    ['PATCH', '/api/v1/admin/academic-sessions/{id}/toggle', 'AdminController@toggleSession', ['roles' => ['SUPER_ADMIN']]],
    ['GET', '/api/v1/admin/audit-logs', 'AdminController@auditLogs', ['type' => 'ADMIN']],
    ['GET', '/api/v1/admin/settings', 'AdminController@settings', ['type' => 'ADMIN']],
    ['PUT', '/api/v1/admin/settings', 'AdminController@updateSettings', ['roles' => ['SUPER_ADMIN']]],
    ['GET', '/api/v1/admin/faculties', 'AdminController@faculties', ['type' => 'ADMIN']],
    ['GET', '/api/v1/payment-types', 'AdminController@paymentTypes', ['authenticated' => true]],
    ['POST', '/api/v1/payment-types', 'AdminController@createPaymentType', ['roles' => ['SUPER_ADMIN', 'BURSAR']]],
    ['PUT', '/api/v1/payment-types/{id}', 'AdminController@updatePaymentType', ['roles' => ['SUPER_ADMIN', 'BURSAR']]],
    ['DELETE', '/api/v1/payment-types/{id}', 'AdminController@deletePaymentType', ['roles' => ['SUPER_ADMIN', 'BURSAR']]],
    ['POST', '/api/v1/admin/verify-payment', 'AdminController@verifyPayment', ['type' => 'ADMIN']],
    ['POST', '/api/v1/admin/payment-tokens', 'PaymentController@createToken', ['roles' => ['SUPER_ADMIN']]],
    ['GET', '/api/v1/reconciliation', 'AdminController@reconciliation', ['roles' => ['SUPER_ADMIN', 'BURSAR']]],
    ['GET', '/api/v1/reports/summary', 'AdminController@reportSummary', ['type' => 'ADMIN']],
];