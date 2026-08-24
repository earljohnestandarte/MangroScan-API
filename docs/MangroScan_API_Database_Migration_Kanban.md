**MANGROSCAN**

**API & Database Migration**  
**Kanban Blueprint**

Migration from direct Supabase access to a dedicated PHP API layer and self-managed relational database

| Recommended baseline Laravel API layer \+ PostgreSQL/PostGIS \+ private object storage \+ queue worker \+ existing FastAPI inference service. CodeIgniter 4 and MySQL remain viable alternatives, but the supplied schema already uses PostgreSQL/PostGIS types and GIS indexes, making PostgreSQL the lower-friction database target. |
| :---- |

| Document purpose | Implementation backlog, endpoint contract, dependency map, database objects, DCL, migration and acceptance plan |
| :---- | :---- |
| **Source basis** | Capstone Code-Blooded (1).md \+ MangroScan-Flowchart-Grid.md \+ current MangroScan application workflow |
| **Initial kanban state** | Ready / Blocked / Backlog are planning states; assignees are role placeholders until team names are entered |
| **Prepared** | 2026-08-10 |

## **Pulled-contribution verification — 2026-08-24**

The `feat(BAT-01)` contribution is attributed to **Jessamae Sumanoy** (commit `3cb4b67`; matching author email). The subsequent Jason endpoint/workflow commits through `74fffa2` are attributed to **Jason Benabente** by their Git author metadata.

At the time of that review, neither contribution set was approved as `Done`: Jason's 18 focused SQLite tests passed with 130 assertions, while PostgreSQL was blocked by the test role's missing schema privilege. Jason's implemented P2 endpoints plus `LAYER-02` and `CONF-01/02` remain `Testing` under that review. ACC-01 and VAL-05 have since passed the completed MATCH-01 handoff, dedicated cross-database matrices, full-suite verification, corrective least-privilege DCL, and the fresh-six-metric protocol; both are now `Done`.

BAT-01 is `Working`: its route exists, but it has no endpoint feature test or endpoint DCL, its migration diverges from the authoritative `battery_packs` schema, and its PHP files fail Pint. The duplicate DCL sequence number `046` in Jason's changes and the incomplete per-endpoint negative/error test matrix must also be resolved before promotion.

**Decision principle**

No web or mobile client should connect directly to the production database or hold the FastAPI service key. The PHP API becomes the application security and transaction boundary.

# **1\. Executive decision and migration scope**

The supplied schema is a modular geospatial-AI monitoring database that preserves the evidence chain from site and mission through captured data, model execution, tree results, field validation, and report/export. The workflow is organized into seven operational segments: authentication, site/mission setup, flight operations, upload/QC, AI processing/mapping, validation/accuracy, and reporting/output.

The target architecture replaces Supabase-specific client access with a dedicated REST API. Supabase Auth, direct table queries, storage calls, and Edge Function-style server logic must be mapped to explicit API endpoints, server-side authorization, database migrations, private file storage, queues, and auditable service integrations.

## **1.1 Stack recommendation**

| Choice | Recommendation | Why for MangroScan | Trade-off |
| :---- | :---- | :---- | :---- |
| API framework | Laravel | Strong fit for a large modular REST API with policies, migrations, filesystem abstraction, background jobs/queues, validation, testing and service classes. | More framework surface area than CodeIgniter. |
| Alternative API | CodeIgniter 4 | Good lightweight REST option; official authentication/authorization and queue packages exist. | More application conventions must be designed by the team for a project this broad. |
| Database | PostgreSQL \+ PostGIS | Matches supplied GEOMETRY(Point/Polygon, 4326), JSONB and GiST spatial-index design; best continuity from the supplied schema. | Requires PostGIS-capable hosting. |
| Alternative DB | MySQL | Can support POINT/POLYGON, SRID 4326, JSON and spatial indexes. | Schema translation is required: JSONB, GiST, UUID defaults, timestamp semantics and PostgreSQL routines/RLS differ. |
| Large file storage | Private S3/MinIO or server object storage | Images, videos, LiDAR, report files and model artifacts stay outside relational rows; DB stores file metadata/path. | Requires backup/lifecycle policy separate from DB. |
| AI execution | Existing FastAPI remains separate | PHP API owns authentication, job state and persistence; FastAPI remains inference-only and receives calls from trusted server jobs. | Requires queue/worker and service-to-service observability. |

## **1.2 Supabase replacement map**

| Current Supabase responsibility | Target replacement | Owner |
| :---- | :---- | :---- |
| Auth | PHP API authentication endpoints \+ password hashing \+ token/session storage \+ RBAC policies | Backend/Security |
| Database client from React / Expo | REST API resource endpoints; no direct DB credentials in clients | Backend/API |
| Row Level Security | API authorization \+ organization scoping; optional PostgreSQL RLS as defense-in-depth | Backend \+ DB |
| Storage | Private object storage or server filesystem; signed/authorized download endpoints | Backend/DevOps |
| Edge/server functions | Service classes \+ transactional application services \+ queued jobs | Backend/API |
| Realtime updates | Durable notification table \+ polling first; SSE/WebSocket only if needed later | Backend |
| Signed file URLs | API-authorized temporary object URL or streamed download | Backend/Storage |

| Migration guardrail Do not rewrite the FastAPI inference service into PHP. The API layer should orchestrate inference, keep X-API-Key server-side, persist processing state, normalize failures, and store canonical results. |
| :---- |

# **2\. Target architecture**

| React Web                     Expo Mobile (offline-first)    |                                  |    \+------------ HTTPS / JSON \--------+                       |                 /api/v1 PHP API        Auth | RBAC | Validation | Transactions             |        |            |             |        |            \+--\> PostgreSQL \+ PostGIS             |        |                    views / routines / DCL             |        |             |        \+--\> Private object storage (images, video, exports)             |             \+--\> Queue / worker \--\> FastAPI inference service                                  (server-only X-API-Key)                                         |                                         \+--\> detector / classifier / pipelineCross-cutting: audit logs • notifications • request IDs • retries • metrics • backups |
| :---- |

## **2.1 Non-negotiable boundaries**

* Web and mobile clients never receive database credentials or the FastAPI API key.  
* Every protected request is authenticated and authorized server-side; UI permission checks are convenience only.  
* Every organization-scoped query must include the authenticated user's organization boundary unless the caller is a system administrator with an explicit cross-organization permission.  
* Processing and report generation are asynchronous when work may exceed a normal HTTP request timeout; return 202 Accepted with a job resource.  
* Images, videos, LiDAR, model files and generated exports are stored outside the relational database; only metadata and paths/keys belong in tables.  
* Audit logging must be append-only from normal application roles.

# **3\. API contract standards**

| Rule | Standard |
| :---- | :---- |
| Base path | /api/v1 |
| Content | application/json; multipart only for upload transport if direct object-upload URLs are not used |
| Authentication | Bearer token for mobile/API calls; secure cookie session is optional for same-origin web if the team prefers |
| IDs | UUID strings in API payloads |
| Dates | ISO-8601 UTC timestamps; date-only values use YYYY-MM-DD |
| Geometry | GeoJSON objects at API boundary; convert to/from PostGIS geometry in repository/service layer |
| Pagination | page, per\_page (max 100); meta includes page, per\_page, total, last\_page |
| Filtering | Explicit query parameters; do not accept arbitrary SQL/order expressions |
| Idempotency | Require Idempotency-Key for upload completion, mobile sync, processing-job creation and report generation |
| Request trace | Return X-Request-ID; write it to application logs and processing/audit records where relevant |
| Versioning | Backward-compatible changes within /v1; breaking change requires /v2 |

## **3.1 Standard success envelope**

| {  "data": { ... },  "meta": {    "request\_id": "req\_..."  }} |
| :---- |

## **3.2 Paginated response**

| {  "data": \[ ... \],  "meta": {    "page": 1,    "per\_page": 25,    "total": 143,    "last\_page": 6,    "request\_id": "req\_..."  }} |
| :---- |

## **3.3 Standard error envelope**

| {  "error": {    "code": "VALIDATION\_FAILED",    "message": "The request contains invalid fields.",    "details": {      "mission\_id": \["The selected mission does not exist."\]    },    "request\_id": "req\_..."  }} |
| :---- |

| HTTP | Use |
| :---- | :---- |
| 200 | Successful read/update/action with response body |
| 201 | Resource created |
| 202 | Accepted for asynchronous work |
| 204 | Successful delete/archive/action with no body |
| 400 | Malformed request or invalid state transition |
| 401 | Unauthenticated / expired credential |
| 403 | Authenticated but not permitted |
| 404 | Resource not found inside caller's authorized scope |
| 409 | Conflict, duplicate, stale version, idempotency conflict or sync conflict |
| 422 | Field validation error |
| 429 | Rate limited |
| 500 | Unexpected server error |
| 502/503 | FastAPI/object storage/downstream dependency unavailable |

# **4\. Dependency hierarchy and critical path**

| P0 FOUNDATIONSYS/DB \-\> AUTH/RBAC \-\> SITE \-\> MISSION \-\> FLIGHT/CHECKLIST                                     |          |                                     |          \+-\> MOBILE SYNC                                     |          \+-\> MEDIA/SENSOR UPLOAD \-\> QC                                     |                                  |                                     \+-\> AI MODEL/SERVICE \--------------+-\> PROCESSING JOB                                                                            |                                                                            \-\> TREE RESULTS / MAP                                                                                  |                                                                                  \-\> VALIDATION / ACCURACY                                                                                         |                                                                                         \-\> REPORT / EXPORTCross-cutting after AUTH: audit, notifications, organization scoping, file authorization.Dashboard reads become stable only after canonical result and validation contracts are stable. |
| :---- |

| Stage | Must exist first | Unlocks | Release gate |
| :---- | :---- | :---- | :---- |
| 0\. Platform | DB connection, migrations, environment, storage, queue | All API modules | Health checks \+ migration rollback tested |
| 1\. Identity | Platform | Every protected endpoint | Login, /me, permissions, org isolation tests pass |
| 2\. Site/Mission | Identity | Flight planning | CRUD \+ mission approval/state rules pass |
| 3\. Flight/Mobile | Site/Mission \+ drones | Capture/upload | Preflight/start/complete \+ offline bundle/sync pass |
| 4\. Media/AI | Flight \+ storage \+ model/service registry | Tree results | Upload resume \+ worker retry \+ result persistence pass |
| 5\. Validation | Results | Accuracy/finalization | Ground truth \+ decisions \+ metrics pass |
| 6\. Reporting | Final/validated results | Exports/dashboard | Report/export audit and download authorization pass |
| 7\. Cutover | All P0 endpoints | Supabase retirement | Data reconciliation \+ client smoke tests \+ rollback plan |

# **5\. Kanban conventions**

| Priority | Meaning | Planning status at start |
| :---- | :---- | :---- |
| **P0** | Critical path; needed before Supabase can be removed | **Ready if dependencies are platform-only; otherwise Blocked** |
| **P1** | Core production capability; should ship with migration | **Blocked until P0 dependency is ready** |
| **P2** | Important administration/operational completeness | Backlog |
| **P3** | Future or optimization | Backlog |

Assigned-to values are role placeholders. Replace 'TBD \- ...' with actual team member names in your project board. Statuses are intentionally conservative and do not claim implementation progress.

# **6\. Detailed API endpoint kanban**

Each row is an implementation card. Request/response fields are intentionally explicit but compact; the common response and error envelopes in Section 3 apply to every endpoint. Resource reads must be organization-scoped and soft-deleted rows excluded unless an administrator explicitly requests archived data.

## **Platform & authentication**

Authentication infrastructure uses Laravel Sanctum 4.x, Laravel's first-party token guard for SPA and mobile clients. `personal_access_tokens` stores only SHA-256 token hashes, uses UUID keys and UUID polymorphic user references, supports per-token expiry, and never exposes stored token material. The custom token model is registered in `AppServiceProvider`; protected endpoint groups use the `auth:sanctum` guard. Environment configuration may set a non-secret token prefix for automated secret scanning, but raw access tokens remain one-time response values.

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| SYS-01 | GET /healthLiveness/readiness for API, DB, storage and queue. | No body | 200 {status,db,storage,queue,time} | DB config | **P0** | TBD \- DevOps/API | **Ready** |
| SYS-02 | GET /meta/capabilitiesClient feature flags and API capability discovery. | No body | 200 {api\_version,features,limits} | SYS-01 | **P1** | TBD \- Backend/API | **Blocked** |
| AUTH-01 | POST /auth/loginAuthenticate web/mobile user. | {email,password,device\_name?} | 200 {user,access\_token,expires\_at,roles,permissions} | users \+ RBAC | **P0** | TBD \- Backend/Security | **Ready** |
| AUTH-02 | GET /auth/meReturn authenticated profile and effective access. | Bearer token | 200 {user,organization,roles,permissions} | AUTH-01 | **P0** | TBD \- Backend/Security | **Blocked** |
| AUTH-03 | POST /auth/logoutRevoke current token/session. | Bearer token | 204 | AUTH-01 | **P0** | TBD \- Backend/Security | **Blocked** |
| AUTH-04 | POST /auth/refreshRotate expiring mobile access credential when refresh-token design is used. | {refresh\_token} | 200 {access\_token,expires\_at,refresh\_token?} | AUTH-01 | **P1** | TBD \- Backend/Security | **Blocked** |
| AUTH-05 | PUT /auth/passwordAuthenticated password change. | {current\_password,new\_password,new\_password\_confirmation} | 204 | AUTH-01 | **P1** | Codex \- Backend/Security | **Done** |
| AUTH-06 | POST /auth/password/forgotIssue password-reset workflow. | {email} | 202 {message} | users \+ mail config | **P1** | Codex \- Backend/Security | **Done** |
| AUTH-07 | POST /auth/password/resetComplete password reset. | {token,email,password,password\_confirmation} | 204 | AUTH-06 | **P1** | Codex \- Backend/Security | **Done** |
| AUTH-08 | GET /auth/permissionsLightweight permission refresh for UI. | No body | 200 {roles:\[...\],permissions:\[...\]} | AUTH-02 | **P1** | TBD \- Backend/Security | **Blocked** |

### **SYS-01 — GET /api/v1/health**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | SYS-01 / P0 |
| Purpose | Public liveness/readiness report for the API database, default private storage disk and queue connection. |
| Required permission | None; this is an unauthenticated platform probe. |
| Dependencies | Laravel database, filesystem and queue configuration. |
| Request / validation | No body. An optional valid `X-Request-ID` is echoed; otherwise the API generates one. |
| Success | `200` standard envelope containing `status`, `db`, `storage`, `queue` and ISO-8601 UTC `time`; each dependency is `ok`. |
| Errors | `503 SERVICE_UNAVAILABLE` standard error envelope when any required dependency cannot be reached; unexpected framework errors remain `500`. |
| Workflow / tenant scope | No organization data is queried or returned. |
| Side effects / audit / notifications | Read-only probe; no audit event or notification is created. |
| Tests | `tests/Feature/Platform/HealthTest.php` covers success shape/status, dependency failure, request-ID traceability and API versioning. |
| Implementation status | Done. |

### **SYS-02 — GET /api/v1/meta/capabilities**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | SYS-02 / P1 |
| Purpose | Public discovery of the API version, enabled client-facing platform features and stable API limits. |
| Required permission | None; clients may inspect compatibility before login. |
| Dependencies | SYS-01 readiness checks must pass. |
| Request / validation | No body. An optional valid `X-Request-ID` is echoed; otherwise the API generates one. |
| Success | `200` standard envelope with `api_version: v1`, `features: {health_checks: true, request_ids: true, token_authentication: true}` and `limits: {pagination_per_page_max: 100}`. |
| Errors | `503 SERVICE_UNAVAILABLE` when a SYS-01 dependency is unavailable; unexpected framework/configuration errors remain `500`. |
| Workflow / tenant scope | No authentication or organization data is required or returned. |
| Side effects / audit / notifications | Read-only platform metadata; no audit event or notification is created. |
| Tests | `tests/Feature/Platform/MetaCapabilitiesTest.php` covers the exact success payload, request ID and dependency failure. |
| Implementation status | Done. |

### **AUTH-01 — POST /api/v1/auth/login**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AUTH-01 / P0 |
| Purpose | Authenticate an active web/mobile user and issue an expiring Sanctum Bearer token. |
| Required permission | None; this is the public credential-exchange endpoint. |
| Dependencies | Organizations, users, roles, permissions, role/user joins, UUID Sanctum tokens and immutable audit storage. |
| Request / validation | Required normalized `email` and `password`; optional nullable `device_name` up to 100 characters. |
| Success | `200` standard envelope containing the exact documented `user`, `access_token`, `expires_at`, `roles` and `permissions` fields. The user projection contains only `user_id`, `organization_id`, `first_name`, `last_name` and `email`. |
| Errors | `401 INVALID_CREDENTIALS` for unknown, inactive, deleted or password-mismatched users; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected persistence failures remain `500`. |
| Workflow / tenant scope | Role and effective-permission output includes global roles and roles belonging to the authenticated user's organization; foreign-organization role assignments are ignored. |
| Side effects | Creates one hashed UUID-backed token. Token and mandatory audit insertion share a transaction and roll back together. |
| Audit / notifications | Writes `auth.login` or `auth.failed` with request ID, IP, user agent and a one-way email hash. Passwords and token material are sanitized and never stored. No notification is required. |
| Tests | `tests/Feature/Auth/LoginTest.php` covers exact success shape/status, RBAC projection, tenant isolation, hashed token persistence, inactive/invalid credentials, validation, rate limiting, audit safety and transaction rollback. |
| Implementation compatibility | Uses the existing physical `password` and `status` columns without renaming or deleting identity fields; the public contract remains aligned with Section 7.1. |
| Implementation status | Done — the endpoint passes the complete SQLite suite and the complete PostgreSQL 18/PostGIS suite against an isolated `mangroscan_test` database. |

### **AUTH-02 — GET /api/v1/auth/me**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AUTH-02 / P0 |
| Purpose | Refresh the authenticated web/mobile caller's safe profile, organization metadata and effective access. |
| Required permission | Any valid Sanctum Bearer token belonging to an active user in an active organization. |
| Dependencies | AUTH-01, UUID Sanctum tokens, organizations, users, roles, permissions and role/user joins. |
| Request / validation | No body. The opaque token is supplied through `Authorization: Bearer ...`. |
| Success | `200` standard envelope containing `user`, `organization`, sorted tenant-scoped `roles`, sorted effective `permissions`, and request ID metadata. Passwords, token material and internal timestamps are never projected. |
| Errors | `401 UNAUTHENTICATED` for missing, invalid, expired or orphaned tokens; `403 ACCOUNT_INACTIVE` for inactive users or organizations; `429 RATE_LIMITED`; unexpected persistence failures remain `500`. |
| Workflow / tenant scope | Includes global roles and roles owned by the authenticated user's organization. Foreign-organization role assignments and their permissions are excluded. |
| Side effects / audit / notifications | Read-only identity refresh. Sanctum may update the token's `last_used_at`; no business audit event or notification is created. |
| Tests | `tests/Feature/Auth/AuthenticatedProfileTest.php` covers exact success shape, tenant isolation, last-use tracking, missing/invalid/expired/orphaned tokens, inactive user/organization state, no audit side effect and throttling. |
| Implementation compatibility | Uses the current identity columns and a shared effective-access service also consumed by AUTH-01, preventing role/permission projection drift. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

### **AUTH-03 — POST /api/v1/auth/logout**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AUTH-03 / P0 |
| Purpose | End the current web/mobile device session without affecting the user's other active devices. |
| Required permission | Any valid, unexpired Sanctum Bearer token. Inactive users or organizations may still revoke a valid token to reduce risk. |
| Dependencies | AUTH-01, UUID Sanctum tokens and immutable audit storage. |
| Request / validation | No body. The current opaque token is supplied through `Authorization: Bearer ...`. |
| Success | Empty `204 No Content`; no response envelope or token material is returned. |
| Errors | `401 UNAUTHENTICATED` for missing, invalid, expired or already-revoked tokens; `429 RATE_LIMITED`; audit/persistence failures remain `500` and preserve the token through rollback. |
| Workflow / tenant scope | Revokes only the presented token. Other device/browser tokens owned by the same user remain valid. No organization resource data is queried or exposed. |
| Side effects | Token deletion and audit insertion share one database transaction. If audit persistence fails, deletion rolls back. |
| Audit / notifications | Writes `auth.logout` with user ID, revoked token row ID, safe device name, revocation time, request ID, IP and user agent. Raw or hashed token material is never copied into audit JSON. No notification is required. |
| Tests | `tests/Feature/Auth/LogoutTest.php` covers exact 204 semantics, current-token-only revocation, follow-up token validity, standard 401/429 errors, inactive-identity logout, safe audit evidence and transactional rollback. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

### **AUTH-05 - PUT /api/v1/auth/password**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Allow an authenticated active user in an active organization to replace their own password. No RBAC permission is required beyond active Sanctum identity. |
| Request / validation | Requires the correct current password plus a confirmed new password of at least 12 characters containing mixed case, letters, numbers and symbols. Reuse of the submitted current password is rejected. Incorrect current credentials use field-level 422 without revealing stored hash details. |
| Success / security behavior | Returns exact empty `204 No Content`. The password is rehashed through Laravel and all of the user's active access credentials are revoked, requiring fresh login on every device. Password and token material never enter response or audit data. |
| Transaction / audit / DCL | Password update, credential revocation and immutable `auth.password.changed` evidence share one transaction; audit failure restores both old hash and tokens. Existing identity DCL already provides API UPDATE/DELETE while audit remains append-only. No notification is required. |
| Tests / status | `PasswordChangeTest` covers exact 204, new hash, all-token revocation, secret-free audit, incorrect current password, strength/confirmation, authentication, inactive identity, rollback, throttling and DCL. Done - full SQLite passes 271 tests / 1528 assertions and PostgreSQL 18/PostGIS passes 271 / 1535; route, Pint, live privilege and diff gates pass. |

### **AUTH-06 - POST /api/v1/auth/password/forgot**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / request | Accept one normalized email address and issue reset instructions only for an active, non-deleted user in an active organization. Unknown/inactive accounts use the documented generic resource 404 response. |
| Success / delivery | Returns `202` with exact message data and request metadata. Laravel's password broker stores only a slow-hashed reset token and sends the one-time raw token through the reset notification. A configurable `MANGROSCAN_WEB_URL` creates the existing web client's reset link without exposing the API host or secrets. |
| Throttling / workflow | The public credential limiter constrains abusive requests by normalized email/IP. The password repository separately prevents rapid valid-account reissue with documented 409 while retaining the original token. |
| Transaction / audit / DCL | Token creation, immutable `auth.password.reset.requested` evidence and notification dispatch execute in one database transaction; audit/mail failure rolls back persisted evidence. Audit contains delivery method only, never email token material. API alone receives reset-token CRUD; reporting and worker receive no access. |
| Tests / status | `PasswordForgotTest` covers exact 202, normalized active lookup, notification/reset URL, hashed persistence, 404s, duplicate 409, validation, rollback-before-delivery, rate limiting and DCL. Done - full SQLite passes 278 tests / 1567 assertions and PostgreSQL 18/PostGIS passes 278 / 1574; route, Pint, live privilege and diff gates pass. |

### **AUTH-07 - POST /api/v1/auth/password/reset**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / request | Complete AUTH-06 using required one-time token, normalized email, and a confirmed strong password. Unknown, deleted or inactive identities remain generic 404 resources; malformed/expired/consumed tokens use standardized documented 400. |
| Success / security behavior | Returns exact empty `204 No Content`. The token hash and expiry are verified under a row lock, the token is consumed, the password is rehashed, and every active access credential is revoked. A replay therefore fails even when submitted concurrently. |
| Transaction / audit / DCL | Password replacement, reset-token deletion, credential revocation and immutable `auth.password.reset.completed` evidence share one transaction. Audit failure restores all prior state and no secret/token/password material is recorded. AUTH-06 reset-token DCL plus existing identity DCL are reused. |
| Tests / status | `PasswordResetTest` covers exact 204, hash/token/session persistence, audit safety, invalid/consumed 400, unavailable 404, strength/confirmation, rollback, throttling and DCL. Done - full SQLite passes 285 tests / 1600 assertions and PostgreSQL 18/PostGIS passes 285 / 1607; route, Pint, live privilege and diff gates pass. |

### **AUTH-08 — GET /api/v1/auth/permissions**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AUTH-08 / P1; implemented now because it unlocks multiple P0 authorization dependencies. |
| Purpose | Provide a small role/permission refresh payload for web/mobile authorization state without repeating the full AUTH-02 profile. |
| Required permission | Any valid Sanctum Bearer token belonging to an active user in an active organization. |
| Dependencies | AUTH-02 and the shared effective-access service. |
| Request / validation | No body. The opaque token is supplied through `Authorization: Bearer ...`. |
| Success | `200` standard envelope containing sorted `roles`, sorted effective `permissions`, and request ID metadata. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE`; `429 RATE_LIMITED`; unexpected persistence failures remain `500`. |
| Workflow / tenant scope | Includes global roles and roles owned by the authenticated user's organization only. Foreign-organization assignments and permissions are excluded. |
| Side effects / audit / notifications | Read-only authorization refresh. Sanctum may update `last_used_at`; no business audit event or notification is created. |
| Tests | `tests/Feature/Auth/EffectivePermissionsTest.php` covers exact response shape, tenant isolation, authentication, inactive organization state, no audit side effect and throttling. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

## **Organizations, users and RBAC**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| ORG-01 | GET /organizationsList organizations for system admin. | Query: page,per\_page,search,status | 200 {data:\[Organization\],meta} | AUTH-08 | **P1** | Backend/API | **Done** |
| ORG-02 | POST /organizationsCreate tenant/owner organization. | {organization\_name,organization\_type,contact\_email?,contact\_number?,address?} | 201 {data:Organization} | ORG-01 | **P1** | Backend/API | **Done** |
| ORG-03 | GET /organizations/{id}Organization detail. | Path: id | 200 {data:Organization} | ORG-01 | **P1** | Backend/API | **Done** |
| ORG-04 | PATCH /organizations/{id}Update/archive organization metadata. | Partial Organization fields | 200 {data:Organization} | ORG-03 | **P1** | Backend/API | **Done** |
| USR-01 | GET /usersList users inside authorized org scope. | Query: org\_id?,role?,active?,search,page | 200 {data:\[User\],meta} | AUTH-08 | **P0** | TBD \- Backend/API | **Blocked** |
| USR-02 | POST /usersCreate managed user account. | {organization\_id,first\_name,last\_name,email,position\_title?,roles:\[role\_id\]} | 201 {data:User} | USR-01 \+ RBAC-01 | **P0** | TBD \- Backend/API | **Blocked** |
| USR-03 | GET /users/{id}User detail \+ roles. | Path: id | 200 {data:{user,roles}} | USR-01 | **P1** | TBD \- Backend/API | **Blocked** |
| USR-04 | PATCH /users/{id}Update profile/role-relevant account fields. | Partial user fields | 200 {data:User} | USR-03 | **P1** | Backend/API | **Done** |
| USR-05 | POST /users/{id}/activationActivate/deactivate account without hard delete. | {is\_active:boolean,reason?} | 200 {data:User} | USR-03 | **P1** | Backend/API | **Done** |
| RBAC-01 | GET /rolesList roles. | No body | 200 {data:\[Role\]} | AUTH-08 | **P0** | TBD \- Backend/Security | **Blocked** |
| RBAC-02 | GET /permissionsList permission catalog. | No body | 200 {data:\[Permission\]} | AUTH-08 | **P0** | TBD \- Backend/Security | **Blocked** |
| RBAC-03 | PUT /users/{id}/rolesReplace a user role assignment set. | {role\_ids:\[uuid\]} | 200 {data:{user\_id,roles}} | USR-03 \+ RBAC-01 | **P0** | TBD \- Backend/Security | **Blocked** |
| RBAC-04 | PUT /roles/{id}/permissionsReplace role permission set. | {permission\_ids:\[uuid\]} | 200 {data:{role\_id,permissions}} | RBAC-01 \+ RBAC-02 | **P1** | Backend/Security | **Done** |

### **ORG-01 — GET /api/v1/organizations**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | ORG-01 / P1 |
| Purpose | Return the global, paginated organization directory used by system-administration workflows. |
| Required permission | `organizations.manage`, enforced after Sanctum authentication and active user/organization checks. The permission must derive from a global or caller-organization role. |
| Dependencies | AUTH-08, organizations, shared request IDs, pagination, throttling and tenant-aware effective permission resolution. |
| Request / validation | Optional trimmed case-insensitive `search`, normalized `status` (`active` or `inactive`), positive `page`, and `per_page` from 1 through 100. Search spans name, type, email, contact number and address. |
| Success | `200` standard envelope containing safe Organization resources and exact `request_id`, `page`, `per_page`, `total`, `last_page` metadata. Resources expose the documented metadata and timestamps but never `deleted_at`. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected database failures remain `500`. Shared missing-resource handling remains standardized for the endpoint family. |
| Workflow / tenant scope | This is intentionally a cross-tenant system-administration view. Authorization is evaluated before the global query; a malicious permission assignment from a foreign-organization role cannot authorize access. Soft-deleted organizations remain excluded. |
| Ordering / side effects | Results sort by organization name then UUID for stable pages. The operation is read-only and creates no business audit event or notification. |
| DCL | Existing identity DCL gives the API role organization access needed by identity and organization workflows. Reporting, worker and auditor roles receive no organization-table access; the live PostgreSQL privilege check confirms API SELECT only among those readers. |
| Tests / status | `OrganizationIndexTest` covers exact fields/meta/order, global scope, search/status composition, soft deletion, validation, authentication, local/foreign RBAC, inactive identity, no audit, throttling and DCL. Done — full SQLite passes 294 tests / 1647 assertions and PostgreSQL 18/PostGIS passes 294 / 1654; focused suites, route, Pint, Composer, live privilege and diff gates pass. |

### **ORG-02 — POST /api/v1/organizations**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | ORG-02 / P1 |
| Purpose | Create a new active tenant/owner organization for subsequent user, site and asset ownership workflows. |
| Required permission | `organizations.manage`, enforced after Sanctum and active-identity checks. Foreign-tenant role assignments cannot authorize global organization creation. |
| Dependencies | ORG-01, organizations, immutable audit storage, standard request IDs, validation and authenticated throttling. |
| Request / validation | Required trimmed name (150 characters) and normalized documented type (`school`, `lgu`, `denr`, `ngo`, `research_group`); optional normalized RFC email, 50-character contact number and text address. Status is server-owned and begins as `active`. |
| Success | `201` standard envelope containing the safe Organization resource plus request ID metadata. Omitted contact fields remain null and no internal soft-delete state is projected. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; case-insensitive duplicate name `409 CONFLICT`; malformed input `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected persistence failures remain `500`. |
| Concurrency / conflict policy | Organization names are normalized before comparison and remain reserved by soft-deleted rows. PostgreSQL uses a transaction-scoped advisory lock keyed by the normalized name so concurrent same-name requests cannot both pass the absence check. |
| Transaction / audit | Organization persistence and immutable `organization.create` evidence share one transaction. Audit failure rolls back the tenant; evidence records normalized public metadata, actor and request context without timestamps or secrets. |
| DCL / tests | Existing identity DCL grants API organization INSERT and audit INSERT while audit UPDATE and report/worker organization INSERT remain denied. `OrganizationStoreTest` covers normalized success, null optionals, validation, active/archived duplicates, rollback, authentication, local/foreign RBAC, inactive identity, throttling and DCL. Done — full SQLite passes 304 tests / 1714 assertions and PostgreSQL 18/PostGIS passes 304 / 1721; route, Pint, Composer, live privilege and diff gates pass. |

### **ORG-03 — GET /api/v1/organizations/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | ORG-03 / P1 |
| Purpose | Return one organization's documented metadata for system-administration detail and edit workflows. |
| Required permission | `organizations.manage`, enforced after Sanctum and active-identity checks. Only permissions from global or caller-organization roles are effective. |
| Dependencies | ORG-01, organizations, the shared Organization resource, request IDs, standard errors and authenticated throttling. |
| Request / lookup | UUID path identifier constrained at routing. Malformed, missing and soft-deleted identifiers all resolve to the same standard 404 without exposing archived metadata. |
| Success | `200` standard envelope containing the exact safe Organization resource and request ID metadata. The system-admin scope intentionally permits foreign-tenant detail. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND`; `429 RATE_LIMITED`; unexpected database failures remain `500`. |
| Side effects / DCL | Read-only detail lookup with no business audit event or notification. Existing identity DCL permits API SELECT while report, worker and auditor organization SELECT remain denied. |
| Tests / status | `OrganizationShowTest` covers exact fields, foreign-tenant detail, missing/malformed/archived 404s, authentication, local/foreign RBAC, inactive identity, no audit, throttling and DCL. Done — full SQLite passes 311 tests / 1752 assertions and PostgreSQL 18/PostGIS passes 311 / 1759; focused suites, route, Pint, Composer, live privilege and diff gates pass. |

### **ORG-04 — PATCH /api/v1/organizations/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | ORG-04 / P1 |
| Purpose | Partially update organization metadata or logically archive/restore an organization through its documented active/inactive status. |
| Required permission | `organizations.manage`, enforced after Sanctum and active-identity checks. Foreign-tenant role assignments cannot authorize updates. |
| Dependencies | ORG-03, organizations, shared Organization resource, immutable audit storage, standard validation/errors and authenticated throttling. |
| Request / validation | At least one documented field must be present. Name/type are non-null when supplied; optional contacts/address may be cleared with null; type and status use documented domains; strings and email are normalized to their storage limits. Unknown-only and empty requests fail validation. |
| Success | `200` standard envelope containing the updated safe Organization resource and request ID metadata. `status=inactive` is a logical archive and does not soft-delete the row, so detail and restoration workflows remain available. |
| Errors / conflicts | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; hidden/missing target `404 NOT_FOUND`; duplicate active/archived name or self-organization deactivation `409 CONFLICT`; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected persistence failures remain `500`. |
| Concurrency / safety | Target rows are locked for update. Name changes reuse transaction-scoped PostgreSQL advisory locking before case-insensitive reservation checks. The caller's own organization cannot be set inactive, preventing the administrative request from locking out its authenticating tenant. |
| Transaction / audit | Metadata/status persistence and immutable `organization.update` full before/after evidence share one transaction. Audit failure restores every field. No notification is required. |
| DCL / tests | Existing identity DCL grants API organization UPDATE and audit INSERT while audit UPDATE and report/worker organization UPDATE remain denied. `OrganizationUpdateTest` covers updates/null clearing, inactive archive, validation, name conflicts, self-lockout, 404s, rollback, local/foreign RBAC, throttling and DCL. Done — full SQLite passes 322 tests / 1835 assertions and PostgreSQL 18/PostGIS passes 322 / 1842; route, Pint, Composer, live privilege and diff gates pass. |

### **RBAC-01 — GET /api/v1/roles**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | RBAC-01 / P0 |
| Purpose | Return the role catalog available for current-tenant user and assignment workflows. |
| Required permission | `roles.manage`, enforced through the reusable `permission:` middleware after Sanctum and active-identity checks. |
| Dependencies | AUTH-08, roles, permissions, role/permission joins and the shared effective-access service. |
| Request / validation | No body or query parameters. |
| Success | `200` standard envelope containing sorted safe role resources (`role_id`, `organization_id`, `role_name`, `role_code`, `description`, `is_system_role`) and request ID metadata. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `429 RATE_LIMITED`; unexpected database failures remain `500`. |
| Workflow / tenant scope | Returns global roles and roles belonging to the authenticated user's organization only. Foreign-organization roles are excluded even when maliciously assigned to the caller. |
| Authorization isolation | A permission inherited only through a foreign-organization role is ignored and cannot authorize the endpoint. The standard 403 includes the required permission code without exposing catalog data. |
| Side effects / audit / notifications | Read-only catalog lookup. No business audit event or notification is created. |
| Tests | `tests/Feature/Rbac/RoleIndexTest.php` covers exact resource shape/order, global/current-tenant scope, authentication, missing permission, foreign-role privilege rejection, inactive organization state, no audit side effect and throttling. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

### **RBAC-02 — GET /api/v1/permissions**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | RBAC-02 / P0 |
| Purpose | Return the canonical global permission catalog used by role administration workflows. |
| Required permission | `permissions.manage`, enforced through the reusable tenant-aware permission middleware. |
| Dependencies | AUTH-08, permissions, roles, role/permission joins and the shared effective-access service. |
| Request / validation | No body or query parameters. |
| Success | `200` standard envelope containing permission resources sorted by code (`permission_id`, `permission_code`, `permission_name`, `description`) and request ID metadata. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `429 RATE_LIMITED`; unexpected database failures remain `500`. |
| Workflow / tenant scope | Permission definitions are global reference data. Access to the catalog is still authorized only from global/current-organization roles; a foreign-organization assignment cannot grant catalog access. |
| Side effects / audit / notifications | Read-only catalog lookup. No business audit event or notification is created. |
| Tests | `tests/Feature/Rbac/PermissionIndexTest.php` covers exact resource shape/order, authentication, missing permission, foreign-role privilege rejection, inactive user state, no audit side effect and throttling. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

### **USR-01 — GET /api/v1/users**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | USR-01 / P0 |
| Purpose | Return a paginated, searchable user directory inside an explicitly authorized organization scope. |
| Required permission | `users.manage`. A foreign `org_id` additionally requires `organizations.manage`; both permissions must come from global/current-organization roles. |
| Dependencies | AUTH-08, users, organizations, roles, user/role joins, standard pagination and the shared organization-scope service. |
| Request / validation | Optional UUID `org_id`, normalized role code, boolean `active`, case-insensitive `search`, positive `page`, and `per_page` from 1 through 100. |
| Success | `200` standard envelope containing safe user resources and exact `request_id`, `page`, `per_page`, `total`, `last_page` metadata. Passwords, tokens and soft-deleted users are never projected. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND` for an elevated unknown organization; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected database failures remain `500`. |
| Workflow / tenant scope | Defaults to the caller's organization. A cross-tenant query is allowed only for callers with `organizations.manage` and remains pinned to the single requested organization. Role filters accept only global or target-organization roles, so malicious foreign assignments cannot affect results. |
| Query behavior | Search covers first, middle and last name plus email. Role, activity and search filters compose. Results sort by last name, first name and UUID for stable pages. |
| Implementation compatibility | The current physical `status` column remains unchanged and maps to the public `is_active` boolean. Missing profile-extension fields are not invented or leaked. |
| Side effects / audit / notifications | Read-only directory lookup. No business audit event or notification is created. |
| Tests | `tests/Feature/User/UserIndexTest.php` covers exact safe fields and pagination, current/cross-tenant isolation, elevated scope, unknown organizations, search/activity/role filters, foreign-role rejection, validation, authentication, permission checks, no audit side effect and throttling. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

### **USR-02 — POST /api/v1/users**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | USR-02 / P0 |
| Purpose | Create a managed account and assign its initial authorized role set atomically. |
| Required permission | `users.manage`. Creation in another organization additionally requires `organizations.manage`; both must derive from global/current-organization roles. |
| Dependencies | USR-01, RBAC-01, organizations, users, roles, user/role joins and immutable audit storage. |
| Request / validation | Required organization UUID, normalized first/last name, normalized unique email and 1–20 distinct role UUIDs; optional nullable `position_title` up to 100 characters. |
| Success | `201` standard envelope containing the safe User resource and request ID metadata. Password material and assigned pivot internals are not returned. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND` for an elevated unknown organization; `422 VALIDATION_FAILED` for malformed, duplicate-email or out-of-scope-role input; `429 RATE_LIMITED`; unexpected persistence failures remain `500`. |
| Workflow / tenant scope | Roles must be global or owned by the target organization. Any missing, foreign or soft-deleted target reference rejects the request before user persistence. Cross-organization creation is pinned to one explicitly authorized target. |
| Credential bootstrap | Because the endpoint contract accepts no password, the server generates and hashes a high-entropy undisclosed credential. The user later establishes a known password through the password-reset workflow; no temporary secret is logged or returned. |
| Schema reconciliation | Adds the documented nullable `users.position_title VARCHAR(100)` column through a reversible migration. Existing physical `password` and `status` columns remain intact. |
| Side effects | User insertion, role pivots, `user.create` audit and `role.assign` audit share one transaction and roll back together. |
| Audit / notifications | Both audit events include actor, target UUID, safe identity/role context, request ID, IP and user agent. Password material is never passed to the audit logger. No notification is emitted until the mail workflow is implemented. |
| Tests | `tests/Feature/User/UserStoreTest.php` covers exact creation output, normalization, safe generated credentials, tenant/global role assignment, cross-tenant elevation, foreign-role and duplicate-input rejection, dual audits, rollback, authentication, permission checks and throttling. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

### **USR-03 — GET /api/v1/users/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | USR-03 / P1; implemented now because it unlocks P0 RBAC-03. |
| Purpose | Return one managed user's safe profile together with assignable effective role resources. |
| Required permission | `users.manage`. Cross-organization detail additionally requires `organizations.manage`. |
| Dependencies | USR-01, users, roles, user/role joins, safe User/Role resources and the reusable scoped-user service. |
| Request / validation | UUID path parameter; malformed, missing and soft-deleted targets resolve to the same standard 404. |
| Success | `200` standard envelope containing `{user,roles}` and request ID metadata. The role list is sorted and contains only global or target-organization roles. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND`; `429 RATE_LIMITED`; unexpected database failures remain `500`. |
| Workflow / tenant scope | Normal managers query only their own organization, so foreign UUIDs are indistinguishable from missing records. Callers with `organizations.manage` may resolve a foreign target explicitly. Malicious foreign-role assignments are excluded from output. |
| Side effects / audit / notifications | Read-only detail lookup. No business audit event or notification is created. |
| Tests | `tests/Feature/User/UserShowTest.php` covers safe profile/role shape, foreign-role exclusion, hidden/elevated cross-tenant access, malformed/missing/deleted targets, authentication, permission checks, no audit side effect and throttling. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

### **USR-04 — PATCH /api/v1/users/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | USR-04 / P1 |
| Purpose | Partially update a managed user's safe physical profile fields without conflating organization membership, roles, activation or credentials. |
| Required permission | `users.manage`; cross-organization targets additionally require `organizations.manage`. Both permissions must derive from global/current-organization roles. |
| Dependencies | USR-03, users, safe User resource, scoped-user resolution, immutable audit storage and standard request controls. |
| Request / validation | At least one of first, nullable middle, last name, nullable position title or email. Strings are trimmed, email is lowercased/RFC validated, and physical column limits are enforced. Empty or unknown-only requests fail validation. |
| Success | `200` standard envelope containing the updated safe User resource and request ID metadata. Organization, roles, activation and credential fields cannot be changed through this card. |
| Errors / conflicts | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; hidden/missing/deleted target `404 NOT_FOUND`; active or soft-deleted case-insensitive email reservation `409 CONFLICT`; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected failures remain `500`. |
| Concurrency / transaction | The authorized target is reloaded with a row lock. Email changes use a transaction advisory lock before the case-insensitive reservation check. Profile persistence and immutable `user.update` before/after evidence share one transaction and roll back together. |
| DCL / tests | Existing identity DCL grants API user UPDATE and audit INSERT while audit UPDATE and report/worker user UPDATE remain denied. `UserUpdateTest` covers normalized/null partial updates, reserved email, hidden/elevated scope, validation, rollback, local/foreign RBAC, throttling and DCL. Done — full SQLite passes 333 tests / 1903 assertions and PostgreSQL 18/PostGIS passes 333 / 1910; route, Pint, Composer, live privilege and diff gates pass. |

### **USR-05 — POST /api/v1/users/{id}/activation**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | USR-05 / P1 |
| Purpose | Activate or deactivate a managed account by changing its physical status without deleting profile or role data. |
| Required permission | `users.manage`; cross-organization targets additionally require `organizations.manage`. Both permissions must derive from global/current-organization roles. |
| Dependencies | USR-03, users, personal access tokens, safe User resource, scoped-user resolution, immutable audit storage and authenticated throttling. |
| Request / validation | Required normalized boolean `is_active`; optional nullable trimmed reason up to 1000 characters. The reason is audit context only and is not persisted on or projected from the user row. |
| Success | `200` standard envelope containing the safe User resource with the resulting `is_active` value and request ID metadata. Activation does not manufacture credentials; deactivation revokes all existing target sessions. |
| Errors / conflicts | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; hidden/missing/deleted target `404 NOT_FOUND`; no-op transitions and self-deactivation `409 CONFLICT`; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected failures remain `500`. |
| Transaction / audit | The target is row-locked. Status mutation, deactivation session revocation and immutable `user.activation.update` old/new status plus reason evidence share one transaction. Audit failure restores both state and tokens. |
| DCL / tests | Existing identity DCL grants API user UPDATE, token DELETE and audit INSERT while audit UPDATE and report user UPDATE remain denied. `UserActivationTest` covers activation/deactivation, token policy, reason audit, validation, conflicts, hidden/elevated scope, rollback, local/foreign RBAC, throttling and DCL. Done — full SQLite passes 344 tests / 1964 assertions and PostgreSQL 18/PostGIS passes 344 / 1971; route, Pint, Composer, live privilege and diff gates pass. |

### **RBAC-03 — PUT /api/v1/users/{id}/roles**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | RBAC-03 / P0 |
| Purpose | Atomically replace a managed user's complete role assignment set. |
| Required permission | `roles.manage`; cross-organization targets additionally require `organizations.manage`. |
| Dependencies | USR-03, RBAC-01, users, roles, user/role joins, scoped-user resolution and immutable audit storage. |
| Request / validation | A present array of at most 20 distinct role UUIDs. An empty array intentionally removes all assignments. Every non-empty role must be global or owned by the target user's organization. |
| Success | `200` standard envelope containing target `user_id`, sorted safe Role resources and request ID metadata. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND` for hidden/missing users; `422 VALIDATION_FAILED` for malformed, missing or foreign roles; `429 RATE_LIMITED`; unexpected persistence failures remain `500`. |
| Workflow / tenant scope | Normal managers can replace roles only for users in their organization. Elevated managers may target a foreign user, but replacement roles remain pinned to global plus that user's organization. Existing malicious foreign assignments are removed by full-set replacement. |
| Side effects | Pivot deletion/insertion and mandatory `role.assign` audit persistence share one transaction and roll back together. |
| Audit / notifications | Audit evidence stores actor, target UUID, sorted old/new role UUID sets, request ID, IP and user agent. No notification is required. |
| Tests | `tests/Feature/Rbac/UserRoleReplaceTest.php` covers exact full/empty replacement, tenant scope, elevated cross-tenant access, unavailable-role rejection, old/new audit evidence, rollback, authentication, permission and validation errors, and throttling. |
| Implementation status | Done — the endpoint passes focused and complete SQLite plus PostgreSQL 18/PostGIS suites, formatting and repository validation. |

### **RBAC-04 — PUT /api/v1/roles/{id}/permissions**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | RBAC-04 / P1 |
| Purpose | Atomically replace a non-system role's complete permission assignment set. |
| Required permission | Both `roles.manage` and `permissions.manage`. A non-system global role additionally requires `organizations.manage`; all authority must derive from global/current-organization assignments. |
| Dependencies | RBAC-01, RBAC-02, roles, permissions, role/permission joins, effective access, immutable audit storage and authenticated throttling. |
| Request / validation | A present array of at most 100 distinct permission UUIDs. Empty intentionally removes all permissions. Every non-empty identifier must exist in the global permission catalog. |
| Success | `200` standard envelope containing target `role_id`, permission resources sorted by code/UUID and request ID metadata. |
| Scope / conflicts | Current-tenant roles are editable. Foreign-tenant roles are hidden. Non-system global roles require explicit organization elevation. System roles are visible only to elevated callers but immutable and return `409 CONFLICT`. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or missing authority; hidden/missing role `404 NOT_FOUND`; immutable system role `409 CONFLICT`; malformed/unknown permissions `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected failures remain `500`. |
| Transaction / audit | The role is row-locked; full pivot synchronization and immutable `role.permissions.replace` sorted before/after UUID evidence share one transaction. Audit failure restores the previous set. |
| DCL / tests | Existing identity DCL grants API role-permission INSERT/DELETE and audit INSERT while audit UPDATE and reporting pivot writes remain denied. `RolePermissionReplaceTest` covers exact/empty sets, validation, system protection, tenant/global/foreign scope, dual authority, foreign-role rejection, rollback, throttling and DCL. Done — full SQLite passes 356 tests / 2014 assertions and PostgreSQL 18/PostGIS passes 356 / 2021; route, Pint, Composer, live privilege and diff gates pass. |

## **Survey sites, boundaries, plots and permits**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| SITE-01 | GET /sitesList sites visible to user. | Query: search,status,province,page | 200 {data:\[Site\],meta} | AUTH-08 | **P0** | Codex \- GIS/API | **Done** |
| SITE-02 | POST /sitesRegister monitoring site. | {site\_name,site\_code,description?,province,city\_municipality,barangay?,center\_point:GeoJSON?,area\_hectares?,environment\_type,access\_notes?} | 201 {data:Site} | SITE-01 | **P0** | Codex \- GIS/API | **Done** |
| SITE-03 | GET /sites/{id}Site detail with summary counts. | Path: id | 200 {data:{site,counts}} | SITE-01 | **P0** | Codex \- GIS/API | **Done** |
| SITE-04 | PATCH /sites/{id}Update site metadata. | Partial Site fields | 200 {data:Site} | SITE-03 | **P1** | Codex \- GIS/API | **Done** |
| SITE-05 | DELETE /sites/{id}Soft archive site after dependency checks. | Path: id | 204 | SITE-03 | **P2** | TBD \- GIS/API | Backlog |
| BOUND-01 | GET /sites/{id}/boundariesList site polygons. | Path: site id | 200 {data:\[Boundary\]} | SITE-03 | **P0** | Codex \- GIS/API | **Done** |
| BOUND-02 | POST /sites/{id}/boundariesCreate survey/no-fly/restoration polygon. | {boundary\_name,boundary\_type,boundary\_geom:GeoJSON,source?} | 201 {data:Boundary} | BOUND-01 | **P0** | Codex \- GIS/API | **Done** |
| BOUND-03 | PATCH /boundaries/{id}Update boundary metadata/geometry. | Partial boundary fields | 200 {data:Boundary} | BOUND-02 | **P1** | Codex \- GIS/API | **Done** |
| PLOT-01 | GET /sites/{id}/plotsList monitoring plots. | Path: site id | 200 {data:\[Plot\]} | SITE-03 | **P1** | Codex \- GIS/API | **Done** |
| PLOT-02 | POST /sites/{id}/plotsCreate validation plot. | {plot\_code,plot\_name?,plot\_geom:GeoJSON,area\_square\_meters?,description?} | 201 {data:Plot} | PLOT-01 | **P1** | Codex \- GIS/API | **Done** |
| PLOT-03 | PATCH /plots/{id}Update/soft archive plot. | Partial Plot fields | 200 {data:Plot} | PLOT-02 | **P2** | TBD \- GIS/API | Backlog |
| PERMIT-01 | GET /sites/{id}/access-permissionsList permit/access records. | Path: site id | 200 {data:\[AccessPermission\]} | SITE-03 | **P2** | TBD \- Backend/API | Backlog |
| PERMIT-02 | POST /sites/{id}/access-permissionsRecord field-access permit. | {permit\_title,issuing\_agency,permit\_number?,valid\_from?,valid\_until?,document\_path?,status} | 201 {data:AccessPermission} | PERMIT-01 | **P2** | TBD \- Backend/API | Backlog |

### **BOUND-03 — PATCH /api/v1/boundaries/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Partially update tenant-owned boundary metadata or Polygon(4326); requires `boundaries.manage` from a valid current/global role. |
| Validation / success | At least one normalized BOUND-02 field; strict documented name/type/source domains and valid Polygon GeoJSON. Returns `200` safe Boundary plus request ID while site/creator remain fixed. |
| Scope / transaction | Foreign/missing/malformed boundaries are hidden. The target is row-locked; metadata/parameterized geometry and immutable `boundary.update` before/after evidence share one rollback-safe transaction. |
| DCL / tests | `018_site_boundary_update_grants.sql` adds API UPDATE only; API DELETE stays denied, reporting remains SELECT-only and worker UPDATE is denied. Done — full SQLite passes 374 tests / 2104 assertions and PostgreSQL 18/PostGIS passes 374 / 2111; focused suites, route, Pint, Composer, live privilege and diff gates pass. |

### **PLOT-01 - GET /api/v1/sites/{id}/plots**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | List non-deleted monitoring/validation plots for one tenant-visible site; requires active identity and tenant-valid `sites.read`. Foreign, missing and malformed site identifiers remain non-enumerable 404s. |
| Response / ordering | Returns exact Plot resources and request ID metadata, ordered by site-local plot code then UUID. SITE-03's existing plot summary now counts real non-deleted rows. The read creates no audit or notification. |
| Schema / spatial | Adds the documented UUID `monitoring_plots` schema with site lineage, site-local code uniqueness, required PostGIS Polygon(4326), GiST index, optional positive square-meter area and soft deletion. SQLite retains JSON only as the fast compatibility test representation. |
| DCL / tests | API and reporting roles receive SELECT only; worker receives none. `SitePlotIndexTest` covers exact shape/order, PostGIS GeoJSON, summary count, soft deletion, tenant isolation, local/foreign RBAC, throttling, PostgreSQL constraint and DCL. Done - full SQLite passes 256 tests / 1450 assertions and PostgreSQL 18/PostGIS passes 256 / 1457; route, Pint, live privilege and diff gates pass. |

### **PLOT-02 - POST /api/v1/sites/{id}/plots**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Create a validation plot inside one tenant-visible site; requires active identity and tenant-valid `plots.manage`. Organization and site lineage are server-derived. |
| Request / response | Requires normalized uppercase `plot_code` and valid Polygon GeoJSON; accepts nullable trimmed name/description and positive bounded area. Returns `201 {data:Plot}` plus request ID with PostGIS geometry serialized as GeoJSON. |
| Workflow / conflicts | Plot codes are unique within a site, remain reserved after soft deletion, and duplicate creation returns documented 409. Foreign, missing and malformed parent sites remain 404. PostgreSQL rechecks geometric validity before the parameterized insert. |
| Transaction / audit / DCL | Plot insert and immutable `plot.create` evidence share one transaction; audit failure rolls back the geometry. API gains INSERT only in addition to PLOT-01 SELECT; reporting remains read-only and worker receives none. |
| Tests / status | `SitePlotStoreTest` covers exact normalized persistence/resource/audit, validation, duplicate conflict, PostGIS geometry, tenant hiding, rollback, local/foreign RBAC and throttling. Done - full SQLite passes 264 tests / 1489 assertions and PostgreSQL 18/PostGIS passes 264 / 1496; route, Pint, live privilege and diff gates pass. |

### **SITE-01 - GET /api/v1/sites**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | SITE-01 / P0 |
| Purpose | Return a stable, paginated catalog of survey sites visible to the authenticated caller. |
| Required permission | `sites.read`, enforced after Sanctum authentication and active user/organization checks by the shared tenant-aware permission middleware. |
| Dependencies | AUTH-08, organizations, users, tenant-scoped RBAC, survey-site persistence, PostGIS and standard pagination. |
| Request / validation | Optional trimmed `search`, normalized `status` (`active` or `archived`), case-insensitive exact `province`, positive `page`, and `per_page` from 1 through 100. |
| Success | `200` standard envelope containing safe Site resources plus exact `request_id`, `page`, `per_page`, `total`, and `last_page` metadata. Nullable center points are emitted as RFC 7946-style GeoJSON objects. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected database failures remain `500`. The endpoint has no target identifier requiring a normal `404`. |
| Workflow / tenant scope | Every query is pinned to the caller's `organization_id`; there is no cross-tenant override in the endpoint contract. Soft-deleted sites and all foreign-organization rows are excluded before filtering or pagination. |
| Query behavior | Search covers site name, code, description, city/municipality and barangay. Filters compose, and results sort by site name then UUID for stable pages. |
| Spatial persistence | PostgreSQL stores nullable `geometry(Point,4326)` with a GiST spatial index and projects it with `ST_AsGeoJSON`. SQLite uses a JSON column only as a fast compatibility-test substitute. Public resources never expose WKB/EWKB. |
| Database privileges | `database/sql/dcl/003_survey_site_grants.sql` grants `SELECT` only to API and reporting roles; worker and auditor roles receive no implicit access. PostgreSQL verification confirms the intended privilege matrix. |
| Side effects / audit / notifications | Read-only catalog lookup. No business audit event or notification is created. |
| Tests | `tests/Feature/Site/SiteIndexTest.php` covers exact resource/metadata shape, PostGIS-to-GeoJSON projection, organization and soft-delete isolation, composed filters, validation, authentication, inactive identity, missing/foreign-role permission rejection, no audit side effect and throttling. |
| Implementation status | Done - focused and complete SQLite plus PostgreSQL 18/PostGIS suites pass at 87 tests / 493 assertions; touched PHP files pass Pint, Composer metadata validates, the route is registered, DCL scripts execute, and diff checks are clean. |

### **SITE-02 - POST /api/v1/sites**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | SITE-02 / P0 |
| Purpose | Register a new monitoring site owned by the authenticated caller's organization. |
| Required permission | `sites.manage`, enforced after Sanctum and active-identity checks. The permission must derive from a global or current-organization role. |
| Dependencies | SITE-01, survey-site persistence/resource projection, organizations, users, PostGIS, tenant-scoped RBAC and immutable audit storage. |
| Request / validation | Required normalized name/code, province, city/municipality and environment type (`coastal`, `riverine`, `estuarine`); optional nullable description, barangay, access notes, area (non-negative `NUMERIC(12,4)`) and GeoJSON Point. Longitude/latitude are bounded to -180..180 and -90..90. Site codes normalize to uppercase and remain globally unique as documented. |
| Success | `201` standard envelope containing the complete safe Site resource and request ID metadata. New sites always start `active`, carry the caller as `created_by`, and return the center point as GeoJSON rather than WKB/EWKB. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `422 VALIDATION_FAILED` for malformed/duplicate input; `429 RATE_LIMITED`; unexpected persistence failures remain `500`. No client-selected target reference requires a normal `404`. |
| Workflow / tenant scope | Organization ownership is derived exclusively from the authenticated user. Extra client-supplied organization fields are excluded from validated data, preventing cross-tenant creation. Foreign-role permission assignments cannot authorize the write. |
| Spatial persistence | PostgreSQL uses a parameterized `ST_SetSRID(ST_GeomFromGeoJSON(?),4326)` insert into `geometry(Point,4326)`; SQLite stores the equivalent JSON only in compatibility tests. Numeric coordinate strings are normalized to numbers before encoding. |
| Transaction / concurrency | Site insertion and mandatory audit persistence share one database transaction. The database unique constraint remains the final site-code concurrency guard. Audit failure rolls back the inserted site. |
| Audit / notifications | One immutable `site.create` event records actor, site UUID, tenant-owned normalized fields, GeoJSON, request ID, IP and user agent; timestamps are omitted from the change payload. No notification is required. |
| Database privileges | The site DCL grants API `SELECT, INSERT` only and reporting `SELECT` only. API `UPDATE`, worker access and auditor access remain denied until a later endpoint explicitly requires them. |
| Tests | `tests/Feature/Site/SiteStoreTest.php` covers normalized PostGIS creation and GeoJSON output, forced caller tenancy, optional null fields, bounds/precision/uniqueness validation, exact audit evidence, rollback, authentication, local/foreign permission scope and throttling. |
| Implementation status | Done - focused and complete SQLite plus PostgreSQL 18/PostGIS suites pass at 94 tests / 558 assertions; touched PHP files pass Pint, Composer metadata validates, both site routes are registered, DCL executes with the intended matrix, and diff checks are clean. |

### **SITE-03 - GET /api/v1/sites/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | SITE-03 / P0 |
| Purpose | Return one visible survey site together with stable summary counts for its direct operational children. |
| Required permission | `sites.read`, enforced after Sanctum and active user/organization checks. Foreign-organization role grants are excluded by effective-access scoping. |
| Dependencies | SITE-01, safe Site/GeoJSON projection, survey-site tenancy and the standard error/throttle stack. Child tables are counted when their dependency migrations exist. |
| Request / validation | UUID site path parameter constrained at routing. Malformed UUIDs, missing rows, soft-deleted rows and rows outside the caller's organization all resolve to the same standard 404. |
| Success | `200` standard envelope containing `{site,counts}` and request ID metadata. `counts` always contains integer `boundaries`, `plots`, `access_permissions`, and `missions` keys. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND`; `429 RATE_LIMITED`; unexpected database failures remain `500`. |
| Workflow / tenant scope | Lookup is constrained by caller `organization_id` before UUID resolution, preventing foreign-site enumeration even when a valid foreign UUID is supplied. Detail geometry uses the same PostGIS-to-GeoJSON projection as the list endpoint. |
| Count behavior | Existing child tables are counted by `site_id`; soft-deleted plots and missions are excluded. A child table whose planned dependency migration has not landed yet contributes zero while preserving the response shape, so BOUND/PLOT/MSN migrations require no contract change. |
| Side effects / audit / notifications | Read-only detail and aggregate lookup. No audit event or notification is created. Existing site DCL `SELECT` access is sufficient; no privilege expansion is introduced. |
| Tests | `tests/Feature/Site/SiteShowTest.php` covers exact site/count envelope, PostGIS GeoJSON, current-tenant resolution, foreign/missing/deleted/malformed anti-enumeration 404s, authentication, foreign-role rejection, inactive organization, no audit side effect and throttling. |
| Implementation status | Done - focused and complete SQLite plus PostgreSQL 18/PostGIS suites pass at 100 tests / 586 assertions; touched PHP files pass Pint, Composer metadata validates, all three site routes are registered, and diff checks are clean. |

### **SITE-04 — PATCH /api/v1/sites/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | SITE-04 / P1 |
| Purpose | Partially update a tenant-owned site's documented metadata and optional Point(4326) without changing ownership, creator or lifecycle. |
| Required permission | `sites.manage`, enforced after Sanctum and active-identity checks through tenant-aware effective permissions. |
| Dependencies | SITE-03, survey sites, PostGIS, safe Site resource, scoped-site resolution, immutable audit storage and authenticated throttling. |
| Request / validation | At least one SITE-02 metadata field. Required-on-present name/code/location/environment fields, nullable description/barangay/point/area/access notes, storage limits, documented environment domain and strict longitude/latitude point bounds. Codes normalize uppercase. Status is excluded because SITE-05 owns archival. |
| Success | `200` standard envelope containing the updated safe Site resource and request ID. Organization, creator and status remain server-owned and unchanged. Point output is GeoJSON only. |
| Errors / conflicts | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; foreign/missing/deleted target `404 NOT_FOUND`; active/deleted global code reservation `409 CONFLICT`; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected failures remain `500`. |
| Concurrency / transaction | The target is row-locked. Code changes use a PostgreSQL transaction advisory lock. Metadata and parameterized Point(4326) mutation plus immutable `site.update` full before/after evidence share one transaction and roll back together. |
| DCL / tests | `017_survey_site_update_grants.sql` adds API UPDATE only; API DELETE stays denied, reporting remains SELECT-only and worker UPDATE is denied. `SiteUpdateTest` covers metadata/geometry updates and clearing, fixed ownership/lifecycle, validation, code conflict, tenant hiding, rollback, local/foreign RBAC, throttling and DCL. Done — full SQLite passes 366 tests / 2069 assertions and PostgreSQL 18/PostGIS passes 366 / 2076; route, Pint, Composer, live privilege and diff gates pass. |

### **BOUND-01 - GET /api/v1/sites/{id}/boundaries**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | BOUND-01 / P0 |
| Purpose | Return the ordered polygon boundaries attached to one tenant-visible survey site. |
| Required permission | `sites.read`, since the permission catalog defines no separate boundary-read code; mutation remains reserved for `boundaries.manage`. |
| Dependencies | SITE-03, scoped site resolution, users, survey sites, PostGIS and the safe boundary resource projection. |
| Request / validation | UUID site path constrained at routing. Foreign, missing, soft-deleted and malformed site targets return the same standard 404 before child rows are queried. |
| Success | `200` standard envelope containing safe Boundary resources ordered by name then UUID plus request ID metadata. Geometry is emitted as GeoJSON Polygon. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND`; `429 RATE_LIMITED`; unexpected database failures remain `500`. |
| Workflow / tenant scope | The parent site is resolved under caller `organization_id`, then every boundary query is pinned to that resolved site UUID. Foreign boundaries cannot enter the result set. |
| Spatial persistence | Adds the documented `site_boundaries` table with UUID ownership, `geometry(Polygon,4326)`, GiST spatial index, site/type lookup index, cascade-on-site deletion and restricted creator deletion. SQLite uses JSON only for compatibility testing. |
| Side effects / audit / notifications | Read-only boundary lookup. No audit event or notification is created. SITE-03 immediately reflects the table through its `boundaries` summary count. |
| Database privileges | `004_site_boundary_grants.sql` grants `SELECT` only to API and reporting roles. API insert/update/delete plus worker/auditor access remain denied until explicitly needed. |
| Tests | `tests/Feature/Site/SiteBoundaryIndexTest.php` covers ordered same-site output, PostGIS GeoJSON, foreign-row exclusion, SITE-03 count integration, hidden parent IDs, authentication, missing/foreign permission grants, no audit side effect and throttling. |
| Implementation status | Done - focused and complete SQLite plus PostgreSQL 18/PostGIS suites pass at 105 tests / 612 assertions; PostgreSQL confirms Polygon(4326), GiST and DCL shape; touched PHP passes Pint, Composer/routes validate, and diff checks are clean. |

### **BOUND-02 - POST /api/v1/sites/{id}/boundaries**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | BOUND-02 / P0 |
| Purpose | Create a survey, no-fly or restoration polygon inside one tenant-visible monitoring site. |
| Required permission | `boundaries.manage`, derived only from global/current-organization roles after Sanctum and active-identity enforcement. |
| Dependencies | BOUND-01, scoped site resolution, Polygon(4326) persistence/resource projection and immutable audit storage. |
| Request / validation | Required trimmed name, normalized type (`survey_area`, `no_fly_zone`, `restoration_area`) and GeoJSON Polygon; optional normalized source (`manual`, `drone_map`, `imported_geojson`). Every ring requires at least four positions, three distinct vertices, closure, WGS84 bounds and no self-intersection. |
| Success | `201` standard envelope containing the complete safe Boundary resource and request ID metadata. Geometry is returned as GeoJSON and the actor is persisted as `created_by`. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND` for hidden/missing/malformed parent sites; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected failures remain `500`. |
| Workflow / tenant scope | The parent site is resolved under caller organization before creation; no organization/site ownership field from the body is accepted. Foreign site UUIDs are indistinguishable from missing rows. |
| Spatial persistence | Structural validation runs at the API boundary, then PostgreSQL rechecks `ST_IsValid` and inserts with parameterized `ST_SetSRID(ST_GeomFromGeoJSON(?),4326)`. SQLite JSON is a compatibility-test substitute only. |
| Transaction / audit | Polygon insertion and mandatory immutable `boundary.create` audit share one transaction. Evidence stores actor, site/boundary UUIDs, normalized metadata, GeoJSON, request ID, IP and user agent; audit failure rolls back the polygon. No notification is required. |
| Database privileges | Boundary DCL grants API `SELECT, INSERT` and reporting `SELECT`; API update/delete plus worker/auditor access remain denied. |
| Tests | `tests/Feature/Site/SiteBoundaryStoreTest.php` covers exact creation output/persistence/audit, numeric normalization, ring structure/bounds/self-intersection, hidden parents, authentication, local/foreign permission scope, rollback and throttling. |
| Implementation status | Done - focused and complete SQLite plus PostgreSQL 18/PostGIS suites pass at 113 tests / 664 assertions; touched PHP passes Pint, Composer/routes validate, DCL executes with the intended matrix, and diff checks are clean. |

## **Drone, sensor and hardware registry**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| DRONE-01 | GET /dronesList drone units. | Query: status,search,page | 200 {data:\[Drone\],meta} | AUTH-08 | **P1** | Codex \- Backend/API | **Done** |
| DRONE-02 | POST /dronesRegister drone. | {drone\_name,model?,serial\_number?,firmware\_version?,max\_flight\_minutes?,payload\_capacity\_grams?,status} | 201 {data:Drone} | DRONE-01 | **P1** | Codex \- Backend/API | **Done** |
| DRONE-03 | GET /drones/{id}Drone detail \+ attached sensors. | Path: id | 200 {data:{drone,sensors}} | DRONE-01 | **P1** | Codex \- Backend/API | **Done** |
| DRONE-04 | PATCH /drones/{id}Update drone status/metadata. | Partial Drone fields | 200 {data:Drone} | DRONE-03 | **P2** | TBD \- Backend/API | Backlog |
| SENSOR-01 | POST /drones/{id}/sensorsAttach/register sensor. | {sensor\_name,sensor\_type,manufacturer?,model?,serial\_number?,resolution?,range\_meters?,calibration\_required,status} | 201 {data:Sensor} | DRONE-03 | **P1** | Codex \- Backend/API | **Done** |
| SENSOR-02 | PATCH /sensors/{id}Update sensor. | Partial Sensor fields | 200 {data:Sensor} | SENSOR-01 | **P2** | TBD \- Backend/API | Backlog |
| CAL-01 | POST /sensors/{id}/calibrationsRecord sensor calibration. | {calibration\_date,calibration\_method,calibration\_file\_path?,calibration\_notes?,is\_valid} | 201 {data:Calibration} | SENSOR-01 | **P2** | TBD \- Backend/API | Backlog |
| BAT-01 | GET /batteriesList battery packs. | Query: status,type,page | 200 {data:\[Battery\],meta} | AUTH-08 | **P2** | Jessamae Sumanoy | **Working** |
| BAT-02 | POST /batteriesRegister battery. | {battery\_code,battery\_type,capacity\_mah?,voltage?,status} | 201 {data:Battery} | BAT-01 | **P2** | TBD \- Backend/API | Backlog |

### **DRONE-01 - GET /api/v1/drones**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / dependency | Return the caller's tenant-owned drone catalog. Although P1, DRONE-01 was pulled before FLT-01 because the authoritative `flight_sessions.drone_id` foreign key requires the drone table. |
| Authentication / authorization | Sanctum, active user/organization checks, and tenant-effective `drones.read` are mandatory. Every query is additionally constrained by the authenticated user's `organization_id`. |
| Request / response | Optional normalized `status`, case-insensitive `search`, positive `page`, and `per_page` 1 through 100. Success is exact safe Drone resources plus `request_id`, `page`, `per_page`, `total`, and `last_page`. |
| Tenant / lifecycle behavior | Records from other organizations and soft-deleted drones never affect output or pagination. Search covers name, model, and serial number; status accepts only `available`, `maintenance`, or `retired`. |
| Database schema | Adds UUID-backed `drones`, organization FK, documented metadata and numeric capacities, globally unique nullable serial number, soft deletion, organization/status index, and a PostgreSQL lifecycle check constraint. |
| Side effects / privileges | Read-only; no audit event or notification. `007_drone_grants.sql` gives API and reporting roles SELECT only and denies INSERT, UPDATE, and DELETE until corresponding write cards land. |
| Tests / status | `DroneIndexTest` covers exact fields/meta/order, tenant and soft-delete isolation, filters, validation, `drones.read` authorization, inactive identities, no audit, throttling, constraint and DCL. |

### **DRONE-02 — POST /api/v1/drones**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Register a drone owned by the active authenticated caller's organization after tenant-effective `drones.manage` authorization. |
| Request / success | Required normalized name/status; optional model, uppercased serial, firmware and positive bounded flight/payload decimals. Returns `201` exact safe Drone plus request ID with server-owned tenant UUID. |
| Conflicts / transaction | Serial numbers are globally reserved by active and soft-deleted units. PostgreSQL advisory locking prevents concurrent duplicate registration. Drone insert and immutable `drone.create` evidence share one rollback-safe transaction. |
| DCL / tests | `019_drone_write_grants.sql` adds API INSERT only; API update/delete remain denied, reporting remains SELECT-only and worker insert is denied. Done — full SQLite passes 382 tests / 2148 assertions and PostgreSQL 18/PostGIS passes 382 / 2155; focused suites, route, Pint, Composer, live privilege and diff gates pass. |

### **DRONE-03 - GET /api/v1/drones/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Return one tenant-owned, non-deleted drone and its attached sensors. Sanctum, active identity, and tenant-effective `drones.read` are mandatory. |
| Success / errors | Returns the exact `200 {data:{drone,sensors}}` contract plus request ID metadata. Missing, malformed, foreign-tenant, and soft-deleted drone identifiers are all hidden behind `404 NOT_FOUND`; standard `401`, inactive-identity `403`, `429`, and unexpected `500` handling remain in force. |
| Ordering / side effects | Sensors sort deterministically by name then UUID and expose only documented fields. This detail read creates no audit event or notification. |
| Database schema | Adds UUID-backed `drone_sensors` with cascade FK to drones, documented sensor metadata, positive optional range, calibration flag, drone/status and serial indexes, and PostgreSQL checks for the documented sensor types and lifecycle states. Sensors do not use soft deletion because the authoritative schema does not define it. |
| DCL / tests | `020_drone_sensor_read_grants.sql` grants sensor SELECT only to API and reporting roles; API insert/update/delete and worker access remain denied. Done - full SQLite passes 389 tests / 2182 assertions and PostgreSQL 18/PostGIS passes 389 / 2192; focused suites, route, Pint, Composer, live privilege and diff gates pass. |

### **SENSOR-01 - POST /api/v1/drones/{id}/sensors**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Attach a documented sensor to a tenant-owned, non-deleted drone. Active Sanctum identity and tenant-effective `sensors.manage` are required. |
| Request / success | Requires normalized name, one of `rgb_camera`, `lidar`, `depth`, `gps`, or `imu`, a boolean calibration flag, and one of `active`, `inactive`, or `maintenance`; documented manufacturer, model, serial, resolution and positive two-decimal range remain optional. Returns `201 {data:Sensor}` plus request ID. |
| Tenant / conflicts | Parent lookup is tenant-scoped and hides foreign, deleted, missing, and malformed drone IDs behind `404 NOT_FOUND`. Sensor serial numbers are normalized but intentionally not treated as unique because the authoritative schema defines only a nullable indexed field; concurrent attachments are therefore valid. |
| Transaction / audit | Sensor insertion and immutable `sensor.create` evidence share one transaction, including actor, parent/sensor UUIDs, normalized safe metadata and request context. Audit failure rolls the sensor back; no notification is required. |
| DCL / tests | `021_drone_sensor_write_grants.sql` adds API INSERT only. Combined with DRONE-03 grants, API has SELECT/INSERT, reporting has SELECT, and API update/delete plus reporting/worker writes remain denied. Done - full SQLite passes 398 tests / 2242 assertions and PostgreSQL 18/PostGIS passes 398 / 2252; focused suites, route, Pint, Composer, live privilege and diff gates pass. |

## **Mission planning and lifecycle**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| MSN-01 | GET /missionsList missions visible to caller. | Query: site\_id,status,from,to,search,page | 200 {data:\[Mission\],meta} | SITE-01 | **P0** | Codex \- Backend/API | **Done** |
| MSN-02 | POST /missionsCreate survey mission. | {site\_id,mission\_code,mission\_title,mission\_objective,planned\_start\_at?,planned\_end\_at?,coverage\_target\_hectares?} | 201 {data:Mission} | MSN-01 | **P0** | Codex \- Backend/API | **Done** |
| MSN-03 | GET /missions/{id}Mission detail with team/flights/summary. | Path: id | 200 {data:{mission,team,flight\_summary}} | MSN-01 | **P0** | Codex \- Backend/API | **Done** |
| MSN-04 | PATCH /missions/{id}Update planning fields before finalization. | Partial Mission fields | 200 {data:Mission} | MSN-03 | **P0** | Codex \- Backend/API | **Done** |
| MSN-05 | DELETE /missions/{id}Soft archive allowed mission. | Path: id | 204 | MSN-03 | **P2** | TBD \- Backend/API | Backlog |
| TEAM-01 | PUT /missions/{id}/teamReplace mission team assignments atomically. | {members:\[{user\_id,team\_role}\]} | 200 {data:\[MissionTeamMember\]} | MSN-03 \+ USR-01 | **P0** | Codex \- Backend/API | **Done** |
| MSN-06 | POST /missions/{id}/approveApprove mission and record approver. | {decision:"approved"|"rejected",notes?} | 200 {data:Mission} | MSN-03 \+ AUTH-08 | **P0** | Codex \- Backend/API | **Done** |
| MSN-07 | POST /missions/{id}/startTransition mission to in\_progress. | {started\_at?} | 200 {data:Mission} | MSN-06 | **P1** | Codex \- Backend/API | **Done** |
| MSN-08 | POST /missions/{id}/completeFinalize mission operations. | {ended\_at?,completion\_notes?} | 200 {data:Mission} | Flights completed | **P1** | Codex \- Backend/API | **Done** |

### **MSN-01 - GET /api/v1/missions**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MSN-01 / P0 |
| Purpose | Return a stable, paginated mission catalog reachable through the caller's tenant-owned survey sites. |
| Required permission | `missions.read`, accepted only from global/current-organization roles after Sanctum and active-identity checks. |
| Dependencies | SITE-01, organizations/users, survey sites, tenant-aware RBAC, standard pagination and the documented mission schema. |
| Request / validation | Optional UUID `site_id`, normalized lifecycle `status`, inclusive `from`/`to` dates (`YYYY-MM-DD`), case-insensitive `search`, positive `page`, and `per_page` from 1 through 100. Reversed dates fail validation. |
| Success | `200` standard envelope containing safe Mission resources and exact `request_id`, `page`, `per_page`, `total`, `last_page` metadata. Physical `mission_status` is intentionally projected as public `status`. |
| Errors | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND` for an explicit hidden/missing site filter; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected failures remain `500`. |
| Workflow / tenant scope | Missions are selected only through non-deleted sites whose `organization_id` matches the actor. A supplied site filter is resolved through the same anti-enumeration site service before querying. Foreign missions and missions under soft-deleted sites never affect data or pagination. |
| Query behavior | Search covers code, title and objective. Site, status, planned-start date and search filters compose. Scheduled missions sort by planned start then UUID; unscheduled missions sort last on both supported databases. |
| Database schema | Adds UUID-backed `survey_missions` with documented planning/actual timestamps, target/completed coverage, creator/approver references, soft deletion, code uniqueness, site/status and site/date indexes, and PostgreSQL lifecycle check (`planned`, `in_progress`, `completed`, `cancelled`, `failed`). |
| Side effects / audit / notifications | Read-only mission lookup. No audit event or notification is created. |
| Database privileges | `005_survey_mission_grants.sql` grants `SELECT` only to API and reporting roles; insert/update/delete plus worker/auditor access remain denied. |
| Tests | `tests/Feature/Mission/MissionIndexTest.php` covers exact fields/meta/order, site-lineage and soft-delete isolation, composed filters, hidden explicit sites, validation, authentication, missing/foreign-role permission rejection, no audit side effect and throttling. |
| Implementation status | Done - focused and complete SQLite plus PostgreSQL 18/PostGIS suites pass at 120 tests / 698 assertions; PostgreSQL confirms the lifecycle constraint and DCL matrix; touched PHP passes Pint, Composer/routes validate, and diff checks are clean. |

### **MSN-02 - POST /api/v1/missions**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MSN-02 / P0 |
| Purpose / permission | Create a planned mission in one visible site; requires tenant-valid `missions.create`. |
| Dependencies | MSN-01, scoped site resolution, mission schema/resource and immutable audit storage. |
| Request / validation | Required site UUID, normalized unique code, title and objective; optional ordered planning timestamps and non-negative `NUMERIC(12,4)` coverage target. |
| Success / errors | `201 {data:Mission}` plus request ID; standard `401`, `403`, anti-enumeration `404`, `422`, `429`, and unexpected `500`. |
| Tenant / workflow | Site ownership derives from scoped resolution; foreign/missing sites are hidden. New missions always start `planned`; clients cannot inject lifecycle/actor/approval fields. |
| Transaction / audit | Mission insertion and immutable `mission.create` evidence share one transaction; audit failure rolls back creation. No notification is required at initial planning. |
| Database privileges | Mission DCL grants API `SELECT, INSERT` and reporting `SELECT`; update/delete remain denied. |
| Tests | `tests/Feature/Mission/MissionStoreTest.php` covers output/persistence, normalization, time/precision/uniqueness validation, hidden sites, audit/rollback, authentication, local/foreign RBAC and throttling. |
| Implementation status | Done - complete SQLite and PostgreSQL suites pass at 127 tests / 733 assertions; touched Pint, Composer, DCL and diff gates pass. |

### **MSN-03 - GET /api/v1/missions/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MSN-03 / P0 |
| Purpose / permission | Return one mission, ordered team and flight-status summary; requires tenant-valid `missions.read`. |
| Tenant / response | Mission lookup follows non-deleted site organization lineage. Success is exact `{mission,team,flight_summary}` plus request ID; foreign/missing/deleted/malformed IDs share 404. |
| Team schema | Adds UUID `mission_team_members`, mission/user FKs, assignment timestamp, mission/role index and unique mission/user/role invariant. |
| Flight summary | Stable `total`, `planned`, `flying`, `completed`, `aborted`, `failed` integers; reads real `flight_sessions` once its drone-dependent FLT-01 migration lands and returns zeros beforehand. |
| Side effects / DCL | Read-only, with no audit/notification. Team DCL grants API/reporting `SELECT` only. |
| Tests / status | `MissionShowTest` covers exact shape/order, tenant 404s, RBAC, no audit and throttling. Done at 132 tests / 750 assertions on SQLite and PostgreSQL; Pint, Composer, DCL and diff gates pass. |

### **MSN-04 - PATCH /api/v1/missions/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MSN-04 / P0 |
| Purpose / permission | Partially update mission planning fields before lifecycle finalization; requires tenant-valid `missions.update`. |
| Request / validation | One or more of site, code, title, objective, planned start/end and coverage target. Codes normalize/retain uniqueness; final combined time window and numeric precision are validated. Lifecycle, actual, creator and approval fields are not accepted. |
| Workflow / tenant | Only `planned` missions may change; later states return standard `409 CONFLICT`. Mission and replacement-site lookup both use anti-enumeration organization scope. |
| Transaction / audit | Update and immutable `mission.update` old/new evidence share one transaction; audit failure restores the prior row. |
| Errors / DCL | Standard 401/403/404/409/422/429/500. API gains `UPDATE` only; delete and reporting mutation remain denied. |
| Tests / status | `MissionUpdateTest` covers partial success, site move, conflict, validation, tenant hiding, audit rollback, RBAC and throttling. Complete suites pass at 140 tests / 779 assertions; focused PostgreSQL, Pint, Composer, DCL and diff gates pass. |

### **TEAM-01 - PUT /api/v1/missions/{id}/team**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Atomically replace the complete mission team; requires tenant-valid `mission_team.manage`. |
| Validation / tenant | Present array (including empty) of up to 50 pilot/observer/validator/researcher assignments; duplicate user/role pairs fail validation and every user must be active in the mission organization. |
| Workflow / response | Changes are allowed only while planned and unapproved; otherwise 409. Returns sorted safe MissionTeamMember resources plus request ID. |
| Transaction / audit | Delete/insert replacement and immutable `mission.team.replace` before/after evidence share one transaction and roll back together. |
| DCL / tests | API receives team `SELECT,INSERT,DELETE` but no update; reporting remains read-only. `MissionTeamReplaceTest` covers full/empty sets, scope, duplicates, conflict, rollback, access and throttling. Complete suites pass at 147 tests / 806 assertions with PostgreSQL, Pint, Composer, DCL and diff gates green. |

### **MSN-06 - POST /api/v1/missions/{id}/approve**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Record one final approved/rejected decision; requires tenant-valid `missions.approve`. |
| Schema reconciliation | The documented schema has `approved_by` and lifecycle `cancelled`, not approval-status/notes columns. Approval preserves `planned` and records approver; rejection transitions to `cancelled`; decision/notes persist in immutable audit evidence. |
| Workflow / concurrency | Tenant-scoped mission resolution plus transaction row lock; only undecided planned missions are eligible. Repeated or later-state decisions return 409. |
| Transaction / audit | State/approver update and `mission.approval` old/new decision evidence share one transaction and roll back together. Existing mission UPDATE privilege is sufficient. |
| Tests / status | `MissionApprovalTest` covers both decisions, mapping, duplicate conflict, validation, tenant hiding, rollback, auth and throttling. Complete SQLite suite passes 155 tests / 832 assertions; full/focused PostgreSQL, Pint, Composer and diff gates pass. |

### **MSN-07 - POST /api/v1/missions/{id}/start**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Transition an approved tenant mission from `planned` to `in_progress`. Appendix B defines no `missions.start` permission, so the endpoint uses tenant-valid `missions.update` after Sanctum and active-identity checks. |
| Request / success | Accepts optional nullable `started_at`; omission/null uses server time. A supplied offset-aware instant is normalized to UTC. Returns the complete safe Mission resource plus request ID, with mission lifecycle timestamps serialized consistently in UTC on SQLite and PostgreSQL. |
| Workflow / tenant | The mission must remain planned and have `approved_by`; unapproved or already transitioned missions return `409 CONFLICT`. Foreign, deleted, missing, and malformed IDs use the existing tenant anti-enumeration `404`. |
| Transaction / audit / DCL | The row is locked, status/time update and immutable `mission.start` before/after evidence share one transaction, and audit failure restores the planned state. Existing survey-mission UPDATE and audit INSERT grants are sufficient; no DCL expansion or notification is required. |
| Tests / status | `MissionStartTest` covers explicit/default time, approval/state gates, validation, anti-enumeration, rollback, local/foreign RBAC, inactive identity and throttling. Done - full SQLite passes 407 tests / 2289 assertions and PostgreSQL 18/PostGIS passes 407 / 2299; focused suites, route, Pint, Composer, DCL and diff gates pass. |

### **MSN-08 - POST /api/v1/missions/{id}/complete**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Finalize an active tenant mission after flight operations; requires the documented tenant-valid `missions.complete` permission. |
| Request / success | Optional nullable `ended_at` defaults to server time and normalizes to UTC; optional trimmed `completion_notes` is bounded to 5000 characters. Returns the safe Mission resource plus request ID with `completed` status and `actual_end_at`. |
| Workflow gate | The mission must be `in_progress` with an actual start, must contain at least one flight, and every attached flight must be `completed`. Zero-flight and mixed lifecycle sets return `409 CONFLICT` with count/status details. Completion time must be strictly after mission start. |
| Schema reconciliation | The authoritative mission table has no completion-notes column. Notes therefore persist in immutable audit evidence, matching MSN-06's documented decision-note treatment, without inventing a physical field or exposing an undocumented response property. |
| Transaction / audit / DCL | Mission and flight rows are locked; transition/time update and `mission.complete` evidence share one transaction. Existing API mission UPDATE and audit INSERT grants suffice; mission DELETE, report/worker mutation and audit UPDATE remain denied. No notification is required. |
| Tests / status | `MissionCompleteTest` covers explicit/default completion, notes evidence, every lifecycle/flight gate, validation/time order, anti-enumeration, rollback, local/foreign RBAC, inactive identity and throttling. Done - full SQLite passes 416 tests / 2355 assertions and PostgreSQL 18/PostGIS passes 416 / 2365; focused suites, route, Pint, Composer, live DCL and diff gates pass. |

## **Flight operations and field readiness**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| FLT-01 | GET /missions/{id}/flightsList mission sorties. | Query: status,quality\_status,page | 200 {data:\[Flight\],meta} | MSN-03 | **P0** | Codex \- Backend/API | **Done** |
| FLT-02 | POST /missions/{id}/flightsCreate flight sortie. | {drone\_id,pilot\_user\_id,flight\_code,planned\_altitude\_meters?,notes?} | 201 {data:Flight} | MSN-06 \+ DRONE-01 | **P0** | Codex \- Backend/API | **Done** |
| FLT-03 | GET /flights/{id}Flight detail/readiness summary. | Path: id | 200 {data:{flight,checklists,waypoint\_count,media\_count}} | FLT-01 | **P0** | Codex \- Backend/API | **Done** |
| FLT-04 | PATCH /flights/{id}Update planned flight metadata. | Partial Flight fields | 200 {data:Flight} | FLT-03 | **P1** | Codex \- Backend/API | **Done** |
| CHK-01 | POST /flights/{id}/checklistsSubmit pre/post-flight checklist. | {checklist\_type,battery\_ok,weather\_ok,gps\_ok,camera\_ok,lidar\_depth\_ok,storage\_ok,overall\_status,remarks?} | 201 {data:Checklist} | FLT-03 | **P0** | Codex \- Mobile/API | **Done** |
| FLT-05 | POST /flights/{id}/startStart flight only after required preflight gate. | {started\_at,takeoff\_location?:GeoJSON} | 200 {data:Flight} | CHK-01 passed | **P0** | Codex \- Mobile/API | **Done** |
| FLT-06 | POST /flights/{id}/completeComplete flight and capture landing summary. | {ended\_at,landing\_location?:GeoJSON,actual\_avg\_altitude\_meters?,notes?} | 200 {data:Flight} | FLT-05 | **P0** | Codex \- Mobile/API | **Done** |
| FLT-07 | POST /flights/{id}/failAbort/fail flight with reason. | {status:"aborted"|"failed",reason,ended\_at?} | 200 {data:Flight} | FLT-05 | **P1** | Codex \- Mobile/API | **Done** |
| WPT-01 | PUT /flights/{id}/waypointsBatch replace ordered route waypoints. | {waypoints:\[{sequence\_no,location:GeoJSON,altitude\_meters?,speed\_mps?,action?}\]} | 200 {data:{count}} | FLT-03 | **P1** | Codex \- GIS/API | **Done** |
| ENV-01 | POST /flights/{id}/environment-logsAppend environment observation. | {recorded\_at,weather\_condition,wind\_speed\_mps?,temperature\_celsius?,humidity\_percent?,visibility\_status?,notes?} | 201 {data:EnvironmentLog} | FLT-03 | **P2** | TBD \- Mobile/API | Backlog |
| BAT-03 | POST /flights/{id}/battery-usageRecord battery use for sortie. | {battery\_id,start\_percentage,end\_percentage,usage\_minutes?,notes?} | 201 {data:BatteryUsage} | FLT-03 \+ BAT-01 | **P2** | TBD \- Mobile/API | Backlog |

### **FLT-01 - GET /api/v1/missions/{id}/flights**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Return a stable paginated sortie list for one mission; requires tenant-valid `flights.read` after Sanctum and active-identity checks. |
| Tenant / anti-enumeration | Parent resolution uses the shared mission scope through non-deleted site organization lineage. Foreign, deleted, missing, and malformed mission identifiers all return the standard 404 without exposing flight counts. |
| Request / response | Optional normalized `status`, `quality_status`, positive `page`, and `per_page` 1 through 100. Success contains exact safe Flight resources plus `request_id`, `page`, `per_page`, `total`, and `last_page`; physical `flight_status` is projected as public `status`. |
| Filters / ordering | Flight lifecycle accepts `planned`, `flying`, `completed`, `aborted`, or `failed`; quality accepts `pending`, `acceptable`, `rejected`, or `needs_recapture`. Filters compose and output sorts by code then UUID. |
| Database schema | Adds authoritative UUID `flight_sessions` with mission, drone and optional pilot FKs, globally unique code, timing/altitude/duration/notes fields, indexes for mission lifecycle/quality and FK navigation, and both PostgreSQL state constraints. |
| Spatial behavior | Takeoff and landing are genuine PostGIS `POINT(4326)` columns with GiST indexes and GeoJSON API projection. SQLite JSON exists only as a fast compatibility-test substitute. |
| Side effects / privileges | Read-only; no audit event or notification. The initial DCL granted API/reporting SELECT only; FLT-02 subsequently adds API INSERT while reporting remains read-only. This schema also activates MSN-03's real flight-summary query. |
| Tests / status | `MissionFlightIndexTest` covers exact fields/meta/GeoJSON, filters, validation, anti-enumeration, authentication, tenant-scoped RBAC, no audit, throttling, constraints and DCL. Done - full SQLite passes 170 tests / 928 assertions and PostgreSQL 18/PostGIS passes 170 / 930; route, Pint, Composer, DCL and diff gates pass. |

### **FLT-02 - POST /api/v1/missions/{id}/flights**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Create one planned sortie; requires tenant-valid `flights.create` after Sanctum and active-identity checks. |
| Request / response | Required tenant-owned drone UUID, active pilot UUID and normalized globally unique flight code; optional non-negative `NUMERIC(8,2)` planned altitude and notes. Success is `201 {data:Flight}` plus request ID with server-controlled `planned` flight and `pending` quality states. |
| Mission workflow | The explicit MSN-06 dependency is enforced: only an approved mission still in `planned` state accepts new sorties. Unapproved, rejected/cancelled or later-state missions return standard 409 conflict. Mission lookup retains tenant anti-enumeration. |
| Resource workflow | Drone and pilot are resolved inside the caller's organization under transaction locks. Foreign/missing/deleted identifiers return 404; a maintenance/retired drone or inactive pilot returns 409. The contract does not require pilot team membership, so no undocumented TEAM-01 dependency is added. |
| Transaction / audit | Mission, drone and pilot state are locked and rechecked; flight insertion and immutable `flight.create` evidence share one transaction. Audit failure rolls back the sortie. No notification is required at planning time. |
| Database privileges | Flight DCL grants API `SELECT, INSERT`, reporting `SELECT`, and continues to deny UPDATE and DELETE. |
| Tests / status | `MissionFlightStoreTest` covers exact normalized persistence/audit, validation/uniqueness, approval and resource conflicts, tenant hiding, audit rollback, local/foreign RBAC and throttling. Done - full SQLite passes 179 tests / 989 assertions and PostgreSQL 18/PostGIS passes 179 / 991; route, Pint, Composer, DCL and diff gates pass. |

### **FLT-03 - GET /api/v1/flights/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Return one sortie with ordered checklist readiness evidence and stable waypoint/media child counts; requires tenant-valid `flights.read`. |
| Tenant / anti-enumeration | Flight resolution follows mission -> non-deleted site -> organization lineage. Foreign flights and flights below soft-deleted missions, plus missing/malformed UUIDs, return the same standard 404. |
| Response | Exact `data:{flight,checklists,waypoint_count,media_count}` plus request ID. Checklists sort by evidence time then UUID. Media count remains zero until the `media_assets` schema lands, then counts only non-deleted rows without changing the response contract. |
| Child schema | Adds authoritative `flight_waypoints` with unique per-flight sequence, optional motion/action metadata and PostGIS `POINT(4326)` + GiST; adds `flight_checklists` with checker FK, documented boolean evidence and type/status domains. These shared tables are physical prerequisites for CHK-01 and WPT-01 but those write cards are not claimed here. |
| Side effects / privileges | Read-only; no audit or notification. API/reporting roles receive SELECT only on both child tables. |
| Tests / status | `FlightShowTest` covers exact detail/checklist fields/order, GeoJSON, real waypoint count, forward-compatible media count, tenant/deleted-lineage hiding, local/foreign RBAC, no audit, throttling, domains and DCL. Done - full SQLite passes 185 tests / 1028 assertions and PostgreSQL 18/PostGIS passes 185 / 1031; route, Pint, Composer, DCL and diff gates pass. |

### **FLT-04 - PATCH /api/v1/flights/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Partially update planning metadata for one tenant flight; requires tenant-valid `flights.update`. |
| Request / response | At least one of tenant-owned available `drone_id`, active tenant `pilot_user_id`, normalized globally unique `flight_code`, nullable non-negative two-decimal planned altitude, or nullable trimmed notes. Returns the exact safe Flight resource plus request ID. |
| Workflow / tenant | Only `planned` flights may change. Mission ownership, lifecycle/quality state, actual timestamps/locations/altitude and duration remain server-controlled. Target flight and replacement resources use tenant anti-enumeration; unavailable drones/inactive pilots return 409. |
| Concurrency / audit | The flight and replacement resources are row-locked. Changed codes use a PostgreSQL advisory lock before global duplicate recheck. Update and immutable `flight.update` complete before/after evidence share one rollback-safe transaction. |
| DCL / tests | Existing flight DCL already grants API SELECT/INSERT/UPDATE while denying DELETE; reporting remains SELECT-only and worker UPDATE stays denied. `FlightUpdateTest` covers full/partial/null edits, validation, duplicate/resource conflicts, tenant hiding, lifecycle, rollback, RBAC and throttling. Done - full SQLite passes 426 tests / 2418 assertions and PostgreSQL 18/PostGIS passes 426 / 2428; focused suites, route, Pint, Composer, live privilege and diff gates pass. |

### **CHK-01 - POST /api/v1/flights/{id}/checklists**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Append pre-flight or post-flight readiness evidence; requires tenant-valid `checklists.submit`. |
| Request / response | Required normalized documented type, six strict booleans, documented overall status and optional trimmed remarks. Success is `201 {data:Checklist}` plus request ID; `checked_by` and evidence time are server controlled. |
| Lifecycle | Pre-flight evidence is accepted only while the flight is `planned`; post-flight evidence is accepted only after `completed`, `aborted`, or `failed`. Invalid combinations return 409. |
| Repeat behavior | The authoritative schema/manual specifies no unique flight/type invariant, so repeated submissions are append-only evidence rather than overwrites. FLT-05 evaluates the latest pre-flight record deterministically by `created_at` then UUID. |
| Tenant / transaction / audit | Flight lookup follows mission/site organization lineage and hides foreign/deleted-lineage IDs. A locked flight-state recheck, checklist insertion and immutable `flight.checklist.submit` audit share one transaction; audit failure rolls back evidence. |
| Database privileges | API receives checklist `SELECT, INSERT`, reporting remains SELECT-only, and waypoint privileges remain unchanged/read-only; no update/delete grants. |
| Tests / status | `FlightChecklistStoreTest` covers normalized persistence and exact audit, repeat evidence, validation, lifecycle conflicts, tenant hiding, rollback, local/foreign RBAC and throttling. Done - full SQLite passes 194 tests / 1083 assertions and PostgreSQL 18/PostGIS passes 194 / 1086; route, Pint, Composer, DCL and diff gates pass. |

### **FLT-05 - POST /api/v1/flights/{id}/start**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Transition a ready planned sortie to `flying`; requires tenant-valid `flights.start`. |
| Readiness / concurrency | The flight row and latest deterministic pre-flight checklist are locked. Only `planned` with latest `passed` evidence can transition; missing, failed, conditional, repeated and later-state starts return 409. |
| Request / spatial behavior | Required start timestamp preserves the submitted instant across database session timezones. Optional takeoff is validated GeoJSON Point and persisted as PostGIS `POINT(4326)`. |
| Transaction / audit / privileges | State, timestamp, optional geometry and immutable `flight.start` evidence share one transaction. API flight privileges expand to UPDATE while reporting remains SELECT-only and DELETE remains denied. |
| Tests / status | `FlightStartTest` covers readiness ordering, lifecycle, UTC instants, PostGIS geometry, validation, tenant hiding, rollback, local/foreign RBAC and throttling. Done - full SQLite passes 203 tests / 1128 assertions and PostgreSQL 18/PostGIS passes 203 / 1131; live privilege and static gates pass. |

### **FLT-06 - POST /api/v1/flights/{id}/complete**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Complete one active sortie with its landing summary; requires tenant-valid `flights.complete`. |
| Lifecycle / time | A row-locked flight must be `flying` with a stored start time, and the required end instant must be strictly later. Repeated, unstarted and invalid-time completions return 409. Duration in minutes is derived server-side to two decimals. |
| Request / spatial behavior | Optional nullable GeoJSON landing Point, non-negative two-decimal average altitude and trimmed notes follow omitted-versus-explicit-null semantics. PostgreSQL stores landing as `POINT(4326)` and timestamp instants remain offset-safe. |
| Transaction / audit / privileges | Lifecycle, end time, duration and optional summary updates share one transaction with immutable `flight.complete` old/new evidence. Existing API UPDATE privilege is sufficient; reporting stays read-only. |
| Tests / status | `FlightCompleteTest` covers full/minimal/null summaries, lifecycle/time ordering, validation, PostGIS geometry, tenant hiding, rollback, local/foreign RBAC and throttling. Done - full SQLite passes 213 tests / 1188 assertions and PostgreSQL 18/PostGIS passes 213 / 1191; route, Pint, DCL and diff gates pass. |

### **FLT-07 - POST /api/v1/flights/{id}/fail**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Terminate one active tenant sortie as `aborted` or `failed`. Appendix B defines no separate failure permission, so the transition uses tenant-valid `flights.complete`, the documented terminal-flight authority. |
| Request / response | Required normalized terminal status and trimmed reason up to 5000 characters; optional nullable `ended_at` defaults to server time. Success returns the safe Flight resource plus request ID, canonical UTC timestamps and computed duration. The authoritative flight `notes` field stores the terminal reason. |
| Workflow / tenant | Only a `flying` flight with a start time may transition, and end must be strictly after start. Planned/already-terminal states return `409 CONFLICT`; foreign, deleted-lineage, missing and malformed IDs remain hidden. |
| Transaction / audit / DCL | Row lock, lifecycle/time/duration/reason update, sync-version increment and immutable `flight.fail` evidence share one rollback-safe transaction. Existing API UPDATE privilege suffices; reporting stays read-only and DELETE/worker mutation remain denied. |
| Tests / status | `FlightFailTest` covers both outcomes, explicit/default UTC timing, duration/reason, validation/time ordering, state/tenant hiding, rollback, permission and throttling. Done - full SQLite passes 432 tests / 2464 assertions and PostgreSQL 18/PostGIS passes 432 / 2474; focused suites, route, Pint, Composer, DCL and diff gates pass. |

### **WPT-01 - PUT /api/v1/flights/{id}/waypoints**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Atomically replace the complete waypoint route for one tenant flight; requires documented `flights.update`. |
| Request / validation | Requires a present array of up to 1000 waypoints and accepts empty to clear. Each item has a distinct non-negative sequence, strict WGS84 GeoJSON Point, optional nullable non-negative two-decimal altitude/speed, and optional nullable action (`capture`, `turn`, `hover`, `return_home`). The authoritative schema does not require contiguous or one-based sequences, so sparse/zero sequences remain valid. |
| Workflow / spatial | Replacement is allowed only while the flight is `planned`; later states return 409 and foreign/deleted-lineage IDs remain hidden. PostgreSQL persists genuine `POINT(4326)` geometry while SQLite JSON is the test compatibility substitute. |
| Transaction / audit | Flight row lock, full delete/insert replacement and immutable `flight.waypoints.replace` before/after route evidence share one transaction. Audit failure restores the previous complete route. No notification is required. |
| DCL / tests | `022_flight_waypoint_write_grants.sql` adds API INSERT/DELETE only to existing SELECT; API UPDATE remains denied, reporting remains SELECT-only and worker mutation is denied. Done - full SQLite passes 439 tests / 2516 assertions and PostgreSQL 18/PostGIS passes 439 / 2526; focused suites, route, Pint, Composer, live privilege and diff gates pass. |

## **Mobile offline synchronization**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| SYNC-01 | POST /mobile/devices/registerRegister app installation for sync/audit. | {device\_id,platform,app\_version,device\_name?} | 201 {data:{device\_id,server\_time}} | AUTH-02 \+ schema extension | **P0** | Codex \- Mobile/API | **Done** |
| SYNC-02 | GET /mobile/bootstrapDownload authorized mission/flight reference bundle. | Query: cursor? | 200 {data:{missions,flights,checklist\_templates,settings,tombstones},meta:{cursor,server\_time}} | MSN/FLT \+ AUTH | **P0** | Codex \- Mobile/API | **Done** |
| SYNC-03 | GET /mobile/missions/{id}/bundleDownload one mission for offline use. | Path: mission id | 200 {data:{mission,site,flights,team,boundaries,plots}} | MSN-06 | **P0** | Codex \- Mobile/API | **Done** |
| SYNC-04 | POST /mobile/syncPush offline changes and receive server changes/conflicts. | {device\_id,base\_cursor,changes:\[{client\_id,entity,operation,version,payload}\]} | 200 {data:{applied,conflicts,server\_changes},meta:{cursor}} | SYNC-01 \+ all mutable mobile resources | **P0** | TBD \- Mobile/API | **Blocked** |
| SYNC-05 | GET /mobile/sync/statusShow pending server work relevant to device. | Query: device\_id | 200 {data:{last\_cursor,last\_sync\_at,pending\_notifications}} | SYNC-04 | **P1** | TBD \- Mobile/API | **Blocked** |

### **SYNC-01 - POST /api/v1/mobile/devices/register**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Register an authenticated active user's app installation for offline synchronization and audit correlation. No undocumented permission is added beyond the AUTH-02 identity prerequisite. |
| Request / response | Required UUID installation ID, normalized `android`/`ios`/`web` platform and bounded app version; optional nullable trimmed device name. Returns exact `201 {data:{device_id,server_time}}` plus request ID. |
| Ownership / idempotency | A device UUID is permanently scoped to its registering user. Identical retries return the same registration without extra audit or timestamp churn; owned metadata changes update in place; another account receives 409 without exposing user details. |
| Schema / transaction / DCL | Adds UUID `sync_devices` with user FK, platform constraint, cursor and last-sync fields needed by SYNC-04/05. Registration/update and `sync.device.register` audit share one transaction. API receives SELECT/INSERT/UPDATE only; report/worker roles receive no device access. |
| Tests / status | `SyncDeviceRegisterTest` covers exact response/persistence, retry idempotency, metadata refresh/null, cross-account conflict, validation, active identity, rollback, throttling, PostgreSQL constraint and DCL. Done - full SQLite passes 222 tests / 1238 assertions and PostgreSQL 18/PostGIS passes 222 / 1242; live privilege and static gates pass. |

### **SYNC-02 - GET /api/v1/mobile/bootstrap**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Download a stable offline reference snapshot after active identity plus tenant-valid `missions.read` and `flights.read`. It is read-only and creates no audit event. |
| Full / delta behavior | Without a cursor, returns all non-deleted tenant missions and their flights. A valid cursor returns mission/flight rows updated after its boundary through the response snapshot time plus tenant mission tombstones deleted in that interval. |
| Cursor / consistency | Cursors are encrypted, versioned and opaque; tampered, unsupported or future boundaries return 422. Queries use one transaction and a shared server-time upper boundary, with explicit offset-safe PostgreSQL bindings. |
| Contract / forward compatibility | Returns exact mission and Flight resource arrays plus structurally stable `checklist_templates`, `settings` and `tombstones`. The first two remain explicit empty arrays until their authoritative P0/P1 schemas land; no P2 endpoint is claimed. |
| Tests / status | `MobileBootstrapTest` covers full and delta snapshots, tenant/deleted isolation, tombstones, cursor tampering/future validation, active identity, both local/foreign permissions, no audit and throttling. Done - full SQLite passes 228 tests / 1272 assertions and PostgreSQL 18/PostGIS passes 228 / 1276; route, Pint and diff gates pass. |

### **SYNC-03 - GET /api/v1/mobile/missions/{id}/bundle**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Download one approved tenant mission's field graph after active identity and tenant-valid mission, flight and site read permissions. It is read-only and creates no audit event. |
| Approval / anti-enumeration | A recorded MSN-06 approver is required, while approved missions remain downloadable in later lifecycle states. Unapproved, foreign, soft-deleted and missing missions all return the documented 404 rather than exposing approval or tenancy. |
| Bundle composition | Returns exact `{mission,site,flights,team,boundaries,plots}` using existing safe resources, stable child ordering, and real PostGIS Point/Polygon GeoJSON. Only the target mission/site graph is included. |
| Forward compatibility | `plots` remains an explicit empty array until P1 PLOT-01/02 deliver the authoritative monitoring plot schema; the bundle contract will not change and those endpoint cards are not claimed here. |
| Tests / status | `MobileMissionBundleTest` covers exact graph/spatial serialization, approval and later lifecycle behavior, unavailable mission hiding, active identity, three local/foreign permissions, no audit and throttling. Done - full SQLite passes 234 tests / 1301 assertions and PostgreSQL 18/PostGIS passes 234 / 1305; route, Pint and diff gates pass. |

### **SYNC-04 shared foundation (endpoint remains blocked)**

| Implementation field | Detail |
| :---- | :---- |
| Dependency boundary | The public push endpoint is intentionally not registered until every P0/P1 mobile mutation it must reconcile is implemented. Its tracker card remains Not Done / Blocked. |
| Schema / versioning | Adds request-idempotency, applied-change and conflict ledgers plus monotonic flight `sync_version`; existing server-side flight start/completion mutations advance that version atomically. Device cursors use unbounded text because encrypted cursor envelopes exceed the former 150-character limit. |
| DCL / tests | API has only the ledger permissions needed for a future sync transaction; report and worker roles receive none. `MobileSyncInfrastructureTest` verifies the portable schema, uniqueness guards and versioned least-privilege DCL without exposing `/mobile/sync`. |

## **Media, sensor uploads and quality control**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| MEDIA-01 | GET /flights/{id}/mediaList captured image/video metadata. | Query: type,quality\_status,processing\_status,page | 200 {data:\[MediaAsset\],meta} | FLT-03 | **P0** | Codex \- Backend/API | **Done** |
| MEDIA-02 | POST /flights/{id}/media/uploadsInitiate resumable/private upload. | {file\_name,file\_type,mime\_type,file\_size\_bytes,checksum\_sha256?,capture\_location?:GeoJSON,captured\_at?,metadata?} | 201 {data:{upload\_id,storage\_key,upload\_url?|parts?}} | FLT-05/06 \+ storage | **P0** | Codex \- Storage/API | **Done** |
| MEDIA-03 | POST /media/uploads/{uploadId}/completeFinalize upload after checksum/size validation. | {parts? ,checksum\_sha256?} | 201 {data:MediaAsset} | MEDIA-02 | **P0** | Codex \- Storage/API | **Done** |
| MEDIA-04 | GET /media/{id}Return private-storage-safe media metadata; download URL/token issuance remains exclusive to MEDIA-05. | Path: id | 200 {data:MediaAsset} | MEDIA-03 | **P0** | Codex \- Storage/API | **Done** |
| MEDIA-05 | POST /media/{id}/downloadIssue temporary private download URL or stream token. | No body | 200 {data:{url,expires\_at}} or streamed file | MEDIA-04 | **P1** | TBD \- Storage/API | **Blocked** |
| MEDIA-06 | PATCH /media/{id}/qualitySet QC result. | {quality\_score?,quality\_status,notes?} | 200 {data:MediaAsset} | MEDIA-04 | **P0** | Codex \- Backend/API | **Done** |
| MEDIA-07 | DELETE /media/{id}Soft-delete unneeded media after dependency check. | Path: id | 204 | MEDIA-04 | **P2** | TBD \- Storage/API | Backlog |
| SDS-01 | POST /flights/{id}/sensor-datasets/uploadsUpload LiDAR/depth/GPS/IMU dataset. | {file\_name,dataset\_type,file\_format,sensor\_id,file\_size\_bytes,spatial\_reference?,metadata?} | 201 {data:{upload\_id,...}} | FLT-03 \+ storage | **P1** | Codex \- Storage/API | **Done** |
| SDS-02 | POST /sensor-datasets/uploads/{uploadId}/completeFinalize sensor dataset. | {checksum\_sha256?} | 201 {data:SensorDataset} | SDS-01 | **P1** | Codex \- Storage/API | **Done** |

### **MEDIA-04 — GET /api/v1/media/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MEDIA-04 / P0 |
| Approved boundary / permission | Returns metadata only using the established private-storage-safe `MediaAsset` projection and tenant-valid `media.read`. MEDIA-05 remains the sole endpoint authorized to issue a temporary private download URL or stream token. |
| Exact response / privacy | Returns exact `200 {data:MediaAsset}` plus request trace metadata. The response excludes `storage_key`, URL, preview/download pointer, token and expiry fields, and the endpoint never calls the storage adapter. |
| Tenant and lifecycle scope | Resolves non-deleted media through flight, mission and non-deleted site organization lineage. Foreign, soft-deleted, missing, malformed and deleted-parent records are indistinguishable 404s. The read creates no audit, notification or mutation. |
| DCL / tests / status | Reuses `012_media_asset_grants.sql`: API and reporting roles have SELECT only, with no worker access or new write privilege. `MediaShowTest` covers the exact safe shape, PostGIS Point(4326), private-field exclusion, tenant/deleted-lineage hiding, authentication, tenant-valid RBAC, inactive identity, throttling and no side effects. Done — focused SQLite media suite passes 23 tests / 203 assertions and PostgreSQL 18/PostGIS passes 23 / 204; full SQLite passes 623 / 3923 with nine PostgreSQL-only skips and full PostgreSQL passes 632 / 3953. |

### **MEDIA-06 — PATCH /api/v1/media/{id}/quality**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MEDIA-06 / P0 |
| Purpose / permission | Sets the quality-control result on non-deleted tenant-visible media; requires an active identity and tenant-valid `media.quality_review`. Foreign, missing, malformed, soft-deleted and deleted-parent lineage remain indistinguishable 404s. |
| Request / validation | Requires normalized `quality_status` in `pending`, `acceptable`, `rejected` or `needs_recapture`. Optional nullable `quality_score` accepts 0 through 100 with at most two decimal places; optional nullable notes are trimmed. Omitted optional fields remain unchanged and explicit null clears them. |
| Transaction / response | A row lock serializes reviews. The QC fields and monotonic media `sync_version` update in the same transaction as immutable `media.quality` audit evidence; audit failure rolls the mutation back. Success returns exact `200 {data:MediaAsset}` plus request ID using the approved private-storage-safe shape, with no URL/token issuance or storage access. No notification is emitted. |
| Audit / DCL | Audit evidence records the actor, media ID, request trace, old/new QC state, sync version, storage key and checksum without a URL or token. `044_media_quality_review_grants.sql` grants the API role UPDATE only on `quality_score`, `quality_status`, `notes`, `sync_version` and `updated_at`; worker/report roles receive no write and asset DELETE remains unavailable. |
| Tests / status | `MediaQualityUpdateTest` covers exact safe response and persistence, normalized/full and partial/null updates, sync versions, audit evidence and rollback, validation/domain bounds, tenant and deleted-lineage hiding, authentication, tenant-valid RBAC, inactive identity, throttling and least-privilege DCL. Done — focused SQLite and PostgreSQL pass 7 tests / 90 assertions each; full SQLite passes 630 / 4013 with nine PostgreSQL-only skips and PostgreSQL 18/PostGIS passes 639 / 4043. Route, Pint, Composer and live allowed/denied column privilege gates pass. |

### **MEDIA-01 - GET /api/v1/flights/{id}/media**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | List non-deleted image/video metadata for one tenant-visible flight; requires active identity and tenant-valid `media.read`. Foreign, missing and malformed flight identifiers remain non-enumerable 404s. |
| Request / response | Optional normalized `type`, `quality_status`, `processing_status`, positive `page`, and `per_page` 1 through 100. Returns exact private-storage-safe MediaAsset resources plus standard request/pagination metadata; storage keys are never projected. |
| Schema / spatial / domains | Adds UUID `media_assets` with flight/uploader lineage, private object metadata, PostGIS Point(4326) capture location, JSONB capture metadata, QC/processing state, sync version and soft deletion. PostgreSQL checks file type, QC state/range, processing state and lowercase SHA-256. |
| Ordering / side effects / DCL | Stable capture-time/UUID ordering is portable across SQLite and PostgreSQL. The read is audit-free. API and reporting roles receive SELECT only; the worker receives no access until a dependent processing endpoint needs it. |
| Tests / status | `FlightMediaIndexTest` covers exact safe shape, pagination, composed normalized filters, PostGIS serialization, soft-delete/tenant isolation, validation, authentication, local/foreign RBAC, no audit, throttling, constraints and DCL. Done - full SQLite passes 243 tests / 1374 assertions and PostgreSQL 18/PostGIS passes 243 / 1379; route, Pint, live privilege and diff gates pass. |

### **MEDIA-02 — POST /api/v1/flights/{id}/media/uploads**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MEDIA-02 / P0 |
| Purpose / permission | Initiate a private direct upload for a tenant-visible flight that is `flying` or `completed`; requires active authentication and tenant-valid `media.upload`. Foreign/missing/malformed flight IDs remain 404 and a planned/failed/aborted flight returns 409 before storage issuance. |
| Request / validation | Requires `Idempotency-Key` (1–100 chars) and the exact documented metadata. Normalizes type/MIME/checksum, accepts bounded image/video MIME pairs and configurable size limit, requires a safe base filename, validates lowercase SHA-256, ISO date, object metadata and optional GeoJSON Point coordinate ranges. The filename never becomes a storage path; only its extension is retained on an opaque UUID key. |
| Storage / response | Persists a durable initiated session and returns exact `{upload_id,storage_key,upload_url}`. Laravel's filesystem adapter issues the real temporary private PUT URL: the configured local disk uses a signed `/storage/...` receiver and an S3-compatible disk can issue its native target when its adapter is installed/configured. Unsupported/invalid transport maps to 503 while retaining the retryable idempotent session. No MediaAsset exists until MEDIA-03 validates the stored object. |
| Idempotency / audit / spatial | User + idempotency key and a canonical request fingerprint are locked transactionally. An identical retry returns the same session/key with a newly issued target and no duplicate audit; changed payload or unusable/expired state returns 409. Capture evidence uses PostGIS Point(4326), JSONB metadata and timezone-safe ISO-8601 storage. New sessions emit immutable `media.upload.initiate` evidence including private storage key/checksum but never signed URL. |
| DCL / tests / status | `031_media_upload_initiation_grants.sql` grants API SELECT/INSERT on upload sessions only; update/delete and report/worker access await dependent cards. `MediaUploadInitiateTest` covers exact response, a real signed local PUT and cleanup, metadata persistence, no premature MediaAsset, idempotency/fingerprint conflict, validation, tenant/workflow/RBAC/inactivity, 503 retryability, throttling, PostGIS constraints and DCL. Done — focused SQLite passes 10 tests / 82 assertions with one PostgreSQL-only skip and PostgreSQL passes 10 / 84; full SQLite passes 535 / 3188 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 535 / 3204. |

### **MEDIA-03 — POST /api/v1/media/uploads/{uploadId}/complete**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MEDIA-03 / P0 |
| Purpose / permission | Verify and atomically finalize one direct private upload. Requires an active authenticated initiator with tenant-valid `media.upload`; the upload must belong to that user and resolve through flight → mission → site to the caller's organization. Foreign, missing and malformed upload IDs remain non-enumerable 404s. |
| Request / verification | Requires `Idempotency-Key` (1–100 chars) and accepts the exact documented optional `parts` and lowercase-normalized SHA-256. The durable session must remain initiated and unexpired. The server streams the private object to compute authoritative SHA-256, checks exact byte size, rejects initiation/completion checksum disagreement, and maps a missing/mismatched object to 409 or a storage inspection outage to 503. Multipart evidence is bounded, validated and included in the canonical completion fingerprint; the current signed direct-PUT transport does not require parts. |
| Transaction / response / spatial | After out-of-transaction object inspection, a second locked transaction rechecks lifecycle and expiry, creates exactly one MediaAsset, copies PostGIS Point(4326), capture time and JSON metadata, marks the session completed, and emits immutable `media.upload.complete` evidence. PostgreSQL uses a single `INSERT … SELECT` so no broad asset update privilege is needed. `201` returns the exact private-storage-safe MediaAsset resource plus request ID; `storage_key` is never projected. |
| Idempotency / concurrency | Completion keys are serialized per user with a PostgreSQL transaction advisory lock and protected by a unique user/key constraint. Same upload + key + canonical payload returns the original asset without storage reinspection, duplicate asset or duplicate audit. Changed completion evidence, another key after completion, or reuse of the key for another upload returns 409. Parts are sorted by part number before fingerprinting so equivalent orderings retry safely. |
| DCL / tests / status | `032_media_upload_completion_grants.sql` adds only selected completion-column UPDATE on upload sessions and selected-column MediaAsset INSERT; API has no full asset INSERT, UPDATE or DELETE, and report/worker roles receive no writes. `MediaUploadCompleteTest` covers exact safe success, real private-object size/hash verification, PostGIS copy, idempotent retry/key conflicts, lifecycle/tenant/RBAC/inactivity, validation, missing/mismatched objects, 503, throttling, schema and DCL. Done — focused SQLite and PostgreSQL pass 11 tests / 85 assertions; full SQLite passes 546 / 3273 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 546 / 3289; route, Pint and live privilege gates pass. |

### **SDS-01 — POST /api/v1/flights/{id}/sensor-datasets/uploads**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | SDS-01 / P1 |
| Purpose / permission | Initiate a private direct upload for LiDAR, depth, GPS or IMU data on a tenant-visible flying/completed flight. Requires active authentication and tenant-valid `media.upload`; the sensor must belong to that flight's drone. Foreign/missing/malformed flights and unrelated sensors remain 404. |
| Request / exact response | Requires `Idempotency-Key` (1–100 chars), safe base filename, documented dataset type/format/sensor/positive bounded size, and optional bounded spatial reference/object metadata. Returns exact `201 {data:{upload_id,storage_key,upload_url}}` plus request ID using the proven private temporary-PUT transport. Storage keys are opaque mission/flight/upload UUID paths. |
| Idempotency / audit / storage | Actor/key and canonical flight/payload are transactionally locked. Identical retries reuse the session and issue a fresh target without duplicate audit; changed/unusable/expired replay returns 409. Durable initiation and secret-free `sensor_dataset.upload.initiate` evidence share a transaction; transport failure leaves the retryable session intact, matching MEDIA-02 semantics. |
| Schema / DCL / tests | Adds durable sensor-upload sessions with UUID flight/sensor/user lineage, fingerprint, private storage metadata, expiry/lifecycle indexes and PostgreSQL type/status/expiry constraints. API receives SELECT/INSERT only; update/completion and report/worker access await SDS-02. `SensorDatasetUploadInitiateTest` covers exact response/persistence, sensor binding, idempotency/conflict, validation, workflow/tenant boundaries, authentication/RBAC/inactivity, throttling, schema and DCL. Done — focused SQLite/PostgreSQL pass 4 tests / 31 assertions each; full SQLite passes 615 / 3766 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 615 / 3786; live privilege gates pass. |

### **SDS-02 — POST /api/v1/sensor-datasets/uploads/{uploadId}/complete**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | SDS-02 / P1 |
| Purpose / permission | Verify and atomically finalize one initiated private sensor-dataset upload. Requires an active authenticated initiator with tenant-valid `media.upload`; upload ownership and flight → mission → site lineage must resolve to the caller's organization. Foreign, missing and malformed upload IDs remain non-enumerable 404s. |
| Request / verification / response | Requires `Idempotency-Key` (1–100 chars) and accepts the exact documented optional lowercase-normalized SHA-256. The server streams the private object to compute authoritative SHA-256, enforces exact byte size and optional checksum, maps missing/mismatched evidence to 409 and storage inspection outage to 503, and returns exact private-storage-safe `201 {data:SensorDataset}` plus request ID. `storage_key` is never projected or readable through the SDS-02 API grant. |
| Transaction / idempotency / audit | A PostgreSQL transaction advisory lock plus actor/key uniqueness serializes completion. Same upload/key/payload replays the original dataset without reinspection, duplicate data or duplicate audit; changed evidence, another key after completion, or reuse on a sibling upload returns 409. Lifecycle and expiry are checked before and after out-of-transaction object inspection. Dataset insertion, completed-session linkage/checksum and immutable `sensor_dataset.upload.complete` evidence commit atomically; mandatory audit failure rolls everything back. |
| Schema / DCL / tests / status | Adds completion fingerprint/key, authoritative checksum, completion time and dataset UUID linkage with PostgreSQL checksum/state consistency constraints. `040_sensor_dataset_upload_completion_grants.sql` grants only selected session UPDATE, selected dataset INSERT and safe response/filter-column SELECT; it grants no full-row write/read, private storage-key SELECT, DELETE or report mutation. `SensorDatasetUploadCompleteTest` covers real private-object verification, exact safe response, lifecycle, tenant/RBAC/inactivity, validation, idempotent replay/key conflicts, 503, audit rollback, throttling, constraints and DCL. Done — focused SQLite/PostgreSQL pass 5 tests / 46 assertions each; full SQLite passes 620 / 3812 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 620 / 3832; route, scoped Pint, Composer, diff and live privilege gates pass. |

## **AI service, model registry and processing jobs**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| AISVC-01 | GET /admin/ai-servicesAI backend overview for administrator. | No body | 200 {data:{services,models,jobs}} | schema extension \+ AUTH | **P1** | Codex \- AI/API | **Done** |
| AISVC-02 | POST /admin/ai-servicesRegister trusted FastAPI backend. | {service\_name,base\_url,api\_key,environment,enabled} | 201 {data:AiService}; key never returned | AISVC schema \+ secret encryption | **P1** | Codex \- AI/API | **Done** |
| AISVC-03 | POST /admin/ai-services/{id}/testHealth-test FastAPI service. | No body | 200 {data:{status,version,latency\_ms}} | AISVC-02 | **P1** | Codex \- AI/API | **Done** |
| AISVC-04 | POST /admin/ai-services/{id}/synchronizePull authoritative /models metadata. | No body | 200 {data:{models\_synced,capabilities}} | AISVC-03 | **P1** | Codex \- AI/API | **Done** |
| AISVC-05 | POST /admin/ai-services/{id}/credentialsRotate encrypted FastAPI key. | {api\_key} | 204 | AISVC-02 | **P2** | Jason Benabente | **Testing** |
| MODEL-01 | GET /ai-modelsList model registry and deployment versions. | Query: type,deployed | 200 {data:\[AiModel\]} | AUTH \+ ai\_models | **P1** | Codex \- AI/API | **Done** |
| MODEL-02 | GET /ai-models/{id}Model detail and versions. | Path: id | 200 {data:{model,versions}} | MODEL-01 | **P1** | Codex \- AI/API | **Done** |
| MODEL-03 | POST /ai-models/{id}/versions/{versionId}/deployMark model version deployed after validation. | {release\_notes?} | 200 {data:AiModelVersion} | MODEL-02 | **P2** | Jason Benabente | **Testing** |
| JOB-01 | GET /processing-jobsList processing jobs. | Query: mission\_id,flight\_id,status,type,page | 200 {data:\[ProcessingJob\],meta} | AUTH \+ processing\_jobs | **P0** | Codex \- AI/API | **Done** |
| JOB-02 | POST /processing-jobsQueue detector/classifier/combined processing. | {mission\_id,flight\_session\_id?,job\_type,media\_ids:\[uuid\],parameters?} | 202 {data:{processing\_job\_id,job\_status:"queued"}} | MEDIA-03 \+ AISVC-04 \+ MODEL-01 | **P0** | Codex \- AI/API | **Done** |
| JOB-03 | GET /processing-jobs/{id}Job status, runs, outputs and errors. | Path: id | 200 {data:{job,model\_runs,output\_summary}} | JOB-02 | **P0** | Codex \- AI/API | **Done** |
| JOB-04 | POST /processing-jobs/{id}/retryRetry failed job idempotently. | {reason?} | 202 {data:ProcessingJob} | JOB-03 failed | **P1** | Codex \- AI/API | **Done** |
| JOB-05 | POST /processing-jobs/{id}/cancelCancel queued/running job when supported. | {reason?} | 200 {data:ProcessingJob} | JOB-03 | **P2** | Jason Benabente | **Testing** |

### **AISVC-01 — GET /api/v1/admin/ai-services**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AISVC-01 / P1 |
| Purpose / permission | Give an active administrator a database-backed AI operations overview; requires tenant-valid `ai_services.manage`. A permission attached only through another organization's role cannot authorize the caller. The endpoint does not probe FastAPI, so an overview read cannot leak topology credentials or fail because a backend is offline. |
| Success / scope | Returns exact `data:{services,models,jobs}` plus request ID. `services` is the global registry sorted enabled first and then by name/UUID; it exposes operational configuration and stored health/sync evidence but never `api_key` or `encrypted_api_key`. `models` contains global non-deleted model/deployed/version counts. `jobs` contains fixed lifecycle counts constrained through mission → active site → caller organization lineage, with no job identifiers, inputs, errors, or foreign-tenant counts. |
| Database foundation | Adds the authoritative UUID `ai_services` registry required by AISVC-02–04: unique URL and name/environment identity, encrypted credential storage, enabled state, constrained health state, version/capabilities, health/sync timestamps, and creator lineage. This foundation does not register, test, or synchronize a service and therefore does not claim dependent routes. |
| DCL / side effects | `027_ai_service_overview_grants.sql` grants the API role column-level SELECT on safe service fields only. It cannot select the encrypted key or whole row and has no writes; report/worker roles receive nothing. The overview creates no audit/notification and makes no HTTP request. |
| Tests / status | `AiServiceOverviewTest` covers exact nested shape/order, empty values, global model semantics, tenant-safe job aggregation, credential exclusion, zero outbound calls, authentication, local/foreign RBAC, inactive identity, throttling, PostgreSQL health constraints, and DCL. Done — focused SQLite passes 7 tests / 48 assertions with one PostgreSQL-only skip and focused PostgreSQL passes 7 / 49; full SQLite passes 501 / 2892 (five PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 501 / 2906. |

### **AISVC-02 — POST /api/v1/admin/ai-services**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AISVC-02 / P1 |
| Purpose / permission | Register a trusted FastAPI backend as an active administrator with tenant-valid `ai_services.manage`. Registration stores configuration only and makes no health request; AISVC-03 owns downstream verification. |
| Request / validation | Requires the exact documented `service_name`, `base_url`, `api_key`, `environment`, and boolean `enabled`. Text is trimmed, environment normalized lowercase, and trailing URL slashes removed. Base URLs must be valid HTTP(S) service roots without embedded credentials, query, or fragment. Case-insensitive URL identity and name/environment identity return standard `409 CONFLICT`, including concurrent PostgreSQL submissions protected by advisory locks. |
| Secret / response boundary | The API key is encrypted through Laravel's authenticated application encryption before persistence. `201` returns the safe AiService resource and request ID; neither plaintext nor encrypted credential appears in the response, exception details, HTTP calls, or audit values. Initial health state is `unknown` with no version, capabilities, or health/sync timestamps. |
| Transaction / audit / DCL | Service creation and immutable `ai_service.create` evidence commit in one transaction. The audit includes only safe registry fields. `028_ai_service_registration_grants.sql` adds column-level API INSERT (including encrypted credential persistence) while retaining AISVC-01's safe-column-only SELECT; no UPDATE/DELETE or report/worker privilege is added. |
| Tests / status | `AiServiceStoreTest` covers normalized safe success, decryption-at-rest proof, secret-free response/audit, no downstream request, required/type/URL validation, both conflicts, authentication, local/foreign RBAC, inactive identity, throttling, and DCL. Done — focused SQLite/PostgreSQL pass 7 tests / 65 assertions each; full SQLite passes 508 / 2957 (five PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 508 / 2971. |

### **AISVC-03 — POST /api/v1/admin/ai-services/{id}/test**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AISVC-03 / P1 |
| Purpose / permission | Health-test one enabled registered FastAPI backend as an active administrator with tenant-valid `ai_services.manage`. Missing/malformed UUIDs return the standard non-enumerating 404; disabled services return 409 without making a request. |
| Downstream protocol / response | Calls `GET {base_url}/health` with JSON accept and the decrypted key only in server-side `X-API-Key`, using configurable connect/overall timeouts. A successful downstream body must contain status `ok`/`healthy` and a non-empty version. Returns exact `200 {data:{status:"healthy",version,latency_ms}}` plus request ID. Invalid successful payloads map to `502 BAD_GATEWAY`; transport or non-success responses map to `503 SERVICE_UNAVAILABLE`, never forwarding downstream bodies or exception secrets. |
| Persistence / audit | Each actual probe persists health state, version, check time, and measured latency; failures persist `unavailable` evidence. The state change and immutable `ai_service.health_test` audit commit together, and audit values contain health evidence only. A disabled/missing/unauthorized/rate-limited call causes neither HTTP traffic nor audit. |
| Secret / DCL boundary | The pluggable `AiInferenceClient` keeps FastAPI behavior out of controllers. PostgreSQL exposes the encrypted value only through a one-ID, owner-defined function with a fixed search path; `029_ai_service_health_grants.sql` grants only that function plus safe latency SELECT and health-column UPDATE to the API. Direct secret SELECT/UPDATE and every report/worker privilege remain denied. |
| Tests / status | `AiServiceHealthTest` covers exact success, header/path use, health evidence/audit, secret hygiene, disabled/missing/malformed IDs, auth/local/foreign RBAC, inactive identity, 502/503 mapping, failure evidence, throttling, migration/function, and DCL. Done — focused SQLite and PostgreSQL pass 8 tests / 64 assertions each; full SQLite passes 516 / 3021 (five PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 516 / 3035. |

### **AISVC-04 — POST /api/v1/admin/ai-services/{id}/synchronize**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AISVC-04 / P1 |
| Purpose / permission | Pull authoritative model metadata from one enabled service whose last persisted health state is `healthy`; requires active authentication and tenant-valid `ai_services.manage`. Missing/malformed IDs return 404, and unready services return 409 before any HTTP call. |
| Downstream contract / validation | Calls `GET {base_url}/models` using the same server-only credential and timeout boundary. The canonical bounded document is `{capabilities:{scalar values},models:[{key,name,type,framework?,description?,versions:[{label,artifact_ref,accuracy?,precision_score?,recall_score?,f1_score?,rmse?,release_notes?}]}]}`. It permits at most 100 distinct models and 100 distinct versions/model, accepts only registry model types and bounded metrics/text, and maps malformed metadata to `502 BAD_GATEWAY`; transport/non-success responses are normalized to 503 without forwarding content. |
| Upsert / workflow behavior | Adds nullable service provenance (`ai_service_id`, `external_model_key`) with unique source identity. Synchronization restores or upserts each source model and version in one locked transaction, updates safe metadata and private artifact references, retains absent records, never deletes anything, never writes training-dataset lineage, and preserves `is_deployed` exactly. Repeats are idempotent. Returns exact `{models_synced,capabilities}` and persists capabilities/sync time. |
| Audit / secret / DCL | Immutable `ai_service.synchronize` evidence contains only counts, source keys, capability keys and time—never the API key or private artifact reference. `030_ai_service_synchronization_grants.sql` grants only model/version metadata INSERT/UPDATE and service capability/time UPDATE to the API; deployment UPDATE and all DELETE/report/worker writes remain denied. Credential access reuses AISVC-03's one-ID function. |
| Tests / status | `AiServiceSynchronizeTest` covers creation, canonical response, X-API-Key/path, capabilities, source provenance, private artifact persistence, idempotent updates, deployment preservation, readiness/IDs, auth/local/foreign RBAC/inactivity, atomic 502 validation, secret-safe 503 handling, throttling, schema and DCL. Done — focused SQLite/PostgreSQL pass 9 tests / 85 assertions each; full SQLite passes 525 / 3106 (five PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 525 / 3120. |

### **JOB-01 - GET /api/v1/processing-jobs**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | List durable processing jobs visible through the authenticated user's organization; requires active identity and tenant-valid `processing_jobs.manage`. The route does not contact FastAPI. |
| Request / response | Optional normalized UUID `mission_id`/`flight_id`, documented `status`/`type`, positive `page`, and `per_page` 1 through 100. Returns exact ProcessingJob resources with standard request/pagination metadata. |
| Tenant / workflow behavior | Mission and flight filters resolve through existing tenant scopes, returning anti-enumeration 404s for foreign or missing resources. Jobs are constrained again through mission-site organization lineage and sorted newest queued first with a UUID tie-breaker. |
| Schema / DCL / side effects | Adds the documented UUID `processing_jobs` schema with mission/optional-flight/creator lineage, JSONB input/output summaries, lifecycle timestamps and safe failure evidence. PostgreSQL checks documented job type/status domains and timestamp ordering. API/reporting receive SELECT only; worker access waits for JOB-02. Listing creates no audit or notification. |
| Tests / status | `ProcessingJobIndexTest` covers exact shape/pagination, composed filters, validation, inaccessible resources, authentication/RBAC, tenant isolation, no audit, throttling, PostgreSQL constraints and DCL. Done - full SQLite passes 250 tests / 1417 assertions and PostgreSQL 18/PostGIS passes 250 / 1423; route, Pint, live privilege and diff gates pass. |

### **JOB-02 — POST /api/v1/processing-jobs**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | JOB-02 / P0 |
| Purpose / permission | Durably queue detector, classifier or combined processing for finalized tenant-owned media; requires active Sanctum authentication and tenant-valid `processing_jobs.create`. Mission, optional flight and every media ID are resolved within the caller's organization. Foreign, missing and malformed resources remain non-enumerable 404s. |
| Request / exact success | Requires `Idempotency-Key` (1–100 chars), UUID mission, optional UUID flight, normalized `detection`, `classification` or `full_pipeline`, 1–1000 distinct media UUIDs, and optional object parameters capped at 100 top-level items and 65,536 encoded bytes. Returns exact `202 {data:{processing_job_id,job_status:"queued"}}` plus request ID; the manual's illustrative `created_at` is intentionally omitted because the CSV contract is authoritative. |
| Workflow / model resolution | Optional flight must belong to the requested mission and be completed. Every non-deleted media item must belong to that mission/optional flight, originate from a completed flight, have non-rejected quality, and be `pending` or `failed`; otherwise 404/409. The endpoint resolves the newest deployed detector/classifier version only through an enabled healthy synchronized AI service. Missing required capability returns 503 without creating state. `full_pipeline` is orchestration of detector + classifier, not a third model. |
| Transaction / provenance / idempotency | One transaction locks the mission, flight, media and user/key identity; inserts the queued processing job, creates one queued immutable-provenance model run per media and required model version, marks inputs queued, and emits secret-free `processing.create` audit evidence. Canonical media order and recursively sorted parameter maps make equivalent retries return the same job with no duplicate runs/audit; changed payload returns 409. Audit failure rolls back all state. The authoritative `processing_jobs`/`model_runs` rows are the durable worker queue; inference is not performed synchronously. |
| Schema / DCL / tests | Adds user-scoped creation idempotency to processing jobs and the documented UUID `model_runs` table with job/model/media lineage, JSONB parameters and PostgreSQL type/status/timestamp checks. `033_processing_job_creation_grants.sql` gives API selected-column job/run inserts and media-status update only; worker can read inputs/service metadata and update only lifecycle/output columns, including controlled credential-function execution, while reporting remains read-only. `ProcessingJobStoreTest` covers exact success/provenance, canonical retry/conflict, tenant/hierarchy hiding, workflow conflicts, capability 503, auth/RBAC/inactivity, validation/size bound, rollback, throttling and DCL. Done — focused SQLite/PostgreSQL pass 10 tests / 91 assertions; full SQLite passes 556 / 3364 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 556 / 3380; route, Pint and live privilege gates pass. |

### **JOB-03 — GET /api/v1/processing-jobs/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | JOB-03 / P0 |
| Purpose / permission | Return one durable processing workflow, all execution runs, current output summary and safe error evidence. Requires active Sanctum authentication and tenant-valid `processing_jobs.manage`; lookup is constrained through job → mission → non-deleted site → caller organization. Foreign, missing, malformed and soft-deleted-lineage IDs remain non-enumerable 404s. |
| Exact response / ordering | Returns exact `200 {data:{job,model_runs,output_summary}}` plus request ID. `job` uses the established ProcessingJob resource including safe `error_message`; `model_runs` exposes run/job/version/media identifiers, type, parameters, lifecycle timestamps/status and creation time. Runs order by creation time then UUID. Queued jobs may legitimately return `model_runs:[]` and `output_summary:null`. |
| Security / side effects / DCL | Model version provenance is exposed by UUID only; private `model_file_path`, service topology and encrypted credentials never cross the API. This read makes no FastAPI call and creates no audit, notification or mutation. It reuses JOB-01 processing-job SELECT and JOB-02 model-run SELECT; reporting remains read-only and no new DCL is needed. |
| Tests / status | `ProcessingJobShowTest` covers exact nested fields/order and output, safe error/provenance boundaries, empty queued state, tenant/missing/malformed/deleted-lineage hiding, authentication, RBAC, inactivity, throttling, no audit and read-only DCL. Done — focused SQLite and PostgreSQL pass 6 tests / 42 assertions; Processing module passes 23 tests / 176 SQLite and 177 PostgreSQL assertions; full SQLite passes 562 / 3406 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 562 / 3422; route, Pint and diff gates pass. |

### **JOB-04 — POST /api/v1/processing-jobs/{id}/retry**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | JOB-04 / P1 |
| Purpose / permission | Queue an idempotent new execution workflow from a tenant-visible failed job while preserving the original job and runs as immutable failure evidence. Requires active Sanctum authentication and tenant-valid `processing_jobs.create`; foreign, missing, malformed and deleted-site lineage remain non-enumerable 404s. |
| Request / exact response | Requires `Idempotency-Key` (1–100 chars) and accepts optional trimmed reason up to 5000 characters. Returns exact `202 {data:ProcessingJob}` plus request ID using the established safe resource. Actor/key plus source job and normalized reason form the fingerprint; identical retries return the same queued job without duplicate runs/audit, while changed evidence returns 409. |
| Workflow / provenance | Only failed jobs with a non-empty execution plan, tenant-owned inputs still marked failed, and enabled healthy services behind every original model version can retry. A new queued job copies input summary and original run model/media/parameter provenance, marks inputs queued, and records `retry_of_job_id`/reason; the failed job/runs are never rewritten and no synchronous inference call occurs. |
| Transaction / audit / DCL / tests | Idempotency advisory lock, source/media locks, job/run inserts, media queueing and secret-free `processing.retry` evidence share one transaction, so audit failure restores all state. The additive DCL grants API INSERT only on retry lineage/reason; existing JOB-02 selected-column job/run/media grants do the rest, while update/delete/report/worker boundaries remain unchanged. `ProcessingJobRetryTest` covers exact durable provenance, canonical replay/conflict, failed/media/service gates, tenant/path hiding, validation, authentication/RBAC/inactivity, audit rollback and DCL. Done — focused SQLite/PostgreSQL pass 7 tests / 43 assertions each; full SQLite passes 594 / 3638 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 594 / 3657; live privilege gates pass. |

### **MODEL-01 — GET /api/v1/ai-models**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MODEL-01 / P1 |
| Purpose / permission | Return the global, soft-delete-aware AI model registry. Requires active Sanctum authentication and tenant-valid `ai_models.read`; a foreign-organization role grant cannot authorize access. The registry is global because the authoritative AI tables have no organization key. |
| Request / validation | Optional normalized documented type (`species_classifier`, `tree_detector`, `height_estimator`, `age_estimator`) and boolean `deployed`. `deployed=true` means at least one version has `is_deployed=true`; `false` means no deployed version exists. |
| Success / response boundary | `200` standard envelope containing sorted base AiModel resources (`model_id`, name/type/framework/description, creator and UTC timestamps) plus request ID. Version arrays and private `model_file_path` values are not exposed: MODEL-02 explicitly owns `{model,versions}` detail. Soft-deleted models are excluded. Reads create no audit/notification and make no FastAPI call, so current success does not depend on downstream availability. |
| Database foundation | Adds authoritative `ai_models` and `ai_model_versions` tables with UUID/FK provenance, model-type check, model/version uniqueness and deployment index. A minimal authoritative `training_datasets` table is included only because `ai_model_versions.training_dataset_id` requires it; no DATASET P2 route or completion is claimed. |
| DCL / tests / status | `026_ai_model_registry_grants.sql` grants API/report SELECT only on model tables; the training-dataset foundation remains ungranted, as do every mutation and worker/auditor access. `AiModelIndexTest` covers exact global projection/order, soft deletion, type/deployment filters, path/version non-exposure, validation, authentication, local/foreign RBAC, inactive identity, throttling, no audit, PostgreSQL constraints and DCL. Done — full SQLite passes 487 tests / 2803 assertions (four PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 487 / 2816; focused suites, route, Pint, Composer, migration and live privilege gates pass. |

### **MODEL-02 — GET /api/v1/ai-models/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | MODEL-02 / P1 |
| Purpose / permission | Return one global, non-deleted AI model and its version history. Requires active Sanctum authentication and tenant-valid `ai_models.read`; a role from another organization cannot authorize the caller. |
| Path / lookup behavior | The documented `id` is constrained to UUID syntax at the route. Missing, malformed, and soft-deleted identifiers all resolve to the standard `404 NOT_FOUND` boundary. The global registry is intentionally not organization-filtered because its authoritative tables have no organization key. |
| Success / response boundary | `200` standard envelope with exact `{data:{model,versions}}` plus request ID. `model` uses the MODEL-01 base projection. Versions expose identifiers, training provenance, bounded metrics, deployment state, release notes, and UTC timestamps; private `model_file_path` is never returned. Versions sort deployed first, then newest creation time, then UUID for deterministic ties; no history is `[]`. |
| DCL / side effects | Reuses `026_ai_model_registry_grants.sql`: API/report roles have SELECT only on `ai_models` and `ai_model_versions`, with no registry writes or training-dataset access. The read creates no audit or notification and makes no FastAPI call. |
| Tests / status | `AiModelShowTest` covers exact safe shape, version ordering, empty history, private-path exclusion, missing/malformed/deleted IDs, authentication, local/foreign RBAC, inactive identity, throttling, no audit, and existing DCL. Done — focused SQLite and PostgreSQL pass 7 tests / 41 assertions each; full SQLite passes 494 / 2844 (four PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 494 / 2857. |

## **Tree results, summaries and geospatial layers**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| TREE-01 | GET /tree-observationsFilter canonical tree observations. | Query: mission\_id,flight\_id,species\_id,validation\_status,min\_confidence,page | 200 {data:\[TreeObservation\],meta} | JOB-03 completed | **P0** | Codex \- Results/API | **Done** |
| TREE-02 | GET /tree-observations/{id}Tree detail with model provenance/results. | Path: id | 200 {data:{tree,species\_predictions,height\_estimations,age\_estimations,source\_media,model\_run}} | TREE-01 | **P0** | Codex \- Results/API | **Done** |
| TREE-03 | GET /missions/{id}/trees.geojsonMap-ready tree features. | Query: species\_id?,validated\_only? | 200 GeoJSON FeatureCollection | TREE-01 \+ PostGIS | **P0** | Codex \- GIS/API | **Done** |
| COUNT-01 | GET /missions/{id}/tree-countsMission/species count summary. | Query: species\_id? | 200 {data:\[TreeCountSummary\]} | TREE-01 \+ count routine | **P0** | Codex \- Results/API | **Done** |
| RESULT-01 | GET /tree-observations/{id}/speciesSpecies prediction history. | Path: id | 200 {data:\[ClassificationResult\]} | TREE-02 | **P1** | Codex \- Results/API | **Done** |
| RESULT-02 | GET /tree-observations/{id}/heightsHeight estimates. | Path: id | 200 {data:\[HeightEstimation\]} | TREE-02 | **P1** | Codex \- Results/API | **Done** |
| RESULT-03 | GET /tree-observations/{id}/agesAge estimates \+ assumptions. | Path: id | 200 {data:\[AgeEstimation\]} | TREE-02 | **P1** | Codex \- Results/API | **Done** |
| LAYER-01 | GET /missions/{id}/layersList geospatial/photogrammetry outputs. | Query: type? | 200 {data:\[Layer\]} | JOB-03 | **P1** | Codex \- GIS/API | **Done** |
| LAYER-02 | POST /missions/{id}/layers/buildQueue map layer build/refresh. | {layer\_types:\[...\],parameters?} | 202 {data:{job\_id}} | TREE-01 \+ photogrammetry inputs | **P1** | Jason Benabente | **Testing** |

### **TREE-01 — GET /api/v1/tree-observations**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | TREE-01 / P0 |
| Purpose / permission | Return paginated canonical tree observations visible through the authenticated user's organization. Requires active Sanctum authentication and tenant-valid `results.read`; every row is constrained through observation → mission → non-deleted site → caller organization. |
| Request / exact response | Supports normalized UUID `mission_id`, `flight_id`, and `species_id`; documented validation status; confidence from 0 through 1; positive page; and `per_page` from 1 through 100. Mission and flight filters use tenant-scoped lookup, while species is a global reference. Returns exact `200 {data:[TreeObservation],meta}` with stable newest-first pagination. |
| Resource / geospatial boundary | The base TreeObservation projection includes lineage IDs, tree code, GeoJSON point/crown geometry, bounding box, confidence, canonical species/height/age values, validation status, and UTC timestamps. PostgreSQL reads geometries with `ST_AsGeoJSON`; storage internals and unrelated model/service details are not exposed. Reads create no audit or notification. |
| Schema / DCL | Adds the documented mangrove species, persistent tree entity, and canonical observation foundations with UUID lineage, PostGIS Point/Polygon SRID 4326 columns, spatial/query indexes, uniqueness, soft deletion, and PostgreSQL domain/range checks. API/report roles receive SELECT only. The worker receives only the selected INSERT/UPDATE inference-result columns and cannot set validation state or delete rows. |
| Tests / status | `TreeObservationIndexTest` covers exact shape/order/pagination, composed filters, GeoJSON, validation, inaccessible filters and deleted lineage, authentication/RBAC/inactivity, tenant isolation, no audit, throttling, schema constraints and DCL. Done — focused SQLite passes 7 tests / 59 assertions and PostgreSQL passes 7 / 60; full SQLite passes 569 / 3465 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 569 / 3482; live privilege checks pass. |

### **TREE-02 — GET /api/v1/tree-observations/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | TREE-02 / P0 |
| Purpose / permission | Return one canonical tenant-visible tree with its complete prediction, height, age, source-media and execution provenance. Requires active Sanctum authentication and tenant-valid `results.read`; lookup follows tree → mission → non-deleted site → caller organization, yielding anti-enumerable 404s. |
| Exact response / ordering | Returns exact `200 {data:{tree,species_predictions,height_estimations,age_estimations,source_media,model_run}}` plus request ID. Result histories place final evidence first with deterministic rank/time/UUID tie-breakers. Empty histories are arrays and optional media/run provenance is null. |
| Security / response boundary | Reuses safe TreeObservation, MediaAsset and ModelRun projections. Private storage keys, model artifact paths, service topology and encrypted credentials never cross the API. Source-media and model-run lookups are independently tenant-constrained as defense in depth. This read creates no audit, notification, or downstream call. |
| Schema / DCL / tests | Adds documented sensor-dataset, species-growth-model, species-classification, canopy-height and age-estimation foundations with UUID provenance, indexes, uniqueness and PostgreSQL domain/range checks. API receives result SELECT only; reporting receives reference/result SELECT; worker receives result INSERT/SELECT but no update/delete. `TreeObservationShowTest` covers exact shape/order, empty/null state, tenant/path/deleted-lineage hiding, authentication/RBAC/inactivity, throttling, safe provenance, constraints and DCL. Done — focused SQLite passes 6 tests / 49 assertions and PostgreSQL passes 6 / 50; full SQLite passes 575 / 3514 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 575 / 3532; live privilege gates pass. |

### **TREE-03 — GET /api/v1/missions/{id}/trees.geojson**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | TREE-03 / P0 |
| Purpose / permission | Return map-ready canonical tree points for one tenant-visible mission. Requires active Sanctum authentication and tenant-valid `results.read`; mission lookup uses the shared organization scope and hides foreign, missing, malformed and deleted-site lineage. |
| Request / exact response | Supports optional global species UUID and boolean `validated_only`; validated-only includes accepted `validated` and `corrected` canonical states. Returns a raw RFC 7946-style `{type:"FeatureCollection",features:[...]}` with `application/geo+json`, not the standard data/meta envelope, because the endpoint contract explicitly requires a GeoJSON FeatureCollection. |
| Features / ordering / security | Each deterministic tree-code/UUID-ordered feature contains its observation UUID, GeoJSON Point, and map-safe canonical properties (lineage, confidence, species, height, age and validation state). Soft-deleted observations are excluded. The response exposes no storage/model/service secrets and creates no audit, notification or mutation. Empty missions return a valid empty collection. |
| DCL / tests / status | Reuses TREE-01's API/report SELECT-only observation/species privileges; no new database grant is necessary. `MissionTreeGeoJsonTest` covers exact media type/top-level/feature shape, PostGIS-safe coordinates, ordering, composed filters, empty/deleted state, validation, anti-enumerable mission/species lookup, authentication/RBAC/inactivity, no audit and throttling. Done — focused SQLite/PostgreSQL pass 6 tests / 38 assertions each; full SQLite passes 581 / 3552 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 581 / 3570. |

### **COUNT-01 — GET /api/v1/missions/{id}/tree-counts**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | COUNT-01 / P0 |
| Purpose / permission | Return current overall and per-species canonical tree counts for one tenant-visible mission. Requires active Sanctum authentication and tenant-valid `results.read`; mission lookup is organization scoped and species is a global UUID reference. |
| Exact response / semantics | Returns exact `200 {data:[TreeCountSummary]}`. Without a species filter, the first row is the overall mission summary (`species_id:null`) followed by UUID-ordered classified-species rows; unclassified trees contribute only to the overall row. A species filter returns its row when observations exist, otherwise `[]`. Validated totals include `validated` and `corrected`; soft-deleted observations never count. |
| Freshness / side effects | Counts are derived transaction-free from current canonical observations, preventing stale materialized totals and creating no summary, audit, notification or other mutation. Live rows use the documented summary projection with nullable persisted-summary/provenance/density/confidence/timestamp fields. |
| Schema / routine / DCL / tests | Adds the documented `tree_count_summaries` foundation with UUID lineage, indexes and PostgreSQL non-negative/range checks plus stable `app.mission_tree_counts(uuid,uuid)`. API/report roles receive summary SELECT and routine execution only; worker receives SELECT/INSERT/UPDATE and routine execution but no DELETE. `MissionTreeCountTest` covers exact overall/species math and shape, filtering, deleted/unclassified/empty behavior, validation, tenant/path hiding, authentication/RBAC/inactivity, no side effects, throttling, constraints and DCL. Done — focused SQLite passes 6 tests / 43 assertions and PostgreSQL passes 6 / 44; full SQLite passes 587 / 3595 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 587 / 3614; live privilege gates pass. |

### **RESULT-01 — GET /api/v1/tree-observations/{id}/species**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | RESULT-01 / P1 |
| Purpose / permission | Return the complete species-prediction history for one tenant-visible canonical tree. Requires active Sanctum authentication and tenant-valid `results.read`; lookup follows tree → mission → non-deleted site → caller organization and returns anti-enumerable 404s. |
| Exact response / ordering | Returns exact `200 {data:[ClassificationResult]}`. Final evidence sorts first, then rank, newest evidence and UUID for deterministic ties; no predictions returns `[]`. Each safe result contains only observation/run/species provenance, confidence, rank, bounded classification basis, final flag and UTC creation time. |
| Security / DCL / tests | Model file paths, service topology and credentials are absent; the read creates no audit, notification or mutation. Reuses TREE-02's API SELECT-only result DCL while reporting remains read-only. `TreeSpeciesPredictionIndexTest` covers exact fields/order, JSON basis, empty history, tenant/missing/malformed/deleted-lineage hiding, authentication/RBAC/inactivity, throttling, no audit, secret exclusion and DCL. Done — focused SQLite/PostgreSQL pass 5 tests / 24 assertions each; full SQLite passes 599 / 3662 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 599 / 3681. |

### **RESULT-02 — GET /api/v1/tree-observations/{id}/heights**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | RESULT-02 / P1 |
| Purpose / permission | Return the complete canopy-height estimation history for one tenant-visible canonical tree. Requires active Sanctum authentication and tenant-valid `results.read`; tenant and deleted-lineage behavior matches TREE-02/RESULT-01. |
| Exact response / ordering | Returns exact `200 {data:[HeightEstimation]}`. Final evidence sorts first, then newest time and UUID; no estimates returns `[]`. The safe resource includes tree/run/dataset provenance, documented method, height/confidence, measurement notes, final flag and UTC creation time. |
| DCL / side effects / tests | Reuses TREE-02 API SELECT-only result privileges; no DCL or schema expansion is needed. Reads create no audit, notification or mutation. `TreeHeightEstimationIndexTest` covers exact fields/order, final/notes evidence, empty history, tenant/missing/malformed/deleted-lineage hiding, authentication/RBAC/inactivity, throttling, no audit and DCL. Done — focused SQLite/PostgreSQL pass 4 tests / 22 assertions each; full SQLite passes 603 / 3684 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 603 / 3703. |

### **RESULT-03 — GET /api/v1/tree-observations/{id}/ages**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | RESULT-03 / P1 |
| Purpose / permission | Return the complete age-estimation history and assumptions for one tenant-visible canonical tree. Requires active Sanctum authentication and tenant-valid `results.read`, with the established tree/mission/site organization boundary. |
| Exact response / ordering | Returns exact `200 {data:[AgeEstimation]}`. Final evidence sorts first, then newest time and UUID; no estimates returns `[]`. The projection preserves growth-model and height-estimation provenance, point/range estimates, confidence, assumptions, final flag and UTC creation time. |
| DCL / side effects / tests | Reuses TREE-02 API SELECT-only age-result privileges; reference/model formula internals are not expanded into the response, and the read has no side effects. `TreeAgeEstimationIndexTest` covers exact fields/order, range/confidence/assumption evidence, empty history, tenant/missing/malformed/deleted-lineage hiding, authentication/RBAC/inactivity, throttling, no audit and DCL. Done — focused SQLite/PostgreSQL pass 4 tests / 24 assertions each; full SQLite passes 607 / 3708 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 607 / 3727. |

### **LAYER-01 — GET /api/v1/missions/{id}/layers**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | LAYER-01 / P1 |
| Purpose / permission | List generated geospatial layer metadata for one tenant-visible mission. Requires active Sanctum authentication and tenant-valid `results.read`; shared mission scoping hides foreign, missing, malformed and deleted-site lineage. |
| Request / exact response | Supports optional normalized documented type (`tree_points`, `species_map`, `canopy_height`, `orthomosaic`). Returns exact `200 {data:[Layer]}` ordered by type, name and UUID. Each layer exposes identifier/mission/name/type, style configuration, default visibility, creator and UTC timestamps; private storage keys are intentionally excluded. |
| Schema / DCL / side effects | Adds documented `photogrammetry_products` and `geospatial_layers` foundations with UUID lineage, storage-key uniqueness, PostGIS Polygon(4326) bounds, indexes and PostgreSQL product/layer/range constraints. API/report/worker roles receive SELECT only; build/write grants await LAYER-02. Reads create no audit, notification or mutation. |
| Tests / status | `MissionLayerIndexTest` covers safe exact projection/order, storage-key exclusion, normalized filtering, validation, tenant/missing/malformed/deleted-lineage hiding, authentication/RBAC/inactivity, throttling, no audit, PostGIS schema constraints and read-only DCL. Done — focused SQLite passes 4 tests / 27 assertions and PostgreSQL passes 4 / 28; full SQLite passes 611 / 3735 (six PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 611 / 3755; live privilege gates pass. |

## **Confidence review and field validation**

### **Validation database foundation**

| Implementation field | Detail |
| :---- | :---- |
| Scope | Reproduces the authoritative base `validation_sessions`, `ground_truth_tree_records`, `validation_matches` and `accuracy_metrics` tables. UUID models and inverse relationships connect missions, sites, plots, users, species, tree observations and model versions. VAL-01 provides scope/options, VAL-02 provides the filtered session index, VAL-03 creates audited activities, VAL-04 assembles the complete workspace, GT-01 records field evidence, and MATCH-01 persists validation decisions. |
| Spatial / integrity | Ground-truth locations and nullable corrected decision geometry use PostGIS `POINT(4326)` with GiST indexes; SQLite JSON is test-only. Matches carry required direct session lineage, while nullable ground/tree references obey the approved paired/false-positive/false-negative shapes. Versioned FKs preserve mission/session/user/species/tree evidence. PostgreSQL checks validation methods, health states, match states/reference shapes, and non-negative measurement/error/metric domains. All foreign-key navigation and session metric lookup paths are indexed. |
| Timestamp behavior | Validation sessions participate in the shared PostgreSQL `fn_touch_updated_at` trigger. Evidence/metric tables retain their authoritative append-style `created_at`, `validated_at` or `computed_at` timestamps and do not receive invented mutable timestamps. |
| DCL boundary | `045_validation_foundation_grants.sql` keeps the base tables closed by default. VAL-01, VAL-02 and VAL-04 reuse versioned SELECT privileges. `048_validation_session_creation_grants.sql` adds only open-session INSERT columns; `050_ground_truth_creation_grants.sql` adds GT-01 INSERT columns; `051_validation_decision_grants.sql` adds MATCH-01 INSERT and canonical tree-update columns. `052_accuracy_recompute_grants.sql` corrects the earlier generic metric grant with column-limited INSERT/UPDATE and no DELETE. `053_validation_completion_grants.sql` likewise guarantees only status/notes/completion actor/time updates and denies lineage mutation. Worker/report/auditor mutation remains denied. |
| Contract resolutions | VAL-01 nests each site and plots beneath its mission while preserving `{missions,species,assignees,sessions}`. GT-01 adds nullable `field_code`/`crown_diameter_m` and required default-true `is_tree`, mapping `notes` to `remarks`. MATCH-01 adds required direct `validation_session_id`, nullable asymmetric ground/tree references, accepted species/height/age, corrected point, notes, and JSONB evidence. Errors are derived before corrections; matched validates, corrected updates only supplied canonical fields, false positives reject, and false negatives create no fake tree. |
| Tests / status | The VAL-01..05, GT-01, MATCH-01, and ACC-01 suites verify exact safe projections, tenant and lineage constraints, GeoJSON input/output, decision-specific references, strict domains, server-derived errors/metrics, stable recompute identities, canonical tree transitions, fresh-six-metric completion, authentication/RBAC/inactivity/throttling, rollback-safe audit, duplicate/protocol conflicts, routes, and least-privilege DCL. VAL-05-focused SQLite and PostgreSQL/PostGIS each pass 12 tests / 91 assertions. Full SQLite passes 751 / 5089 with twelve expected PostgreSQL-only skips; full PostgreSQL passes 751 / 5124. Migration rollback/reapply, live four-role DCL, Pint, routes, schema, Composer, and tracker-integrity gates pass. The validation chain through VAL-05 is Done. |

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| CONF-01 | GET /confidence-reviewMission-scoped low-confidence queue. | Query: mission\_id\*,flight\_id?,result\_type?,status?,severity?,page | 200 {data:\[ReviewRecord\],summary,groups,map,meta} | TREE/RESULT \+ confidence flag extension | **P1** | Jason Benabente | **Testing** |
| CONF-02 | PUT /confidence-review/{resultId}Create/update review flag/status/assignment. | {status,review\_note?,assigned\_to?,reason?,resolution\_notes?} | 200 {data:ConfidenceFlag} | CONF-01 | **P1** | Jason Benabente | **Testing** |
| VAL-01 | GET /validation/scopesMission/site/plot/species/assignee options. | No body | 200 {data:{missions,species,assignees,sessions}} | MSN/SITE/USR | **P0** | Earljohn Estandarte | **Done** |
| VAL-02 | GET /validation-sessionsList field validation sessions. | Query: mission\_id?,site\_id?,status?,page | 200 {data:\[ValidationSession\],meta} | VAL-01 | **P0** | Earljohn Estandarte | **Done** |
| VAL-03 | POST /validation-sessionsCreate mission-scoped validation activity. | {mission\_id,site\_id,plot\_id?,validated\_by,validation\_date,method,notes?} | 201 {data:ValidationSession} | VAL-01 \+ TREE-01 | **P0** | Earljohn Estandarte | **Done** |
| VAL-04 | GET /validation-sessions/{id}Validation workspace data and map layers. | Path: id | 200 {data:{context,observations,ground\_truth\_records,matches,metrics,layers}} | VAL-03 | **P0** | Earljohn Estandarte | **Done** |
| GT-01 | POST /validation-sessions/{id}/ground-truthCreate manual field tree record. | {field\_code?,species\_id?,location:GeoJSON,height\_m?,age\_years?,diameter\_cm?,crown\_diameter\_m?,health\_status,is\_tree,photo\_path?,notes?} | 201 {data:GroundTruthRecord} | VAL-04 | **P0** | Earljohn Estandarte | **Done** |
| MATCH-01 | POST /validation-sessions/{id}/decisionsStore matched/corrected/false-positive/false-negative decision. | {tree\_observation\_id?,ground\_truth\_id?,decision,accepted\_species\_id?,accepted\_height\_m?,accepted\_age\_years?,corrected\_geometry?,notes?,validation\_evidence?} | 201 {data:ValidationMatch} | VAL-04 \+ GT-01 | **P0** | Earljohn Estandarte | **Done** |
| ACC-01 | POST /validation-sessions/{id}/accuracy/recomputeRecompute precision/recall/F1/RMSE/MAE evidence. | No body | 200 {data:\[AccuracyMetric\]} | MATCH-01 | **P0** | Jason Benabente | **Done** |
| VAL-05 | POST /validation-sessions/{id}/completeComplete validation task. | {notes} | 200 {data:ValidationSession} | MATCH-01; protocol gate | **P1** | Jason Benabente | **Done** |

## **Reports, exports, dashboard and saved views**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| RPT-01 | GET /reportsList report records. | Query: mission\_id,site\_id,status,type,page | 200 {data:\[Report\],meta} | AUTH | **P1** | Codex \- Reporting/API | **Done** |
| RPT-02 | POST /reportsPrepare report definition/draft. | {mission\_id,site\_id,report\_title,report\_type,audience?,summary?,interpretation?,limitations?,recommendations?,formats?} | 201 {data:Report} | TREE/ACC finalized | **P1** | Earljohn Estandarte | **Done** |
| RPT-03 | GET /reports/{id}Report draft/source metadata. | Path: id | 200 {data:{report,source\_summary}} | RPT-02 | **P1** | Earljohn Estandarte | **Done** |
| RPT-04 | PATCH /reports/{id}Update report content/status while editable. | Partial report fields | 200 {data:Report} | RPT-03 | **P1** | Earljohn Estandarte | **Blocked** |
| RPT-05 | POST /reports/{id}/generateGenerate professional PDF/report artifact asynchronously. | {format:"PDF",options?} | 202 {data:{job\_id,report\_id,status}} | RPT-03 \+ report routine/storage | **P0** | Earljohn Estandarte | **Blocked** |
| RPT-06 | POST /reports/{id}/approveApprove generated report. | {decision:"approved"|"rejected",notes?} | 200 {data:Report} | RPT-05 complete | **P1** | Earljohn Estandarte | **Blocked** |
| EXP-01 | POST /reports/{id}/exportsGenerate CSV/XLSX/GeoJSON/KML/etc. | {format,filters?,options?} | 202 {data:{job\_id,export\_type}} | RPT-03 \+ canonical results | **P0** | Earljohn Estandarte | **Blocked** |
| EXP-02 | GET /exported-filesExport audit registry. | Query: report\_id?,mission\_id?,type?,page | 200 {data:\[ExportedFile\],meta} | EXP-01 | **P1** | Earljohn Estandarte | **Blocked** |
| EXP-03 | POST /exported-files/{id}/downloadAuthorized temporary download. | No body | 200 {data:{url,expires\_at}} or stream | EXP-02 \+ storage | **P0** | Earljohn Estandarte | **Blocked** |
| DASH-01 | GET /dashboard/overviewRole-scoped KPI overview. | Query: site\_id?,mission\_id?,date range? | 200 {data:{missions,trees,species,validation,processing}} | TREE \+ ACC \+ materialized views | **P1** | Earljohn Estandarte | **Done** |
| DASH-02 | GET /dashboard/missions/{id}Mission analytics/detail dashboard. | Path: id | 200 {data:{counts,species,height,age,accuracy,layers}} | DASH-01 | **P1** | Earljohn Estandarte | **Done** |
| VIEW-01 | GET /dashboard/saved-viewsList caller saved filters/map configs. | No body | 200 {data:\[SavedView\]} | AUTH | **P2** | TBD \- Dashboard/API | Backlog |
| VIEW-02 | POST /dashboard/saved-viewsSave filter/map state. | {view\_name,site\_id?,mission\_id?,filter\_config,map\_config} | 201 {data:SavedView} | VIEW-01 | **P2** | TBD \- Dashboard/API | Backlog |
| VIEW-03 | PATCH /dashboard/saved-views/{id}Update saved view. | Partial saved-view fields | 200 {data:SavedView} | VIEW-02 | **P2** | TBD \- Dashboard/API | Backlog |
| VIEW-04 | DELETE /dashboard/saved-views/{id}Delete own saved view. | Path: id | 204 | VIEW-02 | **P2** | TBD \- Dashboard/API | Backlog |

### **RPT-01 — GET /api/v1/reports**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | RPT-01 / P1 |
| Purpose / permission | List report-registry metadata visible through current-tenant site and mission lineage. Requires active Sanctum authentication and tenant-valid `reports.read`; a permission inherited only from a foreign-organization role cannot authorize access. |
| Request / validation | Optional UUID `mission_id` and `site_id`; normalized documented status (`draft`, `generated`, `approved`, `archived`) and type (`monitoring_summary`, `validation_report`, `species_report`); positive `page`; and optional `per_page` from 1 through 100 under the shared pagination standard. Foreign or missing filter targets return non-enumerable 404s. |
| Success / ordering | `200` standard envelope containing exact Report resources (`report_id`, mission/site lineage, title/type/status, generator/approver, summary and UTC timestamps) plus complete request ID/page metadata. Results sort newest first by creation time then UUID. |
| Tenant / integrity behavior | Both the report's direct `site_id` and its `mission.site` must belong to the caller's organization, so inconsistent legacy cross-tenant rows cannot leak. The authoritative schema does not define a mission/site composite FK, so none is invented. Reads create no audit or notification and call no downstream service; documented 502/503 codes remain available only if future report reads acquire a dependency. |
| Database / DCL | Adds the documented UUID `reports` table, required FKs, report type/status checks and mission/status, site/status and type/time indexes. `025_report_grants.sql` gives API and reporting roles SELECT only; worker/auditor and all mutations remain denied until their dependent workflows are implemented. |
| Tests / status | `ReportIndexTest` covers exact resources/pages/order, all filters, tenant and inconsistent-lineage exclusion, target anti-enumeration, validation, authentication, local/foreign RBAC, inactive identity, no audit, throttling, PostgreSQL domains/indexes and DCL. Done — full SQLite passes 479 tests / 2750 assertions (three PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 479 / 2762; focused suites, route, Pint, Composer, migration and live privilege gates pass. |

### **RPT-02 — POST /api/v1/reports**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Create a tenant-scoped `draft` report definition. Requires active Sanctum authentication and a tenant-valid `reports.create` grant. |
| Request / normalization | Required tenant-visible `mission_id` and matching `site_id`, trimmed title (max 200), and documented report type. Optional audience (max 2,000), four content fields (max 20,000 each), and one to five distinct normalized requested formats from PDF, CSV, XLSX, GeoJSON, and KML. Blank optional strings become `null`. |
| Response / server fields | `201 {data:Report}` returns the exact draft content resource. `report_status=draft`, `generated_by=null`, and `approved_by=null` are server-owned regardless of extra client input. RPT-01 keeps its established metadata-only list resource. |
| Scope / transaction | The mission must belong to the supplied site and that site must belong to the caller's organization; foreign, missing, and inconsistent lineage returns non-enumerable 404. Report insertion and immutable `report.create` audit evidence share one rollback-safe transaction. |
| Schema / DCL / tests | Adds only the five tracker-approved content columns. `054_report_creation_grants.sql` grants column-limited API INSERT; API UPDATE/DELETE and report/worker INSERT remain denied. `ReportStoreTest` covers full/minimal drafts, normalization, validation, lineage, auth, local/foreign RBAC, inactivity, audit rollback, throttling, route/schema/DCL, and both databases. Focused SQLite and PostgreSQL/PostGIS each pass 9 / 84; full SQLite passes 765 / 5298 with thirteen expected PostgreSQL-only skips; full PostgreSQL/PostGIS passes 778 / 5335. |

### **RPT-03 — GET /api/v1/reports/{id}**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / permission | Return one full report draft plus stable live source evidence. Requires active Sanctum authentication and tenant-valid `reports.read`. |
| Response | Exact `{data:{report,source_summary}}`. `report` uses the RPT-02 content resource. `source_summary` always contains `mission`, `site`, `trees`, `validation`, and `accuracy`; counts are integers and all six accuracy keys are decimal strings or `null`. Empty missions are zero/null-filled. |
| V-09 design | `v_report_source_summary` separately pre-aggregates non-deleted tree observations, validation sessions/ground truth, and deterministic latest V-08 accuracy metrics before joining them to non-deleted mission/site lineage. It is a live view, not MV-01, so report preview does not require dashboard refresh. |
| Scope / effects | Both direct report site and mission-site lineage must match and belong to the actor's organization. Foreign, missing, malformed, inconsistent, or deleted lineage returns non-enumerable 404. Reads create no audit event or notification. |
| DCL / tests | `055_report_source_summary_grants.sql` revokes all view access before granting SELECT only to API/report roles; worker/auditor and every mutation remain denied. `ReportShowTest` covers exact/full and empty summaries, latest metrics, lineage hiding, auth, local/foreign RBAC, inactivity, throttling, V-09 DDL/DCL, and both databases. Focused SQLite and PostgreSQL/PostGIS each pass 8 / 68; full SQLite passes 773 / 5366 with thirteen expected skips; full PostgreSQL/PostGIS passes 786 / 5403. |

## **Notifications, settings and audit**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| NOTIF-01 | GET /notificationsList durable notifications for current user. | Query: unread\_only?,type?,page | 200 {data:\[Notification\],meta} | AUTH \+ notification\_logs | **P1** | Codex \- Backend/API | **Done** |
| NOTIF-02 | GET /notifications/unread-countLightweight badge count. | No body | 200 {data:{unread\_count}} | NOTIF-01 | **P1** | Codex \- Backend/API | **Done** |
| NOTIF-03 | POST /notifications/{id}/readMark one notification read. | No body | 200 {data:Notification} | NOTIF-01 | **P1** | Codex \- Backend/API | **Done** |
| NOTIF-04 | POST /notifications/read-allMark caller notifications read. | No body | 204 | NOTIF-01 | **P2** | TBD \- Backend/API | Backlog |
| SET-01 | GET /settingsRead permitted settings by group. | Query: group? | 200 {data:\[Setting\]} | AUTH | **P2** | TBD \- Backend/API | Backlog |
| SET-02 | PUT /settings/{key}Update managed setting. | {setting\_value,description?} | 200 {data:Setting} | SET-01 \+ admin permission | **P2** | TBD \- Backend/API | Backlog |
| AUD-01 | GET /audit-logsSearch immutable audit trail. | Query: user\_id?,action?,table\_name?,record\_id?,from?,to?,page | 200 {data:\[AuditLog\],meta} | AUTH \+ audit trigger | **P1** | Codex \- Security/API | **Done** |
| AUD-02 | GET /audit-logs/{id}Audit event detail. | Path: id | 200 {data:AuditLog} | AUD-01 | **P2** | Jason Benabente | **Testing** |

### **NOTIF-01 — GET /api/v1/notifications**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | NOTIF-01 / P1 |
| Purpose / permission | Return durable notifications belonging exclusively to the authenticated user. Requires active Sanctum authentication and tenant-valid `notifications.read`; a foreign-organization role assignment cannot authorize the endpoint. |
| Request / validation | Optional boolean `unread_only`, normalized exact `type` up to the schema's 80-character limit, positive `page`, and optional `per_page` from 1 through 100 under the common pagination standard. Notification types remain extensible strings as defined by the authoritative schema rather than an invented closed enum. |
| Success / scope | `200` standard envelope with exact Notification resources (`notification_id`, `user_id`, `notification_type`, `title`, `message`, `is_read`, UTC `created_at`) and request ID/page metadata. Queries are pinned to the caller's `user_id`, excluding both same-organization colleagues and foreign tenants. Results sort newest first by timestamp then UUID. |
| Database / DCL | Adds the documented UUID `notification_logs` table with required user lineage, durable message fields, unread default and user/read/time plus user/type/time indexes. `023_notification_log_grants.sql` gives only `mangroscan_api_rw` SELECT; INSERT and UPDATE remain withheld until notification-producing workflows and NOTIF-03 explicitly require them. Worker, reporting and auditor roles receive no access. |
| Side effects / errors | Read-only polling creates no audit event and does not mutate read state. Errors are `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected failures remain `500`. No target identifier exists for a normal 404 response. |
| Tests / status | `NotificationIndexTest` covers exact shape/pages/order, user/tenant isolation, unread/type composition, validation, authentication, local/foreign RBAC, inactive identity, no audit, throttling, durable FK/index schema and DCL. Done — full SQLite passes 457 tests / 2625 assertions (two PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 457 / 2636; focused suites, route, Pint, Composer, migration, index and live privilege gates pass. |

### **NOTIF-02 — GET /api/v1/notifications/unread-count**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Return the lightweight unread badge count for the authenticated user. Requires active Sanctum authentication and tenant-valid `notifications.read`; foreign-role grants cannot authorize it. |
| Request / success | No body or query parameters. Returns `200` with exact integer `{data:{unread_count}}` plus request ID metadata, including zero when the caller has no unread rows. |
| Scope / side effects | Counts only `notification_logs` rows matching both the caller's `user_id` and `is_read=false`; same-organization colleagues and foreign tenants never contribute. The read creates no audit/notification and never changes read state. |
| Database / tests / status | Reuses NOTIF-01's user/read/time index and API SELECT-only `023_notification_log_grants.sql`; no schema or privilege expansion. `NotificationUnreadCountTest` covers exact output/type, zero behavior, caller isolation, authentication, local/foreign RBAC, inactive identity, throttling, no audit and read-only DCL. Done — full SQLite passes 463 tests / 2649 assertions (two PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 463 / 2660; focused suites and static gates pass. |

### **NOTIF-03 — POST /api/v1/notifications/{id}/read**

| Implementation field | Detail |
| :---- | :---- |
| Purpose / access | Apply one durable unread-to-read transition for the authenticated user's notification. Requires active Sanctum authentication and tenant-valid `notifications.read`; the permission covers both reading and acknowledging the caller's own notification because the catalog defines no separate notification-write code. |
| Request / success | UUID path ID and no body. Returns `200` with the exact shared Notification resource and request ID metadata after persistence. Malformed, missing, same-tenant-unowned and foreign notification IDs all return non-enumerable `404 NOT_FOUND`. |
| Workflow / concurrency | The caller-owned row is resolved and locked inside a transaction before mutation. Only `is_read=false` can transition; an already-read notification returns documented `409 CONFLICT`, preventing a repeated request from claiming another state change. |
| Side effects / DCL | Updates only `is_read`; the authoritative schema has no `read_at` field, so none is invented. Low-risk per-user UI acknowledgement creates no business audit or notification. `024_notification_log_write_grants.sql` adds API UPDATE only; combined privileges are SELECT+UPDATE with no INSERT/DELETE and no worker/report/auditor access. |
| Tests / status | `NotificationReadTest` covers exact resource/persistence, repeat conflict, malformed/missing/same-tenant/foreign hiding, authentication, local/foreign RBAC, inactive identity, throttling, no audit and DCL. Done — full SQLite passes 470 tests / 2693 assertions (two PostgreSQL-only skips) and PostgreSQL 18/PostGIS passes 470 / 2704; focused suites, route, Pint, Composer and live privilege gates pass. |

### **AUD-01 — GET /api/v1/audit-logs**

| Implementation field | Detail |
| :---- | :---- |
| Endpoint ID / priority | AUD-01 / P1 |
| Purpose / permission | Search immutable audit evidence through the standard paginated API envelope. Requires active Sanctum authentication and tenant-valid `audit.read`; a permission inherited only from a foreign-organization role cannot authorize access. |
| Request / validation | Optional UUID `user_id` and `record_id`; normalized exact `action` and `table_name`; ISO-8601 `from`/`to` instants with `to >= from`; positive `page`; and optional `per_page` from 1 through 100 under the common pagination standard. |
| Response / ordering | Returns exact safe AuditLog resources (`audit_log_id`, actor/action/target, old/new JSON evidence, request context and UTC timestamp), newest first by timestamp then UUID, with request ID and complete page metadata. The read creates no audit event or notification. |
| Organization isolation | By default, only events whose actor belongs to the caller's organization are visible, including evidence from soft-deleted historical actors. Foreign and null-actor/system events are excluded because the current audit schema has no organization column. An independently effective `organizations.manage` grant supplies the manual's explicit cross-organization administrator elevation and permits global/system-event review. Foreign or missing `user_id` filters remain non-enumerable 404s without that elevation. |
| Immutability / DCL | Reuses `trg_audit_logs_append_only`, Eloquent mutation guards and `002_identity_and_audit_grants.sql`. Live PostgreSQL checks confirm API SELECT/INSERT but no UPDATE/DELETE/TRUNCATE, auditor SELECT only, and no worker/report access; no privilege expansion or schema change is introduced. |
| Errors / tests / status | `401 UNAUTHENTICATED`; `403 ACCOUNT_INACTIVE` or `FORBIDDEN`; `404 NOT_FOUND` for an unavailable actor filter; `422 VALIDATION_FAILED`; `429 RATE_LIMITED`; unexpected failures remain `500`. `AuditLogIndexTest` covers exact shape/pages/order, every filter, timestamp bounds, tenant/global scope, historical actors, anti-enumeration, validation, authentication/RBAC, inactive identity, throttling and DCL. Done — full SQLite passes 449 tests / 2571 assertions (one PostgreSQL-only skip) and PostgreSQL 18/PostGIS passes 449 / 2581; focused suites, route, Pint, Composer, trigger and live privilege gates pass. |

## **Training datasets and annotation extension**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| DATASET-01 | GET /training-datasetsList training/validation datasets. | Query: type,source,page | 200 {data:\[TrainingDataset\],meta} | AUTH | **P2** | Jason Benabente | **Testing** |
| DATASET-02 | POST /training-datasetsCreate dataset metadata. | {dataset\_name,dataset\_type,source,description?,version\_label?} | 201 {data:TrainingDataset} | DATASET-01 | **P2** | Jason Benabente | **Testing** |
| DATASET-03 | POST /training-datasets/{id}/itemsAttach labeled media/sample. | {media\_id?,label\_file\_path,label\_format,species\_id?,annotation\_status} | 201 {data:DatasetItem} | DATASET-02 \+ MEDIA-03 | **P2** | Jason Benabente | **Testing** |
| ANN-01 | GET /annotation/projectsExisting annotation-workspace project list; requires extension tables if retained. | Query: status?,page | 200 {data:\[AnnotationProject\],meta} | annotation extension | **P2** | Jason Benabente | **Testing** |
| ANN-02 | POST /annotation/projectsCreate annotation project. | {name,dataset\_type,mission\_id?,status} | 201 {data:AnnotationProject} | ANN-01 | **P2** | Jason Benabente | **Testing** |
| ANN-03 | PUT /annotation/items/{id}/objectsReplace item annotations transactionally. | {objects:\[{class\_id,bbox?,polygon?,attributes?}\]} | 200 {data:{count}} | ANN-02 | **P2** | Jason Benabente | **Testing** |
| ANN-04 | POST /annotation/projects/{id}/exportsExport COCO/YOLO/CSV/GeoJSON labels. | {format} | 201 {data:{export\_id,file\_name,storage\_key}} | ANN-03 \+ storage | **P2** | Jason Benabente | **Testing** |

# **7\. Key endpoint request/response examples**

These examples define the expected shape for the highest-risk integrations. Other endpoints follow the same envelope and error conventions.

## **7.1 Login**

| POST /api/v1/auth/login{  "email": "researcher@example.org",  "password": "\*\*\*\*\*\*\*\*",  "device\_name": "MangroScan Mobile"}200 OK{  "data": {    "user": {      "user\_id": "uuid",      "organization\_id": "uuid",      "first\_name": "Researcher",      "last\_name": "User",      "email": "researcher@example.org"    },    "access\_token": "opaque-token",    "expires\_at": "2026-08-10T12:30:00Z",    "roles": \["Researcher"\],    "permissions": \["mission.read", "media.process", "validation.create"\]  },  "meta": {"request\_id": "req\_..."}} |
| :---- |

## **7.2 Create mission**

| POST /api/v1/missions{  "site\_id": "uuid",  "mission\_code": "MSN-2026-014",  "mission\_title": "Quarterly Mangrove Survey",  "mission\_objective": "Tree detection and species monitoring",  "planned\_start\_at": "2026-08-15T00:00:00Z",  "planned\_end\_at": "2026-08-16T00:00:00Z",  "coverage\_target\_hectares": 12.5}201 Created{  "data": {    "mission\_id": "uuid",    "mission\_code": "MSN-2026-014",    "mission\_status": "planned",    "approved\_by": null,    "created\_at": "2026-08-10T03:30:00Z"  }} |
| :---- |

## **7.3 Offline sync**

| POST /api/v1/mobile/sync{  "device\_id": "device-uuid",  "base\_cursor": "cursor\_1042",  "changes": \[    {      "client\_id": "local-checklist-12",      "entity": "flight\_checklist",      "operation": "upsert",      "version": 3,      "payload": {"flight\_session\_id": "uuid", "overall\_status": "passed"}    }  \]}200 OK{  "data": {    "applied": \[{"client\_id": "local-checklist-12", "server\_id": "uuid", "version": 4}\],    "conflicts": \[\],    "server\_changes": \[\]  },  "meta": {"cursor": "cursor\_1043", "server\_time": "2026-08-10T03:31:00Z"}} |
| :---- |

## **7.4 Start media upload**

| POST /api/v1/flights/{flightId}/media/uploadsIdempotency-Key: 6f61...{  "file\_name": "DJI\_0041.MP4",  "file\_type": "video",  "mime\_type": "video/mp4",  "file\_size\_bytes": 482003114,  "checksum\_sha256": "...",  "capture\_location": {"type": "Point", "coordinates": \[123.305278, 9.306944\]},  "captured\_at": "2026-08-10T02:50:00Z"}201 Created{  "data": {    "upload\_id": "uuid",    "storage\_key": "missions/.../DJI\_0041.MP4",    "upload\_strategy": "multipart",    "parts": \[{"part\_number": 1, "url": "temporary-private-upload-url"}\]  }} |
| :---- |

## **7.5 Queue AI processing**

| POST /api/v1/processing-jobsIdempotency-Key: 47c8...{  "mission\_id": "uuid",  "flight\_session\_id": "uuid",  "job\_type": "full\_pipeline",  "media\_ids": \["uuid-1", "uuid-2"\],  "parameters": {"confidence": 0.25, "iou": 0.70}}202 Accepted{  "data": {    "processing\_job\_id": "uuid",    "job\_status": "queued",    "created\_at": "2026-08-10T03:40:00Z"  }} |
| :---- |

## **7.6 Save validation decision**

| POST /api/v1/validation-sessions/{sessionId}/decisions{  "tree\_observation\_id": "uuid",  "ground\_truth\_id": "uuid",  "decision": "corrected",  "accepted\_species\_id": "uuid",  "accepted\_height\_m": 4.82,  "accepted\_age\_years": 6.1,  "corrected\_geometry": {"type": "Point", "coordinates": \[123.305410, 9.307010\]},  "notes": "Ground point corrected after field verification."}201 Created{  "data": {    "validation\_match\_id": "uuid",    "match\_status": "corrected",    "distance\_error\_meters": 1.86,    "validated\_at": "2026-08-10T04:10:00Z"  }} |
| :---- |

## **7.7 Generate report**

| POST /api/v1/reports/{reportId}/generateIdempotency-Key: 517f...{  "format": "PDF",  "options": {"include\_validation": true, "include\_map": true}}202 Accepted{  "data": {    "job\_id": "uuid",    "report\_id": "uuid",    "status": "queued"  }} |
| :---- |

# **8\. Database migration order**

Foreign-key creation order should follow the dependency chain rather than alphabetic order. This reduces migration failures and makes seed data predictable.

| Order | Database objects | Reason |
| :---- | :---- | :---- |
| 1 | extensions / database settings / schemas | Enable pgcrypto \+ PostGIS before UUID/spatial DDL. |
| 2 | organizations, users, roles, permissions, role\_permissions, user\_roles | Identity and ownership root. |
| 3 | survey\_sites, site\_boundaries, monitoring\_plots, site\_access\_permissions | Mission parent geography. |
| 4 | drones, drone\_sensors, sensor\_calibrations, battery\_packs | Flight resources. |
| 5 | survey\_missions, mission\_team\_members | Mission parent records. |
| 6 | flight\_sessions, flight\_waypoints, flight\_environment\_logs, flight\_checklists, battery\_usage\_logs | Operational flight records. |
| 7 | media\_assets, sensor\_datasets | Captured inputs. |
| 8 | training\_datasets, ai\_models, ai\_model\_versions, training\_dataset\_items | Model registry and provenance. |
| 9 | processing\_jobs, model\_runs | Execution graph. |
| 10 | mangrove\_species, species\_growth\_models, mangrove\_tree\_entities, tree\_observations | Core canonical results. |
| 11 | species\_classification\_results, canopy\_height\_estimations, age\_estimations, tree\_count\_summaries | Derived tree results. |
| 12 | photogrammetry\_products, geospatial\_layers | Map/output products. |
| 13 | validation\_sessions, ground\_truth\_tree\_records, validation\_matches, accuracy\_metrics | Validation evidence. |
| 14 | reports, exported\_files, dashboard\_saved\_views, notification\_logs, system\_settings | Presentation/output layer. |
| 15 | audit\_logs \+ extension tables \+ indexes \+ views \+ routines \+ triggers \+ grants | Cross-cutting/hardening after base FK graph exists. |

# **9\. Schema extensions required to preserve current application behavior**

| Important The supplied database design is strong, but several current application features are not fully represented by base tables. Add these deliberately instead of hiding the state inside JSON fields. |
| :---- |

| Extension | Recommended object | Why / minimum fields | Priority |
| :---- | :---- | :---- | :---- |
| Flight \+ mission approval history | approval\_requests or approval\_events | Generic subject\_type/subject\_id, requested\_by, assigned\_to, decision, notes, decided\_at. The base mission has approved\_by, but a generic event table preserves rejection/history and can cover flights. | **P0** |
| Offline mobile synchronization | sync\_devices, sync\_change\_log, sync\_conflicts | device\_id/user\_id, last\_cursor, entity/version/tombstone, client\_id, conflict payload; required for safe offline-first sync instead of direct Supabase replication. | **P0** |
| AI service connection | ai\_service\_connections | name, base\_url, environment, enabled, health status, version, encrypted credential reference, last health/model sync. Never return raw key. | **P1** |
| Confidence review workflow | confidence\_review\_flags | source\_result\_id/type, severity, status, assigned\_to, reason, review\_note, resolution\_notes, timestamps. | **P1** |
| API credentials | personal\_access\_tokens / refresh\_tokens | Framework/token-specific token hash, user/device, abilities, expires\_at, revoked\_at. Do not store plaintext tokens. | **P0** |
| Annotation workspace (if retained) | annotation\_projects, annotation\_items, annotation\_objects, annotation\_exports | Current report/export code exposes annotation exports; persist project/item/object lineage rather than browser-only state. | **P2** |
| Report content fields | reports columns or report\_sections | audience, interpretation, limitations, recommendations, completeness score/details, selected flights/formats if these remain part of UI. | **P1** |

# **10\. Database views and materialized views**

Views should simplify repeated joins and enforce a consistent read model. They do not replace API authorization. Organization filtering still occurs through policy/repository scope (and optionally PostgreSQL RLS).

| ID | View | Source | Purpose | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| V-01 | v\_user\_effective\_permissions | users \+ organizations \+ user\_roles \+ roles \+ role\_permissions \+ permissions | One row per user/permission. Back AUTH-02/AUTH-08 and server-side permission checks. | **P0** | Codex \- DB/Security | **Done** |
| V-02 | v\_mission\_overview | survey\_missions \+ sites \+ team \+ flight aggregates | Mission list/detail counts, approval/status, coverage, flight totals. | **P0** | DB Engineer | **Blocked** |
| V-03 | v\_flight\_readiness | flight\_sessions \+ latest preflight \+ drone/sensor/permit state | Compute whether flight may start and why blocked. | **P0** | DB Engineer | **Blocked** |
| V-04 | v\_media\_processing\_queue | media\_assets \+ flight \+ mission \+ processing jobs | Media uploaded/quality-ready/not-yet-processed queue for Researcher. | **P0** | DB Engineer | **Blocked** |
| V-05 | v\_processing\_job\_overview | processing\_jobs \+ model\_runs \+ model versions | Human-readable job state, model provenance and latest error. | **P0** | DB Engineer | **Blocked** |
| V-06 | v\_tree\_result\_detail | tree\_observations \+ final species \+ latest/final height \+ latest/final age \+ source media | Canonical tree detail read model. | **P0** | DB/GIS Engineer | **Blocked** |
| V-07 | v\_validation\_workspace | validation sessions \+ ground truth \+ matches \+ tree observations | Supports validation session summary/list; object-level data can still use focused queries. | **P1** | DB Engineer | **Blocked** |
| V-08 | v\_mission\_accuracy\_summary | accuracy\_metrics | Latest validation metric per mission/metric type, including validation-session and model-version provenance. | **P1** | Earljohn Estandarte | **Done** |
| V-09 | v\_report\_source\_summary | mission/site/count/species/validation aggregates | Stable source for report preview and generation. | **P1** | DB Engineer | **Blocked** |
| V-10 | v\_notification\_inbox | notification\_logs \+ users | Unread/read inbox ordered by created\_at. | **P1** | DB Engineer | **Blocked** |
| V-11 | v\_audit\_activity | audit\_logs \+ users | Admin audit browsing without repeating user joins. | **P1** | DB/Security | **Blocked** |
| MV-01 | mv\_dashboard\_mission\_metrics | missions/sites \+ tree/validation/processing aggregates \+ V-08 | Tenant-keyed dashboard KPI snapshot; refresh after canonical result/validation changes. | **P1** | Earljohn Estandarte | **Done** |
| MV-02 | mv\_species\_distribution\_by\_mission | tree observations \+ species | Pre-aggregated species distribution for charts/reports. | **P2** | DB Engineer | Backlog |
| MV-03 | mv\_tree\_density\_by\_site | tree observations \+ site area/spatial aggregation | Trend/density reporting; refresh on finalized results. | **P2** | DB/GIS Engineer | Backlog |

## **10.1 Example PostgreSQL view**

V-01 is implemented by `2026_08_12_070000_create_user_effective_permissions_view.php`. It preserves the documented six-column projection, excludes inactive/soft-deleted identities and ignores role assignments from another organization while retaining system/global roles. `EffectiveAccessService` now obtains effective permission codes from this shared read model for AUTH-02/AUTH-08 and permission middleware while preserving role projection semantics. `041_user_effective_permissions_view_grants.sql` gives only the API runtime SELECT; worker, reporting and auditor roles receive no access. `UserEffectivePermissionsViewTest` verifies global/tenant/foreign role semantics, inactive/deleted filtering, reproducible DDL, service adoption and read-only DCL on SQLite and PostgreSQL 18.

| CREATE OR REPLACE VIEW app.v\_user\_effective\_permissions ASSELECT    u.user\_id,    u.organization\_id,    r.role\_id,    r.role\_name,    p.permission\_id,    p.permission\_codeFROM app.users uJOIN app.user\_roles ur ON ur.user\_id \= u.user\_idJOIN app.roles r ON r.role\_id \= ur.role\_idJOIN app.role\_permissions rp ON rp.role\_id \= r.role\_idJOIN app.permissions p ON p.permission\_id \= rp.permission\_idWHERE u.is\_active \= TRUE  AND u.deleted\_at IS NULL; |
| :---- |

## **10.2 Dashboard read models and refresh contract**

`2026_08_25_000000_create_dashboard_read_models.php` implements V-08 and MV-01 without changing an endpoint contract. V-08 uses a deterministic window order (`computed_at`, then UUID) to expose the latest supported accuracy metric for each mission and metric type while retaining validation-session and model-version provenance. MV-01 starts from non-deleted missions and sites, carries `organization_id`, `site_id`, and `mission_id` on every row, and joins separately pre-aggregated tree, validation/ground-truth, processing, and accuracy groups so one-to-many joins cannot inflate counts. PostgreSQL receives a materialized view plus a unique mission index for concurrent refresh and tenant/status/date indexes for the documented dashboard filters; SQLite receives an identically named live view strictly for fast compatibility tests.

The PostgreSQL snapshot is intentionally not refreshed by a read endpoint. Run `php artisan dashboard:refresh` with the view owner/migrator database connection after canonical result or validation changes; the command uses `REFRESH MATERIALIZED VIEW CONCURRENTLY` outside transactions and safely falls back to a non-concurrent refresh if an authorized caller is already inside a transaction. The API runtime keeps SELECT-only access and cannot refresh the view. Dashboard data is a snapshot as of the most recent successful command. `049_dashboard_read_model_grants.sql` revokes access from `PUBLIC` and every service role before granting SELECT only to `mangroscan_api_rw` and `mangroscan_report_ro`; worker/auditor and all write privileges remain denied. `DashboardReadModelTest` covers deterministic latest-metric selection, aggregate integrity, tenant keys, soft-delete filtering, refresh behavior, DDL/indexes, and DCL on SQLite and PostgreSQL/PostGIS. DASH-01 exposes the tenant/role-scoped five-group overview, while DASH-02 exposes exact mission counts, species distribution, null-safe height/age summaries, six latest accuracy metrics, and storage-safe layer metadata. A visible mission absent from PostgreSQL MV-01 returns explicit `503 SERVICE_UNAVAILABLE`; reads never self-refresh or create audit events. DASH-02 focused SQLite passes 8 tests / 57 assertions with one expected PostgreSQL-only skip, focused PostgreSQL/PostGIS passes 9 / 59, full SQLite passes 756 / 5214 with thirteen expected PostgreSQL-only skips, and full PostgreSQL/PostGIS passes 769 / 5251. The dashboard chain and MATCH-01 → ACC-01 chain are complete.

## **10.3 Example map-ready view**

| CREATE OR REPLACE VIEW app.v\_tree\_map\_features ASSELECT    t.tree\_observation\_id,    t.mission\_id,    t.flight\_session\_id,    t.tree\_code,    t.detection\_confidence,    t.validation\_status,    s.scientific\_name AS final\_species,    ST\_AsGeoJSON(t.tree\_location)::jsonb AS geometryFROM app.tree\_observations tLEFT JOIN app.mangrove\_species s       ON s.species\_id \= t.final\_species\_idWHERE t.deleted\_at IS NULL; |
| :---- |

# **11\. Database routines, triggers and invariant enforcement**

| ID | Routine | Type | Purpose | Pri |
| :---- | :---- | :---- | :---- | :---- |
| R-01 | fn\_touch\_updated\_at() \+ triggers | BEFORE UPDATE | Set updated\_at consistently on mutable tables. Implemented for every current application table carrying `updated_at`, with invoker rights and no direct runtime execution. | **P0 — Done** |
| R-02 | fn\_audit\_row\_change() \+ triggers | AFTER INSERT/UPDATE/DELETE | Append old/new JSONB to immutable audit\_logs; include app user/request context where available. | **P0** |
| R-03 | fn\_user\_has\_permission(user\_id, permission\_code) | SQL function | Optional DB-side helper for routines/RLS. API policies remain primary authorization surface. Implemented with V-01 tenant-safe semantics, invoker rights and API-only EXECUTE. | **P1 — Done** |
| R-04 | fn\_flight\_readiness(flight\_id) | SQL function | Return passed:boolean and reasons\[\] from preflight/resource/approval state. | **P0** |
| R-05 | sp\_recompute\_tree\_count\_summary(mission\_id) | Procedure / transaction | Upsert total/species counts after completed processing/validation. | **P0** |
| R-06 | sp\_recompute\_accuracy\_metrics(validation\_session\_id) | Procedure / transaction | Compute sample size, precision/recall/F1/species accuracy and error metrics from validation matches. | **P0** |
| R-07 | sp\_finalize\_processing\_job(job\_id, output\_summary) | Procedure / transaction | Atomically mark job completed, persist summary, refresh count/materialized views, enqueue notifications via application outbox or insert notification rows. | **P1** |
| R-08 | sp\_finalize\_report(report\_id, exported\_file...) | Procedure / transaction | Atomically move report to generated state and write exported\_files record. | **P1** |
| R-09 | fn\_validate\_geometry\_srid() | Constraint/trigger helper | Reject or normalize geometry not in SRID 4326 for API-facing geographic fields. | **P1** |

## **11.1 R-01 updated-at trigger**

`2026_08_12_070200_create_touch_updated_at_triggers.php` creates the rerunnable PostgreSQL `app.fn_touch_updated_at()` trigger function and the original table triggers. Later schema extensions add their triggers after their tables exist; `2026_08_24_000000_add_battery_touch_trigger.php` closes the battery-table gap introduced by the hardware contribution. All 38 current application tables that carry `updated_at` now have an explicit `BEFORE UPDATE` trigger. Database statement time replaces caller-supplied timestamps consistently, including query-builder and worker updates that bypass Eloquent timestamp handling, without inheriting an older transaction-start timestamp. The function uses invoker rights with a `pg_catalog`-only search path; direct execution is revoked from `PUBLIC` in the migration and from every runtime role by `043_touch_updated_at_trigger_grants.sql`. `TouchUpdatedAtTriggerTest` compares trigger coverage against PostgreSQL catalog metadata, proves stale timestamps are overwritten, verifies extension migrations, and statically verifies the closed DCL. SQLite remains a deliberate no-op because its application writes retain Laravel timestamp behavior.

## **11.2 Updated-at trigger example**

| CREATE OR REPLACE FUNCTION app.fn\_touch\_updated\_at()RETURNS triggerLANGUAGE plpgsqlSECURITY INVOKERSET search\_path \= pg\_catalogAS $$BEGIN  NEW.updated\_at := statement\_timestamp();  RETURN NEW;END;$$;CREATE TRIGGER trg\_survey\_missions\_touchBEFORE UPDATE ON app.survey\_missionsFOR EACH ROW EXECUTE FUNCTION app.fn\_touch\_updated\_at(); |
| :---- |

## **11.2 Routine design rule**

| Keep business workflows in the API; keep database invariants atomic. Use routines for calculations, audit triggers, state integrity and expensive reusable aggregates. Do not bury the entire mission workflow in stored procedures; the API/service layer should remain testable and readable. |
| :---- |

# **12\. Constraints and indexes to add before production**

| Area | Indexes / constraints | Why |
| :---- | :---- | :---- |
| Identity | UNIQUE users(email); indexes users.organization\_id; role/user join indexes | Login, user search, RBAC joins |
| Mission | UNIQUE site\_code, mission\_code, flight\_code; indexes mission.site\_id, flight.mission\_id, flight.drone\_id | Core navigation |
| Media | (flight\_session\_id, quality\_status), checksum\_sha256 extension index, deleted\_at partial indexes | Upload queue \+ duplicate detection |
| Processing | (mission\_id, job\_status), (flight\_session\_id, job\_status), model\_runs(processing\_job\_id) | Researcher queue |
| Results | tree(mission\_id), tree(flight\_session\_id), tree(final\_species\_id), result FK indexes | Dashboard/filtering |
| Spatial | GiST on site boundary, plot geom, capture location, tree location, crown polygon | PostGIS map/within/intersection queries |
| Validation | validation\_sessions(mission\_id), ground\_truth(session\_id), matches(tree\_id, ground\_truth\_id) | Workspace and metric recompute |
| Reports | reports(mission\_id,status), exported\_files(report\_id, exported\_at) | Report registry |
| Notifications | notification\_logs(user\_id,is\_read,created\_at DESC) | Unread inbox |
| Audit | audit\_logs(table\_name,record\_id,created\_at), audit\_logs(user\_id,created\_at) | Traceability |

# **13\. Database DCL and security roles**

The database account used by the application should not own the schema. Separate ownership, migration, runtime read/write, worker, reporting and backup duties. Never embed production DB passwords in source code or frontend configuration.

| DB role | Purpose | Typical rights |
| :---- | :---- | :---- |
| mangroscan\_owner | NOLOGIN schema/object owner | Own schemas/tables/views/functions; not used by app |
| mangroscan\_migrator | CI/CD migrations | CREATE/ALTER/DROP in app schema; execute migration routines |
| mangroscan\_api\_rw | PHP API runtime | SELECT/INSERT/UPDATE/DELETE allowed tables; EXECUTE approved functions; no DDL |
| mangroscan\_worker | Queue/AI/report worker | Runtime writes for processing/results/exports; no user/RBAC administration unless needed |
| mangroscan\_report\_ro | Read-only reporting/debug | SELECT approved views/materialized views only |
| mangroscan\_auditor | Audit review | SELECT audit and operational views; no writes |
| mangroscan\_backup | Backup account | Minimal rights required by backup tooling; no app login |

## **13.1 PostgreSQL DCL baseline**

The executable bootstrap is version controlled at `database/sql/dcl/001_roles_and_schema.sql`. Run it as a PostgreSQL cluster administrator before application migrations. It creates only NOLOGIN group roles, establishes the `app` schema, removes public schema-creation rights, and grants schema access without embedding credentials. Environment-specific LOGIN roles and passwords are provisioned outside source control and receive the appropriate group-role membership. Laravel uses the `app,public` search path so new application objects resolve to `app` while PostGIS extension objects and transitional public-schema objects remain available.

PostgreSQL extensions are installed by `2026_08_12_061500_enable_postgresql_extensions.php` before relational migrations. Its rollback is deliberately a no-op because dropping `pgcrypto` or PostGIS can destroy dependencies outside this application's migration boundary.

| \-- Ownership and runtime rolesCREATE ROLE mangroscan\_owner NOLOGIN;CREATE ROLE mangroscan\_api\_rw NOLOGIN;CREATE ROLE mangroscan\_worker NOLOGIN;CREATE ROLE mangroscan\_report\_ro NOLOGIN;CREATE ROLE mangroscan\_auditor NOLOGIN;CREATE SCHEMA IF NOT EXISTS app AUTHORIZATION mangroscan\_owner;REVOKE CREATE ON SCHEMA public FROM PUBLIC;REVOKE ALL ON SCHEMA app FROM PUBLIC;GRANT USAGE ON SCHEMA app TO mangroscan\_api\_rw, mangroscan\_worker,                                  mangroscan\_report\_ro, mangroscan\_auditor;GRANT SELECT, INSERT, UPDATE, DELETEON ALL TABLES IN SCHEMA app TO mangroscan\_api\_rw;GRANT SELECT, INSERT, UPDATE, DELETEON app.processing\_jobs, app.model\_runs, app.tree\_observations,   app.species\_classification\_results, app.canopy\_height\_estimations,   app.age\_estimations, app.tree\_count\_summaries, app.reports,   app.exported\_filesTO mangroscan\_worker;GRANT SELECT ON app.v\_mission\_overview,                app.v\_tree\_result\_detail,                app.v\_mission\_accuracy\_summary,                app.v\_report\_source\_summaryTO mangroscan\_report\_ro;GRANT SELECT ON app.audit\_logs, app.v\_audit\_activityTO mangroscan\_auditor;REVOKE UPDATE, DELETE ON app.audit\_logsFROM mangroscan\_api\_rw, mangroscan\_worker;\-- New objects inherit intended runtime rights.ALTER DEFAULT PRIVILEGES FOR ROLE mangroscan\_owner IN SCHEMA appGRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO mangroscan\_api\_rw; |
| :---- |

## **13.2 Optional PostgreSQL RLS**

If you want defense-in-depth similar to Supabase RLS, enable row-level security on organization-owned resources and have the API set the tenant context inside each transaction. This is optional and should be added only after API authorization tests are stable.

| \-- Conceptual pattern; adapt organization lineage per table.ALTER TABLE app.survey\_sites ENABLE ROW LEVEL SECURITY;CREATE POLICY survey\_sites\_org\_scopeON app.survey\_sitesUSING (  organization\_id \= current\_setting('app.organization\_id', true)::uuid);\-- At request transaction start, the API sets:\-- SET LOCAL app.organization\_id \= '...'; |
| :---- |

## **13.3 MySQL security translation**

* Use separate MySQL users/roles for migrations, API runtime, workers, report readers and backups; grant only schema/table privileges required by each.  
* MySQL does not provide PostgreSQL-style row-level security policies; organization scoping must be guaranteed in API repository/policy code, with restricted views where useful.  
* Translate GiST indexes to SPATIAL INDEX and ensure indexed spatial columns meet MySQL SRID/NULL requirements.

# **14\. PostgreSQL/PostGIS vs MySQL translation notes**

| Supplied/PostgreSQL design | MySQL equivalent / concern |
| :---- | :---- |
| UUID DEFAULT gen\_random\_uuid() | Use application-generated UUIDs, CHAR(36), or BINARY(16) strategy consistently. |
| JSONB | Use JSON; review JSON indexing/generated columns for high-volume filters. |
| TIMESTAMPTZ | Store UTC consistently; MySQL TIMESTAMP/DATETIME timezone behavior differs. |
| GEOMETRY(Point/Polygon, 4326\) | Use POINT/POLYGON with SRID 4326 and validate SRID on writes. |
| GiST spatial index | Use MySQL SPATIAL INDEX on supported NOT NULL SRID-restricted spatial columns. |
| PostGIS functions | Map each ST\_\* function to MySQL spatial equivalent; integration-test GeoJSON coordinate order and distance units. |
| Partial indexes | Often require composite indexes or generated-column strategies in MySQL. |
| RLS policies | No direct equivalent; use API tenant filters and restricted views. |
| PL/pgSQL routines | Rewrite as MySQL stored routines/triggers or move calculation to application services. |

| Decision Use PostgreSQL/PostGIS unless deployment constraints force MySQL. The provided schema already speaks PostgreSQL/PostGIS natively, so choosing MySQL creates avoidable translation and GIS validation work during an already large migration. |
| :---- |

# **15\. FastAPI integration contract**

The PHP API should expose application-level processing endpoints and hide the inference-service topology from the clients. A queue worker performs the actual FastAPI call, converts the response into database transactions, and updates processing\_jobs/model\_runs/tree result tables.

| Step | API/worker responsibility | Persisted state |
| :---- | :---- | :---- |
| 1 | Validate caller, mission/flight, media quality and requested model capability | processing\_jobs \= queued |
| 2 | Resolve deployed model/service and decrypt service credential server-side | model\_runs \= queued |
| 3 | Worker calls FastAPI with media and parameters | job/run \= running; started\_at |
| 4 | Normalize detector/classifier/pipeline response | tree\_observations and result tables in transaction |
| 5 | Compute counts/map outputs as needed | tree\_count\_summaries / geospatial\_layers |
| 6 | Commit completion; generate durable user notification | job/run \= completed; output\_summary; notification\_logs |
| Failure | Capture safe error code/message; do not leak secret/header | job/run \= failed; error\_message; notification |

# **16\. Mandatory audit events**

Audit storage is implemented by `2026_08_12_062300_create_audit_logs_table.php`. Audit IDs and affected record IDs are UUIDs; JSON snapshots, request ID, IP address and user agent preserve request evidence. PostgreSQL rejects UPDATE and DELETE through `trg_audit_logs_append_only`, the Eloquent model rejects those mutations in application code, and `database/sql/dcl/002_identity_and_audit_grants.sql` revokes UPDATE, DELETE and TRUNCATE from runtime roles. `record_id` is nullable only for system/security events where no canonical resource UUID exists.

| Event | Audit action | Minimum context |
| :---- | :---- | :---- |
| Login / logout / failed login | auth.login / auth.logout / auth.failed | user/email hash, IP, user agent, request ID |
| User/role changes | user.create/update/status; role.assign; permission.change | actor, target user/role, old/new values |
| Mission/flight lifecycle | mission.create/approve/start/complete; flight.create/start/complete/fail | mission/flight ID, actor, transition |
| Media changes | media.upload/quality/delete/download | media ID, storage key, checksum, actor |
| AI jobs | processing.create/retry/cancel/complete/fail | job ID, model versions, input IDs, request ID |
| Validation | validation.create/decision/recompute/complete | session, tree/ground IDs, old/final values |
| Report/export | report.create/generate/approve; export.create/download | report/export IDs, format, actor |
| Settings/credentials | setting.update; ai\_service.rotate | changed key/service; never log secret value |

# **17\. Definition of Done for every endpoint card**

* Route exists under /api/v1 and appears in generated OpenAPI documentation.  
* Request validation covers required fields, enum/state values, UUID ownership and file limits.  
* Authentication and permission/policy checks exist and include organization scope tests.  
* Writes use transactions when more than one table/state change is involved.  
* Response follows the standard envelope and documented HTTP status codes.  
* Audit event is emitted when the action changes security, workflow state, evidence, results or files.  
* Idempotency is tested for retried mobile/upload/job/report writes where duplicate side effects are possible.  
* Unit/service tests cover valid, invalid, unauthorized, forbidden, missing, conflict and downstream-failure cases.  
* Integration test runs against the chosen real DB engine; GIS endpoints include geometry/SRID tests.  
* No secret values are logged or returned; user-controlled filenames are not trusted as filesystem paths.

# **18\. Suggested sprint / kanban sequencing**

| Sprint / lane | Cards to pull first | Exit criteria |
| :---- | :---- | :---- |
| Sprint 0 \- Foundation | DB migrations, DCL skeleton, SYS-01, AUTH-01..03, RBAC-01..03, audit/request ID | Authenticated scoped request reaches DB; migration/rollback automated. |
| Sprint 1 \- Site & Mission | SITE/BOUND/PLOT, MSN-01..06, TEAM-01, DRONE-01..03 | Create/approve mission and assign usable resources entirely through API. |
| Sprint 2 \- Field & Offline | FLT-01..06, CHK-01, SYNC-01..04 | Mobile can download authorized mission, work offline, sync checklist/flight state. |
| Sprint 3 \- Upload & AI | MEDIA-01..06, AISVC-01..04, MODEL-01/02, JOB-01..04 | Captured media reaches private storage; server worker processes and persists result. |
| Sprint 4 \- Results & Validation | TREE-01..03, COUNT-01, VAL-01..04, GT-01, MATCH-01, ACC-01 | Researcher/Environmental workflow can review, ground-truth and recompute accuracy. |
| Sprint 5 \- Reporting | RPT-01..06, EXP-01..03, DASH-01/02, NOTIF-01..03 | Professional report/export generated and authorized download/audit works. |
| Sprint 6 \- Hardening/Cutover | P1/P2 gaps, performance indexes, materialized views, RLS optional, backup/restore, data migration | Supabase clients disabled; reconciliation and rollback drill pass. |

# **19\. Supabase cutover checklist**

* Inventory every Supabase dependency in web/mobile: Auth, database queries/RPC, Storage, signed URLs, realtime subscriptions, Edge Functions, RLS assumptions and environment variables.  
* Create a field/table mapping from current Supabase schema to the target schema; define transformations before moving production data.  
* Migrate reference/config tables first, then sites/missions/flights, then media metadata/storage, then processing/results/validation/reports/audit in FK order.  
* For authentication, decide whether existing password hashes can be safely/legally migrated through the chosen auth stack; otherwise schedule a controlled password reset instead of attempting unsupported hash conversion.  
* Copy object storage with checksums; rewrite database file\_path/storage\_key values only after verification.  
* Run reconciliation reports: row counts, FK orphan checks, checksum counts, mission/flight counts, tree counts, validation metrics and report/export counts.  
* Switch the clients to the API using an environment/config flag; keep Supabase read-only during a short verification window if operationally possible.  
* Remove Supabase service keys and direct DB/storage client code only after smoke tests pass on both web and Expo mobile.  
* Perform a restore drill for both relational database and object storage before declaring migration complete.

# **20\. End-to-end acceptance scenarios**

| Scenario | Expected chain |
| :---- | :---- |
| Authentication and role isolation | User logs in \-\> API returns permissions \-\> forbidden role receives 403 \-\> other organization's IDs return 404/403 without data leakage. |
| Mission setup | Site exists \-\> mission created \-\> team/resources assigned \-\> authorized approval recorded \-\> mission becomes available for permitted clients. |
| Field flight | Offline mission bundle downloaded \-\> preflight passed \-\> flight started \-\> media captured \-\> flight completed \-\> changes sync without duplicates. |
| Upload | Large video resumably uploads \-\> checksum verified \-\> media row created exactly once \-\> Researcher sees ready media. |
| AI processing | Researcher queues combined job \-\> worker calls FastAPI \-\> model/run provenance persisted \-\> tree/species results visible \-\> failure retry does not duplicate trees. |
| Validation | Low-confidence result selected \-\> validation session \-\> ground truth \-\> corrected/matched decision \-\> accuracy recompute \-\> original prediction remains immutable. |
| Reporting | Validated mission \-\> report draft \-\> PDF/CSV/GeoJSON generation \-\> exported\_files record \-\> authorized download \-\> audit events exist. |
| Security | Raw DB credentials/FastAPI key never appear in browser/mobile bundle, network response, logs, audit old/new values or generated report. |

# **21\. Highest migration risks**

| Risk | Impact | Mitigation | Owner |
| :---- | :---- | :---- | :---- |
| Replacing Supabase RLS with incomplete API scoping | Cross-organization data leak | Central tenant scope middleware/repository \+ policy tests; optional PostgreSQL RLS later | Security/API |
| Auth migration incompatibility | Users cannot log in | Prototype auth migration early; planned password reset fallback | Security/API |
| Large video uploads through PHP request path | Timeout/memory failures | Direct/private multipart object upload or resumable protocol; only finalize through API | Storage/API |
| AI processing remains synchronous | HTTP timeout and duplicate jobs | Queue worker \+ idempotency \+ explicit processing job state | AI/API |
| GIS translation if choosing MySQL | Incorrect maps/distances/index use | Prefer PostgreSQL/PostGIS; otherwise integration-test every spatial query | DB/GIS |
| Offline sync conflict logic added late | Lost field data / duplicate uploads | Build cursor/version/conflict model before removing Supabase sync behavior | Mobile/API |
| Files moved without checksum reconciliation | Broken evidence links | Checksum copy verification and immutable storage-key mapping report | DevOps/Storage |
| Audit table writable by runtime users | Evidence tampering | DCL revoke UPDATE/DELETE; append via controlled trigger/service | DB/Security |

# **Appendix A. Source module-to-API mapping**

| Workflow segment | Primary API epics | Core database modules |
| :---- | :---- | :---- |
| 1\. Authentication & Access | AUTH, ORG/USR/RBAC | organizations, users, roles, permissions, user\_roles, role\_permissions, audit\_logs |
| 2\. Site & Mission Setup | SITE/BOUND/PLOT/PERMIT, MSN/TEAM, DRONE/SENSOR | survey\_sites, site\_boundaries, monitoring\_plots, permissions, survey\_missions, mission\_team\_members, drones, sensors |
| 3\. Flight Operations | FLT, CHK, WPT, ENV, BAT, SYNC | flight\_sessions, waypoints, environment logs, checklists, battery usage |
| 4\. Upload & Quality Control | MEDIA, SDS | media\_assets, sensor\_datasets |
| 5\. AI Processing & Mapping | AISVC/MODEL/JOB, TREE/RESULT/COUNT/LAYER | ai\_models, model versions, processing\_jobs, model\_runs, tree/result tables, geospatial layers |
| 6\. Validation & Accuracy | CONF, VAL, GT, MATCH, ACC | validation\_sessions, ground\_truth\_tree\_records, validation\_matches, accuracy\_metrics |
| 7\. Reporting & Output | RPT, EXP, DASH, NOTIF, AUD | reports, exported\_files, saved views, notification\_logs, audit\_logs |

# **Appendix B. Recommended API permission codes**

| Area | Permission codes |
| :---- | :---- |
| Identity | organizations.manage, users.manage, roles.manage, permissions.manage |
| Hardware | drones.read, drones.manage, sensors.manage, sensor\_calibrations.manage, batteries.read |
| Sites | sites.read, sites.manage, boundaries.manage, plots.manage, site\_permissions.manage |
| Missions | missions.read, missions.create, missions.update, missions.approve, missions.complete, mission\_team.manage |
| Flights | flights.read, flights.create, flights.update, flights.start, flights.complete, checklists.submit |
| Media | media.read, media.upload, media.quality\_review, media.delete |
| AI | ai\_services.manage, ai\_models.read, ai\_models.manage, processing\_jobs.create, processing\_jobs.manage |
| Results | results.read, results.export, maps.read |
| Validation | validation.read, validation.create, validation.record\_ground\_truth, validation.decide, validation.complete, accuracy.recompute |
| Reporting | reports.read, reports.create, reports.generate, reports.approve, exports.download |
| Administration | settings.manage, audit.read, notifications.read |

# **Appendix C. Naming and implementation guidance**

* Use controllers only for HTTP concerns; put workflows in service/application classes and DB access in repositories/query objects or disciplined ORM models.  
* Use Form Request / validation classes (Laravel) or dedicated validation DTO/rules (CodeIgniter) instead of inline controller validation for large resources.  
* Use policy/authorization classes per resource; do not scatter role-name string checks across controllers.  
* Use API Resources/transformers so database column names and internal storage paths are not unintentionally exposed.  
* Keep FastAPI integration behind an AiInferenceClient interface so service health, retries and endpoint changes do not leak into controllers.  
* Generate OpenAPI from the implemented contract and make contract tests part of CI.  
* Prefer one migration per coherent object/change; every production migration needs a tested down/rollback or explicit irreversible note.

# **Appendix D. Planning assumptions**

This blueprint uses the supplied PostgreSQL/PostGIS-oriented information schema and the seven-segment MangroScan monitoring flow as the authoritative baseline. Items marked as schema extensions are proposed specifically to preserve current application behaviors (offline mobile sync, AI service management, confidence review, richer report form data and annotation workflows) that are not completely represented in the supplied base schema.

The document is intentionally implementation-oriented: it is a project backlog and contract blueprint, not a final OpenAPI file or executable SQL migration set. The next engineering artifact should be (1) a frozen target ERD/migration set and (2) an OpenAPI 3.1 specification generated from these endpoint cards.
