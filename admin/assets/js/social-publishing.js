document.addEventListener("DOMContentLoaded", () => {
  const highlight = document.querySelector(".bg-\\[\\#FFD700\\]\\/20");
  if (highlight) {
    highlight.classList.add("ring-2", "ring-[#FFD700]/50", "rounded-lg");
  }
});
