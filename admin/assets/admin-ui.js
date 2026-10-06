document
  .querySelectorAll("[data-nav]")
  .forEach((a) =>
    a.addEventListener("click", () => localStorage.setItem("adminLastPage", a.dataset.nav)),
  );

// Supabase hooks go here. Target element ids:
// index: stat-sales, stat-orders, stat-pending, stat-customers, recent-orders, inventory-watch
// customers-body, staff-body, orders-body, products-body, inventory-body, reviews-body
// payment-settings: gcash-name, gcash-number, gcash-qr, save-payment, gcash-status, cash-status