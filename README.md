# Bella Vista Oasis Spa

The guest booking portal and staff dashboard use HTML, CSS, browser JavaScript, PHP, and MySQL. The site runs directly on Apache through XAMPP; no Node.js, Vite, React, TypeScript, or build step is required.

## First-time setup

1. Install XAMPP and start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open [http://localhost/phpmyadmin](http://localhost/phpmyadmin), select **Import**, and import `database/schema.sql`.
3. Configure Google reCAPTCHA v2 and an SMTP email account as described below.
4. Create a manager account. Replace `manager` with the username you want:

   ```powershell
   & 'C:\xampp\php\php.exe' scripts\create_manager.php manager
   ```

   Enter a password of at least 12 characters when prompted.
5. Visit [http://localhost/bellavista](http://localhost/bellavista) for the guest portal, or [http://localhost/bellavista/manager](http://localhost/bellavista/manager) for staff sign-in.

Apache and MySQL must be running in XAMPP whenever you use the app.

## Email, accounts, and reCAPTCHA setup

Guest registration stores accounts in MySQL and sends a six-digit email verification code. Codes expire after 15 minutes and allow up to five verification attempts. Guests can reset their password using a reCAPTCHA-protected code sent to their verified email. Signed-in password changes also require a six-digit code sent to the current email before the password changes. Email changes require two codes: one sent to the current email to authorize the request, then another sent to the new address; the existing email remains active until both are verified. Change codes expire after 15 minutes, allow up to five attempts, and have a 60-second resend cooldown. Guest profile settings show reservation dates, treatment details, and booking statuses. Guests must sign in with a verified account to book; the booking is confirmed immediately after a successful reservation. Guest registration, guest sign-in, bookings, manager sign-in, verification-code resends, and password reset use Google reCAPTCHA v2.

Guest passwords must be 12–50 characters. Registration includes a live strength indicator; a unique password of at least 16 characters is recommended. Full names accept letters and spaces with a 35-character maximum. Booking phone numbers must use the Philippines +63 format, for example +639171234567.

## Manual GCash payments

The app supports manual GCash payment submission without an external payment gateway. Import `database/schema.sql` to create the `payments` table and place the static QR image at `assets/images/gcash-qr-code.jpg`. New signed-in guest bookings begin as `On Progress`; managers can also move an older confirmed guest booking to `On Progress` for payment review if it has no verified payment. Approving a pending payment in `admin_payments.php` automatically marks its eligible reservation as `confirmed`. Signed-in guests can pay for their own active bookings at `checkout_gcash.php` and view the latest payment result at `order_status.php`. Receipt files are stored under the web root in `storage/gcash-receipts`, with direct web access denied; managers view them through the authenticated `receipt_image.php` endpoint. Keep Apache `.htaccess` overrides enabled for the storage protection rule.

The manager dashboard also supports permanent deletion of individually selected bookings, all bookings, or every booking in the selected status across all dates. Deleting a booking cascades to its payment records and removes its validated private receipt file; these actions require a manager session and CSRF token and display an irreversible-action confirmation.

See the [Level 1 data flow diagram](docs/DFD-Level-1.md) for the system processes, external entities, data stores, and manual GCash payment boundary.

1. Edit `config/local.php` with your Google reCAPTCHA v2 site/secret keys and SMTP server details. Register `localhost` as a reCAPTCHA development domain. For an online site, use that site's domain and HTTPS.
2. Use an SMTP app password or dedicated mail account where your provider supports it. Never commit or share `config/local.php`; it is excluded by `.gitignore` and Apache blocks direct access to the `config` folder.

### Gmail SMTP settings

For a Gmail sender account, enable Google's 2-Step Verification and create an **App Password** for this application. Use the complete Gmail address as both the SMTP username and sender address; use the App Password, not the normal Google account password:

```php
'smtp' => [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'your-sender@gmail.com',
    'password' => 'your-16-character-app-password',
    'from_email' => 'your-sender@gmail.com',
    'from_name' => 'Bella Vista Oasis Spa',
],
```

Replace the example address and password locally; do not paste them into chat or commit them. Gmail SMTP sends from the configured mailbox to other valid email addresses, but no provider can guarantee inbox placement. Check Spam/Junk, use the same Gmail address for `username` and `from_email`, and review the sender mailbox's sending limits. If the app is hosted on a domain, configure SPF and DKIM for that domain or use a transactional email provider.

Until valid keys and SMTP settings are filled in, account creation and booking verification will show setup errors and will not skip CAPTCHA or email checks. Do not use Google's test keys on a public site.

## Updating an existing database

Guest-account, email-verification, password-reset, profile settings, and account-change verification are all defined in `database/schema.sql`. Import this file for a new installation. It also checks for and adds the account-related columns, index, and foreign key required by older installations, while preserving existing accounts and reservations. Back up the database before importing it into an existing installation.

## Project files

- `index.php` serves the guest portal and manager page.
- `assets/css/site.css` contains the page styles.
- `assets/css/manager.css` contains staff dashboard styling and motion.
- `assets/css/refinements.css` contains shared responsive polish and reduced-motion-aware transitions.
- `assets/images/bella-vista-logo.svg` is the transparent spa logo shown briefly when a page loads.
- `assets/images/bella-vista-documentation-banner.svg` is the 1600 × 500 branded banner for documentation, presentations, and README headers.
- `assets/js/app.js` handles the booking form, availability checks, manager dashboard, and PHP API requests.
- `api/` contains the PHP endpoints.
- `database/schema.sql` is the project's single SQL file. It creates or updates the MySQL schema and seeds the initial services, rooms, and therapists.

Apache rewrite rules in `.htaccess` serve the manager route and static assets. Keep that file in the project folder. If Apache reports an `.htaccess` error, confirm `mod_rewrite` is enabled and the `htdocs` directory allows overrides (`AllowOverride All`).

## Local database settings

The PHP API uses XAMPP's default local MySQL connection: host `127.0.0.1`, database `bellavista_oasis_app`, user `root`, and a blank password. This is a separate database so an existing `bellavista_spa` database is left untouched. These settings are intended for a local development machine; do not expose this default database configuration to a public server.
