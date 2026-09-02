# MangroScan RBAC Seeder Matrix

This is the developer reference for the deterministic RBAC data installed by `DatabaseSeeder`. The four primary roles are organization-scoped system roles in the seeded MangroScan Development Organization. System Administrator is deliberately not a universal superuser: field and scientific permissions remain assigned to the responsible domain roles. Drone Operator is deliberately limited to assigned, approved field/mobile work.

`Validate Role and Permission` is implemented by authentication, effective-access resolution, and permission middleware. `Record System Activity` is an automatic audit side effect. Neither is a seeded permission.

## Permission matrix

| Permission | System Administrator | Researcher | Environmental Specialist | Drone Operator | Used by endpoint IDs |
| --- | :---: | :---: | :---: | :---: | --- |
| `organizations.manage` | Yes | — | — | — | ORG-01..04 |
| `users.manage` | Yes | — | — | — | USR-01..05 |
| `roles.manage` | Yes | — | — | — | RBAC-01, RBAC-03, RBAC-04, RBAC-05 |
| `permissions.manage` | Yes | — | — | — | RBAC-02, RBAC-04, RBAC-05 |
| `sites.read` | — | Yes | — | Yes | SITE-01, SITE-03, BOUND-01, PLOT-01, SYNC-03 |
| `sites.manage` | — | Yes | — | — | SITE-02, SITE-04 |
| `boundaries.manage` | — | Yes | — | — | BOUND-02, BOUND-03 |
| `plots.manage` | — | Yes | — | — | PLOT-02 |
| `site_permissions.manage` | — | Yes | — | — | PERMIT-01, PERMIT-02 (P2) |
| `missions.read` | — | Yes | Yes | Yes | MSN-01, MSN-03, SYNC-02, SYNC-03 |
| `missions.create` | — | Yes | — | — | MSN-02 |
| `missions.update` | — | Yes | — | — | MSN-04, MSN-07 |
| `missions.approve` | — | — | Yes | — | MSN-06 |
| `missions.complete` | — | Yes | — | — | MSN-08 |
| `mission_team.manage` | — | Yes | — | — | TEAM-01 |
| `flights.read` | — | Yes | Yes | Yes | FLT-01, FLT-03, SYNC-02, SYNC-03 |
| `flights.create` | — | Yes | — | — | FLT-02 |
| `flights.update` | — | Yes | — | — | FLT-04, WPT-01 |
| `flights.start` | — | Yes | — | Yes | FLT-05 |
| `flights.complete` | — | Yes | — | Yes | FLT-06, FLT-07 |
| `checklists.submit` | — | Yes | — | Yes | CHK-01 |
| `media.read` | — | Yes | Yes | Yes | MEDIA-01, MEDIA-04 |
| `media.upload` | — | Yes | — | Yes | MEDIA-02, MEDIA-03, SDS-01, SDS-02 |
| `media.quality_review` | — | — | Yes | — | MEDIA-06 |
| `media.delete` | — | — | — | — | MEDIA-07 (P2) |
| `ai_services.manage` | Yes | — | — | — | AISVC-01..04 |
| `ai_models.read` | Yes | Yes | — | — | MODEL-01, MODEL-02 |
| `ai_models.manage` | Yes | — | — | — | MODEL-03 (P2) |
| `processing_jobs.create` | — | Yes | — | — | JOB-02, JOB-04 |
| `processing_jobs.manage` | — | Yes | — | — | JOB-01, JOB-03 |
| `results.read` | — | Yes | Yes | — | TREE-01..03, COUNT-01, RESULT-01..03, LAYER-01, DASH-01/02; CONF-01 testing |
| `results.export` | — | Yes | Yes | — | EXP-01 |
| `maps.read` | — | Yes | Yes | — | LAYER-01/02 (planned map-specific enforcement) |
| `validation.read` | — | Yes | Yes | — | VAL-01, VAL-02, VAL-04 |
| `validation.create` | — | Yes | Yes | — | VAL-03 |
| `validation.record_ground_truth` | — | Yes | Yes | — | GT-01 |
| `validation.decide` | — | Yes | Yes | — | CONF-02, MATCH-01 |
| `validation.complete` | — | Yes | Yes | — | VAL-05 |
| `accuracy.recompute` | — | — | Yes | — | ACC-01 |
| `reports.read` | — | Yes | Yes | — | RPT-01, RPT-03 |
| `reports.create` | — | Yes | Yes | — | RPT-02, RPT-04 |
| `reports.generate` | — | Yes | Yes | — | RPT-05, EXP-01 |
| `reports.approve` | — | — | Yes | — | RPT-06 |
| `exports.download` | — | Yes | Yes | — | EXP-02, EXP-03 |
| `settings.manage` | Yes | — | — | — | SET-01, SET-02 (P2) |
| `audit.read` | Yes | — | — | — | AUD-01 |
| `notifications.read` | Yes | Yes | Yes | Yes | NOTIF-01..03 |
| `drones.read` | Yes | Yes | — | Yes | DRONE-01, DRONE-03 |
| `drones.manage` | Yes | — | — | — | DRONE-02; DRONE-04 (P2) |
| `sensors.manage` | Yes | — | — | — | SENSOR-01; SENSOR-02 (P2) |
| `sensor_calibrations.manage` | Yes | — | — | — | CAL-01 (P2) |
| `batteries.read` | — | Yes | — | Yes | BAT-01, BAT-03 (P2) |
| `species.manage` | — | — | Yes | — | No endpoint ID currently exists |
| `share_links.manage` | — | Yes | — | — | No endpoint ID currently exists |

The tracker defines mission approval as MSN-06 and assigns it `missions.approve`. It does not currently define a flight-approval endpoint or permission, so no fabricated `flights.approve` permission is seeded. This should be revisited if the tracker adds that workflow.

Hardware authorization was added as a focused correction to the earlier active-auth-only DRONE-01/02/03 and SENSOR-01 implementation. Reads require `drones.read`; drone registration requires `drones.manage`; sensor registration requires `sensors.manage`. CAL-01 and battery endpoints remain backlog items, but their non-duplicative permissions are seeded now for the required role use cases.

## Deterministic developer accounts

| Email | Role | Password source |
| --- | --- | --- |
| `admin@mangroscan.test` | System Administrator | `MANGROSCAN_SEED_USER_PASSWORD` |
| `researcher@mangroscan.test` | Researcher | `MANGROSCAN_SEED_USER_PASSWORD` |
| `specialist@mangroscan.test` | Environmental Specialist | `MANGROSCAN_SEED_USER_PASSWORD` |
| `operator@mangroscan.test` | Drone Operator | `MANGROSCAN_SEED_USER_PASSWORD` |

All four accounts are active, email-verified, and belong to `MangroScan Development Organization`. The password is hashed through Laravel and is never logged or stored in plaintext. Re-running the seeders updates the deterministic records and synchronizes each role's exact permission set without creating duplicates.

Drone Operator mission visibility is limited to approved missions where the user is an assigned mission-team member or pilot. Its flight and media access is further limited to flights whose `pilot_user_id` is the operator. This is enforced by server-side role and assignment scoping; no user-agent, browser, or client-supplied role value is trusted.

## Local and testing workflow

Set a local-only password in the uncommitted `.env`:

```env
MANGROSCAN_SEED_USER_PASSWORD="choose-a-local-development-password"
```

Then migrate and seed:

```bash
php artisan migrate
php artisan db:seed
php artisan mangroscan:qa-users:verify
```

The verification command is read-only. It checks the configured password against each stored hash and verifies the active organization, email verification, exact role, and exact effective permission set without printing credentials. Use the identities for browser QA as follows:

| Identity | Browser-QA focus |
| --- | --- |
| `admin@mangroscan.test` | Organizations, users, roles, permissions, hardware, AI administration, settings, and audit views |
| `researcher@mangroscan.test` | Sites, mission planning, flights, processing, results, validation work, reports, and exports |
| `specialist@mangroscan.test` | Mission approval, scientific validation, accuracy recomputation, report generation, and report approval |
| `operator@mangroscan.test` | Assigned approved mission/flight, checklist, field media, and notification flows |

Before resetting tests, verify that `.env.testing` names the disposable test database (normally `mangroscan_test`) and defines a testing-only seed password. Confirm the resolved values:

```bash
php artisan tinker --env=testing
```

```php
config('app.env');
config('database.connections.pgsql.database');
```

Only after confirming `testing` and the dedicated test database, run:

```bash
php artisan migrate:fresh --seed --env=testing
php artisan test
```

Run `tests/Feature/Rbac/RbacSeederTest.php` for focused idempotency, effective-permission, login, positive/negative authorization, production-guard, and tenant-isolation coverage.

The developer-user seeder returns immediately in `production`. `MANGROSCAN_SEED_USER_PASSWORD` must be non-empty in local/testing environments; otherwise seeding fails loudly before any developer account is created.
