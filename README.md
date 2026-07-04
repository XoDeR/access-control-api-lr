# Access Control API

A multi-tenant IAM-style backend built with **Laravel 13** — JWT refresh rotation, RBAC permissions, team invitations, session management, Redis-backed rate limiting, audit logs, and OpenAPI docs.

## Stack

| Layer | Technology |
|-------|------------|
| Framework | Laravel 13 (API-only, custom auth) |
| Database | PostgreSQL |
| Cache / rate limits | Redis |
| Auth | JWT access tokens + opaque refresh token rotation |
| Password hashing | Argon2id |
| API docs | [Scramble](https://github.com/dedoc/scramble) (OpenAPI / Swagger UI) |
| Tests | Pest + PostgreSQL |

## Architecture

```
Client (Swagger / Postman)
        │
        ▼
┌───────────────────────────────────────┐
│  Laravel API  (/api/v1/*)             │
│  Middleware: JWT · RBAC · Rate limit  │
│  Services: Auth · Token · Permission  │
└───────────────┬───────────────────────┘
                │
        ┌───────┴───────┐
        ▼               ▼
   PostgreSQL         Redis
   (users, orgs,      (rate limits,
    roles, sessions,   JWT blacklist,
    audit logs)        permission cache)
```

### Auth flow

1. **Signup / login** — returns short-lived JWT + opaque refresh token (refresh hash stored in DB).
2. **Refresh** — validates refresh hash, revokes old session, issues rotated tokens.
3. **Logout** — revokes session row and blacklists JWT `jti` until expiry.

### Authorization

- Users join **organizations** via **memberships** with a **role** (owner, admin, member, viewer).
- Roles map to **permissions** (`users.read`, `users.invite`, `projects.write`, `billing.read`).
- `RequirePermission` middleware denies by default (403).
- Policies enforce role-assignment rules (e.g. only owner can assign owner).

## Quick start

### Prerequisites

- PHP 8.3+ with extensions: `pdo_pgsql`, `mbstring`, `openssl`
- Composer
- PostgreSQL 16+ and Redis 7+ — **either** via Docker Compose **or** installed locally

### 1. Clone and install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `JWT_SECRET` in `.env` (can match `APP_KEY` or use a dedicated secret).

### 2. Database — choose one profile

**Option A: Docker Compose** (recommended for local dev)

```bash
docker compose up -d
```

Uses PostgreSQL on `127.0.0.1:5432` and Redis on `127.0.0.1:6379` — same `.env` keys as a local install.

**Option B: Local PostgreSQL + Redis**

Point `.env` at your existing instances (same `DB_*` and `REDIS_*` keys).

Create the database:

```sql
CREATE DATABASE access_control;
```

### 3. Migrate and seed

```bash
php artisan migrate
php artisan db:seed
```

Seeds roles (owner, admin, member, viewer) and permissions with the role-permission matrix.

### 4. Run the API

```bash
php artisan serve
```

- Health check: `GET http://localhost:8000/api/health`
- OpenAPI docs: `http://localhost:8000/docs/api`

### 5. Run tests

Create a test database:

```sql
CREATE DATABASE access_control_test;
```

```bash
composer test
```

Tests use PostgreSQL (`access_control_test`) as configured in `phpunit.xml`.

## Demo flow (Swagger / Postman)

1. `POST /api/v1/auth/signup` — create account, save tokens
2. `POST /api/v1/organizations` — create org (you become owner)
3. `POST /api/v1/organizations/{uuid}/invitations` — invite teammate (`users.invite`)
4. `POST /api/v1/invitations/accept` — accept with invite token
5. `PATCH /api/v1/organizations/{uuid}/members/{userUuid}` — change role
6. `GET /api/v1/organizations/{uuid}/members` — list members (`users.read`)
7. Try invite as **viewer** → 403 Forbidden
8. `GET /api/v1/organizations/{uuid}/audit-logs` — view audit trail
9. `GET /api/v1/sessions` / `DELETE /api/v1/sessions/{uuid}` — manage sessions

## API endpoints (Phase 1)

| Method | Path | Access |
|--------|------|--------|
| GET | `/api/health` | Public |
| POST | `/api/v1/auth/signup` | Public (rate-limited) |
| POST | `/api/v1/auth/login` | Public (rate-limited) |
| POST | `/api/v1/auth/refresh` | Refresh token |
| POST | `/api/v1/auth/logout` | JWT |
| POST | `/api/v1/organizations` | JWT |
| GET | `/api/v1/organizations/{uuid}` | Member |
| POST | `/api/v1/organizations/{uuid}/invitations` | `users.invite` |
| POST | `/api/v1/invitations/accept` | Public (token) |
| GET | `/api/v1/organizations/{uuid}/members` | `users.read` |
| PATCH | `/api/v1/organizations/{uuid}/members/{uuid}` | Admin+ |
| GET | `/api/v1/organizations/{uuid}/audit-logs` | `users.read` |
| GET | `/api/v1/sessions` | JWT |
| DELETE | `/api/v1/sessions/{uuid}` | JWT (own or admin) |

## Project structure

```
app/
  Http/Controllers/Api/V1/   # Auth, Organization, Invitation, Member, Session, AuditLog
  Http/Middleware/           # AuthenticateJwt, RequirePermission, EnsureOrganizationMember
  Http/Requests/               # Form request validation
  Http/Resources/              # JSON API resources
  Models/                      # User, Organization, Role, Permission, Membership, etc.
  Policies/                    # OrganizationPolicy, MembershipPolicy
  Services/                    # Auth, Token, Permission, Invitation, Audit, Session
database/migrations/           # 9 IAM tables
database/seeders/              # Roles + permissions
docs/IMPLEMENTATION.md         # Step-by-step build guide
tests/Feature/                 # Pest integration tests
```

## Phase 2 (planned)

Password reset, API keys, soft deletes, ABAC ownership rules, admin pagination/filtering, Postman/Bruno collection export.

## Resume bullet

> Built a multi-tenant access-control API with Laravel 13 featuring JWT refresh rotation, RBAC permission guards, expiring team invitations, Redis rate limiting, session revocation, audit logging, and OpenAPI documentation with Pest integration tests.

## License

MIT — see [LICENSE](LICENSE).
