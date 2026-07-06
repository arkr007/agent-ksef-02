(function () {
  var root = document.documentElement;
  root.classList.add("js-ready");

  var flashCloseButtons = document.querySelectorAll("[data-dismiss]");
  flashCloseButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      var selector = button.getAttribute("data-dismiss");
      if (!selector) {
        return;
      }

      var target = document.querySelector(selector);
      if (target) {
        target.remove();
      }
    });
  });

  var scrollTargetMarker = document.querySelector("[data-scroll-target]");
  if (scrollTargetMarker) {
    var selector = scrollTargetMarker.getAttribute("data-scroll-target");
    if (selector) {
      var target = document.querySelector(selector);
      if (target) {
        target.scrollIntoView({ behavior: "auto", block: "start" });
      }
    }
  }

  var packageChecklistForm = document.querySelector("[data-package-checklist]");
  if (packageChecklistForm) {
    var runButton = packageChecklistForm.querySelector("[data-run-package-button]");
    var monthInput = packageChecklistForm.querySelector("[data-required-month]");
    var pdfCheckbox = packageChecklistForm.querySelector('input[name="confirm_pdf_ready"]');
    var csvCheckbox = packageChecklistForm.querySelector('input[name="confirm_csv_ready"]');

    var updateChecklistState = function () {
      var ready = Boolean(runButton)
        && Boolean(monthInput && monthInput.value)
        && Boolean(pdfCheckbox && pdfCheckbox.checked)
        && Boolean(csvCheckbox && csvCheckbox.checked);

      if (runButton) {
        runButton.disabled = !ready;
      }
    };

    [monthInput, pdfCheckbox, csvCheckbox].forEach(function (field) {
      if (!field) {
        return;
      }

      field.addEventListener("change", updateChecklistState);
      field.addEventListener("input", updateChecklistState);
    });

    updateChecklistState();
  }

  var localPathHelperRoot = document.querySelector("[data-local-path-helper]");
  if (localPathHelperRoot) {
    var helperBaseUrl = localPathHelperRoot.getAttribute("data-helper-base-url") || "http://localhost:8765";
    var helperStatus = localPathHelperRoot.querySelector("[data-helper-status]");
    var helperButtons = localPathHelperRoot.querySelectorAll("[data-helper-action]");

    var setHelperStatus = function (message, isError) {
      if (!helperStatus) {
        return;
      }

      helperStatus.textContent = message;
      helperStatus.style.color = isError ? "#9f2d20" : "";
    };

    var callHelper = async function (path, payload) {
      var response = await fetch(helperBaseUrl + path, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify(payload || {})
      });

      var data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }

      if (!response.ok) {
        var message = data && data.message ? data.message : "Helper zwrocil blad.";
        throw new Error(message);
      }

      return data || {};
    };

    helperButtons.forEach(function (button) {
      button.addEventListener("click", async function () {
        var action = button.getAttribute("data-helper-action");
        var targetSelector = button.getAttribute("data-target-input");
        var targetInput = targetSelector ? document.querySelector(targetSelector) : null;

        if (!targetInput) {
          setHelperStatus("Nie znaleziono pola docelowego dla wybranej akcji.", true);
          return;
        }

        var path = action === "pick-csv" ? "/pick-file" : "/pick-folder";
        var payload = action === "pick-csv"
          ? { filter: "CSV (*.csv)|*.csv|Wszystkie pliki (*.*)|*.*", title: "Wybierz plik stali_wystawcy.csv" }
          : { description: "Wybierz katalog z lokalnymi dokumentami PDF" };

        button.disabled = true;
        setHelperStatus("Laczenie z helperem lokalnym...", false);

        try {
          var result = await callHelper(path, payload);
          if (result.cancelled) {
            setHelperStatus("Wybor zostal anulowany.", false);
            return;
          }

          if (!result.path) {
            throw new Error("Helper nie zwrocil sciezki.");
          }

          targetInput.value = result.path;
          targetInput.dispatchEvent(new Event("input", { bubbles: true }));
          targetInput.dispatchEvent(new Event("change", { bubbles: true }));
          setHelperStatus("Sciezka zostala pobrana z helpera lokalnego.", false);
        } catch (error) {
          setHelperStatus("Nie udalo sie polaczyc z helperem. Uruchom agent-ksef-helper.cmd. " + error.message, true);
        } finally {
          button.disabled = false;
        }
      });
    });
  }
})();
