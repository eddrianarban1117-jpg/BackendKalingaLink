# KalingaLink

KalingaLink is a Child Development Center management system for MSWDO
administrators, teachers, and parents. This repository contains the PHP API
backend built on the LavaLust MVC framework. The React frontend is maintained
and deployed separately.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.0-8892BF)](https://www.php.net/)
[![GitHub Stars](https://img.shields.io/github/stars/ronmarasigan/lavalust?style=flat)](https://github.com/ronmarasigan/lavalust/stargazers)

---

## Overview

The system supports CDC and enrollment management, child records, attendance,
learning activities, and notifications. Keep the LavaLust framework files
under `scheme/` intact; KalingaLink application code belongs under `app/`.

---

## Features

| Area     | Implementation                                                       |
| -------- | -------------------------------------------------------------------- |
| Backend  | LavaLust 4.6 MVC and API controllers                                 |
| Database | MySQL configured with environment variables                          |
| Features | CDCs, enrollment, child records, attendance, learning, notifications |

---

## Requirements

- PHP 8.0 or higher (the Docker image defaults to PHP 8.3)
- A web server with URL rewriting support (Apache `.htaccess` or Nginx config)
- MySQL-compatible database (provide a CA certificate separately when required)

---

## Local Setup

Copy `.env.example` to `.env`, configure the database and application settings,
then start the PHP backend from the repository root:

```powershell
php -S 127.0.0.1:8000 -t public public/dev-router.php
```

Run the separately maintained frontend at `http://localhost:5173/` and
configure its Vite `/api` proxy to target `http://127.0.0.1:8000`. Set
`VITE_API_PROXY_TARGET` in the frontend's local environment when the backend
uses a different address. In production, configure the frontend's API base URL
and the backend's `API_ALLOWED_ORIGINS` for the deployed frontend origin.

### KalingaLink CDC database

The KalingaLink UI uses the existing LavaLust PHP backend and MySQL. Copy
`.env.example` to `.env`, set the database connection values, and replace both
token secrets with independently generated random values. Remote MySQL
connections require the Aiven CA certificate. For Render, add the certificate
as a Secret File named `ca.pem`; the backend detects it at
`/etc/secrets/ca.pem`. Do not add the certificate to the repository or Docker
image. The Docker image adds Apache's `www-data` user to group `1000`, which
Render uses to grant access to mounted secret files. For other hosts, set
`DB_SSL_CA` to the certificate's absolute path.
Never deploy the example seed password or token secrets.

For hosted deployments, configure `DB_HOST`, `DB_USER`, `DB_NAME`,
`DB_PASSWORD`, `JWT_SECRET`, and `REFRESH_TOKEN_KEY` in the backend service's
environment (not only in the frontend build). Both token secrets must be at
least 32 characters. Database errors on API routes return JSON and write their
details to the backend's server error log; check that log when an API request
still returns HTTP 500.

Set `API_ALLOWED_ORIGINS` in the backend environment to a comma-separated list
of the exact browser origins that use the API (including scheme, host, and
port). Include the backend origin for same-origin deployments behind a
TLS-terminating proxy if the proxy's public scheme cannot be inferred. The
refresh cookie defaults to `SameSite=Lax`, which
works for same-site hosts such as sibling subdomains. For a frontend on a
different site, configure `REFRESH_COOKIE_SAMESITE=None` and serve the API over
HTTPS; `Secure` is enforced for `SameSite=None`. Set
`REFRESH_COOKIE_SECURE=1` for HTTPS production deployments, especially when
TLS terminates at a reverse proxy. With this setting left blank, cookies default
to Secure in production and non-Secure in development. Refresh cookies are
HttpOnly; the login form's “Remember me” option controls whether the cookie is
persistent or session-only. Access tokens remain in frontend memory and are
tied to their database-backed refresh session, so logout and refresh-token
rotation invalidate previous access tokens immediately. Login rate limits are
isolated by the request source address (`REMOTE_ADDR`); deployments behind
proxies must configure the web server to preserve the actual client address.
Login, registration, refresh, and logout reject
browser requests whose `Origin` is not an allowed frontend origin or the
backend's same origin. Requests without an `Origin` header (for example,
server-to-server clients) remain supported. The frontend never receives
database credentials or token-signing secrets.

### Google Sign-In

Google Sign-In uses a server-side OAuth authorization-code flow with state and
PKCE validation. It matches only a verified Google email to an existing
KalingaLink account; it does not create accounts or grant roles. Configure
these values in the private backend `.env` using a Google OAuth **Web
application** client:

```text
GOOGLE_OAUTH_CLIENT_ID=your-web-client-id
GOOGLE_OAUTH_CLIENT_SECRET=your-web-client-secret
GOOGLE_OAUTH_REDIRECT_URI=http://127.0.0.1:8000/api/auth/google/callback
GOOGLE_OAUTH_FRONTEND_URL=http://127.0.0.1:5173/login
```

In Google Cloud Console, configure the OAuth consent screen and create a Web
application OAuth client. Add the exact value of
`GOOGLE_OAUTH_REDIRECT_URI` to **Authorized redirect URIs**. For a local
development browser, use the same host (`127.0.0.1` or `localhost`) for the
frontend URL, callback URL, and backend proxy/API origin; add the frontend
origin to **Authorized JavaScript origins** if requested by the Console.
Use HTTPS URLs in production and configure the public API callback URL as the
authorized redirect URI. Keep the client secret only in the backend
environment, never in Vite environment variables or frontend source. No
database migration is needed. The callback creates the existing refresh-token
session, and the normal `/api/refresh` flow supplies the access token to the
frontend.

Create the database once in Navicat (or another MySQL client) by running
[`database/create_kalingalink.sql`](./database/create_kalingalink.sql):

```sql
CREATE DATABASE IF NOT EXISTS kalingalink
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

Set the database host, port, username, password, name, and, when needed, the
path to a separately provided CA certificate in the local `.env`. Keep `.env`
and certificate files private; never commit or share their real values.

With MySQL running and `.env` configured, apply the schema:

```powershell
php console/cli.php db:migrate
```

`MIGRATIONS_ENABLED=1` is required for migrations; set it back to `0` after the
migration command. Seeding is refused when `APP_ENV=production` and requires
`DEMO_SEED_PASSWORD` to be at least 12 characters.

Migration 020 adds a nullable teacher assignment to enrollment applications.
Applications are automatically assigned only when their active CDC has exactly
one active teacher; applications with no unique teacher remain awaiting
assignment for an MSWDO Admin to route from Enrollment Management. Migration
021 notifies active admins about existing unassigned applications. Migration 022
separates a teacher's requested CDC from their official assignment, supports
enrollment transfers, and creates the teacher verification document metadata
table. Migration 025 restricts CDC names to Cacawan Centro Pamana CDC,
Marfrancisco CDC, and Papandayan Highway CDC, and synchronizes each CDC's
active/inactive status with its approved, active teacher assignment. It stops
without deleting data if other CDC names already exist; resolve those records
and their related applications before retrying the migration. The API also
limits CDC creation to those names and derives status from teacher assignments.
The migration does not create missing CDC rows; add any missing center through
the API using verified location details.

The central notification migration adds notification categories, related
modules, recipient-criteria snapshots, and idempotent publish keys. Notification
recipient records are assigned when a notification is published; each
recipient's read state is stored independently. The current `admin` role is the
administrator-wide sender role, while teachers are restricted to parents and
centers in their active teacher assignment.

Teacher registration starts in a pending state and teacher access stays
disabled until an administrator's approval message is successfully sent and
accepted by the configured mail provider. The email includes the teacher's
username, but never the password; teachers use the password they confirmed
during registration. There is no invitation-acceptance step or activation
link. If delivery fails, the account remains disabled until an administrator
sends the message successfully. Rejection remarks are emailed to the teacher. Migration
`026_teacher_invitation_acceptance.php` creates the teacher verification
workflow and email-delivery tracking tables, while preserving already-active
teacher accounts.

Set `MAIL_PORT=587` and `MAIL_ENCRYPTION=tls`. For Brevo, set
`MAIL_HOST=smtp-relay.brevo.com`, `MAIL_USERNAME` to the SMTP login shown in
Brevo, and `MAIL_PASSWORD` to a Brevo SMTP key (not an API key or account
password). For Gmail, use `MAIL_HOST=smtp.gmail.com`, the Gmail address as
`MAIL_USERNAME`, and a Google App Password as `MAIL_PASSWORD`. Set
`MAIL_FROM_ADDRESS` to a sender verified with the selected provider.
Never commit mail credentials. A successful SMTP submission means the
provider accepted the message; it does not guarantee inbox delivery.

Public teacher registration accepts up to five optional PDF, JPG, or PNG
supporting documents (10 MB each, 30 MB total). Files are stored under the
private `runtime/uploads/teacher-verification/` directory, and only
authenticated admins can download them from Teacher Management. Ensure PHP's
`upload_max_filesize` is at least 10 MB, `post_max_size` is at least 31 MB, and
the backend process can write to `runtime/uploads/`.

Only seed a disposable development database, never a production or shared database:

```powershell
php console/cli.php db:seed
```

The schema includes role-bearing users, parent/teacher profiles, CDCs/classes,
children, enrollment applications/documents/status history, student and teacher
attendance, learning programs/progress, lesson plans/submissions, announcements,
notifications/recipients, settings, and audit logs. API integration is
implemented per module; review the matching React page and LavaLust routes
before treating any individual workflow as fully verified.

### Persistence requirement

Every account-creation and CRUD workflow must write changes through the backend
to the configured database. The frontend must not treat local state or sample
data as a successful create, update, or delete; it should reflect the persisted
API response and show errors when a database operation fails. New or revised
CRUD screens must use this rule, and existing preview-only screens must be
connected to database-backed API workflows before being treated as complete.

Configure the web server to point to the project `public/` directory and enable
Apache `mod_rewrite` or its equivalent.

---

## Project Structure

```
kalingalink/
├── app/
│   ├── config/          # Database, routes, API, and framework configuration
│   ├── controllers/     # KalingaLink API and frontend controllers
│   ├── migrations/      # Versioned schema history
│   ├── models/          # Shared application models
│   └── libraries/       # Custom LavaLust libraries
├── scheme/              # LavaLust framework
└── runtime/             # Private uploads, cache, and logs
```

The React application and LavaLust backend are separate deployable components
in this repository. React handles rendering and API calls; LavaLust owns
authentication, role authorization, validation, workflows, uploads, and
database access. Existing backend API routes remain registered in
`app/config/routes.php`. No browser code connects directly to MySQL.

---

## Configuration

### Database

Database values are read from `.env` by
[`app/config/database.php`](./app/config/database.php). Do not add credentials
to source code or commit `.env`.

### Base URL

The application routes the KalingaLink frontend under `/app/`; `/` redirects
there. The configured base URL is in `app/config/config.php`.

---

## API

The frontend uses the LavaLust API endpoints registered in
[`app/config/routes.php`](./app/config/routes.php):

- `POST /api/login`, `POST /api/refresh`, `POST /api/logout`
- `POST /api/register/parent`, `POST /api/register/teacher`
- `GET /api/me`
- Admin-only user management: `GET /api/admin/users`, `GET /api/admin/users/{id}`,
  `POST /api/admin/users`, `PUT /api/admin/users/{id}`,
  `PATCH /api/admin/users/{id}/status`, and `DELETE /api/admin/users/{id}`
- `GET /api/cdcs`
- `GET /api/enrollments`, `POST /api/enrollments`
- `PATCH /api/enrollments/{id}/status`
- `GET /api/enrollments/{id}/documents/{document_id}`
- Events for authenticated admins, teachers, and parents:
  `GET /api/events`, `GET /api/events/{id}`, admin/teacher `POST /api/events`,
  `PUT /api/events/{id}`, and `DELETE /api/events/{id}`. Shared album routes
  support `POST /api/events/{id}/album`, `POST /api/events/{id}/photos`,
  `PATCH`/`DELETE /api/events/photos/{id}`, and authenticated image reads at
  `/api/events/photos/{id}/image`. Event announcements use
  `GET /api/events/notifications`, `GET /api/events/notifications/count`,
  `PATCH /api/events/notifications/read`, and
  `PATCH /api/events/notifications/read-all`.

User management is restricted to authenticated active `admin` accounts and
only manages `parent` and `teacher` accounts. Account deletion archives by
deactivating the account and revoking its refresh tokens. Account role changes
and management of privileged account roles are not supported by these routes.

---

## Documentation

Full documentation is available at **[https://lavalust.netlify.app](https://lavalust.netlify.app)**

Topics covered include:

- Installation and server configuration
- Routing: static, dynamic, and grouped routes
- Controllers and request handling
- Models and query builder
- Views, layouts, and partials
- Built-in libraries (sessions, form validation, file upload)
- Helper functions
- REST API development
- Security best practices

---

## License

LavaLust Framework is open-source software licensed under the **[MIT License](https://opensource.org/licenses/MIT)**.

---

## LavaLust Documentation

See the [official LavaLust documentation](https://lavalust.netlify.app) for
framework-specific conventions and APIs.
