(() => {
  const root = document.documentElement;
  const storageKey = "school-election-ui";

  function loadUi() {
    try {
      return JSON.parse(localStorage.getItem(storageKey) || "{}") || {};
    } catch {
      return {};
    }
  }

  function applyUi(state) {
    root.classList.toggle("theme-dark", Boolean(state.dark));
    root.classList.toggle("font-large", Boolean(state.large));
    root.classList.toggle("high-contrast", Boolean(state.contrast));
  }

  let uiState = loadUi();
  applyUi(uiState);

  document.addEventListener("click", (event) => {
    const action = event.target.closest("[data-ui-action]");
    if (action) {
      const key = action.dataset.uiAction;
      if (key === "theme") uiState.dark = !uiState.dark;
      if (key === "font") uiState.large = !uiState.large;
      if (key === "contrast") uiState.contrast = !uiState.contrast;
      localStorage.setItem(storageKey, JSON.stringify(uiState));
      applyUi(uiState);
      return;
    }

    const target = event.target.closest("[data-confirm]");
    if (target) {
      const message = target.dataset.confirm || "Подтвердить действие?";
      if (!window.confirm(message)) event.preventDefault();
    }
  });

  document.querySelectorAll(".ballot-card input[type='radio']").forEach((radio) => {
    radio.addEventListener("change", () => {
      document.querySelectorAll(".ballot-card").forEach((card) => card.classList.remove("selected"));
      radio.closest(".ballot-card")?.classList.add("selected");
    });
  });

  const autoRedirect = document.querySelector("[data-auto-redirect]");
  if (autoRedirect) {
    const url = autoRedirect.dataset.autoRedirect;
    const countdown = autoRedirect.querySelector("[data-countdown]");
    let seconds = Number(autoRedirect.dataset.seconds || 8);
    const timer = window.setInterval(() => {
      seconds -= 1;
      if (countdown) countdown.textContent = String(Math.max(seconds, 0));
      if (seconds <= 0) {
        window.clearInterval(timer);
        window.location.replace(url);
      }
    }, 1000);
  }

  const electionCountdown = document.querySelector("[data-election-countdown]");
  if (electionCountdown) {
    const target = new Date(electionCountdown.dataset.electionCountdown).getTime();
    const value = electionCountdown.querySelector("strong");
    const renderCountdown = () => {
      const diff = target - Date.now();
      if (diff <= 0) {
        if (value) value.textContent = "00:00:00";
        window.setTimeout(() => window.location.reload(), 1000);
        return;
      }
      const days = Math.floor(diff / 86400000);
      const hours = Math.floor((diff % 86400000) / 3600000);
      const minutes = Math.floor((diff % 3600000) / 60000);
      const seconds = Math.floor((diff % 60000) / 1000);
      if (value) value.textContent = `${days ? days + " д. " : ""}${String(hours).padStart(2,"0")}:${String(minutes).padStart(2,"0")}:${String(seconds).padStart(2,"0")}`;
    };
    renderCountdown();
    window.setInterval(renderCountdown, 1000);
  }

  const selectAll = document.querySelector("[data-select-all]");
  if (selectAll) {
    selectAll.addEventListener("change", () => {
      document.querySelectorAll("input[name='student_ids[]']").forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
    });
  }

  document.querySelectorAll("[data-qr]").forEach((container) => {
    const text = container.dataset.qr || "";
    if (!text) return;

    if (window.QRCode) {
      container.innerHTML = "";
      new window.QRCode(container, {
        text,
        width: 150,
        height: 150,
        correctLevel: window.QRCode.CorrectLevel.M,
      });
    } else {
      const image = new Image();
      image.width = 150;
      image.height = 150;
      image.alt = "QR-код для входа";
      image.src = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(text)}`;
      container.appendChild(image);
    }
  });

  const importRunner = document.querySelector("[data-import-runner]");
  if (importRunner) {
    const progressBar = importRunner.querySelector("[data-import-progress]");
    const percentLabel = importRunner.querySelector("[data-import-percent]");
    const statusLabel = importRunner.querySelector("[data-import-status]");
    const addedLabel = importRunner.querySelector("[data-import-added]");
    const updatedLabel = importRunner.querySelector("[data-import-updated]");
    const skippedLabel = importRunner.querySelector("[data-import-skipped]");
    let stopped = false;

    const renderImport = (payload) => {
      const percent = Number(payload.percent || 0);
      if (progressBar) progressBar.style.width = `${percent}%`;
      if (percentLabel) percentLabel.textContent = `${percent}%`;
      if (statusLabel) statusLabel.textContent = `Обработано ${payload.cursor || 0} из ${payload.total || 0} строк.`;
      if (addedLabel) addedLabel.textContent = String(payload.result?.added || 0);
      if (updatedLabel) updatedLabel.textContent = String(payload.result?.updated || 0);
      if (skippedLabel) skippedLabel.textContent = String(payload.result?.skipped || 0);
    };

    const showRetry = (message) => {
      stopped = true;
      if (!statusLabel) return;
      statusLabel.textContent = message;
      const retry = document.createElement("button");
      retry.type = "button";
      retry.className = "button secondary small import-retry";
      retry.textContent = "Повторить пакет";
      retry.addEventListener("click", () => {
        retry.remove();
        stopped = false;
        runImportBatch();
      });
      statusLabel.after(retry);
    };

    const runImportBatch = async () => {
      if (stopped) return;
      const body = new FormData();
      body.set("csrf_token", importRunner.dataset.csrf || "");
      body.set("token", importRunner.dataset.token || "");

      try {
        const response = await fetch("import_batch.php", {
          method: "POST",
          body,
          credentials: "same-origin",
          headers: { "X-Requested-With": "XMLHttpRequest" },
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error(payload.message || "Не удалось обработать очередной пакет.");
        }

        renderImport(payload);
        if (payload.done) {
          if (statusLabel) statusLabel.textContent = "Импорт завершён. Обновляем список учеников…";
          window.setTimeout(() => window.location.replace("students.php"), 500);
          return;
        }

        window.setTimeout(runImportBatch, 160);
      } catch (error) {
        showRetry(error instanceof Error ? error.message : "Ошибка пакетного импорта.");
      }
    };

    window.setTimeout(runImportBatch, 250);
  }

  document.querySelectorAll("form").forEach((form) => {
    form.addEventListener("submit", () => {
      const button = form.querySelector("button[type='submit'], button:not([type])");
      if (button && !button.dataset.noLock) {
        window.setTimeout(() => {
          button.disabled = true;
          button.classList.add("is-loading");
        }, 0);
      }
    });
  });

  if (new URLSearchParams(window.location.search).get("print") === "1" && document.querySelector(".credential-card")) {
    window.setTimeout(() => window.print(), 700);
  }
})();
