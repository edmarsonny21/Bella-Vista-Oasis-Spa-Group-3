<?php
$baseDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$appBasePath = $baseDirectory === '/' ? '/' : rtrim($baseDirectory, '/') . '/';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bella Vista Oasis Spa</title>
  <meta name="description" content="Book restorative massage treatments and private wellness suites at Bella Vista Oasis Spa.">
  <base href="<?= htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8') ?>">
  <script>
    (() => {
      if ("scrollRestoration" in history) history.scrollRestoration = "manual";
      document.documentElement.classList.add("brand-splash-active");
      const navigation = performance.getEntriesByType("navigation")[0];
      if (navigation?.type === "reload") {
        if (location.hash) history.replaceState(null, "", location.pathname + location.search);
        window.scrollTo(0, 0);
      }
    })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Playfair+Display:wght@400;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <link rel="stylesheet" href="assets/css/manager.css?v=<?= filemtime(__DIR__ . '/assets/css/manager.css') ?>">
  <link rel="stylesheet" href="assets/css/refinements.css?v=<?= filemtime(__DIR__ . '/assets/css/refinements.css') ?>">
  <script>window.gcashQrCodeUrl = <?= json_encode('assets/images/gcash-qr-code.jpg?v=' . filemtime(__DIR__ . '/assets/images/gcash-qr-code.jpg')) ?>;</script>
  <script defer src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</head>
<body>
  <div class="brand-splash" id="brand-splash" aria-hidden="true"><img src="assets/images/bella-vista-logo.svg" alt=""></div>
  <div id="app">
    <header class="site-header">
      <a class="brand" href="./" aria-label="Bella Vista Oasis Spa home"><span class="brand-mark">BV</span><span><strong>Bella Vista Oasis Spa</strong><small>Highland Wellness Sanctuary</small></span></a>
      <nav aria-label="Main navigation"><a href="#sanctuary">Sanctuary</a><a href="#services">Suites &amp; Rates</a><a href="#heritage">Estate Heritage</a><a href="#policies">Guest Policies</a></nav>
      <div class="header-actions">
        <button class="button button-outline" data-action="guest-auth">Login / Register</button>
        <button class="button button-dark" data-action="book">Book Treatment</button>
      </div>
    </header>
    <main>
      <section id="sanctuary" class="hero section">
        <div class="hero-copy">
          <p class="eyebrow">Established 2021 · Private Highland Estate · Open Daily 1:00 PM – 9:00 PM</p>
          <h1>Where Highland Nature Meets Mindful Healing</h1>
          <p class="lead">Immerse yourself in private architectural suites nestled amongst lush highlands. Experience ancestral Hilot techniques, botanical infusions, and an uncompromising <strong>30-minute sanitization gap</strong> after every treatment.</p>
          <div class="actions"><button class="button button-dark" data-action="book">Reserve Treatment Suite</button><a class="button button-outline" href="#services">Explore Suites &amp; Rates</a></div>
          <p class="hero-note">30-Min Automated Sanitization Buffer <span>·</span> 1:00 PM – 9:00 PM Daily <span>·</span> Single, Couple &amp; Family Pavilions</p>
        </div>
        <figure class="hero-image"><img src="assets/images/hero_sanctuary_oasis_1793002203249.jpg" alt="Bella Vista Oasis Spa sanctuary pavilion"><figcaption>“A sanctuary dedicated to stillness, botanical restorative therapy, and absolute privacy.”</figcaption></figure>
      </section>
      <section class="facts section"><article><small>Location</small><h3>Highland Sanctuary</h3><p>Secluded nature estate surrounded by private bamboo gardens.</p></article><article><small>Session Rates</small><h3>From ₱800 / Session</h3><p>Single, couples suite, and family lounge options.</p></article><article><small>Strict Protocol</small><h3>Certified Cleanliness</h3><p>Automatic 30-minute sanitization gap between guests.</p></article><article><small>Treatments</small><h3>Certified Masters</h3><p>Traditional Hilot, Swedish, deep tissue, and herbal treatments.</p></article></section>
      <section id="services" class="section services">
        <div class="section-heading"><p class="eyebrow">Private Treatment Suites</p><h2>Choose Your Sanctuary</h2><p>Each treatment includes a dedicated private space and a carefully prepared restorative experience.</p></div>
        <div class="service-grid">
          <article class="service-card"><img src="assets/images/service_single_massage_1793002203973.jpg" alt="Private single massage room"><div class="card-content"><p class="eyebrow">SR-1 · SR-2 · SR-3</p><h3>Single Room Massage</h3><p>Private single treatment room with a dedicated certified therapist.</p><div class="card-foot"><strong>₱800</strong><span>1-hour session · 1 guest</span></div><button class="button button-dark" data-action="book" data-service="single-room">Reserve this suite</button></div></article>
          <article class="service-card"><img src="assets/images/service_couples_suite_1793002205206.jpg" alt="Private couples spa suite"><div class="card-content"><p class="eyebrow">CS-1 · CS-2</p><h3>Couples Suite</h3><p>Private room access for two with complimentary herbal tea.</p><div class="card-foot"><strong>₱1,800</strong><span>1-hour session · 2 guests</span></div><button class="button button-dark" data-action="book" data-service="couples-suite">Reserve this suite</button></div></article>
          <article class="service-card"><img src="assets/images/service_family_lounge_1793002205941.jpg" alt="Open-air family spa lounge"><div class="card-content"><p class="eyebrow">FL-1 · FL-2</p><h3>Barkada / Family Spa Lounge</h3><p>Private wellness pavilion for 4 guests, with up to 6 guests accommodated.</p><div class="card-foot"><strong>₱4,000</strong><span>1.5-hour session · up to 6</span></div><button class="button button-dark" data-action="book" data-service="family-lounge">Reserve this suite</button></div></article>
        </div>
      </section>
      <section id="heritage" class="heritage">
        <div class="section heritage-inner"><div><img class="heritage-image" src="assets/images/estate_botanical_grounds_1793002205521.jpg" alt="Bella Vista Highland Estate gardens"></div><div><p class="eyebrow">Our Origin &amp; Philosophy · Est. 2021</p><h2>The Heritage of Bella Vista Oasis</h2><p>Established in 2021 as an architectural extension of the historic Bella Vista private estate, the sanctuary was envisioned as a private retreat for residents and personal guests seeking respite from urban noise.</p><p>In 2023, the estate formally inaugurated Bella Vista Oasis Spa as a standalone wellness center, with dedicated treatment suites and an open-air family lounge—preserving unhurried presence and clinical cleanliness.</p><div class="pillars"><article><strong>Ancestral Botanical Oils</strong><p>Locally sourced coconut, lemongrass, eucalyptus, and wild ginger.</p></article><article><strong>The 30-Min Cleaning Gap</strong><p>Every suite undergoes a mandatory sanitization routine between bookings.</p></article></div></div></div>
      </section>
      <section id="policies" class="section policies"><div class="section-heading"><p class="eyebrow">Sanctuary Etiquette &amp; Guest Care</p><h2>Terms, Health &amp; Booking Policies</h2><p>To preserve the tranquil ambiance and safeguard guest health, all bookings adhere to these policies.</p></div><div class="policy-grid">
        <article><h3>Arrival &amp; 15-Minute Grace Period</h3><p>Please arrive at least 15 minutes prior to your scheduled treatment time. Late arrivals may result in shortened treatment times.</p></article>
        <article><h3>6-Hour Cancellation &amp; Rescheduling Policy</h3><p>Cancellations or rescheduling must be requested at least 6 hours before the appointment. The online booking form does not collect a downpayment.</p></article>
        <article><h3>Mandatory Health &amp; Medical Declaration</h3><p>Disclose medical conditions, allergies, skin sensitivities, chronic conditions, high blood pressure, recent surgeries, injuries, or pregnancy prior to treatment.</p></article>
        <article><h3>Serene &amp; Quiet Atmosphere</h3><p>Please place mobile devices on silent mode and maintain hushed, low-volume speech throughout the suites and relaxation gardens.</p></article>
      </div></section>
    </main>
    <footer class="footer"><div class="footer-grid"><div><a class="brand brand-footer" href="./"><span class="brand-mark">BV</span><span><strong>Bella Vista Oasis Spa</strong><small>Est. 2021 Highland Sanctuary</small></span></a><p>Devoted to organic botanical healing, ancestral Hilot, and unhurried stillness.</p></div><div><h3>Operating Hours</h3><p>Monday to Sunday (Open Daily)<br>1:00 PM – 9:00 PM</p><button class="button button-gold" data-action="book">Reserve Treatment</button></div><div><h3>Sanctuary Concierge</h3><p>Bella Vista Highlands Estate, Sanctuary Ridge Road, Nature Valley</p><p>+63 (02) 8876-9281 / +63 917 882 6274<br>concierge@bellavistaoasis.com</p></div></div><div class="copyright">© <span id="year"></span> Bella Vista Oasis Spa. All Rights Reserved.</div></footer>
    <div id="overlay-root"></div>
  </div>
</body>
</html>
