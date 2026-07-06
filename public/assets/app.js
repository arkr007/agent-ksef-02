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
})();
