# **1\. System Database Overview**

**Recommended DBMS:** PostgreSQL \+ PostGIS  
Reason: MangroScan is a geospatial monitoring system. You need proper support for points, polygons, survey boundaries, tree locations, flight paths, canopy polygons, and exported map layers.

**Core design idea:**

MangroScan should not store only “one survey result.” It should store:

1. **Who** performed the survey.  
2. **Where** the survey happened.  
3. **Which drone/sensors** were used.  
4. **Which flight/session** captured the data.  
5. **Which images/sensor files** were produced.  
6. **Which AI model/version** processed the data.  
7. **What trees were detected** and where.  
8. **What species, height, age, and count results** were generated.  
9. **Which results were validated** by field researchers.  
10. **Which reports/exports** were produced.  
11. **Who changed or accessed records** through audit logs.

This makes the database capstone-worthy because it supports repeated monitoring over time, multiple drones, multiple survey sites, multiple AI model versions, validation, and future startup-level scaling.

# **2\. Proposed Tables With Purpose**

## **A. User, Role, and Security Tables**

| Table | Purpose |
| ----- | ----- |
| organizations | Stores school, LGU, DENR, NGO, or project-owner organizations. |
| users | Stores system users such as admin, drone pilot, researcher, validator, and viewer. |
| roles | Defines user roles. |
| permissions | Defines allowed actions such as create mission, validate results, export report. |
| role\_permissions | Many-to-many table between roles and permissions. |
| user\_roles | Many-to-many table between users and roles. |
| audit\_logs | Tracks important system actions for accountability. |

## **B. Survey Site and Mapping Tables**

| Table | Purpose |
| ----- | ----- |
| survey\_sites | Stores mangrove areas/sites being monitored. |
| site\_boundaries | Stores geospatial boundary polygons for each survey area. |
| monitoring\_plots | Stores smaller field validation plots inside a survey site. |
| site\_access\_permissions | Tracks permits, approval, or field access documentation. |

## **C. Drone, Sensor, and Hardware Tables**

| Table | Purpose |
| ----- | ----- |
| drones | Stores drone units used in surveys. |
| drone\_sensors | Stores attached RGB camera, LiDAR, stereo depth sensor, GPS, etc. |
| sensor\_calibrations | Tracks sensor calibration history. |
| battery\_packs | Stores drone/onboard-computer batteries. |
| battery\_usage\_logs | Tracks battery use per flight. |

## **D. Mission and Flight Tables**

| Table | Purpose |
| ----- | ----- |
| survey\_missions | Main survey mission record for a site/date/objective. |
| mission\_team\_members | Users assigned to a mission. |
| flight\_sessions | Actual drone flight sessions/sorties under a mission. |
| flight\_waypoints | GPS route/waypoints used during flight. |
| flight\_environment\_logs | Weather, wind, visibility, and environmental conditions. |
| flight\_checklists | Pre-flight and post-flight checklist data. |

## **E. Captured Data Tables**

| Table | Purpose |
| ----- | ----- |
| media\_assets | Stores captured images/videos and metadata. |
| sensor\_datasets | Stores LiDAR/depth/photogrammetry/GPS files. |
| photogrammetry\_products | Stores orthomosaic, point cloud, DSM, DTM, CHM outputs. |
| geospatial\_layers | Stores generated map layers for dashboard visualization. |

## **F. AI Model and Processing Tables**

| Table | Purpose |
| ----- | ----- |
| ai\_models | Stores AI models such as species classifier, YOLO tree detector, height estimator. |
| ai\_model\_versions | Tracks model versions, dataset versions, metrics, and deployment status. |
| processing\_jobs | Stores batch processing jobs for images/sensor data. |
| model\_runs | Stores specific AI model execution records. |
| training\_datasets | Stores datasets used for AI model training/validation. |
| training\_dataset\_items | Links images/samples to a training dataset. |

## **G. Mangrove Tree Result Tables**

| Table | Purpose |
| ----- | ----- |
| mangrove\_species | Reference table for mangrove species. |
| species\_growth\_models | Stores species-specific growth-rate formulas for age approximation. |
| mangrove\_tree\_entities | Optional persistent tree identity across repeated monitoring. |
| tree\_observations | Main detected tree record for a specific mission/flight/model run. |
| species\_classification\_results | Stores AI species predictions and confidence scores. |
| canopy\_height\_estimations | Stores estimated height results. |
| age\_estimations | Stores estimated age results. |
| tree\_count\_summaries | Stores total count results by mission/site/species. |

## **H. Validation and Accuracy Tables**

| Table | Purpose |
| ----- | ----- |
| validation\_sessions | Stores field validation activity. |
| ground\_truth\_tree\_records | Stores manually verified tree data. |
| validation\_matches | Links AI-detected trees to ground-truth trees. |
| accuracy\_metrics | Stores accuracy, precision, recall, F1, RMSE, MAE, etc. |

## **I. Reports, Exports, and Dashboard Tables**

| Table | Purpose |
| ----- | ----- |
| reports | Stores generated monitoring reports. |
| exported\_files | Stores exported CSV, PDF, GeoJSON, Shapefile, KML, or image outputs. |
| dashboard\_saved\_views | Stores saved dashboard filters/views. |
| notification\_logs | Stores system notifications. |
| system\_settings | Stores configurable app settings. |

# **3\. Full Database Schema Table-by-Table**

## **A. User, Role, and Security**

## **organizations**

Stores institutions using the system.

| Field | Type | Notes |
| ----- | ----- | ----- |
| organization\_id | UUID | Primary key |
| organization\_name | VARCHAR(150) | Example: Foundation University, DENR, LGU |
| organization\_type | VARCHAR(50) | school, lgu, denr, ngo, research\_group |
| contact\_email | VARCHAR(150) | Nullable |
| contact\_number | VARCHAR(50) | Nullable |
| address | TEXT | Nullable |
| status | VARCHAR(30) | active, inactive |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **users**

Stores all accounts.

| Field | Type | Notes |
| ----- | ----- | ----- |
| user\_id | UUID | Primary key |
| organization\_id | UUID | FK → organizations.organization\_id |
| first\_name | VARCHAR(100) |  |
| last\_name | VARCHAR(100) |  |
| email | VARCHAR(150) | Unique |
| password\_hash | TEXT | Never store plain password |
| contact\_number | VARCHAR(50) | Nullable |
| position\_title | VARCHAR(100) | Example: Drone Pilot, Researcher |
| profile\_photo\_path | TEXT | Nullable |
| is\_active | BOOLEAN | Default true |
| last\_login\_at | TIMESTAMP | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **roles**

| Field | Type | Notes |
| ----- | ----- | ----- |
| role\_id | UUID | Primary key |
| role\_name | VARCHAR(80) | Admin, Drone Pilot, Environmental Scientist, Viewer |
| description | TEXT | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

Recommended roles:

| Role | Access |
| ----- | ----- |
| System Admin | Manage users, drones, settings, reports |
| Drone Pilot | Create/execute missions and upload capture data |
| Environmental Scientist | Validate species, height, age, ground truth |
| Researcher | Analyze monitoring results |
| Viewer | View dashboard and export approved reports |

---

## **permissions**

| Field | Type | Notes |
| ----- | ----- | ----- |
| permission\_id | UUID | Primary key |
| permission\_code | VARCHAR(100) | Unique, e.g. mission.create |
| permission\_name | VARCHAR(150) | Human-readable name |
| description | TEXT | Nullable |

---

## **role\_permissions**

| Field | Type | Notes |
| ----- | ----- | ----- |
| role\_id | UUID | FK → roles.role\_id |
| permission\_id | UUID | FK → permissions.permission\_id |

Composite primary key:  
role\_id, permission\_id

---

## **user\_roles**

| Field | Type | Notes |
| ----- | ----- | ----- |
| user\_id | UUID | FK → users.user\_id |
| role\_id | UUID | FK → roles.role\_id |

Composite primary key:  
user\_id, role\_id

---

## **audit\_logs**

Important for thesis defense because it proves traceability and accountability.

| Field | Type | Notes |
| ----- | ----- | ----- |
| audit\_log\_id | UUID | Primary key |
| user\_id | UUID | FK → users.user\_id, nullable for system-generated actions |
| action | VARCHAR(150) | create, update, delete, login, export, validate |
| table\_name | VARCHAR(100) | Affected table |
| record\_id | UUID | Affected record |
| old\_values | JSONB | Nullable |
| new\_values | JSONB | Nullable |
| ip\_address | VARCHAR(60) | Nullable |
| user\_agent | TEXT | Nullable |
| created\_at | TIMESTAMP | Immutable |

---

# **B. Survey Site and Mapping**

## **survey\_sites**

Stores each mangrove monitoring site.

| Field | Type | Notes |
| ----- | ----- | ----- |
| site\_id | UUID | Primary key |
| organization\_id | UUID | FK → organizations.organization\_id |
| site\_name | VARCHAR(150) | Example: Foundation University Mangrove Site |
| site\_code | VARCHAR(50) | Unique site code |
| description | TEXT | Nullable |
| province | VARCHAR(100) |  |
| city\_municipality | VARCHAR(100) |  |
| barangay | VARCHAR(100) | Nullable |
| center\_point | GEOMETRY(Point, 4326\) | Site center |
| area\_hectares | NUMERIC(12,4) | Nullable |
| environment\_type | VARCHAR(80) | coastal, riverine, estuarine |
| access\_notes | TEXT | Nullable |
| status | VARCHAR(30) | active, archived |
| created\_by | UUID | FK → users.user\_id |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **site\_boundaries**

Stores GIS boundary polygons.

| Field | Type | Notes |
| ----- | ----- | ----- |
| boundary\_id | UUID | Primary key |
| site\_id | UUID | FK → survey\_sites.site\_id |
| boundary\_name | VARCHAR(150) | Main boundary, restoration zone, exclusion zone |
| boundary\_type | VARCHAR(50) | survey\_area, no\_fly\_zone, restoration\_area |
| boundary\_geom | GEOMETRY(Polygon, 4326\) | Boundary polygon |
| source | VARCHAR(100) | manual, drone\_map, imported\_geojson |
| created\_by | UUID | FK → users.user\_id |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **monitoring\_plots**

Smaller validation plots within a site.

| Field | Type | Notes |
| ----- | ----- | ----- |
| plot\_id | UUID | Primary key |
| site\_id | UUID | FK → survey\_sites.site\_id |
| plot\_code | VARCHAR(50) | Example: PLOT-001 |
| plot\_name | VARCHAR(150) | Nullable |
| plot\_geom | GEOMETRY(Polygon, 4326\) | Plot boundary |
| area\_square\_meters | NUMERIC(12,2) | Nullable |
| description | TEXT | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **site\_access\_permissions**

Useful if DENR/LGU/school approval is needed.

| Field | Type | Notes |
| ----- | ----- | ----- |
| access\_permission\_id | UUID | Primary key |
| site\_id | UUID | FK → survey\_sites.site\_id |
| permit\_title | VARCHAR(150) |  |
| issuing\_agency | VARCHAR(150) | Example: LGU, DENR |
| permit\_number | VARCHAR(100) | Nullable |
| valid\_from | DATE | Nullable |
| valid\_until | DATE | Nullable |
| document\_path | TEXT | Nullable |
| status | VARCHAR(30) | pending, approved, expired |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

# **C. Drone, Sensor, and Hardware**

## **drones**

| Field | Type | Notes |
| ----- | ----- | ----- |
| drone\_id | UUID | Primary key |
| organization\_id | UUID | FK → organizations.organization\_id |
| drone\_name | VARCHAR(100) |  |
| model | VARCHAR(100) | Nullable |
| serial\_number | VARCHAR(100) | Unique, nullable |
| firmware\_version | VARCHAR(80) | Nullable |
| max\_flight\_minutes | NUMERIC(5,2) | Nullable |
| payload\_capacity\_grams | NUMERIC(8,2) | Nullable |
| status | VARCHAR(30) | available, maintenance, retired |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **drone\_sensors**

Supports RGB camera, LiDAR/depth sensor, GPS, IMU, thermal camera in the future.

| Field | Type | Notes |
| ----- | ----- | ----- |
| sensor\_id | UUID | Primary key |
| drone\_id | UUID | FK → drones.drone\_id |
| sensor\_name | VARCHAR(100) | RGB Camera, LiDAR, Stereo Depth |
| sensor\_type | VARCHAR(50) | rgb\_camera, lidar, depth, gps, imu |
| manufacturer | VARCHAR(100) | Nullable |
| model | VARCHAR(100) | Nullable |
| serial\_number | VARCHAR(100) | Nullable |
| resolution | VARCHAR(80) | For camera |
| range\_meters | NUMERIC(8,2) | For LiDAR/depth |
| calibration\_required | BOOLEAN | Default false |
| status | VARCHAR(30) | active, inactive, maintenance |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **sensor\_calibrations**

| Field | Type | Notes |
| ----- | ----- | ----- |
| calibration\_id | UUID | Primary key |
| sensor\_id | UUID | FK → drone\_sensors.sensor\_id |
| calibrated\_by | UUID | FK → users.user\_id |
| calibration\_date | TIMESTAMP |  |
| calibration\_method | VARCHAR(150) | Manual, software-assisted |
| calibration\_file\_path | TEXT | Nullable |
| calibration\_notes | TEXT | Nullable |
| is\_valid | BOOLEAN | Default true |
| created\_at | TIMESTAMP |  |

---

## **battery\_packs**

| Field | Type | Notes |
| ----- | ----- | ----- |
| battery\_id | UUID | Primary key |
| organization\_id | UUID | FK → organizations.organization\_id |
| battery\_code | VARCHAR(50) | Unique |
| battery\_type | VARCHAR(80) | drone, onboard\_computer |
| capacity\_mah | INTEGER | Nullable |
| voltage | NUMERIC(6,2) | Nullable |
| cycle\_count | INTEGER | Default 0 |
| status | VARCHAR(30) | available, charging, retired |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **battery\_usage\_logs**

| Field | Type | Notes |
| ----- | ----- | ----- |
| battery\_usage\_id | UUID | Primary key |
| battery\_id | UUID | FK → battery\_packs.battery\_id |
| flight\_session\_id | UUID | FK → flight\_sessions.flight\_session\_id |
| start\_percentage | NUMERIC(5,2) |  |
| end\_percentage | NUMERIC(5,2) |  |
| usage\_minutes | NUMERIC(8,2) | Nullable |
| notes | TEXT | Nullable |
| created\_at | TIMESTAMP |  |

---

# **D. Mission and Flight Management**

## **survey\_missions**

Main mission table.

| Field | Type | Notes |
| ----- | ----- | ----- |
| mission\_id | UUID | Primary key |
| site\_id | UUID | FK → survey\_sites.site\_id |
| mission\_code | VARCHAR(50) | Unique |
| mission\_title | VARCHAR(150) |  |
| mission\_objective | TEXT | Example: classify species and estimate age |
| planned\_start\_at | TIMESTAMP | Nullable |
| planned\_end\_at | TIMESTAMP | Nullable |
| actual\_start\_at | TIMESTAMP | Nullable |
| actual\_end\_at | TIMESTAMP | Nullable |
| mission\_status | VARCHAR(30) | planned, in\_progress, completed, cancelled, failed |
| coverage\_target\_hectares | NUMERIC(12,4) | Nullable |
| coverage\_completed\_hectares | NUMERIC(12,4) | Nullable |
| created\_by | UUID | FK → users.user\_id |
| approved\_by | UUID | FK → users.user\_id, nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **mission\_team\_members**

| Field | Type | Notes |
| ----- | ----- | ----- |
| mission\_team\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| user\_id | UUID | FK → users.user\_id |
| team\_role | VARCHAR(80) | pilot, observer, validator, researcher |
| assigned\_at | TIMESTAMP |  |

Unique constraint:  
mission\_id, user\_id, team\_role

---

## **flight\_sessions**

Each mission can require multiple sorties because flight duration is limited by battery capacity. This directly supports your proposal’s limitation that larger areas may require multiple flights.

| Field | Type | Notes |
| ----- | ----- | ----- |
| flight\_session\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| drone\_id | UUID | FK → drones.drone\_id |
| pilot\_user\_id | UUID | FK → users.user\_id |
| flight\_code | VARCHAR(50) | Unique |
| takeoff\_location | GEOMETRY(Point, 4326\) | Nullable |
| landing\_location | GEOMETRY(Point, 4326\) | Nullable |
| planned\_altitude\_meters | NUMERIC(8,2) | Nullable |
| actual\_avg\_altitude\_meters | NUMERIC(8,2) | Nullable |
| started\_at | TIMESTAMP | Nullable |
| ended\_at | TIMESTAMP | Nullable |
| flight\_duration\_minutes | NUMERIC(8,2) | Nullable |
| flight\_status | VARCHAR(30) | planned, flying, completed, aborted, failed |
| quality\_status | VARCHAR(30) | pending, acceptable, rejected, needs\_recapture |
| notes | TEXT | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **flight\_waypoints**

| Field | Type | Notes |
| ----- | ----- | ----- |
| waypoint\_id | UUID | Primary key |
| flight\_session\_id | UUID | FK → flight\_sessions.flight\_session\_id |
| sequence\_no | INTEGER | Order of waypoint |
| waypoint\_location | GEOMETRY(Point, 4326\) |  |
| altitude\_meters | NUMERIC(8,2) | Nullable |
| speed\_mps | NUMERIC(8,2) | Nullable |
| action | VARCHAR(80) | capture, turn, hover, return\_home |
| created\_at | TIMESTAMP |  |

---

## **flight\_environment\_logs**

| Field | Type | Notes |
| ----- | ----- | ----- |
| environment\_log\_id | UUID | Primary key |
| flight\_session\_id | UUID | FK → flight\_sessions.flight\_session\_id |
| recorded\_at | TIMESTAMP |  |
| weather\_condition | VARCHAR(80) | sunny, cloudy, windy, rainy |
| wind\_speed\_mps | NUMERIC(8,2) | Nullable |
| temperature\_celsius | NUMERIC(5,2) | Nullable |
| humidity\_percent | NUMERIC(5,2) | Nullable |
| visibility\_status | VARCHAR(80) | good, moderate, poor |
| notes | TEXT | Nullable |

---

## **flight\_checklists**

| Field | Type | Notes |
| ----- | ----- | ----- |
| checklist\_id | UUID | Primary key |
| flight\_session\_id | UUID | FK → flight\_sessions.flight\_session\_id |
| checked\_by | UUID | FK → users.user\_id |
| checklist\_type | VARCHAR(30) | pre\_flight, post\_flight |
| battery\_ok | BOOLEAN |  |
| weather\_ok | BOOLEAN |  |
| gps\_ok | BOOLEAN |  |
| camera\_ok | BOOLEAN |  |
| lidar\_depth\_ok | BOOLEAN |  |
| storage\_ok | BOOLEAN |  |
| overall\_status | VARCHAR(30) | passed, failed, conditional |
| remarks | TEXT | Nullable |
| created\_at | TIMESTAMP |  |

---

# **E. Captured Image and Sensor Data**

## **media\_assets**

Stores high-resolution RGB images and optional videos. The proposal requires high-resolution aerial images for environmental monitoring and analysis.

| Field | Type | Notes |
| ----- | ----- | ----- |
| media\_id | UUID | Primary key |
| flight\_session\_id | UUID | FK → flight\_sessions.flight\_session\_id |
| sensor\_id | UUID | FK → drone\_sensors.sensor\_id, nullable |
| file\_name | VARCHAR(255) |  |
| file\_path | TEXT | Local/cloud path |
| file\_type | VARCHAR(50) | image, video |
| mime\_type | VARCHAR(100) | image/jpeg, image/tiff |
| file\_size\_bytes | BIGINT | Nullable |
| captured\_at | TIMESTAMP | Nullable |
| capture\_location | GEOMETRY(Point, 4326\) | Nullable |
| altitude\_meters | NUMERIC(8,2) | Nullable |
| camera\_pitch\_deg | NUMERIC(8,2) | Nullable |
| camera\_yaw\_deg | NUMERIC(8,2) | Nullable |
| image\_width | INTEGER | Nullable |
| image\_height | INTEGER | Nullable |
| quality\_score | NUMERIC(5,4) | 0 to 1 |
| quality\_status | VARCHAR(30) | pending, accepted, rejected |
| metadata | JSONB | EXIF/drone metadata |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **sensor\_datasets**

Stores LiDAR, depth, GPS, and raw sensor outputs.

| Field | Type | Notes |
| ----- | ----- | ----- |
| sensor\_dataset\_id | UUID | Primary key |
| flight\_session\_id | UUID | FK → flight\_sessions.flight\_session\_id |
| sensor\_id | UUID | FK → drone\_sensors.sensor\_id |
| dataset\_type | VARCHAR(50) | lidar\_point\_cloud, depth\_map, gps\_log, imu\_log |
| file\_name | VARCHAR(255) |  |
| file\_path | TEXT |  |
| file\_format | VARCHAR(50) | LAS, LAZ, CSV, JSON, TIFF |
| recorded\_start\_at | TIMESTAMP | Nullable |
| recorded\_end\_at | TIMESTAMP | Nullable |
| spatial\_reference | VARCHAR(80) | EPSG:4326, etc. |
| metadata | JSONB | Sensor metadata |
| quality\_status | VARCHAR(30) | pending, accepted, rejected |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **photogrammetry\_products**

For OpenDroneMap or similar outputs.

| Field | Type | Notes |
| ----- | ----- | ----- |
| product\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| processing\_job\_id | UUID | FK → processing\_jobs.processing\_job\_id |
| product\_type | VARCHAR(50) | orthomosaic, point\_cloud, dsm, dtm, chm |
| file\_name | VARCHAR(255) |  |
| file\_path | TEXT |  |
| file\_format | VARCHAR(50) | GeoTIFF, LAS, LAZ, PNG |
| resolution\_cm\_per\_pixel | NUMERIC(8,2) | Nullable |
| spatial\_reference | VARCHAR(80) |  |
| bounding\_geom | GEOMETRY(Polygon, 4326\) | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **geospatial\_layers**

Stores generated map layers for dashboard/mobile viewing.

| Field | Type | Notes |
| ----- | ----- | ----- |
| layer\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| layer\_name | VARCHAR(150) | Species Map, Height Map, Tree Density Map |
| layer\_type | VARCHAR(50) | tree\_points, species\_map, canopy\_height, orthomosaic |
| file\_path | TEXT | GeoJSON, Tile, Raster path |
| style\_config | JSONB | Color/style settings |
| is\_visible\_default | BOOLEAN | Default true |
| created\_by | UUID | FK → users.user\_id |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

# **F. AI Model and Processing**

## **ai\_models**

| Field | Type | Notes |
| ----- | ----- | ----- |
| model\_id | UUID | Primary key |
| model\_name | VARCHAR(150) | YOLO Tree Detector, Species Classifier |
| model\_type | VARCHAR(80) | species\_classifier, tree\_detector, height\_estimator, age\_estimator |
| framework | VARCHAR(80) | TensorFlow, PyTorch, YOLO, OpenCV |
| description | TEXT | Nullable |
| created\_by | UUID | FK → users.user\_id |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **ai\_model\_versions**

This is important. Never store only one AI model name. A thesis/startup system should track model versions because results may change when the model improves.

| Field | Type | Notes |
| ----- | ----- | ----- |
| model\_version\_id | UUID | Primary key |
| model\_id | UUID | FK → ai\_models.model\_id |
| version\_label | VARCHAR(80) | v1.0, v1.1 |
| model\_file\_path | TEXT |  |
| training\_dataset\_id | UUID | FK → training\_datasets.training\_dataset\_id, nullable |
| accuracy | NUMERIC(6,4) | Nullable |
| precision\_score | NUMERIC(6,4) | Nullable |
| recall\_score | NUMERIC(6,4) | Nullable |
| f1\_score | NUMERIC(6,4) | Nullable |
| rmse | NUMERIC(10,4) | For height/age models |
| is\_deployed | BOOLEAN | Default false |
| release\_notes | TEXT | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **processing\_jobs**

Represents a full batch process after flight capture.

| Field | Type | Notes |
| ----- | ----- | ----- |
| processing\_job\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| flight\_session\_id | UUID | FK → flight\_sessions.flight\_session\_id, nullable |
| job\_type | VARCHAR(80) | image\_quality, detection, classification, photogrammetry, full\_pipeline |
| job\_status | VARCHAR(30) | queued, running, completed, failed |
| input\_summary | JSONB | Input files/images |
| output\_summary | JSONB | Output counts/files |
| started\_at | TIMESTAMP | Nullable |
| completed\_at | TIMESTAMP | Nullable |
| error\_message | TEXT | Nullable |
| created\_by | UUID | FK → users.user\_id |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **model\_runs**

Each actual AI execution.

| Field | Type | Notes |
| ----- | ----- | ----- |
| model\_run\_id | UUID | Primary key |
| processing\_job\_id | UUID | FK → processing\_jobs.processing\_job\_id |
| model\_version\_id | UUID | FK → ai\_model\_versions.model\_version\_id |
| run\_type | VARCHAR(80) | tree\_detection, species\_classification, height\_estimation, age\_estimation |
| input\_media\_id | UUID | FK → media\_assets.media\_id, nullable |
| input\_dataset\_id | UUID | FK → sensor\_datasets.sensor\_dataset\_id, nullable |
| parameters | JSONB | Confidence threshold, IOU threshold, etc. |
| started\_at | TIMESTAMP | Nullable |
| completed\_at | TIMESTAMP | Nullable |
| run\_status | VARCHAR(30) | queued, running, completed, failed |
| created\_at | TIMESTAMP |  |

---

## **training\_datasets**

| Field | Type | Notes |
| ----- | ----- | ----- |
| training\_dataset\_id | UUID | Primary key |
| dataset\_name | VARCHAR(150) |  |
| dataset\_type | VARCHAR(80) | species, detection, height, age |
| source | VARCHAR(150) | field, public\_dataset, manually\_labeled |
| description | TEXT | Nullable |
| version\_label | VARCHAR(80) | v1, v2 |
| created\_by | UUID | FK → users.user\_id |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **training\_dataset\_items**

| Field | Type | Notes |
| ----- | ----- | ----- |
| dataset\_item\_id | UUID | Primary key |
| training\_dataset\_id | UUID | FK → training\_datasets.training\_dataset\_id |
| media\_id | UUID | FK → media\_assets.media\_id, nullable |
| label\_file\_path | TEXT | Annotation file path |
| label\_format | VARCHAR(50) | YOLO, COCO, PascalVOC |
| species\_id | UUID | FK → mangrove\_species.species\_id, nullable |
| annotation\_status | VARCHAR(30) | pending, reviewed, approved |
| created\_at | TIMESTAMP |  |

---

# **G. Mangrove Tree Results**

## **mangrove\_species**

Reference table for species.

| Field | Type | Notes |
| ----- | ----- | ----- |
| species\_id | UUID | Primary key |
| scientific\_name | VARCHAR(150) | Example: Rhizophora mucronata |
| common\_name | VARCHAR(150) | Example: Bakauan |
| local\_name | VARCHAR(150) | Nullable |
| description | TEXT | Nullable |
| typical\_growth\_rate\_cm\_per\_year | NUMERIC(8,2) | Nullable |
| is\_active | BOOLEAN | Default true |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **species\_growth\_models**

Stores formulas or reference rules for age approximation.

| Field | Type | Notes |
| ----- | ----- | ----- |
| growth\_model\_id | UUID | Primary key |
| species\_id | UUID | FK → mangrove\_species.species\_id |
| model\_name | VARCHAR(150) | Height-to-age model |
| formula\_type | VARCHAR(80) | linear, polynomial, lookup\_table, custom |
| formula\_expression | TEXT | Example: age \= height\_cm / growth\_rate |
| min\_height\_meters | NUMERIC(8,2) | Nullable |
| max\_height\_meters | NUMERIC(8,2) | Nullable |
| source\_reference | TEXT | Literature/source notes |
| confidence\_notes | TEXT | Nullable |
| is\_active | BOOLEAN | Default true |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **mangrove\_tree\_entities**

Optional persistent tree identity across multiple monitoring periods.

Example: If the same tree is detected again next month, this table allows MangroScan to track growth over time.

| Field | Type | Notes |
| ----- | ----- | ----- |
| tree\_entity\_id | UUID | Primary key |
| site\_id | UUID | FK → survey\_sites.site\_id |
| persistent\_tree\_code | VARCHAR(80) | Unique |
| first\_detected\_mission\_id | UUID | FK → survey\_missions.mission\_id |
| initial\_location | GEOMETRY(Point, 4326\) |  |
| current\_status | VARCHAR(30) | alive, missing, dead, uncertain |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **tree\_observations**

This is the central result table for detected individual trees.

| Field | Type | Notes |
| ----- | ----- | ----- |
| tree\_observation\_id | UUID | Primary key |
| tree\_entity\_id | UUID | FK → mangrove\_tree\_entities.tree\_entity\_id, nullable |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| flight\_session\_id | UUID | FK → flight\_sessions.flight\_session\_id |
| model\_run\_id | UUID | FK → model\_runs.model\_run\_id, nullable |
| source\_media\_id | UUID | FK → media\_assets.media\_id, nullable |
| tree\_code | VARCHAR(80) | Mission-specific tree ID |
| tree\_location | GEOMETRY(Point, 4326\) | Geotagged tree point |
| crown\_polygon | GEOMETRY(Polygon, 4326\) | Nullable canopy/crown area |
| bounding\_box | JSONB | x, y, width, height from image |
| detection\_confidence | NUMERIC(6,4) | 0 to 1 |
| final\_species\_id | UUID | FK → mangrove\_species.species\_id, nullable |
| final\_height\_meters | NUMERIC(8,2) | Nullable |
| final\_estimated\_age\_years | NUMERIC(8,2) | Nullable |
| validation\_status | VARCHAR(30) | unvalidated, validated, corrected, rejected |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |
| deleted\_at | TIMESTAMP | Nullable |

---

## **species\_classification\_results**

Stores one or more species predictions per tree.

| Field | Type | Notes |
| ----- | ----- | ----- |
| classification\_result\_id | UUID | Primary key |
| tree\_observation\_id | UUID | FK → tree\_observations.tree\_observation\_id |
| model\_run\_id | UUID | FK → model\_runs.model\_run\_id |
| predicted\_species\_id | UUID | FK → mangrove\_species.species\_id |
| confidence\_score | NUMERIC(6,4) | 0 to 1 |
| rank\_no | INTEGER | 1 \= top prediction |
| classification\_basis | JSONB | Leaf shape, canopy texture, structure |
| is\_final | BOOLEAN | Default false |
| created\_at | TIMESTAMP |  |

---

## **canopy\_height\_estimations**

Supports LiDAR/depth/photogrammetric height estimation.

| Field | Type | Notes |
| ----- | ----- | ----- |
| height\_estimation\_id | UUID | Primary key |
| tree\_observation\_id | UUID | FK → tree\_observations.tree\_observation\_id |
| model\_run\_id | UUID | FK → model\_runs.model\_run\_id, nullable |
| method | VARCHAR(80) | lidar, stereo\_depth, photogrammetry, manual |
| height\_meters | NUMERIC(8,2) |  |
| height\_confidence\_score | NUMERIC(6,4) | Nullable |
| source\_dataset\_id | UUID | FK → sensor\_datasets.sensor\_dataset\_id, nullable |
| measurement\_notes | TEXT | Nullable |
| is\_final | BOOLEAN | Default false |
| created\_at | TIMESTAMP |  |

---

## **age\_estimations**

Age estimation is approximate and species/growth-model based, which matches the proposal limitation that actual age can vary due to environmental factors.

| Field | Type | Notes |
| ----- | ----- | ----- |
| age\_estimation\_id | UUID | Primary key |
| tree\_observation\_id | UUID | FK → tree\_observations.tree\_observation\_id |
| growth\_model\_id | UUID | FK → species\_growth\_models.growth\_model\_id |
| height\_estimation\_id | UUID | FK → canopy\_height\_estimations.height\_estimation\_id |
| estimated\_age\_years | NUMERIC(8,2) |  |
| min\_estimated\_age\_years | NUMERIC(8,2) | Nullable |
| max\_estimated\_age\_years | NUMERIC(8,2) | Nullable |
| confidence\_score | NUMERIC(6,4) | Nullable |
| assumptions | TEXT | Nullable |
| is\_final | BOOLEAN | Default false |
| created\_at | TIMESTAMP |  |

---

## **tree\_count\_summaries**

Stores aggregated count results.

| Field | Type | Notes |
| ----- | ----- | ----- |
| tree\_count\_summary\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| site\_id | UUID | FK → survey\_sites.site\_id |
| species\_id | UUID | FK → mangrove\_species.species\_id, nullable |
| model\_run\_id | UUID | FK → model\_runs.model\_run\_id, nullable |
| total\_detected\_trees | INTEGER |  |
| validated\_tree\_count | INTEGER | Nullable |
| estimated\_density\_per\_hectare | NUMERIC(12,4) | Nullable |
| count\_confidence\_score | NUMERIC(6,4) | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

# **H. Field Validation and Accuracy**

## **validation\_sessions**

| Field | Type | Notes |
| ----- | ----- | ----- |
| validation\_session\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| site\_id | UUID | FK → survey\_sites.site\_id |
| plot\_id | UUID | FK → monitoring\_plots.plot\_id, nullable |
| validated\_by | UUID | FK → users.user\_id |
| validation\_date | DATE |  |
| method | VARCHAR(80) | ground\_survey, expert\_review, sample\_plot |
| notes | TEXT | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **ground\_truth\_tree\_records**

Stores manually verified trees.

| Field | Type | Notes |
| ----- | ----- | ----- |
| ground\_truth\_id | UUID | Primary key |
| validation\_session\_id | UUID | FK → validation\_sessions.validation\_session\_id |
| species\_id | UUID | FK → mangrove\_species.species\_id, nullable |
| ground\_location | GEOMETRY(Point, 4326\) |  |
| measured\_height\_meters | NUMERIC(8,2) | Nullable |
| estimated\_age\_years | NUMERIC(8,2) | Nullable |
| diameter\_cm | NUMERIC(8,2) | Nullable |
| health\_status | VARCHAR(50) | healthy, stressed, dead, unknown |
| photo\_path | TEXT | Nullable |
| remarks | TEXT | Nullable |
| created\_at | TIMESTAMP |  |

---

## **validation\_matches**

Links AI result to ground-truth record.

| Field | Type | Notes |
| ----- | ----- | ----- |
| validation\_match\_id | UUID | Primary key |
| ground\_truth\_id | UUID | FK → ground\_truth\_tree\_records.ground\_truth\_id |
| tree\_observation\_id | UUID | FK → tree\_observations.tree\_observation\_id |
| match\_status | VARCHAR(30) | matched, false\_positive, false\_negative, corrected |
| distance\_error\_meters | NUMERIC(10,4) | Nullable |
| species\_correct | BOOLEAN | Nullable |
| height\_error\_meters | NUMERIC(10,4) | Nullable |
| age\_error\_years | NUMERIC(10,4) | Nullable |
| validated\_by | UUID | FK → users.user\_id |
| validated\_at | TIMESTAMP |  |

---

## **accuracy\_metrics**

| Field | Type | Notes |
| ----- | ----- | ----- |
| accuracy\_metric\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| model\_version\_id | UUID | FK → ai\_model\_versions.model\_version\_id, nullable |
| metric\_type | VARCHAR(80) | species\_accuracy, count\_precision, height\_rmse, age\_mae |
| metric\_value | NUMERIC(12,6) |  |
| sample\_size | INTEGER | Nullable |
| computed\_at | TIMESTAMP |  |
| notes | TEXT | Nullable |

---

# **I. Reports, Exports, Dashboard, Settings**

## **reports**

| Field | Type | Notes |
| ----- | ----- | ----- |
| report\_id | UUID | Primary key |
| mission\_id | UUID | FK → survey\_missions.mission\_id |
| site\_id | UUID | FK → survey\_sites.site\_id |
| report\_title | VARCHAR(200) |  |
| report\_type | VARCHAR(80) | monitoring\_summary, validation\_report, species\_report |
| report\_status | VARCHAR(30) | draft, generated, approved, archived |
| generated\_by | UUID | FK → users.user\_id |
| approved\_by | UUID | FK → users.user\_id, nullable |
| summary | TEXT | Nullable |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **exported\_files**

The proposal requires dashboard/mobile export of results and georeferenced data.

| Field | Type | Notes |
| ----- | ----- | ----- |
| export\_file\_id | UUID | Primary key |
| report\_id | UUID | FK → reports.report\_id, nullable |
| mission\_id | UUID | FK → survey\_missions.mission\_id, nullable |
| export\_type | VARCHAR(50) | PDF, CSV, GeoJSON, KML, SHP, XLSX |
| file\_name | VARCHAR(255) |  |
| file\_path | TEXT |  |
| file\_size\_bytes | BIGINT | Nullable |
| exported\_by | UUID | FK → users.user\_id |
| exported\_at | TIMESTAMP |  |

---

## **dashboard\_saved\_views**

| Field | Type | Notes |
| ----- | ----- | ----- |
| saved\_view\_id | UUID | Primary key |
| user\_id | UUID | FK → users.user\_id |
| view\_name | VARCHAR(150) |  |
| site\_id | UUID | FK → survey\_sites.site\_id, nullable |
| mission\_id | UUID | FK → survey\_missions.mission\_id, nullable |
| filter\_config | JSONB | Species, date range, height range |
| map\_config | JSONB | Zoom, layers, center |
| created\_at | TIMESTAMP |  |
| updated\_at | TIMESTAMP |  |

---

## **notification\_logs**

| Field | Type | Notes |
| ----- | ----- | ----- |
| notification\_id | UUID | Primary key |
| user\_id | UUID | FK → users.user\_id |
| notification\_type | VARCHAR(80) | mission\_completed, processing\_failed, report\_ready |
| title | VARCHAR(150) |  |
| message | TEXT |  |
| is\_read | BOOLEAN | Default false |
| created\_at | TIMESTAMP |  |

---

## **system\_settings**

| Field | Type | Notes |
| ----- | ----- | ----- |
| setting\_id | UUID | Primary key |
| setting\_key | VARCHAR(100) | Unique |
| setting\_value | TEXT |  |
| setting\_group | VARCHAR(80) | ai, flight, export, dashboard |
| description | TEXT | Nullable |
| updated\_by | UUID | FK → users.user\_id, nullable |
| updated\_at | TIMESTAMP |  |

---

# **4\. Primary Keys and Foreign Keys**

## **Major Primary Keys**

| Table | Primary Key |
| ----- | ----- |
| organizations | organization\_id |
| users | user\_id |
| survey\_sites | site\_id |
| survey\_missions | mission\_id |
| flight\_sessions | flight\_session\_id |
| media\_assets | media\_id |
| sensor\_datasets | sensor\_dataset\_id |
| processing\_jobs | processing\_job\_id |
| model\_runs | model\_run\_id |
| tree\_observations | tree\_observation\_id |
| validation\_sessions | validation\_session\_id |
| reports | report\_id |

## **Important Foreign Key Relationships**

| Parent | Child | Relationship |
| ----- | ----- | ----- |
| organizations | users | One organization has many users |
| organizations | survey\_sites | One organization can manage many sites |
| survey\_sites | survey\_missions | One site can have many missions |
| survey\_missions | flight\_sessions | One mission can have many drone flights |
| flight\_sessions | media\_assets | One flight captures many images/videos |
| flight\_sessions | sensor\_datasets | One flight captures many sensor datasets |
| survey\_missions | processing\_jobs | One mission can have many processing jobs |
| processing\_jobs | model\_runs | One job can run multiple AI models |
| model\_runs | tree\_observations | One detection model run can produce many tree observations |
| tree\_observations | species\_classification\_results | One tree can have multiple species predictions |
| tree\_observations | canopy\_height\_estimations | One tree can have multiple height estimates |
| tree\_observations | age\_estimations | One tree can have multiple age estimates |
| survey\_missions | validation\_sessions | One mission can have many validation sessions |
| validation\_sessions | ground\_truth\_tree\_records | One validation session records many ground-truth trees |
| ground\_truth\_tree\_records | validation\_matches | One ground-truth tree can be matched to AI observation |
| survey\_missions | reports | One mission can generate many reports |
| reports | exported\_files | One report can have many exported files |

---

# **5\. Suggested Indexes**

## **Security and User Indexes**

CREATE UNIQUE INDEX idx\_users\_email ON users(email);  
CREATE INDEX idx\_users\_organization\_id ON users(organization\_id);  
CREATE INDEX idx\_audit\_logs\_user\_id ON audit\_logs(user\_id);  
CREATE INDEX idx\_audit\_logs\_table\_record ON audit\_logs(table\_name, record\_id);

## **Survey and Mission Indexes**

CREATE UNIQUE INDEX idx\_survey\_sites\_site\_code ON survey\_sites(site\_code);  
CREATE INDEX idx\_survey\_sites\_org ON survey\_sites(organization\_id);  
CREATE INDEX idx\_survey\_missions\_site ON survey\_missions(site\_id);  
CREATE UNIQUE INDEX idx\_survey\_missions\_code ON survey\_missions(mission\_code);  
CREATE INDEX idx\_flight\_sessions\_mission ON flight\_sessions(mission\_id);  
CREATE INDEX idx\_flight\_sessions\_drone ON flight\_sessions(drone\_id);

## **Geospatial Indexes**

CREATE INDEX idx\_survey\_sites\_center\_point   
ON survey\_sites USING GIST(center\_point);

CREATE INDEX idx\_site\_boundaries\_geom   
ON site\_boundaries USING GIST(boundary\_geom);

CREATE INDEX idx\_tree\_observations\_location   
ON tree\_observations USING GIST(tree\_location);

CREATE INDEX idx\_tree\_observations\_crown\_polygon   
ON tree\_observations USING GIST(crown\_polygon);

CREATE INDEX idx\_media\_assets\_capture\_location   
ON media\_assets USING GIST(capture\_location);

## **AI and Result Indexes**

CREATE INDEX idx\_processing\_jobs\_mission ON processing\_jobs(mission\_id);  
CREATE INDEX idx\_model\_runs\_job ON model\_runs(processing\_job\_id);  
CREATE INDEX idx\_tree\_observations\_mission ON tree\_observations(mission\_id);  
CREATE INDEX idx\_tree\_observations\_species ON tree\_observations(final\_species\_id);  
CREATE INDEX idx\_species\_classification\_tree ON species\_classification\_results(tree\_observation\_id);  
CREATE INDEX idx\_height\_estimations\_tree ON canopy\_height\_estimations(tree\_observation\_id);  
CREATE INDEX idx\_age\_estimations\_tree ON age\_estimations(tree\_observation\_id);

## **Validation and Reporting Indexes**

CREATE INDEX idx\_validation\_sessions\_mission ON validation\_sessions(mission\_id);  
CREATE INDEX idx\_ground\_truth\_validation ON ground\_truth\_tree\_records(validation\_session\_id);  
CREATE INDEX idx\_validation\_matches\_tree ON validation\_matches(tree\_observation\_id);  
CREATE INDEX idx\_reports\_mission ON reports(mission\_id);  
CREATE INDEX idx\_exported\_files\_report ON exported\_files(report\_id);

---

# **6\. Entity Relationship Diagram Explanation**

You can explain the ERD this way in your capstone manuscript:

The MangroScan database follows a modular relational design. The organizations, users, roles, and permissions tables handle identity management and role-based access control. Each organization may manage multiple survey\_sites, and each site may contain one or more site\_boundaries and monitoring\_plots.

For operational workflow, each survey\_site can have multiple survey\_missions. A mission represents one monitoring activity for a specific location and period. Each mission may include multiple flight\_sessions because the drone may need several sorties to cover the full survey area. Each flight records waypoints, environmental conditions, checklist data, captured images, and sensor datasets.

Captured data from media\_assets and sensor\_datasets are processed through processing\_jobs. Each processing job can run one or more AI model versions through model\_runs. The outputs are stored in tree\_observations, which represent geotagged individual mangrove trees detected by the system. Each tree observation can have species classification results, canopy height estimations, and age estimations.

Field validation is handled separately through validation\_sessions, ground\_truth\_tree\_records, and validation\_matches. This allows researchers or environmental scientists to compare AI results against actual field measurements. Accuracy results are stored in accuracy\_metrics.

Finally, the system supports dashboard and report outputs through geospatial\_layers, reports, exported\_files, and dashboard\_saved\_views. All major actions are tracked in audit\_logs for security, transparency, and defense-readiness.

---

# **7\. Optional SQL CREATE TABLE Statements**

Below is a PostgreSQL/PostGIS starter DDL. This is suitable for capstone documentation and can be converted to MySQL if needed.

CREATE EXTENSION IF NOT EXISTS "pgcrypto";  
CREATE EXTENSION IF NOT EXISTS postgis;

## **Core User Tables**

CREATE TABLE organizations (  
    organization\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    organization\_name VARCHAR(150) NOT NULL,  
    organization\_type VARCHAR(50) NOT NULL,  
    contact\_email VARCHAR(150),  
    contact\_number VARCHAR(50),  
    address TEXT,  
    status VARCHAR(30) NOT NULL DEFAULT 'active',  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ  
);

CREATE TABLE users (  
    user\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    organization\_id UUID REFERENCES organizations(organization\_id),  
    first\_name VARCHAR(100) NOT NULL,  
    last\_name VARCHAR(100) NOT NULL,  
    email VARCHAR(150) NOT NULL UNIQUE,  
    password\_hash TEXT NOT NULL,  
    contact\_number VARCHAR(50),  
    position\_title VARCHAR(100),  
    profile\_photo\_path TEXT,  
    is\_active BOOLEAN NOT NULL DEFAULT TRUE,  
    last\_login\_at TIMESTAMPTZ,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ  
);

CREATE TABLE roles (  
    role\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    role\_name VARCHAR(80) NOT NULL UNIQUE,  
    description TEXT,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE permissions (  
    permission\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    permission\_code VARCHAR(100) NOT NULL UNIQUE,  
    permission\_name VARCHAR(150) NOT NULL,  
    description TEXT  
);

CREATE TABLE role\_permissions (  
    role\_id UUID NOT NULL REFERENCES roles(role\_id) ON DELETE CASCADE,  
    permission\_id UUID NOT NULL REFERENCES permissions(permission\_id) ON DELETE CASCADE,  
    PRIMARY KEY (role\_id, permission\_id)  
);

CREATE TABLE user\_roles (  
    user\_id UUID NOT NULL REFERENCES users(user\_id) ON DELETE CASCADE,  
    role\_id UUID NOT NULL REFERENCES roles(role\_id) ON DELETE CASCADE,  
    PRIMARY KEY (user\_id, role\_id)  
);

## **Survey Site and Mission Tables**

CREATE TABLE survey\_sites (  
    site\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    organization\_id UUID NOT NULL REFERENCES organizations(organization\_id),  
    site\_name VARCHAR(150) NOT NULL,  
    site\_code VARCHAR(50) NOT NULL UNIQUE,  
    description TEXT,  
    province VARCHAR(100),  
    city\_municipality VARCHAR(100),  
    barangay VARCHAR(100),  
    center\_point GEOMETRY(Point, 4326),  
    area\_hectares NUMERIC(12,4),  
    environment\_type VARCHAR(80),  
    access\_notes TEXT,  
    status VARCHAR(30) NOT NULL DEFAULT 'active',  
    created\_by UUID REFERENCES users(user\_id),  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ  
);

CREATE TABLE site\_boundaries (  
    boundary\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    site\_id UUID NOT NULL REFERENCES survey\_sites(site\_id) ON DELETE CASCADE,  
    boundary\_name VARCHAR(150) NOT NULL,  
    boundary\_type VARCHAR(50) NOT NULL,  
    boundary\_geom GEOMETRY(Polygon, 4326\) NOT NULL,  
    source VARCHAR(100),  
    created\_by UUID REFERENCES users(user\_id),  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE survey\_missions (  
    mission\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    site\_id UUID NOT NULL REFERENCES survey\_sites(site\_id),  
    mission\_code VARCHAR(50) NOT NULL UNIQUE,  
    mission\_title VARCHAR(150) NOT NULL,  
    mission\_objective TEXT,  
    planned\_start\_at TIMESTAMPTZ,  
    planned\_end\_at TIMESTAMPTZ,  
    actual\_start\_at TIMESTAMPTZ,  
    actual\_end\_at TIMESTAMPTZ,  
    mission\_status VARCHAR(30) NOT NULL DEFAULT 'planned',  
    coverage\_target\_hectares NUMERIC(12,4),  
    coverage\_completed\_hectares NUMERIC(12,4),  
    created\_by UUID REFERENCES users(user\_id),  
    approved\_by UUID REFERENCES users(user\_id),  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ,  
    CHECK (mission\_status IN ('planned', 'in\_progress', 'completed', 'cancelled', 'failed'))  
);

## **Drone and Flight Tables**

CREATE TABLE drones (  
    drone\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    organization\_id UUID NOT NULL REFERENCES organizations(organization\_id),  
    drone\_name VARCHAR(100) NOT NULL,  
    model VARCHAR(100),  
    serial\_number VARCHAR(100) UNIQUE,  
    firmware\_version VARCHAR(80),  
    max\_flight\_minutes NUMERIC(5,2),  
    payload\_capacity\_grams NUMERIC(8,2),  
    status VARCHAR(30) NOT NULL DEFAULT 'available',  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ  
);

CREATE TABLE drone\_sensors (  
    sensor\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    drone\_id UUID NOT NULL REFERENCES drones(drone\_id) ON DELETE CASCADE,  
    sensor\_name VARCHAR(100) NOT NULL,  
    sensor\_type VARCHAR(50) NOT NULL,  
    manufacturer VARCHAR(100),  
    model VARCHAR(100),  
    serial\_number VARCHAR(100),  
    resolution VARCHAR(80),  
    range\_meters NUMERIC(8,2),  
    calibration\_required BOOLEAN NOT NULL DEFAULT FALSE,  
    status VARCHAR(30) NOT NULL DEFAULT 'active',  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE flight\_sessions (  
    flight\_session\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    mission\_id UUID NOT NULL REFERENCES survey\_missions(mission\_id),  
    drone\_id UUID NOT NULL REFERENCES drones(drone\_id),  
    pilot\_user\_id UUID REFERENCES users(user\_id),  
    flight\_code VARCHAR(50) NOT NULL UNIQUE,  
    takeoff\_location GEOMETRY(Point, 4326),  
    landing\_location GEOMETRY(Point, 4326),  
    planned\_altitude\_meters NUMERIC(8,2),  
    actual\_avg\_altitude\_meters NUMERIC(8,2),  
    started\_at TIMESTAMPTZ,  
    ended\_at TIMESTAMPTZ,  
    flight\_duration\_minutes NUMERIC(8,2),  
    flight\_status VARCHAR(30) NOT NULL DEFAULT 'planned',  
    quality\_status VARCHAR(30) NOT NULL DEFAULT 'pending',  
    notes TEXT,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    CHECK (flight\_status IN ('planned', 'flying', 'completed', 'aborted', 'failed')),  
    CHECK (quality\_status IN ('pending', 'acceptable', 'rejected', 'needs\_recapture'))  
);

## **Captured Data Tables**

CREATE TABLE media\_assets (  
    media\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    flight\_session\_id UUID NOT NULL REFERENCES flight\_sessions(flight\_session\_id),  
    sensor\_id UUID REFERENCES drone\_sensors(sensor\_id),  
    file\_name VARCHAR(255) NOT NULL,  
    file\_path TEXT NOT NULL,  
    file\_type VARCHAR(50) NOT NULL,  
    mime\_type VARCHAR(100),  
    file\_size\_bytes BIGINT,  
    captured\_at TIMESTAMPTZ,  
    capture\_location GEOMETRY(Point, 4326),  
    altitude\_meters NUMERIC(8,2),  
    camera\_pitch\_deg NUMERIC(8,2),  
    camera\_yaw\_deg NUMERIC(8,2),  
    image\_width INTEGER,  
    image\_height INTEGER,  
    quality\_score NUMERIC(5,4),  
    quality\_status VARCHAR(30) NOT NULL DEFAULT 'pending',  
    metadata JSONB,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ,  
    CHECK (quality\_score IS NULL OR quality\_score BETWEEN 0 AND 1\)  
);

CREATE TABLE sensor\_datasets (  
    sensor\_dataset\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    flight\_session\_id UUID NOT NULL REFERENCES flight\_sessions(flight\_session\_id),  
    sensor\_id UUID NOT NULL REFERENCES drone\_sensors(sensor\_id),  
    dataset\_type VARCHAR(50) NOT NULL,  
    file\_name VARCHAR(255) NOT NULL,  
    file\_path TEXT NOT NULL,  
    file\_format VARCHAR(50),  
    recorded\_start\_at TIMESTAMPTZ,  
    recorded\_end\_at TIMESTAMPTZ,  
    spatial\_reference VARCHAR(80),  
    metadata JSONB,  
    quality\_status VARCHAR(30) NOT NULL DEFAULT 'pending',  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ  
);

## **AI Model and Processing Tables**

CREATE TABLE ai\_models (  
    model\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    model\_name VARCHAR(150) NOT NULL,  
    model\_type VARCHAR(80) NOT NULL,  
    framework VARCHAR(80),  
    description TEXT,  
    created\_by UUID REFERENCES users(user\_id),  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ  
);

CREATE TABLE training\_datasets (  
    training\_dataset\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    dataset\_name VARCHAR(150) NOT NULL,  
    dataset\_type VARCHAR(80) NOT NULL,  
    source VARCHAR(150),  
    description TEXT,  
    version\_label VARCHAR(80),  
    created\_by UUID REFERENCES users(user\_id),  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE ai\_model\_versions (  
    model\_version\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    model\_id UUID NOT NULL REFERENCES ai\_models(model\_id),  
    version\_label VARCHAR(80) NOT NULL,  
    model\_file\_path TEXT NOT NULL,  
    training\_dataset\_id UUID REFERENCES training\_datasets(training\_dataset\_id),  
    accuracy NUMERIC(6,4),  
    precision\_score NUMERIC(6,4),  
    recall\_score NUMERIC(6,4),  
    f1\_score NUMERIC(6,4),  
    rmse NUMERIC(10,4),  
    is\_deployed BOOLEAN NOT NULL DEFAULT FALSE,  
    release\_notes TEXT,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    UNIQUE (model\_id, version\_label)  
);

CREATE TABLE processing\_jobs (  
    processing\_job\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    mission\_id UUID NOT NULL REFERENCES survey\_missions(mission\_id),  
    flight\_session\_id UUID REFERENCES flight\_sessions(flight\_session\_id),  
    job\_type VARCHAR(80) NOT NULL,  
    job\_status VARCHAR(30) NOT NULL DEFAULT 'queued',  
    input\_summary JSONB,  
    output\_summary JSONB,  
    started\_at TIMESTAMPTZ,  
    completed\_at TIMESTAMPTZ,  
    error\_message TEXT,  
    created\_by UUID REFERENCES users(user\_id),  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    CHECK (job\_status IN ('queued', 'running', 'completed', 'failed'))  
);

CREATE TABLE model\_runs (  
    model\_run\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    processing\_job\_id UUID NOT NULL REFERENCES processing\_jobs(processing\_job\_id),  
    model\_version\_id UUID NOT NULL REFERENCES ai\_model\_versions(model\_version\_id),  
    run\_type VARCHAR(80) NOT NULL,  
    input\_media\_id UUID REFERENCES media\_assets(media\_id),  
    input\_dataset\_id UUID REFERENCES sensor\_datasets(sensor\_dataset\_id),  
    parameters JSONB,  
    started\_at TIMESTAMPTZ,  
    completed\_at TIMESTAMPTZ,  
    run\_status VARCHAR(30) NOT NULL DEFAULT 'queued',  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

## **Species and Tree Result Tables**

CREATE TABLE mangrove\_species (  
    species\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    scientific\_name VARCHAR(150) NOT NULL,  
    common\_name VARCHAR(150),  
    local\_name VARCHAR(150),  
    description TEXT,  
    typical\_growth\_rate\_cm\_per\_year NUMERIC(8,2),  
    is\_active BOOLEAN NOT NULL DEFAULT TRUE,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE species\_growth\_models (  
    growth\_model\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    species\_id UUID NOT NULL REFERENCES mangrove\_species(species\_id),  
    model\_name VARCHAR(150) NOT NULL,  
    formula\_type VARCHAR(80) NOT NULL,  
    formula\_expression TEXT NOT NULL,  
    min\_height\_meters NUMERIC(8,2),  
    max\_height\_meters NUMERIC(8,2),  
    source\_reference TEXT,  
    confidence\_notes TEXT,  
    is\_active BOOLEAN NOT NULL DEFAULT TRUE,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE mangrove\_tree\_entities (  
    tree\_entity\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    site\_id UUID NOT NULL REFERENCES survey\_sites(site\_id),  
    persistent\_tree\_code VARCHAR(80) NOT NULL UNIQUE,  
    first\_detected\_mission\_id UUID REFERENCES survey\_missions(mission\_id),  
    initial\_location GEOMETRY(Point, 4326\) NOT NULL,  
    current\_status VARCHAR(30) NOT NULL DEFAULT 'alive',  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ  
);

CREATE TABLE tree\_observations (  
    tree\_observation\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    tree\_entity\_id UUID REFERENCES mangrove\_tree\_entities(tree\_entity\_id),  
    mission\_id UUID NOT NULL REFERENCES survey\_missions(mission\_id),  
    flight\_session\_id UUID NOT NULL REFERENCES flight\_sessions(flight\_session\_id),  
    model\_run\_id UUID REFERENCES model\_runs(model\_run\_id),  
    source\_media\_id UUID REFERENCES media\_assets(media\_id),  
    tree\_code VARCHAR(80) NOT NULL,  
    tree\_location GEOMETRY(Point, 4326\) NOT NULL,  
    crown\_polygon GEOMETRY(Polygon, 4326),  
    bounding\_box JSONB,  
    detection\_confidence NUMERIC(6,4),  
    final\_species\_id UUID REFERENCES mangrove\_species(species\_id),  
    final\_height\_meters NUMERIC(8,2),  
    final\_estimated\_age\_years NUMERIC(8,2),  
    validation\_status VARCHAR(30) NOT NULL DEFAULT 'unvalidated',  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    deleted\_at TIMESTAMPTZ,  
    UNIQUE (mission\_id, tree\_code),  
    CHECK (detection\_confidence IS NULL OR detection\_confidence BETWEEN 0 AND 1\)  
);

CREATE TABLE species\_classification\_results (  
    classification\_result\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    tree\_observation\_id UUID NOT NULL REFERENCES tree\_observations(tree\_observation\_id) ON DELETE CASCADE,  
    model\_run\_id UUID NOT NULL REFERENCES model\_runs(model\_run\_id),  
    predicted\_species\_id UUID NOT NULL REFERENCES mangrove\_species(species\_id),  
    confidence\_score NUMERIC(6,4) NOT NULL,  
    rank\_no INTEGER NOT NULL DEFAULT 1,  
    classification\_basis JSONB,  
    is\_final BOOLEAN NOT NULL DEFAULT FALSE,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    CHECK (confidence\_score BETWEEN 0 AND 1\)  
);

CREATE TABLE canopy\_height\_estimations (  
    height\_estimation\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    tree\_observation\_id UUID NOT NULL REFERENCES tree\_observations(tree\_observation\_id) ON DELETE CASCADE,  
    model\_run\_id UUID REFERENCES model\_runs(model\_run\_id),  
    method VARCHAR(80) NOT NULL,  
    height\_meters NUMERIC(8,2) NOT NULL,  
    height\_confidence\_score NUMERIC(6,4),  
    source\_dataset\_id UUID REFERENCES sensor\_datasets(sensor\_dataset\_id),  
    measurement\_notes TEXT,  
    is\_final BOOLEAN NOT NULL DEFAULT FALSE,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    CHECK (height\_meters \>= 0),  
    CHECK (height\_confidence\_score IS NULL OR height\_confidence\_score BETWEEN 0 AND 1\)  
);

CREATE TABLE age\_estimations (  
    age\_estimation\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    tree\_observation\_id UUID NOT NULL REFERENCES tree\_observations(tree\_observation\_id) ON DELETE CASCADE,  
    growth\_model\_id UUID NOT NULL REFERENCES species\_growth\_models(growth\_model\_id),  
    height\_estimation\_id UUID NOT NULL REFERENCES canopy\_height\_estimations(height\_estimation\_id),  
    estimated\_age\_years NUMERIC(8,2) NOT NULL,  
    min\_estimated\_age\_years NUMERIC(8,2),  
    max\_estimated\_age\_years NUMERIC(8,2),  
    confidence\_score NUMERIC(6,4),  
    assumptions TEXT,  
    is\_final BOOLEAN NOT NULL DEFAULT FALSE,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    CHECK (estimated\_age\_years \>= 0),  
    CHECK (confidence\_score IS NULL OR confidence\_score BETWEEN 0 AND 1\)  
);

## **Validation and Reporting Tables**

CREATE TABLE validation\_sessions (  
    validation\_session\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    mission\_id UUID NOT NULL REFERENCES survey\_missions(mission\_id),  
    site\_id UUID NOT NULL REFERENCES survey\_sites(site\_id),  
    plot\_id UUID,  
    validated\_by UUID NOT NULL REFERENCES users(user\_id),  
    validation\_date DATE NOT NULL,  
    method VARCHAR(80) NOT NULL,  
    notes TEXT,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE ground\_truth\_tree\_records (  
    ground\_truth\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    validation\_session\_id UUID NOT NULL REFERENCES validation\_sessions(validation\_session\_id) ON DELETE CASCADE,  
    species\_id UUID REFERENCES mangrove\_species(species\_id),  
    ground\_location GEOMETRY(Point, 4326\) NOT NULL,  
    measured\_height\_meters NUMERIC(8,2),  
    estimated\_age\_years NUMERIC(8,2),  
    diameter\_cm NUMERIC(8,2),  
    health\_status VARCHAR(50),  
    photo\_path TEXT,  
    remarks TEXT,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE validation\_matches (  
    validation\_match\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    ground\_truth\_id UUID NOT NULL REFERENCES ground\_truth\_tree\_records(ground\_truth\_id) ON DELETE CASCADE,  
    tree\_observation\_id UUID REFERENCES tree\_observations(tree\_observation\_id),  
    match\_status VARCHAR(30) NOT NULL,  
    distance\_error\_meters NUMERIC(10,4),  
    species\_correct BOOLEAN,  
    height\_error\_meters NUMERIC(10,4),  
    age\_error\_years NUMERIC(10,4),  
    validated\_by UUID NOT NULL REFERENCES users(user\_id),  
    validated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE reports (  
    report\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    mission\_id UUID NOT NULL REFERENCES survey\_missions(mission\_id),  
    site\_id UUID NOT NULL REFERENCES survey\_sites(site\_id),  
    report\_title VARCHAR(200) NOT NULL,  
    report\_type VARCHAR(80) NOT NULL,  
    report\_status VARCHAR(30) NOT NULL DEFAULT 'draft',  
    generated\_by UUID REFERENCES users(user\_id),  
    approved\_by UUID REFERENCES users(user\_id),  
    summary TEXT,  
    created\_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),  
    updated\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

CREATE TABLE exported\_files (  
    export\_file\_id UUID PRIMARY KEY DEFAULT gen\_random\_uuid(),  
    report\_id UUID REFERENCES reports(report\_id),  
    mission\_id UUID REFERENCES survey\_missions(mission\_id),  
    export\_type VARCHAR(50) NOT NULL,  
    file\_name VARCHAR(255) NOT NULL,  
    file\_path TEXT NOT NULL,  
    file\_size\_bytes BIGINT,  
    exported\_by UUID REFERENCES users(user\_id),  
    exported\_at TIMESTAMPTZ NOT NULL DEFAULT NOW()  
);

---

# **8\. Scalability, Security, and Future Improvements**

## **Scalability Notes**

1. **Multiple sites supported**  
   survey\_sites allows MangroScan to support Foundation University sites, LGU sites, DENR sites, and future coastal areas.  
2. **Repeated monitoring supported**  
   survey\_missions and tree\_observations allow the same site to be monitored weekly, monthly, or yearly.  
3. **Multiple drones supported**  
   drones, drone\_sensors, and flight\_sessions support different drones, cameras, LiDAR sensors, and depth modules.  
4. **Multiple AI models supported**  
   ai\_models and ai\_model\_versions allow the team to compare YOLO v1, YOLO v2, future CNN classifiers, and improved height/age estimators.  
5. **Ground-truth validation supported**  
   validation\_sessions, ground\_truth\_tree\_records, and validation\_matches make the system defensible during thesis evaluation because accuracy can be measured objectively.  
6. **Future startup expansion supported**  
   This schema can later support subscription organizations, cloud storage, multi-tenant dashboards, paid reports, API integrations, and automated map exports.

## **Security Notes**

Recommended security features:

* Store only hashed passwords using bcrypt or Argon2.  
* Use role-based access control through roles, permissions, role\_permissions, and user\_roles.  
* Keep audit\_logs immutable.  
* Restrict export access to authorized users only.  
* Soft-delete important records using deleted\_at.  
* Validate uploaded file types for images, GeoJSON, CSV, LiDAR files, and reports.  
* Separate public dashboard viewing from admin/validation access.  
* Add backup strategy for database and media storage.  
* Store large files in object storage or server folders; store only file paths and metadata in the database.

## **Future Improvements**

For future versions, you can add:

| Future Feature | Suggested Table |
| ----- | ----- |
| Carbon stock estimation | carbon\_stock\_estimations |
| Mangrove health scoring | tree\_health\_assessments |
| Disease/stress detection | stress\_detection\_results |
| Offline mobile sync | sync\_queue, device\_sessions |
| Public portal | public\_map\_shares |
| API access | api\_keys, api\_usage\_logs |
| Subscription/startup billing | plans, subscriptions, billing\_records |
| Human annotation workflow | annotation\_tasks, annotation\_reviews |

---

## **Final Recommendation**

For your capstone manuscript, present the database as a **modular geospatial-AI monitoring database**. The strongest defense point is that your schema does not only store final results. It stores the full evidence chain:

**site → mission → flight → captured image/sensor data → AI model version → tree observation → species/height/age/count result → field validation → report/export**

That makes the schema strong enough for thesis defense and realistic enough for a future MangroScan startup implementation.

