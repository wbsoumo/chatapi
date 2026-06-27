# Local Installation Guide

This document guides you through installing the WhatsApp-like Chat Backend API on your local computer.

---

## 1. Prerequisites

Before installing, make sure your computer has the following tools:
* **PHP 8.3 or higher** (Ensure the `pdo_mysql`, `gd`, and `openssl` extensions are enabled in your `php.ini` configuration).
* **MySQL Server 8.0 or higher**.
* **Composer** (PHP Package Manager).
* **Redis Server** (Recommended for rate limiting and live token mapping).

---

## 2. Installation Steps

1. **Clone or copy** the codebase into your local development folder (e.g. `whatsapp-backend`).
2. **Open your terminal** and navigate to the project directory:
   ```bash
   cd whatsapp-backend
   ```
3. **Install dependencies** using Composer:
   ```bash
   composer install
   ```
4. **Copy the Environment Template** file to set up credentials:
   ```bash
   cp .env.example .env
   ```
5. **Configure your `.env` settings**:
   * Open the newly created `.env` file.
   * Input your MySQL connection settings:
     ```env
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_DATABASE=whatsapp_db
     DB_USERNAME=root
     DB_PASSWORD=your_mysql_password
     ```
   * Set high-entropy secrets for JWT token signing:
     ```env
     JWT_SECRET=use_a_long_random_string_here
     ```
   * Set up Fast2SMS credentials if testing live SMS:
     ```env
     FAST2SMS_API_KEY=your_live_api_key_or_leave_empty_for_mock_testing
     ```

6. **Create the Database**:
   Log into MySQL and run:
   ```sql
   CREATE DATABASE whatsapp_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

7. **Run Database Migrations**:
   Deploy the MySQL database schema using the CLI migration script:
   ```bash
   php bin/migrate.php
   ```
   To reset all tables fresh, you can run:
   ```bash
   php bin/migrate.php --fresh
   ```

---

## 3. Running the Servers Locally

### Running the REST HTTP Server
You can run PHP's built-in development server pointing to the front controller:
```bash
php -S localhost:8000 -t public/
```
The REST API will now be accessible at `http://localhost:8000`.

### Running the WebSocket Server
Start the CLI Ratchet WebSocket server to listen for active connections:
```bash
php bin/websocket.php
```
The WebSocket server will listen on port `8080` (e.g., `ws://127.0.0.1:8080`).

### Running the Background Worker
To execute pending tasks (FCM notification retries and system cleanups), run:
```bash
php bin/worker.php
```

---

## 4. Running Unit Tests

Run the PHPUnit suite to verify your workspace configuration is correct:
```bash
composer test
```
All tests use an in-memory SQLite connection and will run without touching your live MySQL tables.
