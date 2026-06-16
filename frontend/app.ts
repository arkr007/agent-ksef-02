document.documentElement.classList.add("ts-app-ready");

const monthInputs = document.querySelectorAll<HTMLInputElement>('input[type="month"]');

monthInputs.forEach((input) => {
  input.addEventListener("change", () => {
    input.dataset.hasValue = input.value ? "1" : "0";
  });
});
