# Installation Guide

## Requirements
- Docker & Docker Compose
- Make (optional)

---

## Quick Start (Docker)

1. Clone and enter the project:
```bash
git clone <repo-url>
cd EMC_PFE_challenge
```

2. Copy the environment file:
```bash
cp .env.example .env
```
*(Optional: set your `ORS_API_KEY` in `.env` for live OpenRouteService calculations).*

3. Start containers and setup the database:
```bash
make build
make up
make install
make reset
```
The application will be live at **http://localhost:8000**.

---

## Local Setup (Without Docker)

Requires PHP 8.2+, Composer, and MySQL (or SQLite):

```bash
cp .env.example .env
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed --purge
php -S 127.0.0.1:8000 -t public
```

---

## Demo Accounts

All passenger passwords are `pass123`. Driver passwords are `driver123`. Admin is `admin123`.

| Role | Email | Password | Note |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin@emc.ma` | `admin123` | Admin dashboard access (`/admin`) |
| **Driver** | `karim.driver@emc.ma` | `driver123` | Dacia Logan (4 seats), Rating: 4.2 |
| **Driver** | `samira.driver@emc.ma` | `driver123` | Renault Clio 5 (4 seats), Rating: 4.8 |
| **Driver** | `mehdi.driver@emc.ma` | `driver123` | Peugeot 208 & Tucson (6 seats), Rating: 3.9 |
| **Passenger** | `youssef.passenger@emc.ma` | `pass123` | Passenger account |
| **Passenger** | `fatima.passenger@emc.ma` | `pass123` | Passenger account |
| **Passenger** | `omar.passenger@emc.ma` | `pass123` | Passenger account |
| **Passenger** | `amina.passenger@emc.ma` | `pass123` | Passenger account |
| **Passenger** | `hamza.passenger@emc.ma` | `pass123` | Passenger account |

---

## Useful Commands

| Command | Action |
| :--- | :--- |
| `make up` | Start containers in background |
| `make down` | Stop containers |
| `make reset` | Run migrations and re-seed database |
| `make seed` | Re-seed demo data only |
| `make logs` | View container logs |
| `make sh` | Open shell inside app container |
| `make test` | Run PHPUnit test suite |
| `make clean` | Clear cache and logs |

---

## Testing

Run tests with:
```bash
make test
```
*(Or `bin/phpunit` if running natively).*
