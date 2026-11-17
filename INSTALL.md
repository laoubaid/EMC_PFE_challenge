# EMC Carpooling Platform - Installation & User Guide

Welcome to the **EMC Carpooling Platform** (PFE Challenge). This application is a regional multi-stop carpooling platform built with **Symfony 7.4**, **PHP 8.2+ / 8.4**, **Doctrine ORM**, **OpenRouteService (ORS) Directions API**, and **Leaflet.js** with OpenStreetMap.

---

## 📋 Table of Contents
1. [Prerequisites & System Requirements](#-prerequisites--system-requirements)
2. [Quick Start with Docker (Recommended)](#-quick-start-with-docker-recommended)
3. [Manual / Native Local Setup](#-manual--native-local-setup)
4. [Environment Configuration](#-environment-configuration)
5. [OpenRouteService (ORS) Routing Integration](#-openrouteservice-ors-routing-integration)
6. [Demo Accounts & Credentials](#-demo-accounts--credentials)
7. [Pre-Seeded Scenarios to Test](#-pre-seeded-scenarios-to-test)
8. [Makefile CLI Reference](#-makefile-cli-reference)
9. [Running Automated Tests](#-running-automated-tests)

---

## 💻 Prerequisites & System Requirements

### Option A: Docker (Recommended)
- **Docker Engine** (version 24.0 or newer)
- **Docker Compose** (version 2.20 or newer)
- **GNU Make** (standard on macOS and Linux; optional on Windows via WSL or Git Bash)

### Option B: Native Host Setup (Without Docker)
- **PHP 8.2+** or **PHP 8.4**
- Required PHP extensions:
  - `pdo_mysql` (or `pdo_sqlite` for SQLite development)
  - `intl`
  - `zip`
  - `opcache`
- **Composer 2.x**
- **MySQL 8.0+** (or SQLite for lightweight local development)

---

## 🚀 Quick Start with Docker (Recommended)

1. **Clone the repository:**
   ```bash
   git clone <repository-url>
   cd EMC_PFE_challenge
   ```

2. **Initialize Environment Configuration:**
   ```bash
   make install
   # Or manually: cp .env.example .env
   ```
   *Note: Edit `.env` to supply your `ORS_API_KEY` (see [OpenRouteService Configuration](#-openrouteservice-ors-routing-integration)).*

3. **Build and start Docker containers:**
   ```bash
   make build
   make up
   ```
   This spins up:
   - `app`: PHP 8.4 web server listening on `http://localhost:8000`
   - `database`: MySQL 8.0 server listening on `localhost:3306`

4. **Initialize Database and Seed Demo Data:**
   ```bash
   make db-reset
   ```
   This command applies all pending Doctrine migrations and seeds realistic Moroccan cities, demo users, multi-stop trips, bookings, and route geometries.

5. **Open the application:**
   Visit [http://localhost:8000](http://localhost:8000) in your web browser.

---

## 🛠 Manual / Native Local Setup

If you prefer running PHP directly on your host machine without Docker:

1. **Install dependencies:**
   ```bash
   composer install
   ```

2. **Configure environment:**
   ```bash
   cp .env.example .env
   ```
   For SQLite (zero server setup):
   ```ini
   DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
   ```
   Or for local MySQL:
   ```ini
   DATABASE_URL="mysql://root:root@127.0.0.1:3306/emc_pfe?serverVersion=8.0.39&charset=utf8mb4"
   ```

3. **Run database migrations & seed fixtures:**
   ```bash
   php bin/console doctrine:migrations:migrate --no-interaction
   php bin/console app:seed --purge
   ```

4. **Start the local PHP server:**
   ```bash
   php -S 127.0.0.1:8000 -t public
   ```
   Navigate to [http://localhost:8000](http://localhost:8000).

---

## ⚙️ Environment Configuration

Configuration is managed via environment variables in `.env` (or `.env.local` for local overrides).

| Variable | Default Value | Description |
| :--- | :--- | :--- |
| `APP_ENV` | `dev` | Symfony environment (`dev`, `test`, `prod`). |
| `APP_SECRET` | *(random 32-char hex)* | Secret key used for CSRF and signing tokens. |
| `DATABASE_URL` | `mysql://root:root@database:3306/emc_pfe` | Doctrine database connection string. |
| `MYSQL_ROOT_PASSWORD` | `root` | Root password for the MySQL container in Docker. |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Transport for asynchronous queue events. |
| `ORS_API_KEY` | *(your OpenRouteService key)* | OpenRouteService Directions API key. |
| `ORS_BASE_URL` | `https://api.openrouteservice.org` | OpenRouteService endpoint (supports self-hosted ORS). |
| `DEFAULT_URI` | `http://localhost:8000` | Base URI for CLI URL generation. |

---

## 🗺 OpenRouteService (ORS) Routing Integration

The platform integrates with **OpenRouteService Directions v2 API** (`driving-car`) to automate routing, driving durations, and visual map rendering:

### 1. Cumulative Calculation & Subtraction
- When a driver plans a trip (e.g. **Casablanca → Marrakech → Agadir**), the backend makes **one single ORS call**.
- Cumulative distance (`km`) and driving duration (`minutes`) are stored on each stop (`Waypoint`).
- Any passenger sub-segment (e.g. **Marrakech → Agadir**) is derived instantly via pure subtraction:
  $$\text{Distance} = \text{Stop}_{B}.\text{dist} - \text{Stop}_{A}.\text{dist}$$
  $$\text{Duration} = \text{Stop}_{B}.\text{dur} - \text{Stop}_{A}.\text{dur}$$
  **No extra API calls are made when passengers search, price, or book.**

### 2. Live Route Preview in Ride Creation
- When creating a ride (`/driver/trips/new`), selecting origin, destination, and intermediate stops triggers a debounced live fetch to `POST /driver/route-preview`.
- Distance and duration are computed and displayed live with ETA buffers (`~X h Y min · Z km approx.`).
- Inputs are read-only in the browser and strictly re-computed on the backend upon submission.

### 3. Interactive Leaflet Map
- Trip detail pages (`/trips/{id}` and `/driver/trips/{id}`) render an interactive Leaflet map with OpenStreetMap tiles.
- The route polyline is rendered using the stored GeoJSON `routeGeometry` on the trip, requiring zero external map API tokens.

### 4. Getting an ORS API Key
1. Sign up for free at [openrouteservice.org](https://openrouteservice.org/dev/#/signup).
2. Create an API token (standard free tier grants 2,000 requests/day and 40 requests/min).
3. Paste the key in your `.env` file:
   ```ini
   ORS_API_KEY=your_actual_ors_key_here
   ```

### 5. Non-Blocking Fallback & Route Backfill
- If ORS is temporarily down, rate-limited, or quota-exhausted, the application logs a warning and saves the trip gracefully with null ETAs without interrupting the driver.
- Trips with missing ETAs can be recalculated anytime with rate-limiting using:
  ```bash
  make routes-refresh
  ```

---

## 👥 Demo Accounts & Credentials

The seed command creates realistic user profiles with hashed passwords (`admin123`, `driver123`, `pass123`):

| Role | Name | Email | Password | Details |
| :--- | :--- | :--- | :--- | :--- |
| **Admin** | Admin Platform | `admin@emc.ma` | `admin123` | Full access to Admin Dashboard (`/admin`), pricing settings, and user management. |
| **Driver 1** | Karim Benali | `karim.driver@emc.ma` | `driver123` | **4.2 ★** (15 reviews). Vehicle: Dacia Logan `12345-A-1` (4 seats). |
| **Driver 2** | Samira Idrissi | `samira.driver@emc.ma` | `driver123` | **4.8 ★** (20 reviews). Vehicle: Renault Clio 5 `67890-B-26` (4 seats). |
| **Driver 3** | Mehdi Tazi | `mehdi.driver@emc.ma` | `driver123` | **3.9 ★** (8 reviews). Vehicles: Peugeot 208 `45678-C-6` & Hyundai Tucson `98765-D-6` (6 seats). |
| **Passenger 1** | Youssef Alami | `youssef.passenger@emc.ma` | `pass123` | **4.0 ★** (10 reviews). |
| **Passenger 2** | Fatima Zahra | `fatima.passenger@emc.ma` | `pass123` | **4.5 ★** (12 reviews). |
| **Passenger 3** | Omar Bennani | `omar.passenger@emc.ma` | `pass123` | **4.2 ★** (7 reviews). |
| **Passenger 4** | Amina Mansouri | `amina.passenger@emc.ma` | `pass123` | **4.8 ★** (14 reviews). |
| **Passenger 5** | Hamza Chraibi | `hamza.passenger@emc.ma` | `pass123` | **4.1 ★** (5 reviews). |

---

## 🧪 Pre-Seeded Scenarios to Test

The database includes 6 rich trip scenarios representing Moroccan travel corridors and business rules:

### 1. Intermediate Seat Reuse (Trip #1: Casablanca → Marrakech → Agadir)
- **Status:** Scheduled (> 24h before departure).
- **Driver:** Karim Benali (Logan, 4 passenger seats).
- **Seat Demonstration:**
  - Youssef booked 1 seat: Casablanca → Marrakech.
  - Fatima booked 1 seat: Marrakech → Agadir.
  - Amina booked 2 seats: Casablanca → Agadir (entire trip).
  - *Result:* The seat used by Youssef is freed at Marrakech and reused by Fatima. Both legs have 3 seats booked and 1 seat available!

### 2. Locked Schedule Modification (Trip #2: Tangier → Kenitra → Rabat → Casablanca)
- **Status:** Scheduled (< 24h before departure: +18 hours).
- **Driver:** Samira Idrissi.
- **Rule Verification:** Because less than 24h remain before departure, schedule modifications are locked to protect booked passengers.

### 3. Mutual Two-Way Reviews (Trip #3: Marrakech → Essaouira)
- **Status:** Completed.
- **Driver:** Karim Benali. Passenger: Hamza Chraibi.
- **Review Demonstration:** Both driver and passenger left 5-star feedback praising driving and punctuality.

### 4. Multi-Stop Completed Journey (Trip #4: Fes → Meknes → Rabat → Casablanca)
- **Status:** Completed.
- **Driver:** Mehdi Tazi (Hyundai Tucson 7-seater).
- **Review Demonstration:** Multi-passenger bookings with realistic reviews reflecting driver vehicle comfort.

### 5. Driver Cancellation Penalty & Passenger Refund (Trip #5: Casablanca → Mohammedia → Rabat)
- **Status:** Cancelled by Driver with less than 24h notice.
- **Rules Demonstrated:**
  - Full automated refund issued to passenger (`Payment::STATUS_REFUNDED`).
  - Strict **-1.0 rating penalty** applied to driver (rating dropped from 4.9 to 3.9).
  - Notification dispatches sent to both parties.

### 6. Fresh Open Trip (Trip #6: Rabat → Tangier)
- **Status:** Scheduled (+4 days).
- **Driver:** Samira Idrissi.
- **Testing:** 4 seats open; log in as any passenger to test live booking and pricing calculation!

---

## 🧰 Makefile CLI Reference

Essential development shortcuts:

| Target | Description |
| :--- | :--- |
| `make build` | Builds or rebuilds Docker images. |
| `make up` | Starts Docker containers in background (`http://localhost:8000`). |
| `make down` | Stops and tears down Docker containers. |
| `make restart` | Restarts Docker containers. |
| `make logs` | Streams live Docker logs (`app` & `database`). |
| `make sh` | Opens an interactive shell inside the running `app` container. |
| `make install` | Runs `composer install` inside the app container. |
| `make migrate` | Runs pending Doctrine database migrations (`db-migrate` alias supported). |
| `make seed` | Seeds database with Moroccan cities, users, trips, and routes (`db-seed` alias supported). |
| `make reset` | Executes migrations and re-seeds demo data (`db-reset` alias supported). |
| `make routes-refresh` | Refreshes OpenRouteService routing ETAs and geometries. |
| `make test` | Executes the PHPUnit test suite. |
| `make clean` | Removes cache, logs, and temporary files. |

---

## 🧪 Running Automated Tests

The application includes unit and integration tests covering pricing calculation, routing logic, ORS client mocking, intermediate seat allocation, cancellation rules, and controller security:

```bash
make test
# Or directly:
bin/phpunit
```

*Note: All tests mock HTTP calls to OpenRouteService — tests never consume ORS API quota.*
