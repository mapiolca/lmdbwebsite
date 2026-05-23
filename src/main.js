const controls = document.querySelectorAll("[data-frequency]");
const offerCards = document.querySelectorAll("[data-offer-card]");

function formatPrice(value) {
  return new Intl.NumberFormat("fr-FR", {
    style: "currency",
    currency: "EUR",
    maximumFractionDigits: 0
  }).format(value);
}

function updatePricing(frequency) {
  controls.forEach((button) => {
    button.setAttribute("aria-pressed", String(button.dataset.frequency === frequency));
  });

  offerCards.forEach((card) => {
    const price = frequency === "annual" ? Number(card.dataset.annual) : Number(card.dataset.monthly);
    const period = frequency === "annual" ? "/an HT" : "/mois HT";
    const priceNode = card.querySelector("[data-price]");
    const periodNode = card.querySelector("[data-period]");
    const subscribeLink = card.querySelector("[data-subscribe-link]");

    if (priceNode) priceNode.textContent = formatPrice(price);
    if (periodNode) periodNode.textContent = period;
    if (subscribeLink) {
      const url = new URL(subscribeLink.href);
      url.searchParams.set("frequency", frequency);
      subscribeLink.href = url.toString();
    }
  });
}

controls.forEach((button) => {
  button.addEventListener("click", () => updatePricing(button.dataset.frequency));
});

if (controls.length) {
  updatePricing("monthly");
}

