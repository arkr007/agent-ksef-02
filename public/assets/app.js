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
})();
