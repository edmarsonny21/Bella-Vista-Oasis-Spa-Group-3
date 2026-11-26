(() => {
  "use strict";

  const controlSelector = "input[required], select[required], textarea[required]";

  function isControlValid(input) {
    if (!input.willValidate || !input.validity.valid) return false;
    if (input.name === "confirmPassword") {
      const password = input.form?.querySelector('[name="password"], [name="newPassword"]');
      return !password || input.value === password.value;
    }
    return true;
  }

  function ensureMarker(input) {
    const label = input.closest("label");
    if (!label) return null;

    let marker = label.querySelector(".required-mark");
    if (!marker) {
      marker = document.createElement("span");
      marker.className = "required-mark";
      marker.textContent = "*";
      label.insertBefore(marker, label.firstChild);
    }
    marker.dataset.requiredMarker = "true";
    marker.setAttribute("aria-hidden", "true");
    return marker;
  }

  function updateForm(form) {
    if (!form) return;
    const controls = Array.from(form.querySelectorAll(controlSelector));
    const labels = new Set(Array.from(form.querySelectorAll("label"))
      .filter((label) => label.querySelector(".required-mark")));
    controls.forEach((input) => {
      const marker = ensureMarker(input);
      if (marker) labels.add(input.closest("label"));
    });

    labels.forEach((label) => {
      const marker = label.querySelector(".required-mark");
      const requiredControls = Array.from(label.querySelectorAll("input, select, textarea"))
        .filter((input) => input.required && input.willValidate);
      marker.hidden = !requiredControls.some((input) => !isControlValid(input));
    });

    const note = form.querySelector(".required-note");
    if (note) {
      const incomplete = controls.some((input) => !isControlValid(input));
      const message = incomplete ? "* Indicates required field" : "All required fields are complete.";
      if (note.textContent.trim() !== message) {
        note.replaceChildren();
        if (incomplete) {
          const marker = document.createElement("span");
          marker.className = "required-mark";
          marker.dataset.requiredMarker = "true";
          marker.setAttribute("aria-hidden", "true");
          marker.textContent = "*";
          note.append(marker, document.createTextNode(" Indicates required field"));
        } else {
          note.textContent = message;
        }
      }
    }
  }

  function scan(root) {
    if (root instanceof Element && root.matches("form")) updateForm(root);
    if (root.querySelectorAll) root.querySelectorAll("form").forEach(updateForm);
  }

  function scheduleUpdate(target) {
    window.setTimeout(() => {
      if (target instanceof HTMLElement && target.form) updateForm(target.form);
    }, 0);
  }

  document.addEventListener("input", (event) => scheduleUpdate(event.target));
  document.addEventListener("change", (event) => scheduleUpdate(event.target));
  document.addEventListener("reset", (event) => {
    window.setTimeout(() => updateForm(event.target), 0);
  });

  new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === "childList") {
        record.addedNodes.forEach((node) => {
          if (node instanceof Element) scan(node);
        });
      } else if (record.target instanceof HTMLElement) {
        if (record.target.form) updateForm(record.target.form);
        else scan(record.target);
      }
    }
  }).observe(document.documentElement, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ["required", "disabled"],
  });

  scan(document);
})();
