<?php
$baseDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$appBasePath = $baseDirectory === '/' ? '/' : rtrim($baseDirectory, '/') . '/';
$requestedView = $_GET['view'] ?? 'dashboard';
$therapistView = in_array($requestedView, ['dashboard', 'appointments', 'schedule', 'day-offs'], true)
  ? $requestedView
  : 'dashboard';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Therapist Portal | Bella Vista Oasis Spa</title>
  <base href="<?= htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <link rel="stylesheet" href="assets/css/manager.css?v=<?= filemtime(__DIR__ . '/assets/css/manager.css') ?>">
  <link rel="stylesheet" href="assets/css/refinements.css?v=<?= filemtime(__DIR__ . '/assets/css/refinements.css') ?>">
  <link rel="stylesheet" href="assets/css/therapist.css?v=<?= filemtime(__DIR__ . '/assets/css/therapist.css') ?>">
  <script defer src="assets/js/required-fields.js?v=<?= filemtime(__DIR__ . '/assets/js/required-fields.js') ?>"></script>
  <script defer src="assets/js/therapist.js?v=<?= filemtime(__DIR__ . '/assets/js/therapist.js') ?>"></script>
</head>
<body>
  <div class="manager-page therapist-page" data-therapist-view="<?= htmlspecialchars($therapistView, ENT_QUOTES, 'UTF-8') ?>">
    <header class="manager-header">
      <a class="brand" href="./"><span class="brand-mark">BV</span><span><strong>Bella Vista Oasis Spa</strong><small>Therapist Portal</small></span></a>
    </header>
    <div class="therapist-layout is-unauthenticated" id="therapist-layout">
      <aside class="therapist-sidebar" id="therapist-nav" aria-label="Therapist navigation" hidden>
        <div class="therapist-sidebar-header">
          <p class="therapist-sidebar-label">Workspace</p>
          <button class="therapist-menu-toggle" type="button" data-therapist-nav-toggle aria-label="Close workspace menu" aria-expanded="true" aria-controls="therapist-sidebar-links">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
        </div>
        <nav id="therapist-sidebar-links" aria-label="Therapist pages">
          <a href="therapist/dashboard" data-therapist-nav="dashboard"><span aria-hidden="true">01</span> Overview</a>
          <a href="therapist/appointments" data-therapist-nav="appointments"><span aria-hidden="true">02</span> My appointments</a>
          <a href="therapist/schedule" data-therapist-nav="schedule"><span aria-hidden="true">03</span> Schedule &amp; availability</a>
          <a href="therapist/day-offs" data-therapist-nav="day-offs"><span aria-hidden="true">04</span> Day-off requests</a>
        </nav>
      </aside>
      <main class="therapist-main" id="therapist-main" aria-live="polite">
      <section class="login-card staff-login-card">
        <header class="staff-login-header">
          <span class="staff-login-mark" aria-hidden="true">BV</span>
          <div>
            <p class="eyebrow">Therapist access</p>
            <h1>Sign in to your portal</h1>
            <p class="staff-login-intro">View your assigned appointments, schedule, and availability.</p>
          </div>
        </header>
        <div class="staff-login-body">
        <p class="error-box" id="therapist-error" role="alert" hidden></p>
        <form id="therapist-login">
          <label class="field">Username <span class="required-mark">*</span><input name="username" autocomplete="username" placeholder="e.g., elena.morales" required></label>
          <label class="field">Password <span class="required-mark">*</span><span class="password-input-wrap"><input name="password" type="password" autocomplete="current-password" placeholder="Enter your therapist account password" required><button class="password-toggle" type="button" data-password-toggle aria-label="Show password" title="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5.5 9.5-5.5 9.5 5.5 9.5 5.5-3.5 5.5-9.5 5.5S2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.8"/></svg></button></span></label>
          <div class="captcha-slot" id="therapist-captcha"></div>
          <p class="required-note"><span class="required-mark">*</span> Indicates required field</p>
          <button class="button button-dark" type="submit">Sign in</button>
        </form>
        </div>
      </section>
      </main>
    </div>
    <dialog class="therapist-logout-dialog" aria-labelledby="therapist-logout-title" aria-describedby="therapist-logout-message">
      <span class="therapist-logout-icon" aria-hidden="true">?</span>
      <h2 id="therapist-logout-title">Sign out?</h2>
      <p id="therapist-logout-message">Are you sure you want to log out of your therapist account?</p>
      <p class="therapist-logout-error" data-logout-error role="alert" hidden></p>
      <div class="therapist-logout-actions">
        <button class="button button-outline" type="button" data-logout-cancel>Stay signed in</button>
        <button class="button button-dark" type="button" data-logout-confirm>Sign out</button>
      </div>
    </dialog>
  </div>
</body>
</html>
