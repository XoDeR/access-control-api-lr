# Implementation Guide — Access Control API (Laravel 13)

This document walks through building the IAM backend from scratch, in the order features were implemented. Use it to reproduce the project or understand how each piece fits together.

---

## 1. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| PHP | 8.3+ | Extensions: `pdo_pgsql`, `mbstring`, `openssl`, `redis` (optional, for phpredis) |
| Composer | 2.x | Dependency management |
| PostgreSQL | 16+ | Primary database |
| Redis | 7+ | Rate limiting, JWT blacklist, permission cache |
| Docker | Optional | `docker compose up -d` for Postgres + Redis |

**Windows (Laragon):** enable `extension=pdo_pgsql` and `extension=pgsql` in `php.ini` if not already active.

---

## 2. Project creation

Scaffold Laravel 13 in the repo root:

```bash
composer create-project laravel/laravel .
php artisan key:generate
```

Configure as **API-only** — no Breeze, Jetstream, or auth starter kits. All authentication and RBAC are custom-built.

Key bootstrap changes in `bootstrap/app.php`:

- Register `routes/api.php` with prefix `api`
- Append `RequestId` middleware globally
- Enable `throttleApi()` for API rate limiting
- Render JSON exceptions for `api/*` routes with consistent error envelope

---

## 3. Environment setup

Copy `.env.example` to `.env`. Both Docker and local installs use the **same env keys**:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=access_control
DB_USERNAME=postgres
DB_PASSWORD=postgres

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
CACHE_STORE=redis

JWT_SECRET=           # set to APP_KEY or dedicated secret
JWT_ACCESS_TTL=900    # 15 minutes
JWT_REFRESH_TTL=604800  # 7 days
```

**Docker profile:**

```bash
docker compose up -d
```

**Local profile:** point `DB_*` and `REDIS_*` at your installed services — no code changes required.

Create databases:

```sql
CREATE DATABASE access_control;
CREATE DATABASE access_control_test;  -- for Pest tests
```

---

## 4. Package installation

```bash
composer require firebase/php-jwt dedoc/scramble
composer require --dev pestphp/pest pestphp/pest-plugin-laravel
php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider"
```

| Package | Purpose |
|---------|---------|
| `firebase/php-jwt` | Sign and verify JWT access tokens |
| `dedoc/scramble` | Auto-generate OpenAPI docs at `/docs/api` |
| `pestphp/pest` | Integration tests |

---

## 5. Migrations and seeders

### Why PostgreSQL?

IAM data is highly relational — foreign keys, unique constraints, joins, and transactions across users, organizations, roles, permissions, memberships, sessions, invitations, and audit logs.

### Tables (9 IAM + Laravel cache/jobs)

| Migration | Table | Purpose |
|-----------|-------|---------|
| `0001_..._create_users_table` | `users` | Accounts with UUID public identifier |
| `2026_07_04_000001` | `organizations` | Workspaces / tenants |
| `2026_07_04_000002` | `roles`, `permissions`, `role_permissions` | RBAC core |
| `2026_07_04_000003` | `memberships` | User ↔ org ↔ role join |
| `2026_07_04_000004` | `sessions` | Refresh token hashes (not raw tokens) |
| `2026_07_04_000005` | `invitations` | Expiring invite tokens (hashed) |
| `2026_07_04_000006` | `audit_logs` | Immutable action history |

Run:

```bash
php artisan migrate
php artisan db:seed
```

### Seeded role-permission matrix

| Role | Permissions |
|------|-------------|
| owner | all four |
| admin | all four |
| member | `users.read`, `projects.write` |
| viewer | `users.read`, `billing.read` |

Permissions: `users.read`, `users.invite`, `projects.write`, `billing.read`.

---

## 6. Models and relationships

| Model | Key relationships |
|-------|-------------------|
| `User` | hasMany Membership, UserSession, AuditLog |
| `Organization` | belongsTo User (owner); hasMany Membership, Invitation, AuditLog |
| `Membership` | belongsTo User, Organization, Role |
| `Role` | belongsToMany Permission |
| `UserSession` | table `sessions`; belongsTo User |
| `Invitation` | belongsTo Organization, Role, User (inviter) |
| `AuditLog` | belongsTo User, Organization |

Models with public UUIDs implement `getRouteKeyName(): 'uuid'` for route model binding.

Password hashing uses **Argon2id** via `config/hashing.php`.

---

## 7. Services layer

Built from scratch in `app/Services/`:

### TokenService

- Issues JWT access tokens (`sub` = user UUID, `jti` for revocation)
- Generates opaque 64-char refresh tokens
- Stores `hash('sha256', $token)` in DB — never raw refresh tokens
- Blacklists JWT `jti` via Cache (Redis in production) until access token expiry

### AuthService

- `signup`, `login`, `refresh`, `logout`
- Refresh rotation: revoke old session row, issue new token pair in a DB transaction
- Audit log on login/logout

### PermissionService

- Resolves permissions: membership → role → permissions
- Caches permission slugs per user+org (60s TTL)
- `isOrganizationAdmin()` for owner/admin checks

### OrganizationService

- Creates org + owner membership atomically
- Updates member roles with audit trail

### InvitationService

- Creates 7-day expiring invites with hashed token
- Accept flow: validate token, email match, create membership

### SessionService / AuditService

- List and revoke user sessions
- Append-only audit log writes

---

## 8. Middleware and policies

| Middleware | File | Behavior |
|------------|------|----------|
| `AuthenticateJwt` | Parses Bearer JWT, checks blacklist, sets user |
| `EnsureOrganizationMember` | Verifies membership for org-scoped routes |
| `RequirePermission` | Parametric: `RequirePermission:users.invite` |
| `RequestId` | Sets/propagates `X-Request-Id` header |

### Policies

- **MembershipPolicy** — owner can assign any role; admin cannot promote to owner or assign admin
- **OrganizationPolicy** — view requires membership; update requires admin+

Rate limiters (in `AppServiceProvider`):

- `auth-login`: 10 req/min per IP
- `auth-signup`: 30 req/min per IP
- `api`: 60 req/min per user or IP

---

## 9. Controllers and routes

All routes in `routes/api.php`:

```
GET  /api/health
POST /api/v1/auth/{signup,login,refresh,logout}
POST /api/v1/organizations
GET  /api/v1/organizations/{uuid}
POST /api/v1/organizations/{uuid}/invitations
POST /api/v1/invitations/accept
GET  /api/v1/organizations/{uuid}/members
PATCH /api/v1/organizations/{uuid}/members/{uuid}
GET  /api/v1/organizations/{uuid}/audit-logs
GET  /api/v1/sessions
DELETE /api/v1/sessions/{uuid}
```

Form Requests validate every mutating endpoint. API Resources shape JSON responses.

---

## 10. Rate limiting and Redis

Production `.env` sets `CACHE_STORE=redis`. Laravel's `RateLimiter` and the JWT blacklist both use the cache layer, which backs onto Redis.

When Redis is unavailable (tests), `CACHE_STORE=array` in `phpunit.xml` keeps tests isolated.

---

## 11. OpenAPI setup

Scramble auto-documents routes and Form Requests. After starting the server:

```
http://localhost:8000/docs/api
```

Configuration lives in `config/scramble.php` with `api_path` set to `api`.

---

## 12. Tests

Pest feature tests in `tests/Feature/`:

| File | Coverage |
|------|----------|
| `AuthTest` | signup, login, refresh rotation, logout, invalid credentials |
| `OrganizationTest` | create org, owner membership, show org |
| `InvitationTest` | invite + accept, expired token rejected |
| `PermissionTest` | viewer blocked from invite, member read, admin role change |
| `SessionTest` | list sessions, revoke, revoked refresh fails |
| `AuditLogTest` | login and org creation logged |
| `HealthTest` | health endpoint |

Run:

```bash
composer test
```

Tests require PostgreSQL database `access_control_test` (see `phpunit.xml`).

---

## 13. Demo walkthrough

### Step 1 — Signup

```http
POST /api/v1/auth/signup
Content-Type: application/json

{
  "name": "Alice Owner",
  "email": "alice@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

Save `access_token` and `refresh_token`.

### Step 2 — Create organization

```http
POST /api/v1/organizations
Authorization: Bearer {access_token}

{ "name": "Acme Corp" }
```

Note the org `uuid` from the response.

### Step 3 — Invite teammate

```http
POST /api/v1/organizations/{org_uuid}/invitations
Authorization: Bearer {access_token}

{ "email": "bob@example.com", "role": "member" }
```

Save `invite_token` from the response.

### Step 4 — Accept invitation

```http
POST /api/v1/invitations/accept

{
  "token": "{invite_token}",
  "name": "Bob Member",
  "email": "bob@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

### Step 5 — Assign role

Login as Alice, then:

```http
PATCH /api/v1/organizations/{org_uuid}/members/{bob_uuid}
Authorization: Bearer {alice_access_token}

{ "role": "viewer" }
```

### Step 6 — Permission check

Login as Bob (viewer). Try to invite someone → **403 Forbidden**.

Try `GET /api/v1/organizations/{org_uuid}/members` → **200 OK** (has `users.read`).

### Step 7 — Audit logs

```http
GET /api/v1/organizations/{org_uuid}/audit-logs
Authorization: Bearer {access_token}
```

Expect entries for `auth.login`, `organization.created`, `invitation.created`, `invitation.accepted`, `membership.role_changed`.

---

## 14. Phase 2 roadmap

- [ ] Password reset flow
- [ ] API keys for machine-to-machine access
- [ ] Soft delete users and organizations
- [ ] ABAC-style ownership rules
- [ ] Admin pagination and filtering on audit logs
- [ ] Postman / Bruno collection export

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| `could not find driver (pgsql)` | Enable `pdo_pgsql` in `php.ini` |
| `connection refused` on 5432 | Start Docker Compose or local PostgreSQL |
| Redis connection errors | Start Redis or set `CACHE_STORE=array` for local dev without Redis |
| 403 on org routes | User must be a member; check role permissions |
| Refresh token invalid after refresh | Old token is revoked on rotation — use the new refresh token |
