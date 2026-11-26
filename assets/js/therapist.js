(() => {
  "use strict";

  const main = document.getElementById("therapist-main");
  const layout = document.getElementById("therapist-layout");
  const nav = document.getElementById("therapist-nav");
  const logoutDialog = document.querySelector(".therapist-logout-dialog");
  const currentView = document.querySelector("[data-therapist-view]")?.dataset.therapistView || "dashboard";
  const state = { csrfToken: "", recaptchaSiteKey: "", data: null, error: "" };
  const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[character]);
  const weekdays = ["", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
  const dateToday = () => new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit",
  }).format(new Date());
  const formatTime = (time) => {
    if (!time) return "";
    const [hour, minute] = time.split(":").map(Number);
    return new Intl.DateTimeFormat("en-US", { hour: "numeric", minute: "2-digit", timeZone: "UTC" })
      .format(new Date(Date.UTC(2000, 0, 1, hour, minute)));
  };
  async function request(url, options = {}) {
    const response = await fetch(url, {
      ...options,
      headers: { Accept: "application/json", ...(options.body ? { "Content-Type": "application/json" } : {}) },
    });
    const text = await response.text();
    let payload;
    try { payload = JSON.parse(text); } catch { payload = null; }
    if (!response.ok) throw new Error(payload?.message || `Request failed (${response.status}).`);
    if (!payload || typeof payload !== "object") throw new Error("The server returned an invalid response.");
    return payload;
  }
  async function loadCaptcha() {
    if (!state.recaptchaSiteKey) return;
    if (!window.grecaptcha?.render) {
      const existing = document.querySelector('script[src*="google.com/recaptcha/api.js"]');
      if (!existing) {
        await new Promise((resolve, reject) => {
          const script = document.createElement("script");
          script.src = "https://www.google.com/recaptcha/api.js?render=explicit";
          script.async = true;
          script.defer = true;
          script.onload = resolve;
          script.onerror = () => reject(new Error("Could not load Google reCAPTCHA. Check your connection and try again."));
          document.head.append(script);
        });
      }
      const ready = await new Promise((resolve) => {
        const startedAt = Date.now();
        const check = () => {
          if (typeof window.grecaptcha?.render === "function") resolve(true);
          else if (Date.now() - startedAt >= 10000) resolve(false);
          else window.setTimeout(check, 100);
        };
        check();
      });
      if (!ready) throw new Error("Google reCAPTCHA is unavailable. Reload the page or contact the site administrator.");
    }
    const form = document.getElementById("therapist-login");
    const slot = document.getElementById("therapist-captcha");
    if (!form || !slot || !window.grecaptcha?.render) return;
    slot.replaceChildren();
    window.grecaptcha.render(slot, {
      sitekey: state.recaptchaSiteKey,
      callback: (token) => { form.dataset.recaptchaToken = token; },
      "expired-callback": () => { delete form.dataset.recaptchaToken; },
      "error-callback": () => { delete form.dataset.recaptchaToken; },
    });
  }
  function showError(message) {
    state.error = message;
    const box = document.querySelector("[data-therapist-error]");
    if (box) {
      box.hidden = !message;
      box.textContent = message;
    }
  }
  function renderLogin(error = "") {
    layout.classList.add("is-unauthenticated");
    nav.hidden = true;
    main.innerHTML = `<section class="login-card"><p class="eyebrow">Therapist access</p><h1>Sign in to your portal</h1><p>View your assigned appointments, schedule, and availability.</p><p class="error-box" data-therapist-error role="alert" ${error ? "" : "hidden"}>${escapeHtml(error)}</p><form id="therapist-login"><label class="field">Username <span class="required-mark">*</span><input name="username" autocomplete="username" placeholder="e.g., elena.morales" required></label><label class="field">Password <span class="required-mark">*</span><span class="password-input-wrap"><input name="password" type="password" autocomplete="current-password" placeholder="Enter your therapist account password" required><button class="password-toggle" type="button" data-password-toggle aria-label="Show password" title="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5.5 9.5-5.5 9.5 5.5 9.5 5.5-3.5 5.5-9.5 5.5S2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.8"/></svg></button></span></label><div class="captcha-slot" id="therapist-captcha"></div><p class="required-note"><span class="required-mark">*</span> Indicates required field</p><button class="button button-dark" type="submit">Sign in</button></form></section>`;
    const card = main.querySelector(".login-card");
    const eyebrow = card.querySelector(".eyebrow");
    const heading = card.querySelector("h1");
    const intro = Array.from(card.children).find((element) => element.tagName === "P" && !element.classList.contains("eyebrow") && !element.classList.contains("error-box"));
    const errorBox = card.querySelector(".error-box");
    const form = card.querySelector("#therapist-login");
    const header = document.createElement("header");
    const mark = document.createElement("span");
    const headerContent = document.createElement("div");
    const body = document.createElement("div");
    card.classList.add("staff-login-card");
    header.className = "staff-login-header";
    mark.className = "staff-login-mark";
    mark.setAttribute("aria-hidden", "true");
    mark.textContent = "BV";
    headerContent.append(eyebrow, heading, intro);
    intro.classList.add("staff-login-intro");
    header.append(mark, headerContent);
    body.className = "staff-login-body";
    body.append(errorBox, form);
    card.replaceChildren(header, body);
    form.querySelectorAll(".field").forEach((label) => {
      const labelText = Array.from(label.childNodes).find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
      const marker = label.querySelector(".required-mark");
      if (!labelText || !marker) return;
      const caption = document.createElement("span");
      caption.className = "field-label-row";
      caption.append(document.createTextNode(labelText.textContent.trim()), marker);
      marker.setAttribute("aria-hidden", "true");
      labelText.replaceWith(caption);
    });
    void loadCaptcha().catch((captchaError) => showError(captchaError.message));
  }
  function renderPortalPage() {
    const data = state.data;
    if (!data) return;
    const appointments = data.appointments || [];
    const todayAppointments = appointments.filter((item) => item.bookingDate === dateToday());
    const upcomingAppointments = appointments.filter((item) => item.bookingDate > dateToday());
    const appointmentCard = (item, showActions = true) => {
      const currentStatus = item.status === "in_session" ? "In Progress" : item.status.replaceAll("_", " ");
      const showStart = item.bookingDate === dateToday() && ["confirmed", "in_session"].includes(item.status);
      const showComplete = ["in_session", "cleaning"].includes(item.status);
      return `<article class="therapist-card"><div class="therapist-card-title"><div><p class="eyebrow">${escapeHtml(item.bookingCode)}</p><h3>${escapeHtml(item.guestName)}</h3></div><span class="status ${escapeHtml(item.status)} therapist-status">${escapeHtml(currentStatus)}</span></div><p class="therapist-detail"><strong>${escapeHtml(item.serviceName)}</strong><span>Room ${escapeHtml(item.roomCode)}</span></p><p class="therapist-detail">${escapeHtml(item.bookingDate)} · ${escapeHtml(formatTime(item.startTime))}–${escapeHtml(formatTime(item.endTime))}</p><p class="therapist-detail">Contact: ${escapeHtml(item.guestPhone)} · ${escapeHtml(item.guestEmail)}</p>${item.specialRequests ? `<p class="therapist-detail"><strong>Special requests:</strong><br>${escapeHtml(item.specialRequests).replaceAll("\n", "<br>")}</p>` : ""}${item.healthOthers ? `<p class="therapist-detail therapist-health-note"><strong>Health declaration — Other:</strong><br>${escapeHtml(item.healthOthers)}</p>` : ""}${showActions ? `<div class="therapist-card-actions">${showStart ? `<button class="button button-dark" type="button" data-status="in_session" data-id="${item.id}">Mark In Progress</button>` : ""}${showComplete ? `<button class="button button-outline" type="button" data-status="completed" data-id="${item.id}">Mark Completed</button>` : ""}</div>` : ""}</article>`;
    };
    const savedSchedule = new Map((data.weeklySchedule || []).map((item) => [item.weekday, item]));
    const schedule = Array.from({ length: 7 }, (_, index) => {
      const weekday = index + 1;
      const item = savedSchedule.get(weekday);
      return `<li><strong>${weekdays[weekday]}</strong><span>${item?.isDayOff ? "Day Off" : `${formatTime(item?.startTime || "13:00")}–${formatTime(item?.endTime || "21:00")}`}</span></li>`;
    }).join("");
    const daysOff = (data.dayOffs || []).map((item) => `<li><div><strong>${escapeHtml(item.date)}</strong>${item.note ? `<small>${escapeHtml(item.note)}</small>` : ""}</div><span class="therapist-request-status ${escapeHtml(item.status)}">${escapeHtml(item.status)}</span></li>`).join("") || "<li class=\"therapist-empty-item\">No upcoming day-off requests.</li>";
    const nextAppointment = todayAppointments[0] || upcomingAppointments[0];
    const views = {
      dashboard: {
        title: "Overview",
        description: "A quick look at your day and upcoming work.",
        content: `<section class="therapist-welcome-card"><div><p class="eyebrow">Therapist workspace</p><h2>Good day, ${escapeHtml(data.therapist.name)}</h2><p>Your assignments and schedule, all in one place.</p></div><span class="therapist-availability-pill ${data.therapist.isAvailable ? "available" : "unavailable"}"><i></i>${data.therapist.isAvailable ? "Available" : "Unavailable"}</span></section><section class="therapist-metrics"><article><span>Today’s appointments</span><strong>${todayAppointments.length}</strong><a href="therapist/appointments">View appointments →</a></article><article><span>Upcoming appointments</span><strong>${upcomingAppointments.length}</strong><a href="therapist/appointments">View assignments →</a></article><article><span>Availability</span><strong>${data.therapist.isAvailable ? "On" : "Off"}</strong><a href="therapist/schedule">Manage availability →</a></article></section><section class="therapist-section"><div class="therapist-section-heading"><div><p class="eyebrow">Next on your schedule</p><h2>${nextAppointment ? escapeHtml(nextAppointment.bookingDate === dateToday() ? `Today · ${formatTime(nextAppointment.startTime)}` : nextAppointment.bookingDate) : "You’re all caught up"}</h2></div><a class="therapist-text-link" href="therapist/appointments">All appointments <span aria-hidden="true">→</span></a></div><div class="therapist-cards">${nextAppointment ? appointmentCard(nextAppointment, false) : "<p class=\"therapist-empty\">No upcoming appointments are assigned to you.</p>"}</div></section>`,
      },
      appointments: {
        title: "My appointments",
        description: "Review guest details, treatment notes, and update session status.",
        content: `<section class="therapist-section"><div class="therapist-section-heading"><div><p class="eyebrow">Today</p><h2>${todayAppointments.length} assigned</h2></div></div><div class="therapist-cards">${todayAppointments.map((item) => appointmentCard(item)).join("") || "<p class=\"therapist-empty\">No appointments assigned for today.</p>"}</div></section><section class="therapist-section"><div class="therapist-section-heading"><div><p class="eyebrow">Coming up</p><h2>Upcoming appointments</h2></div></div><div class="therapist-cards">${upcomingAppointments.map((item) => appointmentCard(item)).join("") || "<p class=\"therapist-empty\">No upcoming appointments assigned.</p>"}</div></section>`,
      },
      schedule: {
        title: "Schedule & availability",
        description: "Review your weekly hours and control whether you can receive new assignments.",
        content: `<section class="therapist-section therapist-availability-card"><div><p class="eyebrow">Assignment preference</p><h2>Availability status</h2><p>Unavailable therapists are hidden from new booking assignments.</p></div><form class="therapist-availability-form" id="availability-form"><label class="field">Your status<select name="available"><option value="1" ${data.therapist.isAvailable ? "selected" : ""}>Available for assignments</option><option value="0" ${data.therapist.isAvailable ? "" : "selected"}>Unavailable</option></select></label><button class="button button-dark" type="submit">Save status</button></form></section><section class="therapist-section"><div class="therapist-section-heading"><div><p class="eyebrow">Regular hours</p><h2>My weekly schedule</h2></div></div><ul class="therapist-schedule-list">${schedule}</ul></section>`,
      },
      "day-offs": {
        title: "Day-off requests",
        description: "Request time off and track the status of upcoming requests.",
        content: `<section class="therapist-section"><div class="therapist-section-heading"><div><p class="eyebrow">New request</p><h2>Request a day off</h2></div></div><form class="therapist-dayoff-form" id="dayoff-form"><label class="field">Date <span class="required-mark">*</span><input type="date" name="date" min="${dateToday()}" required></label><label class="field">Schedule / leave note <span class="field-optional">(Optional)</span><textarea name="note" maxlength="500" placeholder="e.g., Medical appointment, family leave"></textarea></label><button class="button button-dark" type="submit">Send request</button><p class="required-note"><span class="required-mark">*</span> Indicates required field</p></form></section><section class="therapist-section"><div class="therapist-section-heading"><div><p class="eyebrow">Your requests</p><h2>Upcoming days off</h2></div></div><ul class="therapist-dayoff-list">${daysOff}</ul></section>`,
      },
    };
    const page = views[currentView] || views.dashboard;
    layout.classList.remove("is-unauthenticated");
    nav.hidden = false;
    nav.querySelectorAll("[data-therapist-nav]").forEach((link) => {
      if (link.dataset.therapistNav === currentView) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    });
    main.dataset.therapistPage = currentView;
    main.innerHTML = `<div class="notice ${state.error ? "error" : ""}" data-therapist-error role="status" ${state.error ? "" : "hidden"}>${escapeHtml(state.error)}</div><div class="therapist-page-heading"><div><p class="eyebrow">Therapist portal</p><h1>${page.title}</h1><p>${page.description}</p></div><button class="button button-outline" type="button" data-logout>Sign out</button></div>${page.content}`;
  }
  async function refresh() {
    state.data = await request("api/therapist_portal.php");
    state.csrfToken = state.data.csrfToken;
    state.error = "";
    renderPortalPage();
  }
  async function initialize() {
    const config = await request("api/auth.php?operation=public_config");
    state.recaptchaSiteKey = config.recaptchaSiteKey || "";
    const session = await request("api/auth.php?operation=therapist_session");
    if (session.authenticated) {
      state.csrfToken = session.csrfToken;
      try { await refresh(); } catch (error) { renderLogin(error.message); }
    } else {
      renderLogin();
      if (!state.recaptchaSiteKey) showError("reCAPTCHA is not configured. Ask the administrator to configure site keys.");
    }
  }

  document.addEventListener("submit", async (event) => {
    if (event.target.id === "therapist-login") {
      event.preventDefault();
      const form = event.target;
      if (!form.dataset.recaptchaToken) {
        showError("Complete the reCAPTCHA challenge before signing in.");
        return;
      }
      const values = new FormData(form);
      const button = form.querySelector('[type="submit"]');
      button.disabled = true;
      try {
        await request("api/auth.php?operation=therapist_login", {
          method: "POST",
          body: JSON.stringify({
            username: values.get("username"),
            password: values.get("password"),
            recaptchaToken: form.dataset.recaptchaToken,
          }),
        });
        await refresh();
      } catch (error) {
        showError(error.message);
        delete form.dataset.recaptchaToken;
        window.grecaptcha?.reset?.();
      } finally {
        if (button.isConnected) button.disabled = false;
      }
    }
    if (event.target.id === "availability-form") {
      event.preventDefault();
      const available = new FormData(event.target).get("available") === "1";
      try {
        await request("api/therapist_portal.php", {
          method: "POST",
          body: JSON.stringify({ action: "availability", available, csrfToken: state.csrfToken }),
        });
        await refresh();
      } catch (error) { showError(error.message); }
    }
    if (event.target.id === "dayoff-form") {
      event.preventDefault();
      const values = new FormData(event.target);
      try {
        await request("api/therapist_portal.php", {
          method: "POST",
          body: JSON.stringify({ action: "request_day_off", date: values.get("date"), note: values.get("note"), csrfToken: state.csrfToken }),
        });
        await refresh();
      } catch (error) { showError(error.message); }
    }
  });
  document.addEventListener("click", async (event) => {
    const passwordToggle = event.target.closest("[data-password-toggle]");
    if (passwordToggle) {
      const passwordInput = passwordToggle.parentElement.querySelector("input");
      const isVisible = passwordInput.type === "password";
      passwordInput.type = isVisible ? "text" : "password";
      passwordToggle.setAttribute("aria-pressed", String(isVisible));
      passwordToggle.setAttribute("aria-label", isVisible ? "Hide password" : "Show password");
      passwordToggle.title = isVisible ? "Hide password" : "Show password";
      passwordToggle.classList.toggle("is-visible", isVisible);
      return;
    }

    const navigationToggle = event.target.closest("[data-therapist-nav-toggle]");
    if (navigationToggle) {
      const sidebar = navigationToggle.closest(".therapist-sidebar");
      const therapistLayout = sidebar?.closest(".therapist-layout");
      if (!sidebar || !therapistLayout) return;
      const collapsed = sidebar.classList.toggle("is-collapsed");
      therapistLayout.classList.toggle("is-sidebar-collapsed", collapsed);
      navigationToggle.setAttribute("aria-expanded", String(!collapsed));
      navigationToggle.setAttribute("aria-label", collapsed ? "Open workspace menu" : "Close workspace menu");
      return;
    }

    const statusButton = event.target.closest("[data-status]");
    if (statusButton) {
      const originalLabel = statusButton.textContent;
      statusButton.disabled = true;
      statusButton.textContent = statusButton.dataset.status === "in_session" ? "Starting…" : "Updating…";
      try {
        await request("api/therapist_portal.php", {
          method: "POST",
          body: JSON.stringify({
            action: "status",
            appointmentId: statusButton.dataset.id,
            status: statusButton.dataset.status,
            csrfToken: state.csrfToken,
          }),
        });
        await refresh();
      } catch (error) { showError(error.message); }
      finally {
        if (statusButton.isConnected) {
          statusButton.disabled = false;
          statusButton.textContent = originalLabel;
        }
      }
    }
    if (event.target.closest("[data-logout]")) {
      logoutDialog.showModal();
      logoutDialog.querySelector("[data-logout-cancel]").focus();
    }
    if (event.target.closest("[data-logout-cancel]")) {
      logoutDialog.close();
    }
    if (event.target.closest("[data-logout-confirm]")) {
      const confirmButton = logoutDialog.querySelector("[data-logout-confirm]");
      const errorMessage = logoutDialog.querySelector("[data-logout-error]");
      confirmButton.disabled = true;
      confirmButton.textContent = "Signing out…";
      errorMessage.hidden = true;
      try {
        await request("api/auth.php?operation=therapist_logout", { method: "POST" });
        logoutDialog.close();
        state.data = null;
        state.csrfToken = "";
        renderLogin();
      } catch (error) {
        errorMessage.textContent = error.message;
        errorMessage.hidden = false;
      } finally {
        confirmButton.disabled = false;
        confirmButton.textContent = "Sign out";
      }
    }
  });
  void initialize().catch((error) => renderLogin(error.message));
})();
