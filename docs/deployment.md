# Production Deployment Guide (cPanel & VPS)

This guide walks you through deploying the WhatsApp Backend API to a production environment, specifically covering cPanel shared hosting and VPS setups.

---

## 1. Directory Setup on cPanel

1. Upload the entire project directory (e.g. `whatsapp-backend`) to your cPanel home folder (outside the public directory, e.g., `/home/username/whatsapp-backend`). This protects your configuration and source files from being publicly accessible.
2. Link the `public` folder to your public directory (e.g., `public_html` or a subdomain folder):
   * Create a symlink from `/home/username/whatsapp-backend/public` to `/home/username/public_html/api` or set your subdomain document root directly to `/home/username/whatsapp-backend/public`.
   * This ensures the browser only has access to `public/index.php` and uploaded media files, securing your code.

---

## 2. Environment Configuration

1. In the production directory, copy `.env.example` to `.env`.
2. Generate high-entropy keys for JWT secrets:
   ```env
   JWT_SECRET=generate_strong_64_character_hex_key
   JWT_REFRESH_SECRET=generate_another_strong_64_character_hex_key
   ```
3. Set your production MySQL database details.
4. Input your production FCM and Fast2SMS credentials.
5. Create a folder at `/home/username/whatsapp-backend/logs` and ensure it has write permissions (`chmod 755`).

---

## 3. WebSocket Configuration on VPS / cPanel

For production environments, running WebSockets over secure HTTPS/WSS (port `443`) is mandatory. Clients will fail to connect over insecure ws:// on an HTTPS website due to mixed-content security.

### Option A: Apache Reverse Proxy (cPanel Shared Hosting / VPS)
If you are using Apache on cPanel, you can map WebSocket requests on a specific subdomain path (e.g., `/ws`) to the backend Ratchet server running on port `8080`.

Open your domain's Apache virtual host file or add the following rules inside the `.htaccess` file if your host supports ProxyPass overrides:

```apache
RewriteEngine On

# Check if request is a WebSocket upgrade
RewriteCond %{HTTP:Upgrade} =websocket [NC]
RewriteCond %{HTTP:Connection} upgrade [NC]
RewriteRule ^ws/(.*) ws://127.0.0.1:8080/$1 [P,L]
```

This forwards `wss://yourdomain.com/ws?token=JWT` securely to `ws://127.0.0.1:8080?token=JWT`.

---

## 4. Keeping the WebSocket Server Running (Supervisor Daemon)

Since the WebSocket server is a persistent CLI process, it must run continuously. If it crashes, it should restart automatically.

On a VPS, install **Supervisor**:
```bash
sudo apt-get install supervisor
```

Create a configuration file at `/etc/supervisor/conf.d/whatsapp-ws.conf`:
```ini
[program:whatsapp-ws]
process_name=%(program_name)s
command=/usr/bin/php /home/username/whatsapp-backend/bin/websocket.php
autostart=true
autorestart=true
user=username
numprocs=1
redirect_stderr=true
stdout_logfile=/home/username/whatsapp-backend/logs/websocket_out.log
stderr_logfile=/home/username/whatsapp-backend/logs/websocket_err.log
```

Start the daemon:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start whatsapp-ws
```

---

## 5. Background Task Scheduling (Cron Job)

The background worker (`bin/worker.php`) handles cleanup tasks (deleting expired OTPs, rate limit logs) and processes queued FCM retries.

Set up a Cron Job in your cPanel dashboard or crontab:

```cron
* * * * * /usr/bin/php /home/username/whatsapp-backend/bin/worker.php > /dev/null 2>&1
```

This runs the worker once every minute, keeping the queue flushed.
