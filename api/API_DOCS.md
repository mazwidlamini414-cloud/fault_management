# BUSIQUIP REST API Reference

Base URL: `https://your-app.up.railway.app/api`

All requests/responses use **JSON**. Authenticated endpoints require:
```
Authorization: Bearer <token>
```

---

## Authentication

### POST `/api/auth.php` — Login
```json
{ "username": "john@gmail.com", "password": "secret", "role": "Technician" }
```
Roles: `Client` | `Technician` | `Accountant` | `Admin`

**Response:**
```json
{
  "success": true,
  "data": {
    "token": "abc123...",
    "expires_at": "2026-06-17 10:00:00",
    "user": { "id": 9, "name": "John Technician", "role": "Technician" }
  }
}
```

### DELETE `/api/auth.php` — Logout
Requires Bearer token. Invalidates the token.

---

## Dashboard

### GET `/api/dashboard.php`
Returns stats tailored to the logged-in role.

**Client** returns: fault counts, invoice totals, wallet balance, recent faults.
**Technician** returns: assigned/in-progress/completed counts, hours logged, active faults.
**Accountant** returns: pending invoices, pending payments, revenue, company balance.
**Admin** returns: all-system stats, client/employee counts, recent faults.

---

## Faults

### GET `/api/faults.php`
List faults. Automatically filtered to the logged-in user's scope.

Query params: `status`, `limit` (max 100), `offset`

Status values: `Pending` | `Assigned` | `In Progress` | `Completed` | `Client Approved` | `Rework Required` | `Closed`

### GET `/api/faults.php?id=X`
Single fault with work log.

### POST `/api/faults.php` — Client reports fault
```json
{
  "description": "The printer stopped working",
  "fault_id": 5,
  "priority": "Medium",
  "equipment_type": "Printer",
  "brand_model": "HP LaserJet",
  "serial_number": "SN12345",
  "fault_location": "Room 3",
  "dept_branch": "IT",
  "is_operational": "No",
  "occurred_before": "Yes",
  "users_affected": 3,
  "contact_method": "Email",
  "service_visit": "Yes"
}
```
`fault_id` comes from `GET /api/lookup.php?type=fault_types`

### PUT `/api/faults.php` — Update fault status

**Technician — start work:**
```json
{ "fault_id": 5, "action": "start" }
```

**Technician — complete:**
```json
{ "fault_id": 5, "action": "complete", "note": "Replaced paper tray" }
```

**Technician — add note:**
```json
{ "fault_id": 5, "action": "note", "note": "Waiting for spare part" }
```

**Client — confirm resolution:**
```json
{ "fault_id": 5, "action": "confirm" }
```

**Client — request rework:**
```json
{ "fault_id": 5, "action": "reject", "reason": "Issue not fully resolved" }
```

---

## Invoices & Payments

### GET `/api/invoices.php`
List invoices. Filtered to client's own invoices automatically.

Query params: `status`, `limit`, `offset`

Status values: `Paid` | `Partial` | `Pending Payment` | `Overdue` | `Unpaid` | `Approved` | `Invoiced`

### GET `/api/invoices.php?id=X`
Full invoice with line items and payment history.

### POST `/api/invoices.php` — Client submits payment
```json
{
  "invoice_id": 5,
  "amount": 618.00,
  "method": "Mobile Money",
  "reference": "MPesa-REF12345",
  "mobile_number": "+26876123456",
  "network": "MTN"
}
```
Payment methods: `Card Transfer` | `Mobile Money` | `Bank Transfer` | `Cash`

---

## Notifications

### GET `/api/notifications.php`
Query params: `unread=1`, `limit`, `offset`

### PUT `/api/notifications.php`
```json
{ "id": 12 }   // mark one as read
{}             // mark ALL as read
```

---

## Messages

### GET `/api/messages.php`
Inbox. Query params: `limit`, `offset`

### POST `/api/messages.php`
```json
{
  "to_id": 1,
  "to_type": "Admin",
  "subject": "Query about invoice",
  "content": "Hi, I have a question...",
  "priority": "Normal"
}
```
to_type: `Client` | `Employee` | `Admin`

### PUT `/api/messages.php`
```json
{ "id": 3 }   // mark one as read
{}            // mark all as read
```

---

## Lookup (Reference Data)

### GET `/api/lookup.php?type=fault_types`
All fault categories (for the "report fault" form dropdown).

### GET `/api/lookup.php?type=clients`
All clients. Access: Admin, Accountant, Technician.

### GET `/api/lookup.php?type=technicians`
All technicians. Access: Admin, Accountant.

### GET `/api/lookup.php?type=employees`
All employees. Access: Admin only.

---

## Profile

### GET `/api/profile.php`
Returns full profile for logged-in user.

### PUT `/api/profile.php` — Update profile / change password
```json
{
  "contact_person": "New Name",
  "company_phone": "+26876000000",
  "current_password": "oldpass",
  "new_password": "newpass"
}
```

---

## Error Responses

All errors follow this format:
```json
{ "success": false, "error": "Description of the problem" }
```

| HTTP Code | Meaning |
|-----------|---------|
| 400 | Bad request / missing fields |
| 401 | Not authenticated / bad token |
| 403 | Forbidden / wrong role |
| 404 | Resource not found |
| 405 | Method not allowed |
