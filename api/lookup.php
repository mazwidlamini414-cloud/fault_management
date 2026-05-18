<?php
// ═══════════════════════════════════════════════════════════════
//  api/lookup.php  —  Static reference data for dropdowns
//
//  GET /api/lookup.php?type=fault_types    All fault categories
//  GET /api/lookup.php?type=clients        All clients (Admin/Accountant/Technician)
//  GET /api/lookup.php?type=employees      All employees (Admin only)
//  GET /api/lookup.php?type=technicians    All technicians
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/middleware.php';

$user = require_auth();
$role = $user['user_type'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_error('Method not allowed', 405);

$type = clean($_GET['type'] ?? '');

if ($type === 'fault_types') {
    // All fault categories — used when reporting a fault
    // Matches the query in report_fault.php
    $res = $conn->query("
        SELECT FAULT_ID, FAULT_TYPE, FAULT_DESCRIPTION, DEFAULT_PRIORITY, DEFAULT_SLA_DAYS
        FROM fault ORDER BY FAULT_TYPE ASC
    ");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    api_ok($rows);
}

if ($type === 'clients') {
    if (!in_array($role, ['Admin', 'Accountant', 'Technician'])) api_error('Access denied', 403);
    $res = $conn->query("
        SELECT CLIENT_ID, COMPANY_NAME, COMPANY_EMAIL, COMPANY_PHONE,
               CONTACT_PERSON_NAME, CLIENT_TYPE
        FROM client ORDER BY COMPANY_NAME ASC
    ");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    api_ok($rows);
}

if ($type === 'employees') {
    if ($role !== 'Admin') api_error('Access denied', 403);
    $res = $conn->query("
        SELECT EMP_ID, FULL_NAME, EMAIL, ROLE, HIRE_DATE, HOURLY_RATE
        FROM employee ORDER BY FULL_NAME ASC
    ");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    api_ok($rows);
}

if ($type === 'technicians') {
    if (!in_array($role, ['Admin', 'Accountant'])) api_error('Access denied', 403);
    $res = $conn->query("
        SELECT EMP_ID, FULL_NAME, EMAIL
        FROM employee WHERE ROLE = 'Technician'
        ORDER BY FULL_NAME ASC
    ");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    api_ok($rows);
}

if (!$type) api_error("'type' parameter is required. Options: fault_types, clients, employees, technicians");
api_error("Unknown type '$type'");


