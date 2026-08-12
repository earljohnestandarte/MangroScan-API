# MangroScan API

> **Geospatial Mangrove Forest Monitoring & Analysis Platform**

A production-grade RESTful API for managing drone-based surveys, AI-powered tree analysis, and environmental monitoring of mangrove forests. Built with Laravel 12, PostgreSQL, PostGIS, and modern backend practices.

[![Build Status](https://img.shields.io/badge/build-passing-brightgreen)](https://github.com)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-12.0-FF2D20.svg?logo=laravel&logoColor=white)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [System Requirements](#system-requirements)
- [Quick Start](#quick-start)
- [Project Structure](#project-structure)
- [Database & Migrations](#database--migrations)
- [API Documentation](#api-documentation)
- [Configuration](#configuration)
- [Development](#development)
- [Testing](#testing)
- [Deployment](#deployment)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

---

## Overview

MangroScan API is a comprehensive backend solution for organizations conducting drone-based environmental surveys of mangrove forests. It provides:

- **Multi-tenant Architecture**: Support for multiple organizations with complete data isolation
- **Survey Management**: Track missions, drone flights, and field teams
- **Media Handling**: Upload, store, and organize aerial imagery and sensor data
- **AI Integration**: Connect to external ML services for tree detection, species classification, and biometric estimation
- **Result Tracking**: Store and query tree observations with estimated age, height, and species
- **Audit Compliance**: Immutable logs of all system actions for accountability
- **Mobile Support**: Offline-capable mobile app synchronization
- **Role-Based Security**: Fine-grained permission system for different user types

---

## Features

✅ **Complete Authentication & Authorization**
- Token-based authentication (Laravel Sanctum)
- Role-Based Access Control (RBAC) with dynamic permissions
- Multi-organization tenant isolation
- Session management and password reset workflow

✅ **Survey Operations**
- Create and manage survey sites with geospatial boundaries
- Plan missions with team member assignments
- Track individual drone flight sessions
- Record pre-flight and post-flight checklists

✅ **Hardware Management**
- Drone registry with specifications
- Sensor attachment and tracking
- Sensor calibration history

✅ **Media & Data Upload**
- Resumable media uploads with session tracking
- Sensor dataset management (LiDAR, depth maps, photogrammetry)
- Configurable storage backends (local, S3)
- File size and type validation

✅ **AI Service Integration**
- AI model registration and versioning
- Service health monitoring
- Model synchronization
- Processing job queue management

✅ **Tree Analysis & Results**
- Tree observation storage with GPS coordinates
- Species classification results
- Canopy height estimation
- Tree age estimation
- Results aggregation and reporting

✅ **Audit & Compliance**
- Immutable audit logs for all mutations
- User action tracking with before/after state
- Sensitive data protection

✅ **Performance & Scale**
- Pagination with configurable limits
- Database indexing optimizations
- Rate limiting and throttling
- Geospatial query support via PostGIS

---

## Tech Stack

### Backend
- **Framework**: [Laravel 12](https://laravel.com/docs) - Modern PHP web framework
- **Language**: PHP 8.2+ - Type-safe, feature-rich programming
- **Database**: PostgreSQL 18 - Powerful relational database
- **Geospatial**: PostGIS - Advanced spatial data support
- **Authentication**: Laravel Sanctum - API token management
- **ORM**: Eloquent - Elegant database abstraction

### Frontend Tooling
- **Build Tool**: Vite 6 - Next-generation frontend build tool
- **CSS Framework**: Tailwind CSS 4 - Utility-first styling
- **HTTP Client**: Axios - Promise-based API requests
- **Package Manager**: npm - Node.js dependency management

### Development & Testing
- **Testing**: PHPUnit 11 - Unit and feature test framework
- **Code Quality**: Laravel Pint - PHP code style fixer
- **Debugging**: Laravel Tinker - Interactive REPL
- **Containers**: Laravel Sail - Docker development environment

### Key Dependencies
```json
{
  "php": "^8.2",
  "laravel/framework": "^12.0",
  "laravel/sanctum": "^4.3",
  "laravel/tinker": "^2.10.1"
}
```

---

## System Requirements

### Minimum Requirements

| Component | Version | Notes |
|-----------|---------|-------|
| PHP | 8.2+ | With extensions: mbstring, PDO, bcmath, ctype, json, tokenizer |
| PostgreSQL | 14+ | PostGIS 3.2+ recommended for geospatial features |
| Node.js | 18+ | For frontend asset compilation |
| Composer | 2.2+ | PHP dependency manager |
| npm | 9+ | Node.js package manager |

### Recommended (Production)

| Component | Specification |
|-----------|---------------|
| CPU | 4+ cores |
| RAM | 8GB+ |
| Storage | 100GB+ (depends on media volume) |
| PostgreSQL | Dedicated instance with replication |
| Redis | For caching and job queue |
| Object Storage | S3 or compatible for media files |

### Local Development (Quick Setup)

- **OS**: Windows 10+, macOS 10.15+, Ubuntu 20.04+ (WSL2 supported)
- **Docker**: Desktop Edition 20.10+ (optional, for Sail)
- **Disk Space**: 2GB+ free
- **RAM**: 4GB minimum (8GB recommended)

---

## Quick Start

### 1. Clone & Setup

```bash
# Clone repository
git clone https://github.com/earljohnestandarte/MangroScan-API.git
cd mangroscan-api

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install
```

### 3. Configure Database

Edit `.env` file:

```env
# Using PostgreSQL (recommended)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=mangroscan
DB_USERNAME=postgres
DB_PASSWORD=your_password

# Or using SQLite (local development)
DB_CONNECTION=sqlite
DB_DATABASE=database.sqlite
```

For PostgreSQL on Windows with WSL2:

```bash
# Check if PostgreSQL is installed
wsl -d Ubuntu psql --version

# If not installed, run in Ubuntu terminal:
sudo apt update
sudo apt install postgresql postgresql-contrib postgis
sudo service postgresql start
```

### 4. Run Migrations

```bash
# Create database
php artisan db:create

# Run all migrations
php artisan migrate

# Seed with sample data (optional)
php artisan db:seed
```

### 5. Start Development Server

```bash
# Option 1: Run all services concurrently
composer run dev

# Option 2: Run individual services separately
php artisan serve                    # API server on localhost:8000
php artisan queue:listen             # Queue worker
php artisan pail --timeout=0         # Log viewer
npm run dev                          # Vite dev server
```

### 6. Verify Installation

```bash
# Check API health
curl http://localhost:8000/api/v1/health

# Expected response
{
  "status": "ok",
  "db": "connected",
  "storage": "ok",
  "queue": "ready",
  "time": "2025-08-13T10:30:45Z"
}
```

---

## Project Structure

```
mangroscan-api/
├── app/
│   ├── Contracts/          # Interfaces for services
│   ├── Exceptions/         # Custom exception classes
│   ├── Http/
│   │   ├── Controllers/    # API endpoint handlers
│   │   │   └── Api/V1/    # V1 API controllers organized by domain
│   │   ├── Middleware/     # HTTP middleware (auth, CORS, etc.)
│   │   ├── Requests/       # Form request validation classes
│   │   └── Resources/      # API response transformers
│   ├── Models/             # 34 Eloquent models (User, Mission, etc.)
│   ├── Providers/          # Service providers (AppServiceProvider)
│   ├── Rules/              # Custom validation rules
│   └── Services/           # Business logic organized by domain
│       ├── Ai/             # AI model management
│       ├── Auth/           # Authentication
│       ├── Drone/          # Drone operations
│       ├── Flight/         # Flight management
│       ├── Media/          # Media uploads
│       ├── Mission/        # Mission orchestration
│       ├── Organization/   # Org administration
│       ├── Processing/     # Job processing
│       ├── Rbac/           # Role & permission logic
│       ├── Site/           # Site management
│       ├── Tenancy/        # Multi-tenant isolation
│       └── User/           # User management
├── bootstrap/
│   ├── app.php             # Application bootstrap
│   └── providers.php       # Service provider registration
├── config/
│   ├── app.php             # App configuration
│   ├── auth.php            # Authentication config
│   ├── database.php        # Database config
│   ├── mangroscan.php      # MangroScan-specific settings
│   ├── filesystems.php     # Storage config
│   ├── logging.php         # Logging configuration
│   └── queue.php           # Job queue config
├── database/
│   ├── migrations/         # Database schema migrations
│   ├── factories/          # Model factories for testing
│   ├── seeders/            # Database seeding classes
│   └── sql/                # Custom SQL files
├── docs/
│   ├── CODEBASE_ANALYSIS.md         # Detailed codebase analysis
│   ├── MangroScan_DB_Schema.md      # Database schema documentation
│   ├── MangroScan_API_Endpoint_Tracker.csv  # Endpoint tracking
│   └── PostgreSQL_Testing.md        # PostgreSQL setup guide
├── public/
│   ├── index.php           # Application entry point
│   └── build/              # Compiled frontend assets
├── resources/
│   ├── css/                # Tailwind CSS
│   ├── js/                 # Frontend JavaScript
│   └── views/              # Blade templates
├── routes/
│   ├── api.php             # API route definitions
│   ├── web.php             # Web route definitions
│   └── console.php         # Console/Artisan commands
├── storage/
│   ├── app/                # Application files
│   ├── framework/          # Framework cache
│   └── logs/               # Application logs
├── tests/
│   ├── TestCase.php        # Base test class
│   ├── Feature/            # HTTP integration tests
│   └── Unit/               # Unit tests
├── vendor/                 # Composer dependencies
├── .env.example            # Example environment file
├── artisan                 # Laravel command-line tool
├── composer.json           # PHP dependencies
├── package.json            # Node.js dependencies
├── phpunit.xml             # PHPUnit configuration (SQLite)
├── phpunit.pgsql.xml       # PHPUnit configuration (PostgreSQL)
├── vite.config.js          # Vite configuration
└── CODEBASE_ANALYSIS.md    # Comprehensive codebase analysis
```

---

## Database & Migrations

### Understanding Migrations

Migrations are version-controlled database schema changes. They allow you to:
- Track schema changes in Git
- Collaborate safely on database updates
- Rollback changes if needed
- Test with different database engines

### Migration Files

Located in `database/migrations/`, ordered chronologically:

```
database/migrations/
├── 2024_01_01_000001_create_organizations_table.php
├── 2024_01_01_000002_create_users_table.php
├── 2024_01_01_000003_create_roles_table.php
├── 2024_01_01_000004_create_permissions_table.php
├── 2024_01_01_000005_create_role_permission_table.php
├── 2024_01_01_000006_create_user_role_table.php
├── 2024_01_01_000007_create_audit_logs_table.php
├── 2024_01_01_000008_create_survey_sites_table.php
├── 2024_01_01_000009_create_site_boundaries_table.php
├── 2024_01_01_000010_create_monitoring_plots_table.php
├── 2024_01_01_000011_create_drones_table.php
├── 2024_01_01_000012_create_drone_sensors_table.php
├── 2024_01_01_000013_create_survey_missions_table.php
├── 2024_01_01_000014_create_mission_team_members_table.php
├── 2024_01_01_000015_create_flight_sessions_table.php
├── 2024_01_01_000016_create_media_assets_table.php
├── 2024_01_01_000017_create_sensor_datasets_table.php
├── 2024_01_01_000018_create_processing_jobs_table.php
├── 2024_01_01_000019_create_ai_services_table.php
├── 2024_01_01_000020_create_ai_models_table.php
├── 2024_01_01_000021_create_model_runs_table.php
├── 2024_01_01_000022_create_tree_observations_table.php
└── ... (more migrations for other tables)
```

### Running Migrations

```bash
# Run pending migrations
php artisan migrate

# Create database before migration (requires DB_CONNECTION config)
php artisan db:create

# Rollback last migration batch
php artisan migrate:rollback

# Rollback all migrations
php artisan migrate:reset

# Rollback and re-run all migrations
php artisan migrate:refresh

# Rollback, re-run, and seed
php artisan migrate:refresh --seed

# Check migration status
php artisan migrate:status
```

### Creating New Migrations

```bash
# Create a new migration
php artisan make:migration create_table_name_table

# Create migration with model
php artisan make:model ModelName -m

# Create migration with model and controller
php artisan make:model ModelName -mc

# Edit generated migration and run
php artisan migrate
```

### Seeding the Database

Database seeders populate tables with sample data for development and testing.

```bash
# Run all seeders
php artisan db:seed

# Run specific seeder
php artisan db:seed --class=UserSeeder

# Refresh database with seeds
php artisan migrate:refresh --seed
```

### Key Tables Overview

| Table | Purpose | Key Fields |
|-------|---------|-----------|
| `organizations` | Tenant/org records | id, name, type, active |
| `users` | System users | id, email, password, org_id |
| `roles` | Authorization roles | id, name, org_id |
| `permissions` | Granular permissions | id, name, description |
| `survey_sites` | Monitoring areas | id, name, center_point (PostGIS Point) |
| `site_boundaries` | Geospatial polygons | id, boundary_geom (PostGIS Polygon) |
| `survey_missions` | Survey campaigns | id, site_id, start_date, status |
| `flight_sessions` | Drone sorties | id, mission_id, status |
| `media_assets` | Captured images | id, flight_id, file_path |
| `processing_jobs` | AI inference jobs | id, status, input_data |
| `tree_observations` | Detected trees | id, job_id, location (PostGIS Point) |
| `species_classification_results` | Classification outputs | id, observation_id, species |
| `audit_logs` | Action history | id, user_id, event, old/new values |

### PostGIS Geospatial Queries

The database uses PostGIS for spatial data:

```sql
-- Find trees within 100 meters of a point
SELECT * FROM tree_observations 
WHERE ST_DWithin(location, ST_Point(-73.123, 40.456)::geography, 100);

-- Find flights that crossed a boundary
SELECT * FROM flight_sessions 
WHERE ST_Intersects(flight_path, (SELECT boundary_geom FROM site_boundaries WHERE id = ?));

-- Calculate area of a site boundary
SELECT ST_Area(boundary_geom) AS area_square_meters 
FROM site_boundaries WHERE id = ?;
```

---

## API Documentation

### Base URL

- **Development**: `http://localhost:8000/api/v1`
- **Production**: `https://api.mangroscan.com/api/v1` (example)

### Authentication

All endpoints (except `/health` and `/meta/capabilities`) require an API token:

```bash
# Get token
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Response
{
  "data": {
    "user": { ... },
    "access_token": "uuid-token-here",
    "expires_at": "2025-08-13T11:30:00Z",
    "roles": [...],
    "permissions": [...]
  }
}

# Use token in requests
curl http://localhost:8000/api/v1/sites \
  -H "Authorization: Bearer uuid-token-here"
```

### Core Endpoints

#### Platform & Health
```
GET    /health                    # Health check
GET    /meta/capabilities         # Feature flags
```

#### Authentication
```
POST   /auth/login                # Login
GET    /auth/me                   # Current user profile
POST   /auth/logout               # Logout
PUT    /auth/password             # Change password
GET    /auth/permissions          # User permissions
POST   /auth/password/forgot      # Request password reset
POST   /auth/password/reset       # Complete password reset
```

#### Organizations & Users
```
GET    /organizations             # List organizations (admin)
POST   /organizations             # Create organization
GET    /organizations/{id}        # Organization detail
PATCH  /organizations/{id}        # Update organization

GET    /users                     # List users
POST   /users                     # Create user
GET    /users/{id}                # User detail
PATCH  /users/{id}                # Update user
POST   /users/{id}/activation     # Activate/deactivate user

GET    /roles                     # List roles
GET    /permissions               # List permissions
PUT    /users/{id}/roles          # Assign user roles
PUT    /roles/{id}/permissions    # Assign role permissions
```

#### Survey Sites
```
GET    /sites                     # List sites
POST   /sites                     # Create site
GET    /sites/{id}                # Site detail
PATCH  /sites/{id}                # Update site

GET    /sites/{id}/boundaries     # List boundaries
POST   /sites/{id}/boundaries     # Create boundary
PATCH  /boundaries/{id}           # Update boundary

GET    /sites/{id}/plots          # List plots
POST   /sites/{id}/plots          # Create plot
```

#### Drones & Hardware
```
GET    /drones                    # List drones
POST   /drones                    # Create drone
GET    /drones/{id}               # Drone detail

POST   /drones/{id}/sensors       # Attach sensor
```

#### Missions & Flights
```
GET    /missions                  # List missions
POST   /missions                  # Create mission
GET    /missions/{id}             # Mission detail
PATCH  /missions/{id}             # Update mission
POST   /missions/{id}/approve     # Approve mission
POST   /missions/{id}/start       # Start mission
POST   /missions/{id}/complete    # Complete mission
PUT    /missions/{id}/team        # Assign team

GET    /missions/{id}/flights     # List flights
POST   /missions/{id}/flights     # Create flight
GET    /flights/{id}              # Flight detail
PATCH  /flights/{id}              # Update flight
```

#### Media & Processing
```
GET    /flights/{id}/media        # List flight media
POST   /media/upload/initiate     # Start media upload
POST   /media/upload/complete     # Finalize upload

GET    /processing-jobs           # List processing jobs
POST   /processing-jobs           # Create job
GET    /processing-jobs/{id}      # Job detail
POST   /processing-jobs/{id}/retry # Retry job
```

#### AI Services
```
GET    /ai-services               # List AI services
POST   /ai-services               # Register service
GET    /ai-services/overview      # Service status
POST   /ai-services/health-test   # Test service
POST   /ai-services/synchronize   # Sync models

GET    /ai-models                 # List models
GET    /ai-models/{id}            # Model detail
```

#### Notifications
```
GET    /notifications             # List notifications
POST   /notifications/{id}/read   # Mark as read
GET    /notifications/unread-count # Unread count
```

### Response Format

**Success (200/201)**:
```json
{
  "data": {
    "id": "uuid",
    "name": "Example",
    "created_at": "2025-08-13T10:00:00Z"
  }
}
```

**Paginated (200)**:
```json
{
  "data": [{ ... }, { ... }],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 100,
    "last_page": 7
  }
}
```

**Error (4xx/5xx)**:
```json
{
  "message": "Validation failed",
  "errors": {
    "email": ["Email is already taken"],
    "password": ["Password must be at least 8 characters"]
  }
}
```

### Status Codes

| Code | Meaning |
|------|---------|
| 200 | OK - Successful GET/PATCH |
| 201 | Created - Successful POST |
| 202 | Accepted - Async operation started |
| 204 | No Content - Successful DELETE/logout |
| 400 | Bad Request - Invalid data |
| 401 | Unauthorized - Missing/invalid token |
| 403 | Forbidden - Insufficient permissions |
| 404 | Not Found - Resource doesn't exist |
| 409 | Conflict - Duplicate/state violation |
| 422 | Unprocessable Entity - Validation error |
| 429 | Too Many Requests - Rate limited |
| 500 | Server Error |

For full API documentation, see [API Endpoint Tracker](docs/MangroScan_API_Endpoint_Tracker%20-%20API%20Endpoint%20Tracker.csv).

---

## Configuration

### Environment Variables (.env)

Create `.env` from `.env.example` and configure:

```env
# Application
APP_NAME="MangroScan"
APP_ENV=local              # local, testing, staging, production
APP_DEBUG=true             # false in production
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=mangroscan
DB_USERNAME=postgres
DB_PASSWORD=password

# Cache & Queue
CACHE_DRIVER=file          # file, redis, memcached
QUEUE_CONNECTION=sync      # sync, redis, database

# Mail
MAIL_MAILER=log            # log, smtp, mailgun
MAIL_FROM_ADDRESS=noreply@mangroscan.local

# MangroScan Config
MANGROSCAN_WEB_URL=http://localhost:5173
AUTH_ACCESS_TOKEN_TTL_MINUTES=60
MEDIA_UPLOAD_DISK=local    # local, s3
MEDIA_MAX_UPLOAD_BYTES=5368709120  # 5GB

# AI Services
AI_SERVICE_CONNECT_TIMEOUT_SECONDS=3
AI_SERVICE_TIMEOUT_SECONDS=10
```

### Key Configuration Files

**config/mangroscan.php**
```php
return [
    'api_version' => 'v1',
    'features' => [
        'health_checks' => true,
        'request_ids' => true,
        'token_authentication' => true,
    ],
    'auth' => [
        'access_token_ttl_minutes' => 60,
        'login_attempts_per_minute' => 5,
    ],
    'media' => [
        'upload_url_ttl_minutes' => 30,
        'max_upload_bytes' => 5_368_709_120,
    ],
];
```

**config/database.php**
- PostgreSQL with PostGIS support
- SQLite fallback for testing
- Connection pooling settings

---

## Development

### Running the Application

#### Option 1: Combined Services (Recommended)

```bash
composer run dev
```

Runs concurrently:
- Laravel API server on localhost:8000
- Queue worker for background jobs
- Real-time log viewer (Pail)
- Vite frontend dev server

#### Option 2: Individual Services

```bash
# Terminal 1: API Server
php artisan serve

# Terminal 2: Queue Worker
php artisan queue:listen --tries=1

# Terminal 3: Log Viewer
php artisan pail --timeout=0

# Terminal 4: Frontend Build
npm run dev
```

### Artisan Commands

```bash
# Model & Code Generation
php artisan make:model ModelName -a              # Model with all files
php artisan make:controller ControllerName --api
php artisan make:request RequestClassName
php artisan make:resource ResourceClassName

# Database
php artisan migrate
php artisan migrate:refresh --seed
php artisan db:seed

# Cache & Config
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Tinker (Interactive REPL)
php artisan tinker
```

### Debugging

#### Using Tinker

```bash
php artisan tinker

# Interactive debugging
>>> $user = App\Models\User::first();
>>> $user->roles;
>>> Auth::loginUsingId($user->id);
```

#### Logging

```php
// Add debug logging
Log::debug('Debug message', ['data' => $variable]);
Log::info('Info message');
Log::warning('Warning message');
Log::error('Error message', ['exception' => $e]);

// View logs
php artisan pail
php artisan pail --filter=User    # Filter by keyword
php artisan pail --type=error     # Only errors
```

#### Code Standards

```bash
# Fix code style
./vendor/bin/pint

# Check without fixing
./vendor/bin/pint --test

# Format specific file
./vendor/bin/pint app/Models/User.php
```

---

## Testing

### Test Structure

```
tests/
├── TestCase.php
├── Feature/
│   ├── Auth/
│   ├── Organization/
│   ├── Mission/
│   └── ...
└── Unit/
    ├── Services/
    ├── Models/
    └── ...
```

### Running Tests

```bash
# Run all tests (SQLite)
php artisan test

# Run with PostgreSQL (geospatial tests)
php artisan test --configuration=phpunit.pgsql.xml

# Run specific test file
php artisan test tests/Feature/Auth/LoginTest.php

# Run with specific filter
php artisan test --filter=LoginTest

# Run with coverage
php artisan test --coverage

# Stop on first failure
php artisan test --stop-on-failure
```

### Writing Tests

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_mission()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)
            ->postJson('/api/v1/missions', [
                'site_id' => $siteId,
                'mission_name' => 'Survey 2025',
                'start_date' => '2025-08-15',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'name']]);
    }
}
```

### Test Coverage Report

```bash
# Generate coverage report
php artisan test --coverage --coverage-html=coverage

# Open coverage/index.html in browser
```

---

## Deployment

### Production Checklist

```bash
# Before deployment
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Install dependencies (production only)
composer install --optimize-autoloader --no-dev
npm install --production
npm run build

# Database migrations
php artisan migrate --force

# Create necessary directories
mkdir -p storage/app storage/framework/{cache,sessions,views} storage/logs
chmod -R 775 storage bootstrap/cache
```

### Environment Configuration

```env
APP_ENV=production
APP_DEBUG=false

# Database
DB_HOST=prod-db.example.com
DB_USERNAME=<secure>
DB_PASSWORD=<secure>

# Cache
CACHE_DRIVER=redis
REDIS_HOST=prod-redis.example.com

# Queue
QUEUE_CONNECTION=redis

# Storage
MEDIA_UPLOAD_DISK=s3
AWS_ACCESS_KEY_ID=<secure>
AWS_SECRET_ACCESS_KEY=<secure>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=mangroscan-media
```

### Server Setup

#### Using Laravel Sail (Docker)
```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
```

#### Using Traditional VPS
1. Install PHP 8.2+, PostgreSQL 18+, Composer, Node.js
2. Clone repository
3. Configure `.env` with production values
4. Run setup commands above
5. Configure Nginx/Apache virtual host
6. Set up process supervisor (Supervisor) for queue worker
7. Enable HTTPS with Let's Encrypt

#### Nginx Configuration Example
```nginx
server {
    listen 80;
    server_name api.mangroscan.com;
    root /var/www/mangroscan-api/public;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Monitoring & Maintenance

```bash
# Monitor logs
tail -f storage/logs/laravel.log

# Queue monitoring
php artisan queue:monitor

# Clear old logs
php artisan log:clear

# Prune old data
php artisan model:prune
```

---

## Troubleshooting

### Common Issues

#### 1. "SQLSTATE[HY000]: General error: 1 no such table"

**Problem**: Database not set up  
**Solution**:
```bash
php artisan migrate
# Or if database doesn't exist:
php artisan db:create
php artisan migrate
```

#### 2. "Class not found" errors

**Problem**: Autoloader not refreshed  
**Solution**:
```bash
composer dump-autoload -o
php artisan cache:clear
```

#### 3. PostgreSQL PostGIS not enabled

**Problem**: Spatial functions not available  
**Solution**:
```bash
# In PostgreSQL
psql -U postgres -d mangroscan
CREATE EXTENSION IF NOT EXISTS postgis;
\q

# Verify
psql -U postgres -d mangroscan -c "SELECT PostGIS_Version();"
```

#### 4. Port already in use (8000)

**Problem**: Laravel server port busy  
**Solution**:
```bash
# Specify different port
php artisan serve --port=8001

# Or find and kill process
lsof -i :8000
kill -9 <PID>
```

#### 5. Permission denied on storage directory

**Problem**: Cannot write to storage  
**Solution**:
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

#### 6. "The only supported ciphers are AES-128-CBC and AES-256-CBC"

**Problem**: APP_KEY not set  
**Solution**:
```bash
php artisan key:generate
```

### Getting Help

1. Check [Laravel Documentation](https://laravel.com/docs)
2. Review [CODEBASE_ANALYSIS.md](CODEBASE_ANALYSIS.md)
3. Check [API Endpoint Tracker](docs/MangroScan_API_Endpoint_Tracker%20-%20API%20Endpoint%20Tracker.csv)
4. Check `storage/logs/laravel.log` for error details
5. Use `php artisan tinker` to debug interactively

---

## Contributing

### Development Guidelines

1. **Branch Naming**: `feature/description`, `bugfix/description`, `docs/description`
2. **Commit Messages**: Clear, descriptive, imperative mood
3. **Code Style**: Run `./vendor/bin/pint` before committing
4. **Testing**: All new code must have tests with good coverage
5. **Documentation**: Update README/docs for new features

### Pull Request Process

1. Create feature branch from main
2. Write code with tests
3. Ensure tests pass: `php artisan test`
4. Ensure style passes: `./vendor/bin/pint`
5. Document changes in PR description
6. Request code review
7. Merge after approval

### Adding New Endpoints

1. Create migration if needed: `php artisan make:migration ...`
2. Create Model: `php artisan make:model ModelName -a`
3. Create Request validation in `app/Http/Requests/`
4. Create Controller in `app/Http/Controllers/Api/V1/`
5. Create Resource in `app/Http/Resources/`
6. Add route in `routes/api.php`
7. Write tests in `tests/Feature/`
8. Update endpoint tracker CSV
9. Run full test suite

---

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).

---

## Project Documentation

- **Full Analysis**: See [CODEBASE_ANALYSIS.md](CODEBASE_ANALYSIS.md)
- **Database Schema**: See [MangroScan_DB_Schema.md](docs/MangroScan_DB_Schema.md)
- **API Endpoints**: See [API Endpoint Tracker](docs/MangroScan_API_Endpoint_Tracker%20-%20API%20Endpoint%20Tracker.csv)
- **Testing Guide**: See [PostgreSQL_Testing.md](docs/PostgreSQL_Testing.md)
- **Changelog**: See [CHANGELOG.md](CHANGELOG.md)

---

**Version**: 1.0.0  
**Last Updated**: August 13, 2025  
**Built with**: Laravel 12 | PostgreSQL 18 + PostGIS | PHP 8.2+
