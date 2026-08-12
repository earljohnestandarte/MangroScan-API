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

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| SYS-01 | GET /healthLiveness/readiness for API, DB, storage and queue. | No body | 200 {status,db,storage,queue,time} | DB config | **P0** | TBD \- DevOps/API | **Ready** |
| SYS-02 | GET /meta/capabilitiesClient feature flags and API capability discovery. | No body | 200 {api\_version,features,limits} | SYS-01 | **P1** | TBD \- Backend/API | **Blocked** |
| AUTH-01 | POST /auth/loginAuthenticate web/mobile user. | {email,password,device\_name?} | 200 {user,access\_token,expires\_at,roles,permissions} | users \+ RBAC | **P0** | TBD \- Backend/Security | **Ready** |
| AUTH-02 | GET /auth/meReturn authenticated profile and effective access. | Bearer token | 200 {user,organization,roles,permissions} | AUTH-01 | **P0** | TBD \- Backend/Security | **Blocked** |
| AUTH-03 | POST /auth/logoutRevoke current token/session. | Bearer token | 204 | AUTH-01 | **P0** | TBD \- Backend/Security | **Blocked** |
| AUTH-04 | POST /auth/refreshRotate expiring mobile access credential when refresh-token design is used. | {refresh\_token} | 200 {access\_token,expires\_at,refresh\_token?} | AUTH-01 | **P1** | TBD \- Backend/Security | **Blocked** |
| AUTH-05 | PUT /auth/passwordAuthenticated password change. | {current\_password,new\_password,new\_password\_confirmation} | 204 | AUTH-01 | **P1** | TBD \- Backend/Security | **Blocked** |
| AUTH-06 | POST /auth/password/forgotIssue password-reset workflow. | {email} | 202 {message} | users \+ mail config | **P1** | TBD \- Backend/Security | **Blocked** |
| AUTH-07 | POST /auth/password/resetComplete password reset. | {token,email,password,password\_confirmation} | 204 | AUTH-06 | **P1** | TBD \- Backend/Security | **Blocked** |
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
| Success | `200` standard envelope with `api_version: v1`, `features: {health_checks: true, request_ids: true}` and `limits: {pagination_per_page_max: 100}`. |
| Errors | `503 SERVICE_UNAVAILABLE` when a SYS-01 dependency is unavailable; unexpected framework/configuration errors remain `500`. |
| Workflow / tenant scope | No authentication or organization data is required or returned. |
| Side effects / audit / notifications | Read-only platform metadata; no audit event or notification is created. |
| Tests | `tests/Feature/Platform/MetaCapabilitiesTest.php` covers the exact success payload, request ID and dependency failure. |
| Implementation status | Done. |

## **Organizations, users and RBAC**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| ORG-01 | GET /organizationsList organizations for system admin. | Query: page,per\_page,search,status | 200 {data:\[Organization\],meta} | AUTH-08 | **P1** | TBD \- Backend/API | **Blocked** |
| ORG-02 | POST /organizationsCreate tenant/owner organization. | {organization\_name,organization\_type,contact\_email?,contact\_number?,address?} | 201 {data:Organization} | ORG-01 | **P1** | TBD \- Backend/API | **Blocked** |
| ORG-03 | GET /organizations/{id}Organization detail. | Path: id | 200 {data:Organization} | ORG-01 | **P1** | TBD \- Backend/API | **Blocked** |
| ORG-04 | PATCH /organizations/{id}Update/archive organization metadata. | Partial Organization fields | 200 {data:Organization} | ORG-03 | **P1** | TBD \- Backend/API | **Blocked** |
| USR-01 | GET /usersList users inside authorized org scope. | Query: org\_id?,role?,active?,search,page | 200 {data:\[User\],meta} | AUTH-08 | **P0** | TBD \- Backend/API | **Blocked** |
| USR-02 | POST /usersCreate managed user account. | {organization\_id,first\_name,last\_name,email,position\_title?,roles:\[role\_id\]} | 201 {data:User} | USR-01 \+ RBAC-01 | **P0** | TBD \- Backend/API | **Blocked** |
| USR-03 | GET /users/{id}User detail \+ roles. | Path: id | 200 {data:{user,roles}} | USR-01 | **P1** | TBD \- Backend/API | **Blocked** |
| USR-04 | PATCH /users/{id}Update profile/role-relevant account fields. | Partial user fields | 200 {data:User} | USR-03 | **P1** | TBD \- Backend/API | **Blocked** |
| USR-05 | POST /users/{id}/activationActivate/deactivate account without hard delete. | {is\_active:boolean,reason?} | 200 {data:User} | USR-03 | **P1** | TBD \- Backend/API | **Blocked** |
| RBAC-01 | GET /rolesList roles. | No body | 200 {data:\[Role\]} | AUTH-08 | **P0** | TBD \- Backend/Security | **Blocked** |
| RBAC-02 | GET /permissionsList permission catalog. | No body | 200 {data:\[Permission\]} | AUTH-08 | **P0** | TBD \- Backend/Security | **Blocked** |
| RBAC-03 | PUT /users/{id}/rolesReplace a user role assignment set. | {role\_ids:\[uuid\]} | 200 {data:{user\_id,roles}} | USR-03 \+ RBAC-01 | **P0** | TBD \- Backend/Security | **Blocked** |
| RBAC-04 | PUT /roles/{id}/permissionsReplace role permission set. | {permission\_ids:\[uuid\]} | 200 {data:{role\_id,permissions}} | RBAC-01 \+ RBAC-02 | **P1** | TBD \- Backend/Security | **Blocked** |

## **Survey sites, boundaries, plots and permits**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| SITE-01 | GET /sitesList sites visible to user. | Query: search,status,province,page | 200 {data:\[Site\],meta} | AUTH-08 | **P0** | TBD \- GIS/API | **Blocked** |
| SITE-02 | POST /sitesRegister monitoring site. | {site\_name,site\_code,description?,province,city\_municipality,barangay?,center\_point:GeoJSON?,area\_hectares?,environment\_type,access\_notes?} | 201 {data:Site} | SITE-01 | **P0** | TBD \- GIS/API | **Blocked** |
| SITE-03 | GET /sites/{id}Site detail with summary counts. | Path: id | 200 {data:{site,counts}} | SITE-01 | **P0** | TBD \- GIS/API | **Blocked** |
| SITE-04 | PATCH /sites/{id}Update site metadata. | Partial Site fields | 200 {data:Site} | SITE-03 | **P1** | TBD \- GIS/API | **Blocked** |
| SITE-05 | DELETE /sites/{id}Soft archive site after dependency checks. | Path: id | 204 | SITE-03 | **P2** | TBD \- GIS/API | Backlog |
| BOUND-01 | GET /sites/{id}/boundariesList site polygons. | Path: site id | 200 {data:\[Boundary\]} | SITE-03 | **P0** | TBD \- GIS/API | **Blocked** |
| BOUND-02 | POST /sites/{id}/boundariesCreate survey/no-fly/restoration polygon. | {boundary\_name,boundary\_type,boundary\_geom:GeoJSON,source?} | 201 {data:Boundary} | BOUND-01 | **P0** | TBD \- GIS/API | **Blocked** |
| BOUND-03 | PATCH /boundaries/{id}Update boundary metadata/geometry. | Partial boundary fields | 200 {data:Boundary} | BOUND-02 | **P1** | TBD \- GIS/API | **Blocked** |
| PLOT-01 | GET /sites/{id}/plotsList monitoring plots. | Path: site id | 200 {data:\[Plot\]} | SITE-03 | **P1** | TBD \- GIS/API | **Blocked** |
| PLOT-02 | POST /sites/{id}/plotsCreate validation plot. | {plot\_code,plot\_name?,plot\_geom:GeoJSON,area\_square\_meters?,description?} | 201 {data:Plot} | PLOT-01 | **P1** | TBD \- GIS/API | **Blocked** |
| PLOT-03 | PATCH /plots/{id}Update/soft archive plot. | Partial Plot fields | 200 {data:Plot} | PLOT-02 | **P2** | TBD \- GIS/API | Backlog |
| PERMIT-01 | GET /sites/{id}/access-permissionsList permit/access records. | Path: site id | 200 {data:\[AccessPermission\]} | SITE-03 | **P2** | TBD \- Backend/API | Backlog |
| PERMIT-02 | POST /sites/{id}/access-permissionsRecord field-access permit. | {permit\_title,issuing\_agency,permit\_number?,valid\_from?,valid\_until?,document\_path?,status} | 201 {data:AccessPermission} | PERMIT-01 | **P2** | TBD \- Backend/API | Backlog |

## **Drone, sensor and hardware registry**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| DRONE-01 | GET /dronesList drone units. | Query: status,search,page | 200 {data:\[Drone\],meta} | AUTH-08 | **P1** | TBD \- Backend/API | **Blocked** |
| DRONE-02 | POST /dronesRegister drone. | {drone\_name,model?,serial\_number?,firmware\_version?,max\_flight\_minutes?,payload\_capacity\_grams?,status} | 201 {data:Drone} | DRONE-01 | **P1** | TBD \- Backend/API | **Blocked** |
| DRONE-03 | GET /drones/{id}Drone detail \+ attached sensors. | Path: id | 200 {data:{drone,sensors}} | DRONE-01 | **P1** | TBD \- Backend/API | **Blocked** |
| DRONE-04 | PATCH /drones/{id}Update drone status/metadata. | Partial Drone fields | 200 {data:Drone} | DRONE-03 | **P2** | TBD \- Backend/API | Backlog |
| SENSOR-01 | POST /drones/{id}/sensorsAttach/register sensor. | {sensor\_name,sensor\_type,manufacturer?,model?,serial\_number?,resolution?,range\_meters?,calibration\_required,status} | 201 {data:Sensor} | DRONE-03 | **P1** | TBD \- Backend/API | **Blocked** |
| SENSOR-02 | PATCH /sensors/{id}Update sensor. | Partial Sensor fields | 200 {data:Sensor} | SENSOR-01 | **P2** | TBD \- Backend/API | Backlog |
| CAL-01 | POST /sensors/{id}/calibrationsRecord sensor calibration. | {calibration\_date,calibration\_method,calibration\_file\_path?,calibration\_notes?,is\_valid} | 201 {data:Calibration} | SENSOR-01 | **P2** | TBD \- Backend/API | Backlog |
| BAT-01 | GET /batteriesList battery packs. | Query: status,type,page | 200 {data:\[Battery\],meta} | AUTH-08 | **P2** | TBD \- Backend/API | Backlog |
| BAT-02 | POST /batteriesRegister battery. | {battery\_code,battery\_type,capacity\_mah?,voltage?,status} | 201 {data:Battery} | BAT-01 | **P2** | TBD \- Backend/API | Backlog |

## **Mission planning and lifecycle**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| MSN-01 | GET /missionsList missions visible to caller. | Query: site\_id,status,from,to,search,page | 200 {data:\[Mission\],meta} | SITE-01 | **P0** | TBD \- Backend/API | **Blocked** |
| MSN-02 | POST /missionsCreate survey mission. | {site\_id,mission\_code,mission\_title,mission\_objective,planned\_start\_at?,planned\_end\_at?,coverage\_target\_hectares?} | 201 {data:Mission} | MSN-01 | **P0** | TBD \- Backend/API | **Blocked** |
| MSN-03 | GET /missions/{id}Mission detail with team/flights/summary. | Path: id | 200 {data:{mission,team,flight\_summary}} | MSN-01 | **P0** | TBD \- Backend/API | **Blocked** |
| MSN-04 | PATCH /missions/{id}Update planning fields before finalization. | Partial Mission fields | 200 {data:Mission} | MSN-03 | **P0** | TBD \- Backend/API | **Blocked** |
| MSN-05 | DELETE /missions/{id}Soft archive allowed mission. | Path: id | 204 | MSN-03 | **P2** | TBD \- Backend/API | Backlog |
| TEAM-01 | PUT /missions/{id}/teamReplace mission team assignments atomically. | {members:\[{user\_id,team\_role}\]} | 200 {data:\[MissionTeamMember\]} | MSN-03 \+ USR-01 | **P0** | TBD \- Backend/API | **Blocked** |
| MSN-06 | POST /missions/{id}/approveApprove mission and record approver. | {decision:"approved"|"rejected",notes?} | 200 {data:Mission} | MSN-03 \+ AUTH-08 | **P0** | TBD \- Backend/API | **Blocked** |
| MSN-07 | POST /missions/{id}/startTransition mission to in\_progress. | {started\_at?} | 200 {data:Mission} | MSN-06 | **P1** | TBD \- Backend/API | **Blocked** |
| MSN-08 | POST /missions/{id}/completeFinalize mission operations. | {ended\_at?,completion\_notes?} | 200 {data:Mission} | Flights completed | **P1** | TBD \- Backend/API | **Blocked** |

## **Flight operations and field readiness**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| FLT-01 | GET /missions/{id}/flightsList mission sorties. | Query: status,quality\_status,page | 200 {data:\[Flight\],meta} | MSN-03 | **P0** | TBD \- Backend/API | **Blocked** |
| FLT-02 | POST /missions/{id}/flightsCreate flight sortie. | {drone\_id,pilot\_user\_id,flight\_code,planned\_altitude\_meters?,notes?} | 201 {data:Flight} | MSN-06 \+ DRONE-01 | **P0** | TBD \- Backend/API | **Blocked** |
| FLT-03 | GET /flights/{id}Flight detail/readiness summary. | Path: id | 200 {data:{flight,checklists,waypoint\_count,media\_count}} | FLT-01 | **P0** | TBD \- Backend/API | **Blocked** |
| FLT-04 | PATCH /flights/{id}Update planned flight metadata. | Partial Flight fields | 200 {data:Flight} | FLT-03 | **P1** | TBD \- Backend/API | **Blocked** |
| CHK-01 | POST /flights/{id}/checklistsSubmit pre/post-flight checklist. | {checklist\_type,battery\_ok,weather\_ok,gps\_ok,camera\_ok,lidar\_depth\_ok,storage\_ok,overall\_status,remarks?} | 201 {data:Checklist} | FLT-03 | **P0** | TBD \- Mobile/API | **Blocked** |
| FLT-05 | POST /flights/{id}/startStart flight only after required preflight gate. | {started\_at,takeoff\_location?:GeoJSON} | 200 {data:Flight} | CHK-01 passed | **P0** | TBD \- Mobile/API | **Blocked** |
| FLT-06 | POST /flights/{id}/completeComplete flight and capture landing summary. | {ended\_at,landing\_location?:GeoJSON,actual\_avg\_altitude\_meters?,notes?} | 200 {data:Flight} | FLT-05 | **P0** | TBD \- Mobile/API | **Blocked** |
| FLT-07 | POST /flights/{id}/failAbort/fail flight with reason. | {status:"aborted"|"failed",reason,ended\_at?} | 200 {data:Flight} | FLT-05 | **P1** | TBD \- Mobile/API | **Blocked** |
| WPT-01 | PUT /flights/{id}/waypointsBatch replace ordered route waypoints. | {waypoints:\[{sequence\_no,location:GeoJSON,altitude\_meters?,speed\_mps?,action?}\]} | 200 {data:{count}} | FLT-03 | **P1** | TBD \- GIS/API | **Blocked** |
| ENV-01 | POST /flights/{id}/environment-logsAppend environment observation. | {recorded\_at,weather\_condition,wind\_speed\_mps?,temperature\_celsius?,humidity\_percent?,visibility\_status?,notes?} | 201 {data:EnvironmentLog} | FLT-03 | **P2** | TBD \- Mobile/API | Backlog |
| BAT-03 | POST /flights/{id}/battery-usageRecord battery use for sortie. | {battery\_id,start\_percentage,end\_percentage,usage\_minutes?,notes?} | 201 {data:BatteryUsage} | FLT-03 \+ BAT-01 | **P2** | TBD \- Mobile/API | Backlog |

## **Mobile offline synchronization**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| SYNC-01 | POST /mobile/devices/registerRegister app installation for sync/audit. | {device\_id,platform,app\_version,device\_name?} | 201 {data:{device\_id,server\_time}} | AUTH-02 \+ schema extension | **P0** | TBD \- Mobile/API | **Blocked** |
| SYNC-02 | GET /mobile/bootstrapDownload authorized mission/flight reference bundle. | Query: cursor? | 200 {data:{missions,flights,checklist\_templates,settings,tombstones},meta:{cursor,server\_time}} | MSN/FLT \+ AUTH | **P0** | TBD \- Mobile/API | **Blocked** |
| SYNC-03 | GET /mobile/missions/{id}/bundleDownload one mission for offline use. | Path: mission id | 200 {data:{mission,site,flights,team,boundaries,plots}} | MSN-06 | **P0** | TBD \- Mobile/API | **Blocked** |
| SYNC-04 | POST /mobile/syncPush offline changes and receive server changes/conflicts. | {device\_id,base\_cursor,changes:\[{client\_id,entity,operation,version,payload}\]} | 200 {data:{applied,conflicts,server\_changes},meta:{cursor}} | SYNC-01 \+ all mutable mobile resources | **P0** | TBD \- Mobile/API | **Blocked** |
| SYNC-05 | GET /mobile/sync/statusShow pending server work relevant to device. | Query: device\_id | 200 {data:{last\_cursor,last\_sync\_at,pending\_notifications}} | SYNC-04 | **P1** | TBD \- Mobile/API | **Blocked** |

## **Media, sensor uploads and quality control**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| MEDIA-01 | GET /flights/{id}/mediaList captured image/video metadata. | Query: type,quality\_status,processing\_status,page | 200 {data:\[MediaAsset\],meta} | FLT-03 | **P0** | TBD \- Backend/API | **Blocked** |
| MEDIA-02 | POST /flights/{id}/media/uploadsInitiate resumable/private upload. | {file\_name,file\_type,mime\_type,file\_size\_bytes,checksum\_sha256?,capture\_location?:GeoJSON,captured\_at?,metadata?} | 201 {data:{upload\_id,storage\_key,upload\_url?|parts?}} | FLT-05/06 \+ storage | **P0** | TBD \- Storage/API | **Blocked** |
| MEDIA-03 | POST /media/uploads/{uploadId}/completeFinalize upload after checksum/size validation. | {parts? ,checksum\_sha256?} | 201 {data:MediaAsset} | MEDIA-02 | **P0** | TBD \- Storage/API | **Blocked** |
| MEDIA-04 | GET /media/{id}Media metadata \+ authorized preview/download pointer. | Path: id | 200 {data:MediaAsset} | MEDIA-03 | **P0** | TBD \- Storage/API | **Blocked** |
| MEDIA-05 | POST /media/{id}/downloadIssue temporary private download URL or stream token. | No body | 200 {data:{url,expires\_at}} or streamed file | MEDIA-04 | **P1** | TBD \- Storage/API | **Blocked** |
| MEDIA-06 | PATCH /media/{id}/qualitySet QC result. | {quality\_score?,quality\_status,notes?} | 200 {data:MediaAsset} | MEDIA-04 | **P0** | TBD \- Backend/API | **Blocked** |
| MEDIA-07 | DELETE /media/{id}Soft-delete unneeded media after dependency check. | Path: id | 204 | MEDIA-04 | **P2** | TBD \- Storage/API | Backlog |
| SDS-01 | POST /flights/{id}/sensor-datasets/uploadsUpload LiDAR/depth/GPS/IMU dataset. | {file\_name,dataset\_type,file\_format,sensor\_id,file\_size\_bytes,spatial\_reference?,metadata?} | 201 {data:{upload\_id,...}} | FLT-03 \+ storage | **P1** | TBD \- Storage/API | **Blocked** |
| SDS-02 | POST /sensor-datasets/uploads/{uploadId}/completeFinalize sensor dataset. | {checksum\_sha256?} | 201 {data:SensorDataset} | SDS-01 | **P1** | TBD \- Storage/API | **Blocked** |

## **AI service, model registry and processing jobs**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| AISVC-01 | GET /admin/ai-servicesAI backend overview for administrator. | No body | 200 {data:{services,models,jobs}} | schema extension \+ AUTH | **P1** | TBD \- AI/API | **Blocked** |
| AISVC-02 | POST /admin/ai-servicesRegister trusted FastAPI backend. | {service\_name,base\_url,api\_key,environment,enabled} | 201 {data:AiService}; key never returned | AISVC schema \+ secret encryption | **P1** | TBD \- AI/API | **Blocked** |
| AISVC-03 | POST /admin/ai-services/{id}/testHealth-test FastAPI service. | No body | 200 {data:{status,version,latency\_ms}} | AISVC-02 | **P1** | TBD \- AI/API | **Blocked** |
| AISVC-04 | POST /admin/ai-services/{id}/synchronizePull authoritative /models metadata. | No body | 200 {data:{models\_synced,capabilities}} | AISVC-03 | **P1** | TBD \- AI/API | **Blocked** |
| AISVC-05 | POST /admin/ai-services/{id}/credentialsRotate encrypted FastAPI key. | {api\_key} | 204 | AISVC-02 | **P2** | TBD \- AI/API | Backlog |
| MODEL-01 | GET /ai-modelsList model registry and deployment versions. | Query: type,deployed | 200 {data:\[AiModel\]} | AUTH \+ ai\_models | **P1** | TBD \- AI/API | **Blocked** |
| MODEL-02 | GET /ai-models/{id}Model detail and versions. | Path: id | 200 {data:{model,versions}} | MODEL-01 | **P1** | TBD \- AI/API | **Blocked** |
| MODEL-03 | POST /ai-models/{id}/versions/{versionId}/deployMark model version deployed after validation. | {release\_notes?} | 200 {data:AiModelVersion} | MODEL-02 | **P2** | TBD \- AI/API | Backlog |
| JOB-01 | GET /processing-jobsList processing jobs. | Query: mission\_id,flight\_id,status,type,page | 200 {data:\[ProcessingJob\],meta} | AUTH \+ processing\_jobs | **P0** | TBD \- AI/API | **Blocked** |
| JOB-02 | POST /processing-jobsQueue detector/classifier/combined processing. | {mission\_id,flight\_session\_id?,job\_type,media\_ids:\[uuid\],parameters?} | 202 {data:{processing\_job\_id,job\_status:"queued"}} | MEDIA-03 \+ AISVC-04 \+ MODEL-01 | **P0** | TBD \- AI/API | **Blocked** |
| JOB-03 | GET /processing-jobs/{id}Job status, runs, outputs and errors. | Path: id | 200 {data:{job,model\_runs,output\_summary}} | JOB-02 | **P0** | TBD \- AI/API | **Blocked** |
| JOB-04 | POST /processing-jobs/{id}/retryRetry failed job idempotently. | {reason?} | 202 {data:ProcessingJob} | JOB-03 failed | **P1** | TBD \- AI/API | **Blocked** |
| JOB-05 | POST /processing-jobs/{id}/cancelCancel queued/running job when supported. | {reason?} | 200 {data:ProcessingJob} | JOB-03 | **P2** | TBD \- AI/API | Backlog |

## **Tree results, summaries and geospatial layers**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| TREE-01 | GET /tree-observationsFilter canonical tree observations. | Query: mission\_id,flight\_id,species\_id,validation\_status,min\_confidence,page | 200 {data:\[TreeObservation\],meta} | JOB-03 completed | **P0** | TBD \- Results/API | **Blocked** |
| TREE-02 | GET /tree-observations/{id}Tree detail with model provenance/results. | Path: id | 200 {data:{tree,species\_predictions,height\_estimations,age\_estimations,source\_media,model\_run}} | TREE-01 | **P0** | TBD \- Results/API | **Blocked** |
| TREE-03 | GET /missions/{id}/trees.geojsonMap-ready tree features. | Query: species\_id?,validated\_only? | 200 GeoJSON FeatureCollection | TREE-01 \+ PostGIS | **P0** | TBD \- GIS/API | **Blocked** |
| COUNT-01 | GET /missions/{id}/tree-countsMission/species count summary. | Query: species\_id? | 200 {data:\[TreeCountSummary\]} | TREE-01 \+ count routine | **P0** | TBD \- Results/API | **Blocked** |
| RESULT-01 | GET /tree-observations/{id}/speciesSpecies prediction history. | Path: id | 200 {data:\[ClassificationResult\]} | TREE-02 | **P1** | TBD \- Results/API | **Blocked** |
| RESULT-02 | GET /tree-observations/{id}/heightsHeight estimates. | Path: id | 200 {data:\[HeightEstimation\]} | TREE-02 | **P1** | TBD \- Results/API | **Blocked** |
| RESULT-03 | GET /tree-observations/{id}/agesAge estimates \+ assumptions. | Path: id | 200 {data:\[AgeEstimation\]} | TREE-02 | **P1** | TBD \- Results/API | **Blocked** |
| LAYER-01 | GET /missions/{id}/layersList geospatial/photogrammetry outputs. | Query: type? | 200 {data:\[Layer\]} | JOB-03 | **P1** | TBD \- GIS/API | **Blocked** |
| LAYER-02 | POST /missions/{id}/layers/buildQueue map layer build/refresh. | {layer\_types:\[...\],parameters?} | 202 {data:{job\_id}} | TREE-01 \+ photogrammetry inputs | **P1** | TBD \- GIS/API | **Blocked** |

## **Confidence review and field validation**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| CONF-01 | GET /confidence-reviewMission-scoped low-confidence queue. | Query: mission\_id\*,flight\_id?,result\_type?,status?,severity?,page | 200 {data:\[ReviewRecord\],summary,groups,map,meta} | TREE/RESULT \+ confidence flag extension | **P1** | TBD \- Validation/API | **Blocked** |
| CONF-02 | PUT /confidence-review/{resultId}Create/update review flag/status/assignment. | {status,review\_note?,assigned\_to?,reason?,resolution\_notes?} | 200 {data:ConfidenceFlag} | CONF-01 | **P1** | TBD \- Validation/API | **Blocked** |
| VAL-01 | GET /validation/scopesMission/site/plot/species/assignee options. | No body | 200 {data:{missions,species,assignees,sessions}} | MSN/SITE/USR | **P0** | TBD \- Validation/API | **Blocked** |
| VAL-02 | GET /validation-sessionsList field validation sessions. | Query: mission\_id?,site\_id?,status?,page | 200 {data:\[ValidationSession\],meta} | VAL-01 | **P0** | TBD \- Validation/API | **Blocked** |
| VAL-03 | POST /validation-sessionsCreate mission-scoped validation activity. | {mission\_id,site\_id,plot\_id?,validated\_by,validation\_date,method,notes?} | 201 {data:ValidationSession} | VAL-01 \+ TREE-01 | **P0** | TBD \- Validation/API | **Blocked** |
| VAL-04 | GET /validation-sessions/{id}Validation workspace data and map layers. | Path: id | 200 {data:{context,observations,ground\_truth\_records,matches,metrics,layers}} | VAL-03 | **P0** | TBD \- Validation/API | **Blocked** |
| GT-01 | POST /validation-sessions/{id}/ground-truthCreate manual field tree record. | {field\_code?,species\_id?,location:GeoJSON,height\_m?,age\_years?,diameter\_cm?,crown\_diameter\_m?,health\_status,is\_tree,photo\_path?,notes?} | 201 {data:GroundTruthRecord} | VAL-04 | **P0** | TBD \- Validation/API | **Blocked** |
| MATCH-01 | POST /validation-sessions/{id}/decisionsStore matched/corrected/false-positive/false-negative decision. | {tree\_observation\_id?,ground\_truth\_id?,decision,accepted\_species\_id?,accepted\_height\_m?,accepted\_age\_years?,corrected\_geometry?,notes?,validation\_evidence?} | 201 {data:ValidationMatch} | VAL-04 \+ GT-01 | **P0** | TBD \- Validation/API | **Blocked** |
| ACC-01 | POST /validation-sessions/{id}/accuracy/recomputeRecompute precision/recall/F1/RMSE/MAE evidence. | No body | 200 {data:\[AccuracyMetric\]} | MATCH-01 | **P0** | TBD \- Validation/DB | **Blocked** |
| VAL-05 | POST /validation-sessions/{id}/completeComplete validation task. | {notes} | 200 {data:ValidationSession} | MATCH-01; protocol gate | **P1** | TBD \- Validation/API | **Blocked** |

## **Reports, exports, dashboard and saved views**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| RPT-01 | GET /reportsList report records. | Query: mission\_id,site\_id,status,type,page | 200 {data:\[Report\],meta} | AUTH | **P1** | TBD \- Reporting/API | **Blocked** |
| RPT-02 | POST /reportsPrepare report definition/draft. | {mission\_id,site\_id,report\_title,report\_type,audience?,summary?,interpretation?,limitations?,recommendations?,formats?} | 201 {data:Report} | TREE/ACC finalized | **P1** | TBD \- Reporting/API | **Blocked** |
| RPT-03 | GET /reports/{id}Report draft/source metadata. | Path: id | 200 {data:{report,source\_summary}} | RPT-02 | **P1** | TBD \- Reporting/API | **Blocked** |
| RPT-04 | PATCH /reports/{id}Update report content/status while editable. | Partial report fields | 200 {data:Report} | RPT-03 | **P1** | TBD \- Reporting/API | **Blocked** |
| RPT-05 | POST /reports/{id}/generateGenerate professional PDF/report artifact asynchronously. | {format:"PDF",options?} | 202 {data:{job\_id,report\_id,status}} | RPT-03 \+ report routine/storage | **P0** | TBD \- Reporting/API | **Blocked** |
| RPT-06 | POST /reports/{id}/approveApprove generated report. | {decision:"approved"|"rejected",notes?} | 200 {data:Report} | RPT-05 complete | **P1** | TBD \- Reporting/API | **Blocked** |
| EXP-01 | POST /reports/{id}/exportsGenerate CSV/XLSX/GeoJSON/KML/etc. | {format,filters?,options?} | 202 {data:{job\_id,export\_type}} | RPT-03 \+ canonical results | **P0** | TBD \- Reporting/API | **Blocked** |
| EXP-02 | GET /exported-filesExport audit registry. | Query: report\_id?,mission\_id?,type?,page | 200 {data:\[ExportedFile\],meta} | EXP-01 | **P1** | TBD \- Reporting/API | **Blocked** |
| EXP-03 | POST /exported-files/{id}/downloadAuthorized temporary download. | No body | 200 {data:{url,expires\_at}} or stream | EXP-02 \+ storage | **P0** | TBD \- Storage/API | **Blocked** |
| DASH-01 | GET /dashboard/overviewRole-scoped KPI overview. | Query: site\_id?,mission\_id?,date range? | 200 {data:{missions,trees,species,validation,processing}} | TREE \+ ACC \+ materialized views | **P1** | TBD \- Dashboard/API | **Blocked** |
| DASH-02 | GET /dashboard/missions/{id}Mission analytics/detail dashboard. | Path: id | 200 {data:{counts,species,height,age,accuracy,layers}} | DASH-01 | **P1** | TBD \- Dashboard/API | **Blocked** |
| VIEW-01 | GET /dashboard/saved-viewsList caller saved filters/map configs. | No body | 200 {data:\[SavedView\]} | AUTH | **P2** | TBD \- Dashboard/API | Backlog |
| VIEW-02 | POST /dashboard/saved-viewsSave filter/map state. | {view\_name,site\_id?,mission\_id?,filter\_config,map\_config} | 201 {data:SavedView} | VIEW-01 | **P2** | TBD \- Dashboard/API | Backlog |
| VIEW-03 | PATCH /dashboard/saved-views/{id}Update saved view. | Partial saved-view fields | 200 {data:SavedView} | VIEW-02 | **P2** | TBD \- Dashboard/API | Backlog |
| VIEW-04 | DELETE /dashboard/saved-views/{id}Delete own saved view. | Path: id | 204 | VIEW-02 | **P2** | TBD \- Dashboard/API | Backlog |

## **Notifications, settings and audit**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| NOTIF-01 | GET /notificationsList durable notifications for current user. | Query: unread\_only?,type?,page | 200 {data:\[Notification\],meta} | AUTH \+ notification\_logs | **P1** | TBD \- Backend/API | **Blocked** |
| NOTIF-02 | GET /notifications/unread-countLightweight badge count. | No body | 200 {data:{unread\_count}} | NOTIF-01 | **P1** | TBD \- Backend/API | **Blocked** |
| NOTIF-03 | POST /notifications/{id}/readMark one notification read. | No body | 200 {data:Notification} | NOTIF-01 | **P1** | TBD \- Backend/API | **Blocked** |
| NOTIF-04 | POST /notifications/read-allMark caller notifications read. | No body | 204 | NOTIF-01 | **P2** | TBD \- Backend/API | Backlog |
| SET-01 | GET /settingsRead permitted settings by group. | Query: group? | 200 {data:\[Setting\]} | AUTH | **P2** | TBD \- Backend/API | Backlog |
| SET-02 | PUT /settings/{key}Update managed setting. | {setting\_value,description?} | 200 {data:Setting} | SET-01 \+ admin permission | **P2** | TBD \- Backend/API | Backlog |
| AUD-01 | GET /audit-logsSearch immutable audit trail. | Query: user\_id?,action?,table\_name?,record\_id?,from?,to?,page | 200 {data:\[AuditLog\],meta} | AUTH \+ audit trigger | **P1** | TBD \- Security/API | **Blocked** |
| AUD-02 | GET /audit-logs/{id}Audit event detail. | Path: id | 200 {data:AuditLog} | AUD-01 | **P2** | TBD \- Security/API | Backlog |

## **Training datasets and annotation extension**

| ID | Endpoint / purpose | Request | Success response | Depends on | Pri | Assigned to | Status |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| DATASET-01 | GET /training-datasetsList training/validation datasets. | Query: type,source,page | 200 {data:\[TrainingDataset\],meta} | AUTH | **P2** | TBD \- AI/API | Backlog |
| DATASET-02 | POST /training-datasetsCreate dataset metadata. | {dataset\_name,dataset\_type,source,description?,version\_label?} | 201 {data:TrainingDataset} | DATASET-01 | **P2** | TBD \- AI/API | Backlog |
| DATASET-03 | POST /training-datasets/{id}/itemsAttach labeled media/sample. | {media\_id?,label\_file\_path,label\_format,species\_id?,annotation\_status} | 201 {data:DatasetItem} | DATASET-02 \+ MEDIA-03 | **P2** | TBD \- AI/API | Backlog |
| ANN-01 | GET /annotation/projectsExisting annotation-workspace project list; requires extension tables if retained. | Query: status?,page | 200 {data:\[AnnotationProject\],meta} | annotation extension | **P2** | TBD \- Annotation/API | Backlog |
| ANN-02 | POST /annotation/projectsCreate annotation project. | {name,dataset\_type,mission\_id?,status} | 201 {data:AnnotationProject} | ANN-01 | **P2** | TBD \- Annotation/API | Backlog |
| ANN-03 | PUT /annotation/items/{id}/objectsReplace item annotations transactionally. | {objects:\[{class\_id,bbox?,polygon?,attributes?}\]} | 200 {data:{count}} | ANN-02 | **P2** | TBD \- Annotation/API | Backlog |
| ANN-04 | POST /annotation/projects/{id}/exportsExport COCO/YOLO/CSV/GeoJSON labels. | {format} | 201 {data:{export\_id,file\_name,storage\_key}} | ANN-03 \+ storage | **P2** | TBD \- Annotation/API | Backlog |

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
| V-01 | v\_user\_effective\_permissions | users \+ organizations \+ user\_roles \+ roles \+ role\_permissions \+ permissions | One row per user/permission. Back AUTH-02/AUTH-08 and server-side permission checks. | **P0** | DB Engineer | **Ready** |
| V-02 | v\_mission\_overview | survey\_missions \+ sites \+ team \+ flight aggregates | Mission list/detail counts, approval/status, coverage, flight totals. | **P0** | DB Engineer | **Blocked** |
| V-03 | v\_flight\_readiness | flight\_sessions \+ latest preflight \+ drone/sensor/permit state | Compute whether flight may start and why blocked. | **P0** | DB Engineer | **Blocked** |
| V-04 | v\_media\_processing\_queue | media\_assets \+ flight \+ mission \+ processing jobs | Media uploaded/quality-ready/not-yet-processed queue for Researcher. | **P0** | DB Engineer | **Blocked** |
| V-05 | v\_processing\_job\_overview | processing\_jobs \+ model\_runs \+ model versions | Human-readable job state, model provenance and latest error. | **P0** | DB Engineer | **Blocked** |
| V-06 | v\_tree\_result\_detail | tree\_observations \+ final species \+ latest/final height \+ latest/final age \+ source media | Canonical tree detail read model. | **P0** | DB/GIS Engineer | **Blocked** |
| V-07 | v\_validation\_workspace | validation sessions \+ ground truth \+ matches \+ tree observations | Supports validation session summary/list; object-level data can still use focused queries. | **P1** | DB Engineer | **Blocked** |
| V-08 | v\_mission\_accuracy\_summary | accuracy\_metrics \+ mission \+ model version | Latest validation metrics per mission/model/metric type. | **P1** | DB Engineer | **Blocked** |
| V-09 | v\_report\_source\_summary | mission/site/count/species/validation aggregates | Stable source for report preview and generation. | **P1** | DB Engineer | **Blocked** |
| V-10 | v\_notification\_inbox | notification\_logs \+ users | Unread/read inbox ordered by created\_at. | **P1** | DB Engineer | **Blocked** |
| V-11 | v\_audit\_activity | audit\_logs \+ users | Admin audit browsing without repeating user joins. | **P1** | DB/Security | **Blocked** |
| MV-01 | mv\_dashboard\_mission\_metrics | missions \+ tree/result/validation aggregates | Fast dashboard KPI reads; refresh after result/validation completion. | **P1** | DB Engineer | **Blocked** |
| MV-02 | mv\_species\_distribution\_by\_mission | tree observations \+ species | Pre-aggregated species distribution for charts/reports. | **P2** | DB Engineer | Backlog |
| MV-03 | mv\_tree\_density\_by\_site | tree observations \+ site area/spatial aggregation | Trend/density reporting; refresh on finalized results. | **P2** | DB/GIS Engineer | Backlog |

## **10.1 Example PostgreSQL view**

| CREATE OR REPLACE VIEW app.v\_user\_effective\_permissions ASSELECT    u.user\_id,    u.organization\_id,    r.role\_id,    r.role\_name,    p.permission\_id,    p.permission\_codeFROM app.users uJOIN app.user\_roles ur ON ur.user\_id \= u.user\_idJOIN app.roles r ON r.role\_id \= ur.role\_idJOIN app.role\_permissions rp ON rp.role\_id \= r.role\_idJOIN app.permissions p ON p.permission\_id \= rp.permission\_idWHERE u.is\_active \= TRUE  AND u.deleted\_at IS NULL; |
| :---- |

## **10.2 Example map-ready view**

| CREATE OR REPLACE VIEW app.v\_tree\_map\_features ASSELECT    t.tree\_observation\_id,    t.mission\_id,    t.flight\_session\_id,    t.tree\_code,    t.detection\_confidence,    t.validation\_status,    s.scientific\_name AS final\_species,    ST\_AsGeoJSON(t.tree\_location)::jsonb AS geometryFROM app.tree\_observations tLEFT JOIN app.mangrove\_species s       ON s.species\_id \= t.final\_species\_idWHERE t.deleted\_at IS NULL; |
| :---- |

# **11\. Database routines, triggers and invariant enforcement**

| ID | Routine | Type | Purpose | Pri |
| :---- | :---- | :---- | :---- | :---- |
| R-01 | fn\_touch\_updated\_at() \+ triggers | BEFORE UPDATE | Set updated\_at consistently on mutable tables. | **P0** |
| R-02 | fn\_audit\_row\_change() \+ triggers | AFTER INSERT/UPDATE/DELETE | Append old/new JSONB to immutable audit\_logs; include app user/request context where available. | **P0** |
| R-03 | fn\_user\_has\_permission(user\_id, permission\_code) | SQL function | Optional DB-side helper for routines/RLS. API policies remain primary authorization surface. | **P1** |
| R-04 | fn\_flight\_readiness(flight\_id) | SQL function | Return passed:boolean and reasons\[\] from preflight/resource/approval state. | **P0** |
| R-05 | sp\_recompute\_tree\_count\_summary(mission\_id) | Procedure / transaction | Upsert total/species counts after completed processing/validation. | **P0** |
| R-06 | sp\_recompute\_accuracy\_metrics(validation\_session\_id) | Procedure / transaction | Compute sample size, precision/recall/F1/species accuracy and error metrics from validation matches. | **P0** |
| R-07 | sp\_finalize\_processing\_job(job\_id, output\_summary) | Procedure / transaction | Atomically mark job completed, persist summary, refresh count/materialized views, enqueue notifications via application outbox or insert notification rows. | **P1** |
| R-08 | sp\_finalize\_report(report\_id, exported\_file...) | Procedure / transaction | Atomically move report to generated state and write exported\_files record. | **P1** |
| R-09 | fn\_validate\_geometry\_srid() | Constraint/trigger helper | Reject or normalize geometry not in SRID 4326 for API-facing geographic fields. | **P1** |

## **11.1 Updated-at trigger**

| CREATE OR REPLACE FUNCTION app.fn\_touch\_updated\_at()RETURNS triggerLANGUAGE plpgsqlAS $$BEGIN  NEW.updated\_at := NOW();  RETURN NEW;END;$$;CREATE TRIGGER trg\_survey\_missions\_touchBEFORE UPDATE ON app.survey\_missionsFOR EACH ROW EXECUTE FUNCTION app.fn\_touch\_updated\_at(); |
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
