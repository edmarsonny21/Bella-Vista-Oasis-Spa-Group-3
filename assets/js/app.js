(() => {
  "use strict";

  const serviceList = [
    { id: "single-room", name: "Single Room Massage", rooms: ["SR-1", "SR-2", "SR-3"], duration: 60, label: "1-hour session", base: 800, minGuests: 1, maxGuests: 1, extra: 0 },
    { id: "couples-suite", name: "Couples Suite", rooms: ["CS-1", "CS-2"], duration: 60, label: "1-hour session", base: 1800, minGuests: 2, maxGuests: 2, extra: 0 },
    { id: "family-lounge", name: "Barkada / Family Spa Lounge", rooms: ["FL-1", "FL-2"], duration: 90, label: "1.5-hour session", base: 4000, minGuests: 4, maxGuests: 6, extra: 750 },
  ];
  const roomNames = {
    "SR-1": "Lotus Sanctuary", "SR-2": "Cedar Serenity", "SR-3": "Bamboo Haven",
    "CS-1": "Harmony Pavilion", "CS-2": "Jasmine Haven",
    "FL-1": "Estate Grand Veranda", "FL-2": "Orchid Oasis Lounge",
  };
  const serviceById = Object.fromEntries(serviceList.map((service) => [service.id, service]));
  const isManagerRoute = /\/manager\/?$/.test(window.location.pathname);
  const projectPath = window.location.pathname.replace(/manager\/?$/, "").replace(/\/?$/, "/");
  const apiUrl = (path) => `${projectPath}api/${path}`;
  const state = {
    modal: null, serviceId: "single-room", roomCode: "SR-1",
    date: manilaDate(), guestCount: 1, selectedTime: "",
    bookings: [], availabilityLoading: false, availabilityError: "",
    bookingError: "", confirmation: null, manager: null, managerCsrfToken: "", deletingBookings: false,
    selectionMode: false, selectedBookingIds: [], bookingFields: {},
    reportDate: manilaDate(), search: "", filter: "all", error: "",
    guest: null, pendingServiceId: null, authMode: "login", authEmail: "",
    authMessage: "", authError: "", recaptchaSiteKey: "", emailConfigured: false,
    guestProfile: null, profileLoading: false, profileError: "",
  };
  let overlay = document.getElementById("overlay-root");
  let modalNeedsEntrance = false;
  let captchaScriptPromise;
  const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[character]);
  const money = (amount) => `₱${Number(amount || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  function manilaDate() {
    return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
  }
  const formatTime = (time) => {
    const [hour, minute] = String(time || "").slice(0, 5).split(":").map(Number);
    if (!Number.isInteger(hour) || !Number.isInteger(minute)) return time || "";
    return new Intl.DateTimeFormat("en-US", { timeZone: "UTC", hour: "numeric", minute: "2-digit" }).format(new Date(Date.UTC(2000, 0, 1, hour, minute)));
  };
  async function request(path, options = {}) {
    const response = await fetch(apiUrl(path), {
      ...options,
      headers: { Accept: "application/json", ...(options.body ? { "Content-Type": "application/json" } : {}), ...options.headers },
    });
    let payload;
    const responseText = await response.text();
    try { payload = JSON.parse(responseText); } catch { payload = null; }
    if (!response.ok) throw new Error(payload?.message || `Request failed (${response.status})`);
    if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
      throw new Error("The server returned an invalid response. Check the PHP error log and import the latest database/schema.sql.");
    }
    return payload;
  }

  async function loadCaptchaApi() {
    if (typeof window.grecaptcha?.render === "function") return;
    if (!captchaScriptPromise) {
      captchaScriptPromise = (async () => {
        const existingScript = document.querySelector('script[src*="google.com/recaptcha/api.js"]');
        if (!existingScript) {
          await new Promise((resolve, reject) => {
            const script = document.createElement("script");
            script.src = "https://www.google.com/recaptcha/api.js?render=explicit";
            script.async = true;
            script.defer = true;
            script.onload = resolve;
            script.onerror = () => reject(new Error("Could not load Google reCAPTCHA. Check your internet connection and try again."));
            document.head.append(script);
          });
        }

        const ready = await new Promise((resolve) => {
          const startedAt = Date.now();
          const checkReady = () => {
            if (typeof window.grecaptcha?.render === "function") {
              resolve(true);
            } else if (Date.now() - startedAt >= 10000) {
              resolve(false);
            } else {
              window.setTimeout(checkReady, 100);
            }
          };
          checkReady();
        });
        if (!ready) {
          throw new Error("Google reCAPTCHA loaded without its checkbox renderer. Reload the page and check that Google reCAPTCHA is allowed by your browser.");
        }
      })().catch((error) => {
        captchaScriptPromise = null;
        throw error;
      });
    }
    await captchaScriptPromise;
  }

  async function renderCaptchaWidget(container, form, attempt = 0) {
    await loadCaptchaApi();
    if (!container.isConnected) return;
    const recaptcha = window.grecaptcha;
    if (typeof recaptcha?.render !== "function") {
      if (attempt < 5) {
        await new Promise((resolve) => window.setTimeout(resolve, 200));
        return renderCaptchaWidget(container, form, attempt + 1);
      }
      throw new Error("Google reCAPTCHA checkbox renderer is unavailable. Reload the page and try again.");
    }

    try {
      container.replaceChildren();
      const widgetId = recaptcha.render(container, {
        sitekey: state.recaptchaSiteKey,
        callback: (token) => { form.dataset.recaptchaToken = token; },
        "expired-callback": () => { delete form.dataset.recaptchaToken; },
        "error-callback": () => { delete form.dataset.recaptchaToken; },
      });
      form.dataset.recaptchaWidget = String(widgetId);
    } catch (error) {
      if (attempt < 2 && error instanceof TypeError && /grecaptcha\.render is not a function/.test(error.message)) {
        await new Promise((resolve) => window.setTimeout(resolve, 200));
        return renderCaptchaWidget(container, form, attempt + 1);
      }
      throw error;
    }
  }

  function renderCaptcha(form) {
    const container = form?.querySelector("[data-captcha]");
    if (!container) return;
    if (!state.recaptchaSiteKey) {
      container.innerHTML = '<p class="captcha-setup">reCAPTCHA is not configured. Add your Google v2 site key to config/local.php.</p>';
      return;
    }
    container.textContent = "Loading reCAPTCHA…";
    void renderCaptchaWidget(container, form).catch((error) => {
      if (container.isConnected) container.innerHTML = `<p class="captcha-setup">${escapeHtml(error.message)}</p>`;
    });
  }

  function getCaptchaToken(form) {
    return form?.dataset.recaptchaToken ||
      form?.querySelector('textarea[name="g-recaptcha-response"]')?.value.trim() ||
      "";
  }

  function updateGuestHeader() {
    const actions = document.querySelector(".header-actions");
    if (!actions) return;
    const accountLabel = state.guest ? `Hi, ${state.guest.name.split(/\s+/)[0]}` : "Login / Register";
    actions.innerHTML = `<button class="button button-outline" data-action="guest-auth">${escapeHtml(accountLabel)}</button>${state.guest ? '<button class="button button-outline" data-action="guest-logout">Sign out</button>' : ""}<button class="button button-dark" data-action="book">Book Treatment</button>`;
  }

  async function initializeGuestSession() {
    try {
      const configuration = await request("auth.php?operation=public_config");
      state.recaptchaSiteKey = configuration.recaptchaSiteKey || "";
      state.emailConfigured = configuration.emailConfigured === true;
      const session = await request("auth.php?operation=guest_session");
      state.guest = session.authenticated ? { name: session.name, email: session.email } : null;
      updateGuestHeader();
    } catch (error) {
      console.error("Could not initialize guest account features:", error);
    }
  }

  function openGuestAuth(mode = "login") {
    state.modal = "guest-auth";
    modalNeedsEntrance = true;
    state.authMode = mode;
    state.authError = "";
    state.authMessage = "";
    renderGuestAuth();
  }

  function showAuthFeedback(title, message, kind = "success") {
    document.querySelector(".auth-feedback-overlay")?.remove();
    const feedback = document.createElement("div");
    feedback.className = "auth-feedback-overlay";
    feedback.innerHTML = `<section class="auth-feedback-card ${kind === "error" ? "is-error" : "is-success"}" role="${kind === "error" ? "alertdialog" : "dialog"}" aria-modal="true" aria-labelledby="auth-feedback-title" aria-describedby="auth-feedback-message"><span class="auth-feedback-icon" aria-hidden="true">${kind === "error" ? "!" : "✓"}</span><h2 id="auth-feedback-title">${escapeHtml(title)}</h2><p id="auth-feedback-message">${escapeHtml(message)}</p><button class="button button-dark" type="button" data-feedback-dismiss>Got it</button></section>`;
    document.body.append(feedback);
    const dismiss = () => {
      animateElementOut(feedback, () => {
        document.removeEventListener("keydown", handleEscape);
      });
    };
    const handleEscape = (event) => {
      if (event.key === "Escape") dismiss();
    };
    feedback.querySelector("[data-feedback-dismiss]")?.addEventListener("click", dismiss);
    feedback.addEventListener("click", (event) => {
      if (event.target === feedback) dismiss();
    });
    document.addEventListener("keydown", handleEscape);
    feedback.querySelector("[data-feedback-dismiss]")?.focus();
  }

  function animateElementOut(element, onComplete) {
    if (!element || element.classList.contains("is-closing")) return;
    element.classList.add("is-closing");
    element.setAttribute("aria-hidden", "true");
    element.inert = true;
    let completed = false;
    let fallbackTimer;
    const handleAnimationEnd = (event) => {
      if (event.target === element && event.animationName === "overlay-leave") finish();
    };
    const finish = () => {
      if (completed) return;
      completed = true;
      window.clearTimeout(fallbackTimer);
      element.removeEventListener("animationend", handleAnimationEnd);
      element.remove();
      onComplete?.();
    };
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reducedMotion) {
      finish();
      return;
    }
    element.addEventListener("animationend", handleAnimationEnd);
    fallbackTimer = window.setTimeout(finish, 300);
  }

  function renderOverlay(markup) {
    overlay = document.getElementById("overlay-root") || overlay;
    const nextOverlayRoot = document.createElement("div");
    nextOverlayRoot.innerHTML = markup;
    const nextOverlay = nextOverlayRoot.querySelector(".overlay");
    const currentOverlay = overlay.querySelector(".overlay");
    if (currentOverlay && nextOverlay && !currentOverlay.classList.contains("is-closing")) {
      const currentDialog = currentOverlay.querySelector(".dialog");
      const nextDialog = nextOverlay.querySelector(".dialog");
      currentOverlay.dataset.action = nextOverlay.dataset.action;
      if (currentDialog && nextDialog) {
        for (const attribute of [...currentDialog.attributes]) {
          currentDialog.removeAttribute(attribute.name);
        }
        for (const attribute of [...nextDialog.attributes]) {
          currentDialog.setAttribute(attribute.name, attribute.value);
        }
        currentDialog.replaceChildren(...nextDialog.childNodes);
      } else {
        currentOverlay.replaceChildren(...nextOverlay.childNodes);
      }
    } else {
      overlay.replaceChildren(...nextOverlayRoot.childNodes);
    }
    document.body.classList.add("modal-open");
    const modalOverlay = overlay.querySelector(".overlay");
    if (modalNeedsEntrance && modalOverlay) {
      modalOverlay.classList.add("is-entering");
      modalNeedsEntrance = false;
    }
  }

  function closeMainOverlay() {
    const modalOverlay = overlay?.querySelector(".overlay");
    if (!modalOverlay) return;
    state.modal = null;
    modalNeedsEntrance = false;
    animateElementOut(modalOverlay, () => {
      if (overlay?.querySelector(".overlay")) return;
      overlay?.replaceChildren();
      document.body.classList.remove("modal-open");
      state.confirmation = null;
      state.pendingServiceId = null;
    });
  }

  function showLogoutConfirmation(manager = false) {
    document.querySelector(".logout-confirm-overlay")?.remove();
    const confirmation = document.createElement("div");
    confirmation.className = "auth-feedback-overlay logout-confirm-overlay";
    confirmation.dataset.logoutManager = String(manager);
    confirmation.innerHTML = `<section class="auth-feedback-card" role="alertdialog" aria-modal="true" aria-labelledby="logout-confirm-title" aria-describedby="logout-confirm-message"><span class="auth-feedback-icon" aria-hidden="true">?</span><h2 id="logout-confirm-title">Sign out?</h2><p id="logout-confirm-message">Are you sure you want to log out of your ${manager ? "manager" : "guest"} account?</p><div class="logout-confirm-actions"><button class="button button-outline" type="button" data-action="logout-confirm-cancel">Stay signed in</button><button class="button button-dark" type="button" data-action="logout-confirm-accept">Sign out</button></div></section>`;
    document.body.append(confirmation);
    confirmation.addEventListener("click", (event) => {
      if (event.target === confirmation) animateElementOut(confirmation);
    });
    confirmation.addEventListener("keydown", (event) => {
      if (event.key === "Escape") animateElementOut(confirmation);
    });
    confirmation.querySelector('[data-action="logout-confirm-cancel"]')?.focus();
  }

  function resetAuthCaptcha(form) {
    if (!form) return;
    delete form.dataset.recaptchaToken;
    const widgetId = form.dataset.recaptchaWidget;
    if (widgetId !== undefined && typeof window.grecaptcha?.reset === "function") {
      window.grecaptcha.reset(Number(widgetId));
    }
  }

  function renderGuestAuth() {
    if (state.modal !== "guest-auth") return;
    const isVerify = state.authMode === "verify";
    const isRegister = state.authMode === "register";
    const isForgot = state.authMode === "forgot";
    const isReset = state.authMode === "reset";
    const needsEmail = isRegister || isVerify || isForgot || isReset;
    const title = isVerify ? "Verify your email" : isRegister ? "Create a guest account" : isForgot ? "Reset your password" : isReset ? "Choose a new password" : "Guest sign in";
    const formFields = isVerify
      ? `<label class="field">Email address<input name="email" type="email" required value="${escapeHtml(state.authEmail)}" readonly></label><label class="field">Six-digit verification code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>`
      : `${isRegister ? '<label class="field">Full name<input name="fullName" autocomplete="name" required maxlength="35" title="Use letters and spaces only; maximum 35 characters"></label>' : ""}<label class="field">Email address<input name="email" type="email" autocomplete="email" required maxlength="254" value="${escapeHtml(state.authEmail)}" ${isReset ? "readonly" : ""}></label>${isForgot ? '<p class="password-help">We’ll send a six-digit reset code if this email belongs to a verified account.</p>' : ""}${isReset ? '<label class="field">Six-digit reset code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>' : ""}${isRegister || isReset ? `<label class="field">${isReset ? "New password" : "Password"}<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="50" required><small>At least 12 characters. A unique 16+ character password is recommended.</small><span class="password-strength" data-password-strength><span class="password-strength-meter" aria-hidden="true"><span data-strength-fill></span></span><span class="password-strength-label" data-strength-label aria-live="polite">Recommended: use 16+ unique characters.</span></span></label><label class="field">Confirm password<input name="confirmPassword" type="password" autocomplete="new-password" minlength="12" maxlength="50" required></label>` : ""}${!isRegister && !isForgot && !isReset ? '<label class="field">Password<input name="password" type="password" autocomplete="current-password" maxlength="50" required><small>Use 12 to 50 characters.</small></label>' : ""}`;
    const controls = isVerify
      ? `<button class="button button-outline" type="button" data-action="auth-resend" ${state.emailConfigured ? "" : "disabled"}>Resend code</button><button class="button button-dark" type="submit">Verify email</button>`
      : `<button class="button button-dark" type="submit" ${(!state.emailConfigured && (isRegister || isForgot)) ? "disabled" : ""}>${!state.emailConfigured && (isRegister || isForgot) ? "Email not configured" : isRegister ? "Register" : isForgot ? "Send reset code" : isReset ? "Reset password" : "Sign in"}</button>`;
    const captcha = '<div class="captcha-slot" data-captcha></div>';
    let authSwitch = "";
    if (isRegister) {
      authSwitch = '<p class="auth-switch">Already have an account? <button type="button" data-auth-mode="login">Sign in</button></p>';
    } else if (isForgot || isReset) {
      authSwitch = '<p class="auth-switch"><button type="button" data-auth-mode="login">Back to sign in</button></p>';
    } else if (!isVerify) {
      authSwitch = `${!isRegister ? '<p class="auth-switch"><button type="button" data-auth-mode="forgot">Forgot password?</button></p>' : ""}<p class="auth-switch">New to Bella Vista? <button type="button" data-auth-mode="register">Register</button></p>`;
    }
    const emailSetupWarning = needsEmail && !state.emailConfigured
      ? '<p class="error-box" role="status">Email delivery is not configured yet. Verification and password reset codes cannot be sent. Ask the site administrator to complete the SMTP settings.</p>'
      : "";
    renderOverlay(`<div class="overlay" data-action="overlay-close"><section class="dialog auth-dialog" role="dialog" aria-modal="true" aria-labelledby="auth-title"><header class="dialog-header"><div><small>Bella Vista Oasis Spa · Guest Account</small><h2 id="auth-title">${title}</h2></div><button class="close-button" data-action="close" aria-label="Close account form">×</button></header><div class="dialog-content">${emailSetupWarning}${state.authMessage ? `<p class="success-box" role="status">${escapeHtml(state.authMessage)}</p>` : ""}${state.authError ? `<p class="error-box" role="alert">${escapeHtml(state.authError)}</p>` : ""}<form id="guest-auth-form"><div class="form-grid">${formFields}</div>${captcha}<div class="dialog-actions"><button class="button button-outline" type="button" data-action="close">Cancel</button>${controls}</div></form>${authSwitch}</div></section></div>`);
    renderCaptcha(document.getElementById("guest-auth-form"));
    if (isRegister || isReset) updatePasswordStrength(document.getElementById("guest-auth-form"));
  }

  function updatePasswordStrength(form) {
    const password = form?.querySelector('[name="password"], [name="newPassword"]')?.value || "";
    const meter = form?.querySelector("[data-password-strength]");
    const fill = meter?.querySelector("[data-strength-fill]");
    const label = meter?.querySelector("[data-strength-label]");
    if (!meter || !fill || !label) return;

    const variety = [
      /[a-z]/.test(password),
      /[A-Z]/.test(password),
      /[0-9]/.test(password),
      /[^A-Za-z0-9]/.test(password),
    ].filter(Boolean).length;
    let level = 0;
    let message = "Recommended: use 16+ unique characters.";
    if (password.length > 0 && password.length < 12) {
      level = 1;
      message = `Weak — add ${12 - password.length} more character${password.length === 11 ? "" : "s"} (12 minimum).`;
    } else if (password.length >= 12 && password.length < 16) {
      level = 2;
      message = "Fair — use 16+ characters for a stronger password.";
    } else if (password.length >= 20 && variety >= 3) {
      level = 4;
      message = "Very strong — keep it unique to this account.";
    } else if (password.length >= 16 && variety >= 3) {
      level = 3;
      message = "Strong — keep it unique to this account.";
    } else if (password.length >= 16) {
      level = 3;
      message = "Good — add a few character types, or use a longer passphrase.";
    }

    meter.dataset.level = String(level);
    fill.style.width = `${level * 25}%`;
    label.textContent = message;
  }

  function setGuestSession(name) {
    state.guest = { name };
    state.modal = null;
    state.authError = "";
    state.authMessage = "";
    overlay.innerHTML = "";
    document.body.classList.remove("modal-open");
    modalNeedsEntrance = false;
    updateGuestHeader();
    if (state.pendingServiceId) {
      const serviceId = state.pendingServiceId;
      state.pendingServiceId = null;
      openBooking(serviceId);
    }
  }

  function openGuestProfile() {
    state.modal = "guest-profile";
    modalNeedsEntrance = true;
    state.guestProfile = null;
    state.profileLoading = true;
    state.profileError = "";
    renderGuestProfile();
    void loadGuestProfile();
  }

  async function loadGuestProfile() {
    try {
      state.guestProfile = await request("auth.php?operation=guest_profile");
      state.guest.email = state.guestProfile.email;
      state.profileError = "";
    } catch (error) {
      state.profileError = error.message;
    } finally {
      state.profileLoading = false;
      renderGuestProfile();
    }
  }

  function renderGuestProfile() {
    if (state.modal !== "guest-profile") return;
    const profile = state.guestProfile;
    const bookings = profile?.bookings || [];
    const bookingRows = bookings.length
      ? `<div class="profile-bookings">${bookings.map((booking) => `<article class="profile-booking"><div class="profile-booking-heading"><strong>${escapeHtml(booking.serviceName)}</strong><span class="status ${escapeHtml(booking.status)}">${booking.status === "on_progress" ? "On Progress" : escapeHtml(booking.status.replaceAll("_", " "))}</span></div><p>${escapeHtml(booking.bookingCode)} · ${escapeHtml(booking.roomCode)}</p><p>${escapeHtml(booking.bookingDate)} at ${escapeHtml(formatTime(booking.startTime))} · ${booking.guestCount} ${booking.guestCount === 1 ? "guest" : "guests"}</p><p class="profile-booking-therapist"><strong>Therapist:</strong> ${booking.therapistName ? escapeHtml(booking.therapistName) : "Not assigned yet"}</p><strong>${money(booking.totalAmount)}</strong>${booking.status !== "cancelled" ? `<div class="profile-payment-actions"><a class="button button-outline" href="checkout_gcash.php?order_id=${encodeURIComponent(booking.bookingCode)}">Pay by GCash</a><a class="button button-outline" href="order_status.php?order_id=${encodeURIComponent(booking.bookingCode)}">Payment status</a></div>` : ""}</article>`).join("")}</div>`
      : '<p class="muted">No reservations are linked to your account yet.</p>';
    const pendingEmail = profile?.pendingEmail;
    const emailChangeStage = profile?.emailChangeStage;
    const emailSection = pendingEmail
      ? `<div class="profile-email-content"><p>${emailChangeStage === "current" ? `A confirmation code was sent to your current email <strong>${escapeHtml(profile?.email)}</strong>. After confirming it, we’ll send a second code to <strong>${escapeHtml(pendingEmail)}</strong>.` : `Your current email is confirmed. Enter the code sent to your new address <strong>${escapeHtml(pendingEmail)}</strong> to finish the change.`} Each code expires after 15 minutes.</p><form id="profile-email-verify-form"><label class="field">Six-digit verification code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><div class="dialog-actions"><button class="button button-dark" type="submit">${emailChangeStage === "current" ? "Confirm current email" : "Verify new email"}</button></div></form><form id="profile-email-resend-form" class="profile-resend-form"><label class="field">Current password<input name="currentPassword" type="password" autocomplete="current-password" required></label><button class="button button-outline" type="submit">Resend this step’s code</button></form></div>`
      : `<div class="profile-email-content"><p>We’ll send a verification code to the new address. Your current email stays active until you verify it.</p><form id="profile-email-form"><div class="form-grid"><label class="field">New email address<input name="newEmail" type="email" autocomplete="email" maxlength="254" required></label><label class="field">Current password<input name="currentPassword" type="password" autocomplete="current-password" required></label></div><div class="dialog-actions"><button class="button button-dark" type="submit">Send verification code</button></div></form></div>`;
    const accountDetails = state.profileLoading
      ? '<div class="profile-loading" role="status"><span class="profile-loading-mark" aria-hidden="true"></span><p>Loading your account…</p></div>'
      : state.profileError
        ? `<p class="error-box" role="alert">${escapeHtml(state.profileError)}</p>`
        : `<section class="profile-identity"><span class="profile-avatar" aria-hidden="true">${escapeHtml((profile?.name || state.guest?.name || "G").trim().charAt(0).toUpperCase())}</span><div><small>Signed in as</small><strong>${escapeHtml(profile?.name || state.guest?.name)}</strong><span>${escapeHtml(profile?.email || state.guest?.email)}</span></div></section><section class="booking-block profile-reservation-panel"><div class="profile-section-heading"><div><small>YOUR SPA VISITS</small><h3>Your reservations</h3></div><span class="profile-count">${bookings.length}</span></div>${bookingRows}</section>`;
    const passwordSection = profile?.passwordChangePending
      ? `<form id="profile-password-verify-form"><p>A six-digit code was sent to your current email <strong>${escapeHtml(profile?.email)}</strong>. Enter it to confirm the new password. The code expires after 15 minutes.</p><label class="field">Six-digit verification code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><div class="profile-form-actions"><button class="button button-dark" type="submit">Verify and change password</button></div></form><form id="profile-password-resend-form" class="profile-resend-form"><label class="field">Current password<input name="currentPassword" type="password" autocomplete="current-password" required></label><button class="button button-outline" type="submit">Send a new code</button></form>`
      : `<form id="profile-password-form"><div class="form-grid"><label class="field">Current password<input name="currentPassword" type="password" autocomplete="current-password" maxlength="50" required></label><label class="field">New password<input name="newPassword" type="password" autocomplete="new-password" minlength="12" maxlength="50" required><small>At least 12 characters. A unique 16+ character password is recommended.</small><span class="password-strength" data-password-strength><span class="password-strength-meter" aria-hidden="true"><span data-strength-fill></span></span><span class="password-strength-label" data-strength-label aria-live="polite">Recommended: use 16+ unique characters.</span></span></label><label class="field full">Confirm new password<input name="confirmPassword" type="password" autocomplete="new-password" minlength="12" maxlength="50" required></label></div><div class="profile-form-actions"><button class="button button-dark" type="submit">Send password verification code</button></div></form>`;
    const profileForms = state.profileLoading || state.profileError
      ? ""
      : `<section class="booking-block profile-settings-card"><div class="profile-section-heading"><div><small>ACCOUNT SECURITY</small><h3>${profile?.passwordChangePending ? "Verify password change" : "Change password"}</h3></div><span class="profile-section-icon" aria-hidden="true">01</span></div>${passwordSection}</section><section class="booking-block profile-settings-card"><div class="profile-section-heading"><div><small>EMAIL PREFERENCES</small><h3>${pendingEmail ? (emailChangeStage === "current" ? "Confirm current email" : "Verify new email") : "Change email address"}</h3></div><span class="profile-section-icon" aria-hidden="true">02</span></div>${emailSection}</section>`;
    renderOverlay(`<div class="overlay" data-action="overlay-close"><section class="dialog auth-dialog profile-dialog" role="dialog" aria-modal="true" aria-labelledby="profile-title"><header class="dialog-header"><div><small>Bella Vista Oasis Spa · Guest Account</small><h2 id="profile-title">Your guest dashboard</h2></div><button class="close-button" data-action="close" aria-label="Close guest dashboard">×</button></header><div class="dialog-content"><div class="profile-layout"><div class="profile-overview">${accountDetails}</div><div class="profile-security">${profileForms}</div></div><div class="profile-footer"><span>Your account details are protected.</span><div><button class="button button-outline" data-action="close">Close</button><button class="button button-outline" data-action="guest-logout">Sign out</button></div></div></div></section></div>`);
    updatePasswordStrength(document.getElementById("profile-password-form"));
  }

  function saveBookingFields(form) {
    const values = new FormData(form);
    state.bookingFields = {
      guestName: values.get("guestName") || "",
      guestEmail: values.get("guestEmail") || "",
      guestPhone: values.get("guestPhone") || "",
      specialRequests: values.get("specialRequests") || "",
      noKnownConditions: values.has("noKnownConditions"),
      healthAgreed: values.has("healthAgreed"),
      termsAccepted: values.has("termsAccepted"),
      healthConditions: values.getAll("healthConditions"),
    };
  }

  function openBooking(serviceId = "single-room") {
    if (!state.guest && !(isManagerRoute && state.manager)) {
      state.pendingServiceId = serviceId;
      openGuestAuth("login");
      return;
    }
    const service = serviceById[serviceId] || serviceList[0];
    state.modal = "booking";
    modalNeedsEntrance = true;
    state.serviceId = service.id;
    state.roomCode = service.rooms[0];
    state.date = manilaDate();
    state.guestCount = service.minGuests;
    state.selectedTime = "";
    state.bookingError = "";
    state.bookingFields = {};
    state.confirmation = null;
    state.bookings = [];
    state.availabilityError = "";
    renderBooking();
    void loadAvailability();
  }

  function currentSlots() {
    const service = serviceById[state.serviceId];
    const today = manilaDate();
    const timeParts = new Intl.DateTimeFormat("en-GB", { timeZone: "Asia/Manila", hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(new Date()).split(":");
    const nowMinutes = Number(timeParts[0]) * 60 + Number(timeParts[1]);
    const slots = [];
    for (let start = 13 * 60; start + service.duration <= 21 * 60; start += service.duration + 30) {
      const end = start + service.duration;
      const cleaningEnd = end + 30;
      const toTime = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
      const startTime = toTime(start);
      const endTime = toTime(end);
      const cleaningEndTime = toTime(cleaningEnd);
      const conflict = state.bookings.some((booking) => !(cleaningEndTime <= booking.startTime || startTime >= booking.cleaningEndTime));
      slots.push({
        startTime, endTime, cleaningEndTime, conflict,
        available: !conflict && !(state.date < today || (state.date === today && start <= nowMinutes)),
      });
    }
    return slots;
  }

  async function loadAvailability() {
    if (state.modal !== "booking" || state.confirmation) return;
    state.availabilityLoading = true;
    state.availabilityError = "";
    state.bookings = [];
    renderBooking();
    try {
      const result = await request(`availability.php?date=${encodeURIComponent(state.date)}&roomCode=${encodeURIComponent(state.roomCode)}`);
      state.bookings = result.bookings;
      state.availabilityLoading = false;
      const firstAvailable = currentSlots().find((slot) => slot.available);
      if (!currentSlots().some((slot) => slot.available && slot.startTime === state.selectedTime)) state.selectedTime = firstAvailable?.startTime || "";
    } catch (error) {
      state.availabilityLoading = false;
      state.availabilityError = error.message;
    }
    renderBooking();
  }

  function renderBooking() {
    if (state.modal !== "booking") return;
    overlay = document.getElementById("overlay-root") || overlay;
    if (state.confirmation) {
      const booking = state.confirmation;
      const gcashQrUrl = escapeHtml(window.gcashQrCodeUrl || `${projectPath}assets/images/gcash-qr-code.jpg`);
      renderOverlay(`<div class="overlay" data-action="overlay-close"><section class="dialog booking-dialog booking-confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="booking-title"><header class="dialog-header"><div><small>Bella Vista Oasis Spa · Sanctuary Booking</small><h2 id="booking-title">${isManagerRoute ? "Reservation Confirmed" : "Booking Submitted"}</h2></div><button class="close-button" data-action="close" aria-label="Close">×</button></header><div class="dialog-content"><div class="success-box"><strong>${isManagerRoute ? "Your treatment is reserved." : "Your booking is On Progress while our team verifies your GCash payment."}</strong><p>Booking code: <strong>${escapeHtml(booking.bookingCode)}</strong></p><p>${escapeHtml(booking.guestName)} · ${escapeHtml(booking.serviceName)} · ${escapeHtml(booking.roomCode)}</p><p>${escapeHtml(booking.bookingDate)} at ${escapeHtml(formatTime(booking.startTime))} · ${money(booking.totalAmount)}</p></div>${!isManagerRoute ? `<section class="confirmation-payment" aria-labelledby="confirmation-payment-title"><div class="confirmation-payment-copy"><p class="eyebrow">Pay securely with GCash</p><h3 id="confirmation-payment-title">Scan to pay your booking total</h3><p>Open GCash and scan the QR code. Pay the exact amount below, then continue to submit your 13-digit reference number and receipt for staff verification.</p><strong class="confirmation-payment-amount">${money(booking.totalAmount)}</strong></div><a class="confirmation-qr-link" href="${gcashQrUrl}" target="_blank" rel="noopener" aria-label="Open the GCash QR code at full size"><img src="${gcashQrUrl}" alt="GCash QR code for Bella Vista Oasis Spa"></a><div class="confirmation-account"><strong>Confirm the recipient</strong><span>Account Name: ED**R SO**Y P.</span><span>Mobile No.: +63 997 695 ••••</span><span>User ID: ••••••••••WP8W1O</span></div><a class="button button-dark confirmation-payment-button" href="${escapeHtml(projectPath)}checkout_gcash.php?order_id=${encodeURIComponent(booking.bookingCode)}">Continue to payment submission</a></section>` : ""}<div class="dialog-actions"><button class="button button-outline" data-action="print">Print confirmation</button><button class="button button-dark" data-action="close">Done</button></div></div></section></div>`);
      return;
    }
    const service = serviceById[state.serviceId];
    const slots = currentSlots();
    const roomOptions = service.rooms.map((code) => `<option value="${code}" ${state.roomCode === code ? "selected" : ""}>${code} — ${roomNames[code]}</option>`).join("");
    const slotHtml = slots.length ? slots.map((slot) => `<button type="button" class="slot${slot.conflict ? " booked" : ""}${slot.startTime === state.selectedTime ? " selected" : ""}" data-action="slot" data-time="${slot.startTime}" ${(!slot.available || state.availabilityLoading || Boolean(state.availabilityError)) ? "disabled" : ""}>${formatTime(slot.startTime)} – ${formatTime(slot.endTime)}${slot.conflict ? " · Already booked" : ""}</button>`).join("") : "<p>There are no sessions for this service on this date.</p>";
    const guestOptions = Array.from({ length: service.maxGuests - service.minGuests + 1 }, (_, index) => service.minGuests + index).map((count) => `<option value="${count}" ${state.guestCount === count ? "selected" : ""}>${count} ${count === 1 ? "guest" : "guests"}${count > service.minGuests ? ` (+${money((count - service.minGuests) * service.extra)} fee)` : ""}</option>`).join("");
    const managerGuestDetails = isManagerRoute
      ? `<section class="booking-block booking-step-card booking-walkin-card"><h3><span class="booking-step-number">01</span><span><small>WALK-IN GUEST</small>Enter guest contact details</span></h3><p class="booking-step-note">Add the guest’s name and email before completing the reservation.</p><div class="form-grid"><label class="field">Guest name<input name="guestName" autocomplete="name" required maxlength="35" title="Use letters and spaces only; maximum 35 characters" value="${escapeHtml(state.bookingFields.guestName)}"></label><label class="field">Guest email<input name="guestEmail" type="email" autocomplete="email" required maxlength="254" value="${escapeHtml(state.bookingFields.guestEmail)}"></label><label class="field">Phone number<input name="guestPhone" type="tel" autocomplete="tel" required maxlength="13" pattern="\\+63[0-9]{10}" placeholder="+639171234567" title="Enter a Philippines mobile number in +63 format" value="${escapeHtml(state.bookingFields.guestPhone || "+63")}"></label><label class="field full">Special requests or additional health details<textarea name="specialRequests" maxlength="2000">${escapeHtml(state.bookingFields.specialRequests)}</textarea></label></div></section>`
      : "";
    renderOverlay(`<div class="overlay" data-action="overlay-close"><section class="dialog booking-dialog" role="dialog" aria-modal="true" aria-labelledby="booking-title"><header class="dialog-header"><div><small>Sanctuary Booking Experience</small><h2 id="booking-title">Reserve Treatment Suite</h2><p class="booking-header-note">A few thoughtful details help us prepare your visit.</p></div><button class="close-button" data-action="close" aria-label="Close booking dialog">×</button></header><div class="dialog-content">
      ${state.bookingError ? `<div class="error-box" role="alert">${escapeHtml(state.bookingError)}</div>` : ""}
      <form id="booking-form">
        <div class="booking-intro"><span class="booking-intro-mark" aria-hidden="true">BV</span><p>Reserve a quiet moment in the sanctuary. Complete each step below to confirm your treatment.</p></div>
        ${managerGuestDetails}
        <section class="booking-block booking-step-card"><h3><span class="booking-step-number">${isManagerRoute ? "02" : "01"}</span><span><small>TREATMENT</small>Choose a treatment and room</span></h3><div class="form-grid"><label class="field full">Treatment<select name="serviceId" id="booking-service">${serviceList.map((item) => `<option value="${item.id}" ${item.id === state.serviceId ? "selected" : ""}>${escapeHtml(item.name)} — ${money(item.base)}</option>`).join("")}</select></label><label class="field">Treatment room<select name="roomCode" id="booking-room">${roomOptions}</select></label><label class="field">Number of guests<select name="guestCount" id="booking-guests">${guestOptions}</select></label></div><p class="booking-step-note">${escapeHtml(service.label)} · Base rate ${money(service.base)}${service.extra ? ` · ${money(service.extra)} per extra guest` : ""}</p></section>
        <section class="booking-block booking-step-card"><h3><span class="booking-step-number">${isManagerRoute ? "03" : "02"}</span><span><small>APPOINTMENT</small>Choose date and time</span></h3><div class="form-grid"><label class="field">Reservation date<input type="date" name="bookingDate" id="booking-date" min="${manilaDate()}" value="${escapeHtml(state.date)}" required></label><div class="booking-session-note"><strong>Sessions for ${escapeHtml(state.roomCode)}</strong><p class="muted">${escapeHtml(service.label)} plus 30-minute sanitization</p></div></div>${state.availabilityError ? `<div class="error-box" role="alert">${escapeHtml(state.availabilityError)}</div>` : ""}${state.availabilityLoading ? "<p>Checking availability…</p>" : `<div class="slot-grid">${slotHtml}</div>`}${!state.availabilityLoading && !state.availabilityError && !slots.some((slot) => slot.available) ? "<p>No sessions are available. Choose another date or room.</p>" : ""}</section>
        <section class="booking-block booking-step-card booking-health-card"><h3><span class="booking-step-number">${isManagerRoute ? "04" : "03"}</span><span><small>WELLBEING &amp; POLICIES</small>Health declaration and policies</span></h3><p>Please indicate any conditions so the therapist can prepare appropriately.</p><div class="form-grid"><label class="check-row"><input type="checkbox" name="noKnownConditions" value="yes" ${state.bookingFields.noKnownConditions ? "checked" : ""}> I have no known medical conditions or treatment sensitivities.</label><label class="check-row"><input type="checkbox" name="healthConditions" value="Allergies" ${state.bookingFields.healthConditions?.includes("Allergies") ? "checked" : ""}> Allergies</label><label class="check-row"><input type="checkbox" name="healthConditions" value="Skin sensitivities" ${state.bookingFields.healthConditions?.includes("Skin sensitivities") ? "checked" : ""}> Skin sensitivities</label><label class="check-row"><input type="checkbox" name="healthConditions" value="Chronic condition or high blood pressure" ${state.bookingFields.healthConditions?.includes("Chronic condition or high blood pressure") ? "checked" : ""}> Chronic condition or high blood pressure</label><label class="check-row"><input type="checkbox" name="healthConditions" value="Recent surgery or injury" ${state.bookingFields.healthConditions?.includes("Recent surgery or injury") ? "checked" : ""}> Recent surgery or injury</label><label class="check-row"><input type="checkbox" name="healthConditions" value="Pregnancy" ${state.bookingFields.healthConditions?.includes("Pregnancy") ? "checked" : ""}> Pregnancy</label></div><label class="check-row"><input type="checkbox" name="healthAgreed" required ${state.bookingFields.healthAgreed ? "checked" : ""}> I have truthfully declared my health information and will consult the therapist before treatment.</label><label class="check-row"><input type="checkbox" name="termsAccepted" required ${state.bookingFields.termsAccepted ? "checked" : ""}> I agree to the guest policies, including arrival, cancellation, and quiet-environment policies.</label></section>
        ${!isManagerRoute ? `<section class="booking-block booking-step-card booking-guest-details"><h3><span class="booking-step-number">04</span><span><small>GUEST DETAILS</small>Contact details</span></h3><div class="booking-guest-note"><span class="booking-guest-icon" aria-hidden="true">${escapeHtml((state.guest?.name || "G").trim().charAt(0).toUpperCase())}</span><p>Booking as <strong>${escapeHtml(state.guest?.name)}</strong><small>Your verified account email will be attached to this reservation.</small></p></div><div class="form-grid"><label class="field">Phone number<input name="guestPhone" type="tel" autocomplete="tel" required maxlength="13" pattern="\\+63[0-9]{10}" placeholder="+639171234567" title="Enter a Philippines mobile number in +63 format" value="${escapeHtml(state.bookingFields.guestPhone || "+63")}"></label><label class="field full">Special requests or additional health details<textarea name="specialRequests" maxlength="2000">${escapeHtml(state.bookingFields.specialRequests)}</textarea></label></div></section>` : ""}
        <section class="booking-block booking-step-card booking-security-card"><h3><span class="booking-step-number">05</span><span><small>FINAL STEP</small>Security check</span></h3><div class="captcha-slot" data-captcha></div></section>
        <div class="booking-submit-bar"><p><small>ESTIMATED TOTAL</small><strong>${money(service.base + Math.max(0, state.guestCount - service.minGuests) * service.extra)}</strong></p><div class="dialog-actions"><button type="button" class="button button-outline" data-action="close">Cancel</button><button type="submit" class="button button-dark" ${state.availabilityLoading ? "disabled" : ""}>Confirm reservation</button></div></div>
      </form></div></section></div>`);
    renderCaptcha(document.getElementById("booking-form"));
  }

  function showManagerShell() {
    const splash = document.getElementById("brand-splash");
    document.body.innerHTML = `<div class="manager-page"><header class="manager-header"><a class="brand" href="${escapeHtml(projectPath)}"><span class="brand-mark">BV</span><span><strong>Bella Vista Oasis Spa</strong><small>Staff &amp; Manager Access</small></span></a></header><main id="manager-main"><p class="manager-wrap">Checking staff access…</p></main><div id="overlay-root"></div></div>`;
    if (splash) document.body.append(splash);
    overlay = document.getElementById("overlay-root");
  }

  function showBrandSplash() {
    const splash = document.getElementById("brand-splash");
    if (!splash) return;
    window.setTimeout(() => {
      splash.remove();
      document.documentElement.classList.remove("brand-splash-active");
    }, 2000);
  }

  function renderLogin(error = "") {
    const main = document.getElementById("manager-main");
    if (!main) return;
    main.innerHTML = `<section class="login-card"><p class="eyebrow">Staff access</p><h1>Manager sign in</h1><p>Sign in to manage reservations, treatment rooms, and staff assignments.</p>${error ? `<div class="error-box" role="alert">${escapeHtml(error)}</div>` : ""}<form id="manager-login"><label class="field">Username<input name="username" autocomplete="username" required></label><label class="field" style="margin-top:14px">Password<input type="password" name="password" autocomplete="current-password" maxlength="50" required></label><div class="captcha-slot" data-captcha></div><button class="button button-dark" style="width:100%;margin-top:18px">Sign in</button></form></section>`;
    renderCaptcha(document.getElementById("manager-login"));
  }

  function showManagerLoginError(message, resetCaptcha = false) {
    const form = document.getElementById("manager-login");
    if (!form) {
      renderLogin(message);
      return;
    }

    let errorBox = form.querySelector('[data-login-error="true"]');
    if (!errorBox) {
      errorBox = document.createElement("div");
      errorBox.className = "error-box";
      errorBox.setAttribute("role", "alert");
      errorBox.dataset.loginError = "true";
      form.prepend(errorBox);
    }
    errorBox.textContent = message;

    if (resetCaptcha) {
      delete form.dataset.recaptchaToken;
      const widgetId = form.dataset.recaptchaWidget;
      if (widgetId !== undefined && typeof window.grecaptcha?.reset === "function") {
        window.grecaptcha.reset(Number(widgetId));
      }
    }
  }

  function filteredAppointments() {
    const appointments = state.manager?.appointments || [];
    const search = state.search.toLowerCase();
    return appointments.filter((appointment) =>
      (!state.reportDate || appointment.bookingDate === state.reportDate) &&
      (state.filter === "all" || appointment.status === state.filter) &&
      (!search || [appointment.guestName, appointment.bookingCode, appointment.roomCode].some((value) => String(value || "").toLowerCase().includes(search)))
    );
  }

  function renderDashboard() {
    const main = document.getElementById("manager-main");
    if (!main || !state.manager) return;
    const allAppointments = state.manager.appointments.filter((appointment) => !state.reportDate || appointment.bookingDate === state.reportDate);
    const appointments = filteredAppointments();
    const revenue = allAppointments.filter((item) => !["cancelled", "on_progress"].includes(item.status)).reduce((sum, item) => sum + Number(item.totalAmount), 0);
    const guests = allAppointments.filter((item) => item.status !== "cancelled").reduce((sum, item) => sum + Number(item.guestCount), 0);
    const inSession = allAppointments.filter((item) => item.status === "in_session").length;
    const cleaning = allAppointments.filter((item) => item.status === "cleaning").length;
    const therapistsInSession = state.manager.therapists.filter((therapist) => therapist.status === "in_session").length;
    const therapistsAssigned = state.manager.therapists.filter((therapist) => therapist.status === "assigned").length;
    const statusOptions = ["on_progress", "confirmed", "in_session", "cleaning", "completed", "cancelled"];
    const statusLabel = (status) => status === "on_progress" ? "On Progress" : status.replaceAll("_", " ");
    const rows = appointments.map((item) => {
      const availableStatuses = item.status === "on_progress"
        ? ["on_progress", "confirmed", "cancelled"]
        : item.status === "confirmed"
          ? statusOptions
          : statusOptions.filter((status) => status !== "on_progress");
      const needsPaymentVerification = item.status === "on_progress" && item.paymentStatus !== "Verified";
      const selected = state.selectedBookingIds.includes(item.id);
      const selectionCell = state.selectionMode
        ? `<td><input class="manager-row-select" type="checkbox" data-action="select-booking" data-appointment="${escapeHtml(item.id)}" aria-label="Select booking ${escapeHtml(item.bookingCode)}" ${selected ? "checked" : ""}></td>`
        : "";
      const statusOptionHtml = availableStatuses.map((status) => {
        const blockedConfirmation = status === "confirmed" && needsPaymentVerification;
        return `<option value="${status}" ${status === item.status ? "selected" : ""} ${blockedConfirmation ? "disabled" : ""}>${escapeHtml(statusLabel(status))}${blockedConfirmation ? " — verify payment first" : ""}</option>`;
      }).join("");
      return `<tr>${selectionCell}<td><strong>${escapeHtml(item.bookingCode)}</strong></td><td>${escapeHtml(formatTime(item.startTime))} – ${escapeHtml(formatTime(item.endTime))}<br><small>Sanitize until ${escapeHtml(formatTime(item.cleaningEndTime))}</small></td><td>${escapeHtml(item.roomCode)}<br>${escapeHtml(item.serviceName)}</td><td>${escapeHtml(item.guestName)}<br><small>${escapeHtml(item.guestPhone)} · ${escapeHtml(item.guestEmail)}</small></td><td>${escapeHtml(item.guestCount)} guest(s)</td><td><select data-action="therapist" data-appointment="${escapeHtml(item.id)}"><option value="">Unassigned</option>${state.manager.therapists.map((therapist) => `<option value="${escapeHtml(therapist.id)}" ${item.therapistId === therapist.id ? "selected" : ""}>${escapeHtml(therapist.name)}</option>`).join("")}</select></td><td>${money(item.totalAmount)}</td><td>${escapeHtml(item.paymentStatus || "Not submitted")}</td><td><span class="status ${escapeHtml(item.status)}">${escapeHtml(statusLabel(item.status))}</span><select data-action="status" data-appointment="${escapeHtml(item.id)}">${statusOptionHtml}</select>${needsPaymentVerification ? '<small class="status-help">Approve the GCash payment to confirm automatically.</small>' : ""}</td></tr>`;
    }).join("");
    const therapistCards = state.manager.therapists.map((therapist) => {
      const statusLabel = therapist.status === "in_session" ? "In session" :
        therapist.status === "assigned" ? "Assigned" : "Available";
      const assignment = therapist.assignedBookingCode
        ? `<p class="therapist-assignment">Booking ${escapeHtml(therapist.assignedBookingCode)}${therapist.assignedBookingStartTime ? ` · ${escapeHtml(formatTime(therapist.assignedBookingStartTime))}` : ""}${therapist.assignedBookingCount > 1 ? ` · ${therapist.assignedBookingCount} bookings today` : ""}</p>`
        : `<p class="therapist-assignment">No bookings assigned for this date</p>`;
      return `<article class="therapist-card"><div class="therapist-card-heading"><strong>${escapeHtml(therapist.name)}</strong><span class="therapist-status-pill ${escapeHtml(therapist.status)}">${statusLabel}</span></div><p class="therapist-specialties">${escapeHtml(therapist.specialties.join(" · "))}</p>${assignment}</article>`;
    }).join("");
    const deletionControls = state.selectionMode
      ? `<button class="button button-outline manager-delete-button" data-action="delete-selected" type="button" ${!state.selectedBookingIds.length || state.deletingBookings ? "disabled" : ""}>Delete selected (${state.selectedBookingIds.length})</button><button class="button button-outline manager-cancel-selection-button" data-action="cancel-selection" type="button" ${state.deletingBookings ? "disabled" : ""}>Cancel selection</button>`
      : `<button class="button button-outline manager-select-delete-button" data-action="begin-selection" type="button" ${!appointments.length || state.deletingBookings ? "disabled" : ""}>Select to delete</button>`;
    const selectionColumnHeader = state.selectionMode ? "<th>Select</th>" : "";
    const reservationColumnCount = state.selectionMode ? 10 : 9;
    const selectionHint = state.selectionMode
      ? "Select one or more bookings, then choose Delete selected."
      : "Use Select to delete to choose specific bookings.";
    main.innerHTML = `<div class="notice manager-notice" data-manager-notice role="status" ${state.error ? "" : "hidden"}>${escapeHtml(state.error)}</div><div class="manager-wrap"><div class="dashboard-title"><div><p class="eyebrow">Operations &amp; Scheduling Console</p><h1>Sanctuary Manager Dashboard</h1><small data-manager-clock>Current spa time (Manila): ${escapeHtml(new Intl.DateTimeFormat("en-PH", { timeZone: "Asia/Manila", dateStyle: "medium", timeStyle: "short" }).format(new Date(state.manager.serverNow)))}</small></div><div class="dashboard-actions"><button class="button button-outline" data-action="refresh-manager" type="button">Refresh</button><a class="button button-outline" href="${escapeHtml(projectPath)}admin_payments.php">GCash payments</a><button class="button button-dark" data-action="walkin">Create Walk-In Booking</button><button class="button button-outline" data-action="logout" type="button">Sign out</button></div></div>
      <section class="metrics"><article class="metric"><small>Calculated revenue</small><strong>${money(revenue)}</strong><span>For ${escapeHtml(state.reportDate)}</span></article><article class="metric"><small>Total guests scheduled</small><strong>${guests}</strong><span>${allAppointments.length} bookings</span></article><article class="metric"><small>Active / in session</small><strong>${inSession}</strong><span>${cleaning} rooms sanitizing</span></article><article class="metric"><small>Therapists</small><strong>${state.manager.therapists.length}</strong><span>${therapistsInSession} in session · ${therapistsAssigned} assigned</span></article></section>
      <section class="panel"><h2>Room &amp; 30-Minute Sanitization Status</h2><div class="room-grid">${state.manager.rooms.map((room) => `<article class="room"><strong>${escapeHtml(room.code)} · ${escapeHtml(room.status.replace("_", " "))}</strong><p>${escapeHtml(room.name)}<br>Capacity: ${escapeHtml(room.capacity)} guests</p>${room.currentBookingId ? `<small>${escapeHtml(room.currentBookingId)}</small>` : ""}</article>`).join("")}</div></section>
      <section class="panel"><h2>Therapist Availability &amp; Assignments</h2><div class="therapist-status-grid">${therapistCards || `<p>No therapists are configured.</p>`}</div></section>
      <section class="panel"><h2>Reservations &amp; Therapist Assignment</h2><div class="form-grid"><label class="field">Report date<input class="control" id="report-date" type="date" value="${escapeHtml(state.reportDate)}"></label><label class="field">Search guest or booking code<input class="control" id="manager-search" value="${escapeHtml(state.search)}" placeholder="Name, code or room"></label><div class="status-filter-group"><label class="field" for="manager-filter">Status filter</label><div class="status-filter-controls"><select class="control" id="manager-filter"><option value="all">All statuses</option>${statusOptions.map((status) => `<option value="${status}" ${state.filter === status ? "selected" : ""}>${escapeHtml(statusLabel(status))}</option>`).join("")}</select>${deletionControls}<button class="button button-outline manager-delete-button" data-action="delete-filtered" type="button" ${state.filter === "all" || !state.manager.appointments.some((item) => item.status === state.filter) || state.deletingBookings ? "disabled" : ""}>Delete filtered</button><button class="button button-outline manager-delete-button" data-action="delete-all" type="button" ${!state.manager.appointments.length || state.deletingBookings ? "disabled" : ""}>Delete all</button></div><small>${selectionHint} Deletion also removes linked GCash records and receipt files.</small></div></div><div class="table-wrap"><table><thead><tr>${selectionColumnHeader}<th>Booking code</th><th>Time &amp; 30m gap</th><th>Room</th><th>Guest</th><th>Party</th><th>Therapist</th><th>Amount</th><th>Payment</th><th>Status</th></tr></thead><tbody>${rows || `<tr><td colspan="${reservationColumnCount}">No reservations found for these filters.</td></tr>`}</tbody></table></div></section>
      </div>`;
  }

  async function loadManagerData({ silent = false } = {}) {
    const previousData = state.manager ? JSON.stringify({
      appointments: state.manager.appointments,
      rooms: state.manager.rooms,
      therapists: state.manager.therapists,
    }) : null;
    try {
      state.error = "";
      state.manager = await request(`manager.php?date=${encodeURIComponent(state.reportDate)}`);
      const currentAppointmentIds = new Set(state.manager.appointments.map((item) => item.id));
      state.selectedBookingIds = state.selectedBookingIds.filter((id) => currentAppointmentIds.has(id));
      const currentData = JSON.stringify({
        appointments: state.manager.appointments,
        rooms: state.manager.rooms,
        therapists: state.manager.therapists,
      });
      if (!silent || previousData !== currentData) renderDashboard();
      else {
        const now = new Intl.DateTimeFormat("en-PH", { timeZone: "Asia/Manila", dateStyle: "medium", timeStyle: "short" }).format(new Date(state.manager.serverNow));
        const clock = document.querySelector("[data-manager-clock]");
        if (clock) clock.textContent = `Current spa time (Manila): ${now}`;
        const notice = document.querySelector("[data-manager-notice]");
        if (notice) {
          notice.hidden = true;
          notice.textContent = "";
        }
      }
    } catch (error) {
      if (error.message.includes("sign in")) {
        state.manager = null;
        renderLogin(error.message);
      } else {
        state.error = error.message;
        if (state.manager) {
          if (silent) {
            const notice = document.querySelector("[data-manager-notice]");
            if (notice) {
              notice.hidden = false;
              notice.textContent = error.message;
            }
          } else {
            renderDashboard();
          }
        } else {
          renderLogin(`Your sign-in was accepted, but the dashboard could not load: ${error.message}`);
        }
      }
    }
  }

  async function deleteManagerAppointments(scope) {
    if (state.deletingBookings) return;
    const filter = scope === "filtered" ? state.filter : "all";
    if (scope === "filtered" && filter === "all") return;
    const selectedIds = scope === "selected"
      ? state.selectedBookingIds.filter((id) => state.manager.appointments.some((item) => item.id === id))
      : [];

    const matchingCount = state.manager.appointments.filter((item) =>
      scope === "all" || (scope === "selected" ? selectedIds.includes(item.id) : item.status === filter)
    ).length;
    if (!matchingCount) {
      window.alert("There are no bookings in this selection.");
      return;
    }

    const statusName = filter === "on_progress" ? "On Progress" : filter.replaceAll("_", " ");
    const selectedCodes = scope === "selected"
      ? state.manager.appointments.filter((item) => selectedIds.includes(item.id)).map((item) => item.bookingCode)
      : [];
    const selectedPreview = selectedCodes.length > 5
      ? `${selectedCodes.slice(0, 5).join(", ")} and ${selectedCodes.length - 5} more`
      : selectedCodes.join(", ");
    const selection = scope === "all"
      ? `Permanently delete all ${matchingCount} bookings`
      : scope === "selected"
        ? `Permanently delete ${matchingCount} manually selected ${matchingCount === 1 ? "booking" : "bookings"} (${selectedPreview})`
        : `Permanently delete ${matchingCount} ${statusName} bookings across all dates`;
    if (!window.confirm(`${selection}? Linked GCash payment records and receipt screenshots will also be permanently deleted. This cannot be undone.`)) {
      return;
    }

    state.deletingBookings = true;
    state.error = "";
    renderDashboard();
    try {
      const result = await request("manager_action.php", {
        method: "POST",
        body: JSON.stringify({
          action: scope === "all" ? "delete_all" : scope === "selected" ? "delete_selected" : "delete_filtered",
          filter,
          appointmentIds: selectedIds,
          csrfToken: state.managerCsrfToken,
        }),
      });
      state.selectionMode = false;
      state.selectedBookingIds = [];
      await loadManagerData();
      const cleanupWarning = result.receiptCleanupWarning ? `\n\nWarning: ${result.receiptCleanupWarning}` : "";
      window.alert(`Permanently deleted ${result.deleted} ${result.deleted === 1 ? "booking" : "bookings"}, including linked payment records and receipt files.${cleanupWarning}`);
    } catch (error) {
      state.error = error.message;
      renderDashboard();
    } finally {
      state.deletingBookings = false;
      if (state.manager) renderDashboard();
    }
  }

  async function checkManagerSession() {
    try {
      const session = await request("auth.php?operation=session");
      if (!session.authenticated) return renderLogin();
      state.managerCsrfToken = session.csrfToken || "";
      await loadManagerData();
    } catch (error) {
      renderLogin(error.message);
    }
  }

  async function submitBooking(form) {
    const values = new FormData(form);
    const service = serviceById[state.serviceId];
    const healthConditions = values.getAll("healthConditions");
    const recaptchaToken = getCaptchaToken(form);
    if (isManagerRoute) {
      saveBookingFields(form);
      const guestName = String(values.get("guestName") || "").trim();
      const guestEmail = String(values.get("guestEmail") || "").trim();
      if (!guestName || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(guestEmail)) {
        state.bookingError = "Enter the walk-in guest’s name and a valid email address in step 01.";
        renderBooking();
        const field = document.querySelector(`#booking-form [name="${guestName ? "guestEmail" : "guestName"}"]`);
        field?.focus();
        field?.scrollIntoView({ block: "center", behavior: "smooth" });
        return;
      }
    }
    if (!values.has("healthAgreed") || !values.has("termsAccepted")) {
      state.bookingError = "Accept the health declaration and booking policies to continue.";
      renderBooking();
      return;
    }
    if (!values.has("noKnownConditions") && healthConditions.length === 0) {
      state.bookingError = "Indicate that you have no known conditions or select any applicable health disclosures.";
      renderBooking();
      return;
    }
    const slot = currentSlots().find((candidate) => candidate.available && candidate.startTime === state.selectedTime);
    if (!slot || state.availabilityLoading || state.availabilityError) {
      state.bookingError = "Choose an available treatment time before confirming.";
      renderBooking();
      return;
    }
    if (!recaptchaToken) {
      state.bookingError = "Complete the reCAPTCHA challenge before confirming your reservation.";
      renderBooking();
      return;
    }
    state.bookingError = "";
    const requests = [healthConditions.length ? `Health disclosures: ${healthConditions.join(", ")}` : "", String(values.get("specialRequests") || "").trim()].filter(Boolean).join("\n");
    const payload = {
      serviceId: service.id, roomCode: state.roomCode, bookingDate: state.date,
      startTime: slot.startTime, guestCount: Number(values.get("guestCount")),
      guestPhone: String(values.get("guestPhone")).trim(), specialRequests: requests,
      healthDeclared: true, termsAccepted: true,
      recaptchaToken,
    };
    if (isManagerRoute) {
      payload.bookingMode = "walkin";
      payload.guestName = String(values.get("guestName")).trim();
      payload.guestEmail = String(values.get("guestEmail")).trim();
    }
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    submit.textContent = "Saving reservation…";
    try {
      const result = await request("bookings.php", { method: "POST", body: JSON.stringify(payload) });
      state.confirmation = result.booking;
      renderBooking();
      if (isManagerRoute) await loadManagerData();
    } catch (error) {
      state.bookingError = error.message;
      renderBooking();
      if (isManagerRoute && error.message.includes("walk-in guest")) {
        const field = document.querySelector('#booking-form [name="guestName"]');
        field?.focus();
        field?.scrollIntoView({ block: "center", behavior: "smooth" });
      }
    }
  }

  document.addEventListener("click", (event) => {
    const target = event.target.closest("[data-action], [data-auth-mode]");
    if (!target) return;
    const action = target.dataset.action;
    if (action === "book" || action === "walkin") openBooking(target.dataset.service || "single-room");
    if (action === "begin-selection") {
      state.selectionMode = true;
      renderDashboard();
    }
    if (action === "cancel-selection") {
      state.selectionMode = false;
      state.selectedBookingIds = [];
      renderDashboard();
    }
    if (action === "delete-selected") void deleteManagerAppointments("selected");
    if (action === "delete-filtered") void deleteManagerAppointments("filtered");
    if (action === "delete-all") void deleteManagerAppointments("all");
    if (action === "guest-auth") state.guest ? openGuestProfile() : openGuestAuth("login");
    if (action === "guest-logout") {
      showLogoutConfirmation(false);
    }
    if (action === "logout-confirm-cancel") {
      animateElementOut(target.closest(".logout-confirm-overlay"));
    }
    if (action === "logout-confirm-accept") {
      const confirmation = target.closest(".logout-confirm-overlay");
      const managerLogout = confirmation?.dataset.logoutManager === "true";
      if (target instanceof HTMLButtonElement) {
        target.disabled = true;
        target.textContent = "Signing out…";
      }
      animateElementOut(confirmation, () => {
        if (managerLogout) {
          void request("auth.php?operation=logout", { method: "POST" }).then(() => {
            state.manager = null;
            state.managerCsrfToken = "";
            state.selectionMode = false;
            state.selectedBookingIds = [];
            renderLogin();
          }).catch((error) => {
            state.error = error.message;
            renderDashboard();
          });
        } else {
          void request("auth.php?operation=guest_logout", { method: "POST" }).then(() => {
            state.guest = null;
            updateGuestHeader();
            closeMainOverlay();
          }).catch((error) => {
            showAuthFeedback("Sign out failed", error.message, "error");
          });
        }
      });
    }
    if (target.dataset.authMode) {
      const email = document.querySelector('#guest-auth-form [name="email"]')?.value || state.authEmail;
      state.authEmail = email;
      state.authMode = target.dataset.authMode;
      state.authError = "";
      state.authMessage = "";
      renderGuestAuth();
    }
    if (action === "auth-resend") {
      const form = document.getElementById("guest-auth-form");
      const recaptchaToken = getCaptchaToken(form);
      if (!recaptchaToken) {
        state.authError = "Complete reCAPTCHA before requesting another code.";
        renderGuestAuth();
      } else {
        void request("auth.php?operation=resend_verification", {
          method: "POST",
          body: JSON.stringify({ email: state.authEmail, recaptchaToken }),
        }).then((result) => {
          state.authMessage = result.message;
          state.authError = "";
          renderGuestAuth();
        }).catch((error) => {
          state.authError = error.message;
          renderGuestAuth();
        });
      }
    }
    if (action === "close" || (action === "overlay-close" && event.target === target)) {
      closeMainOverlay();
      return;
    }
    if (action === "slot") {
      state.selectedTime = target.dataset.time;
      renderBooking();
    }
    if (action === "print") window.print();
    if (action === "logout") {
      showLogoutConfirmation(true);
    }
    if (action === "refresh-manager") {
      const button = target.closest("button");
      if (button) {
        button.disabled = true;
        button.textContent = "Refreshing…";
      }
      void loadManagerData().finally(() => {
        const refreshButton = document.querySelector('[data-action="refresh-manager"]');
        if (refreshButton) {
          refreshButton.disabled = false;
          refreshButton.textContent = "Refresh";
        }
      });
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && state.modal && !document.querySelector(".auth-feedback-overlay")) {
      closeMainOverlay();
    }
  });

  document.addEventListener("click", (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest("a[href]");
    if (!link || link.target || link.hasAttribute("download")) return;
    const destination = new URL(link.href, window.location.href);
    if (destination.origin !== window.location.origin || destination.pathname === window.location.pathname) return;
    event.preventDefault();
    document.body.classList.add("page-leaving");
    window.setTimeout(() => window.location.assign(destination.href), 180);
  });

  document.addEventListener("change", async (event) => {
    const target = event.target;
    if (target.form?.id === "booking-form") saveBookingFields(target.form);
    if (target.id === "booking-service") {
      const service = serviceById[target.value] || serviceList[0];
      state.serviceId = service.id;
      state.roomCode = service.rooms[0];
      state.guestCount = service.minGuests;
      state.selectedTime = "";
      renderBooking();
      await loadAvailability();
    } else if (target.id === "booking-room") {
      state.roomCode = target.value;
      state.selectedTime = "";
      await loadAvailability();
    } else if (target.id === "booking-date") {
      state.date = target.value;
      state.selectedTime = "";
      await loadAvailability();
    } else if (target.id === "booking-guests") {
      state.guestCount = Number(target.value);
      renderBooking();
    } else if (target.id === "report-date") {
      state.reportDate = target.value;
      await loadManagerData();
    } else if (target.id === "manager-filter") {
      state.filter = target.value;
      renderDashboard();
    } else if (target.dataset.action === "select-booking") {
      const selectedIds = new Set(state.selectedBookingIds);
      if (target.checked) selectedIds.add(target.dataset.appointment);
      else selectedIds.delete(target.dataset.appointment);
      state.selectedBookingIds = Array.from(selectedIds);
      renderDashboard();
    } else if (target.dataset.action === "status") {
      await saveManagerAction({ action: "status", appointmentId: target.dataset.appointment, status: target.value });
    } else if (target.dataset.action === "therapist") {
      await saveManagerAction({ action: "therapist", appointmentId: target.dataset.appointment, therapistId: target.value });
    }
  });
  document.addEventListener("input", (event) => {
    if (event.target.name === "fullName" || event.target.name === "guestName") {
      event.target.value = Array.from(event.target.value.replace(/[^\p{L} ]/gu, "")).slice(0, 35).join("");
    }
    if (event.target.name === "guestPhone") {
      let digits = event.target.value.replace(/\D/g, "");
      if (digits.startsWith("63")) digits = digits.slice(2);
      else if (digits.startsWith("0")) digits = digits.slice(1);
      event.target.value = `+63${digits.slice(0, 10)}`;
    }
    if (event.target.form?.id === "booking-form") saveBookingFields(event.target.form);
    if ((event.target.form?.id === "guest-auth-form" && event.target.name === "password") ||
        (event.target.form?.id === "profile-password-form" && event.target.name === "newPassword")) {
      updatePasswordStrength(event.target.form);
    }
    if (event.target.id === "manager-search") {
      const position = event.target.selectionStart;
      state.search = event.target.value;
      renderDashboard();
      const search = document.getElementById("manager-search");
      search?.focus();
      search?.setSelectionRange(position, position);
    }
  });
  document.addEventListener("submit", async (event) => {
    if (event.target.id === "profile-password-form") {
      event.preventDefault();
      const form = event.target;
      const values = new FormData(form);
      if (values.get("newPassword") !== values.get("confirmPassword")) {
        showAuthFeedback("Passwords do not match", "Enter the same new password in both fields.", "error");
        return;
      }
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        const result = await request("auth.php?operation=guest_password_change_request", {
          method: "POST",
          body: JSON.stringify({
            currentPassword: values.get("currentPassword"),
            newPassword: values.get("newPassword"),
          }),
        });
        await loadGuestProfile();
        showAuthFeedback("Verification code sent", result.message);
      } catch (error) {
        showAuthFeedback("Password change not started", error.message, "error");
      } finally {
        if (submit.isConnected) submit.disabled = false;
      }
    }
    if (event.target.id === "profile-password-verify-form") {
      event.preventDefault();
      const form = event.target;
      const values = new FormData(form);
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        const result = await request("auth.php?operation=guest_password_change_verify", {
          method: "POST",
          body: JSON.stringify({ code: values.get("code") }),
        });
        if (state.guestProfile) state.guestProfile.passwordChangePending = false;
        renderGuestProfile();
        showAuthFeedback("Password updated", result.message);
      } catch (error) {
        showAuthFeedback("Code not accepted", error.message, "error");
      } finally {
        if (submit.isConnected) submit.disabled = false;
      }
    }
    if (event.target.id === "profile-password-resend-form") {
      event.preventDefault();
      const form = event.target;
      const values = new FormData(form);
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        const result = await request("auth.php?operation=guest_password_change_resend", {
          method: "POST",
          body: JSON.stringify({ currentPassword: values.get("currentPassword") }),
        });
        await loadGuestProfile();
        showAuthFeedback("New code sent", result.message);
      } catch (error) {
        await loadGuestProfile();
        showAuthFeedback("Code not sent", error.message, "error");
      } finally {
        if (submit.isConnected) submit.disabled = false;
      }
    }
    if (event.target.id === "profile-email-form") {
      event.preventDefault();
      const form = event.target;
      const values = new FormData(form);
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        const result = await request("auth.php?operation=guest_email_change_request", {
          method: "POST",
          body: JSON.stringify({
            currentPassword: values.get("currentPassword"),
            newEmail: values.get("newEmail"),
          }),
        });
        if (state.guestProfile) {
          state.guestProfile.pendingEmail = result.pendingEmail;
          state.guestProfile.emailChangeStage = result.verificationStage;
        }
        renderGuestProfile();
        showAuthFeedback("Verification code sent", result.message);
      } catch (error) {
        showAuthFeedback("Email not changed", error.message, "error");
      } finally {
        if (submit.isConnected) submit.disabled = false;
      }
    }
    if (event.target.id === "profile-email-resend-form") {
      event.preventDefault();
      const form = event.target;
      const values = new FormData(form);
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        const result = await request("auth.php?operation=guest_email_change_resend", {
          method: "POST",
          body: JSON.stringify({ currentPassword: values.get("currentPassword") }),
        });
        if (state.guestProfile) state.guestProfile.emailChangeStage = result.verificationStage;
        renderGuestProfile();
        showAuthFeedback("New code sent", result.message);
      } catch (error) {
        await loadGuestProfile();
        showAuthFeedback("Code not sent", error.message, "error");
      } finally {
        if (submit.isConnected) submit.disabled = false;
      }
    }
    if (event.target.id === "profile-email-verify-form") {
      event.preventDefault();
      const form = event.target;
      const values = new FormData(form);
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        const result = await request("auth.php?operation=guest_email_change_verify", {
          method: "POST",
          body: JSON.stringify({ code: values.get("code") }),
        });
        if (result.verificationStage === "new") {
          if (state.guestProfile) state.guestProfile.emailChangeStage = "new";
          renderGuestProfile();
          showAuthFeedback("Current email confirmed", result.message);
        } else {
          if (state.guestProfile) {
            state.guestProfile.email = result.email;
            state.guestProfile.pendingEmail = null;
            state.guestProfile.emailChangeStage = null;
          }
          if (state.guest) state.guest.email = result.email;
          renderGuestProfile();
          showAuthFeedback("Email updated", result.message);
        }
      } catch (error) {
        if (error.message.includes("could not be sent")) await loadGuestProfile();
        showAuthFeedback("Code not accepted", error.message, "error");
      } finally {
        if (submit.isConnected) submit.disabled = false;
      }
    }
    if (event.target.id === "booking-form") {
      event.preventDefault();
      await submitBooking(event.target);
    }
    if (event.target.id === "manager-login") {
      event.preventDefault();
      const form = event.target;
      const values = new FormData(form);
      const recaptchaToken = getCaptchaToken(form);
      if (!recaptchaToken) {
        showManagerLoginError("Complete the reCAPTCHA challenge before signing in.");
        return;
      }
      const submitButton = form.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = "Signing in…";
      }
      form.setAttribute("aria-busy", "true");
      try {
        await request("auth.php?operation=login", { method: "POST", body: JSON.stringify({ username: values.get("username"), password: values.get("password"), recaptchaToken }) });
        const session = await request("auth.php?operation=session");
        state.managerCsrfToken = session.csrfToken || "";
        await loadManagerData();
      } catch (error) {
        showManagerLoginError(error.message, true);
      } finally {
        if (form.isConnected && !state.manager) {
          form.removeAttribute("aria-busy");
          if (submitButton?.isConnected) {
            submitButton.disabled = false;
            submitButton.textContent = "Sign in";
          }
        }
      }
    }
    if (event.target.id === "guest-auth-form") {
      event.preventDefault();
      const form = event.target;
      const values = new FormData(form);
      state.authEmail = String(values.get("email") || "").trim().toLowerCase();
      state.authError = "";
      if (state.authMode === "verify") {
        const recaptchaToken = getCaptchaToken(form);
        if (!recaptchaToken) {
          state.authError = "Complete the reCAPTCHA challenge before verifying your email.";
          renderGuestAuth();
          return;
        }
        try {
          const result = await request("auth.php?operation=verify_email", {
            method: "POST",
            body: JSON.stringify({ email: state.authEmail, code: values.get("code"), recaptchaToken }),
          });
          setGuestSession(result.name);
          showAuthFeedback("Email verified", "Your account is verified and you are now signed in.");
        } catch (error) {
          state.authError = error.message;
          renderGuestAuth();
        }
        return;
      }

      const recaptchaToken = getCaptchaToken(form);
      if (!recaptchaToken) {
        state.authError = "Complete the reCAPTCHA challenge to continue.";
        renderGuestAuth();
        return;
      }
      if ((state.authMode === "register" || state.authMode === "reset") && values.get("password") !== values.get("confirmPassword")) {
        state.authError = "The password confirmation does not match.";
        renderGuestAuth();
        return;
      }

      try {
        if (state.authMode === "forgot") {
          const result = await request("auth.php?operation=password_reset_request", {
            method: "POST",
            body: JSON.stringify({ email: state.authEmail, recaptchaToken }),
          });
          state.authMode = "reset";
          state.authMessage = result.message;
          renderGuestAuth();
          showAuthFeedback("Check your email", result.message);
        } else if (state.authMode === "reset") {
          const result = await request("auth.php?operation=password_reset", {
            method: "POST",
            body: JSON.stringify({
              email: state.authEmail,
              code: values.get("code"),
              password: values.get("password"),
              recaptchaToken,
            }),
          });
          state.authMode = "login";
          state.authMessage = result.message;
          state.authError = "";
          renderGuestAuth();
          showAuthFeedback("Password updated", result.message);
        } else if (state.authMode === "register") {
          await request("auth.php?operation=register", {
            method: "POST",
            body: JSON.stringify({
              fullName: values.get("fullName"),
              email: state.authEmail,
              password: values.get("password"),
              recaptchaToken,
            }),
          });
          state.authMode = "verify";
          state.authMessage = `A six-digit verification code was sent to ${state.authEmail}.`;
          renderGuestAuth();
        } else {
          try {
            const result = await request("auth.php?operation=guest_login", {
              method: "POST",
              body: JSON.stringify({ email: state.authEmail, password: values.get("password"), recaptchaToken }),
            });
            setGuestSession(result.name);
          } catch (error) {
            if (error.message.includes("Verify your email")) {
              state.authMode = "verify";
              state.authMessage = error.message;
              renderGuestAuth();
            } else {
              throw error;
            }
          }
        }
      } catch (error) {
        if (state.authMode === "register" && error.message.includes("account was created")) {
          state.authMode = "verify";
          state.authError = error.message;
          renderGuestAuth();
        } else if (state.authMode === "reset") {
          resetAuthCaptcha(form);
          showAuthFeedback("Code not accepted", error.message, "error");
        } else {
          state.authError = error.message;
          renderGuestAuth();
        }
      }
    }
  });

  async function saveManagerAction(payload) {
    try {
      await request("manager_action.php", { method: "POST", body: JSON.stringify(payload) });
      await loadManagerData();
    } catch (error) {
      state.error = error.message;
      renderDashboard();
    }
  }

  document.getElementById("year")?.replaceChildren(String(new Date().getFullYear()));
  if (isManagerRoute) {
    showManagerShell();
    showBrandSplash();
    void initializeGuestSession().then(() => checkManagerSession());
    window.setInterval(() => {
      if (state.manager && document.visibilityState === "visible") void loadManagerData({ silent: true });
    }, 30000);
  } else {
    showBrandSplash();
    void initializeGuestSession();
  }
})();
