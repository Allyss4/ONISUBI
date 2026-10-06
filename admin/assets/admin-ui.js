// ONISUBI Admin Panel - Supabase Integration & Realtime Sync

(function () {
  const SUPABASE_URL = "https://ghrexntoqduadflakijc.supabase.co";
  const SUPABASE_ANON_KEY =
    "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdocmV4bnRvcWR1YWRmbGFraWpjIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODg4NjMyNjgsImV4cCI6MjEwNDQzOTI2OH0.CxORa-VSGILFmJF3T4BS_ZHSElVQ6FdStXv51XPBFeg";

  // Remember active navigation
  document.querySelectorAll("[data-nav]").forEach((a) =>
    a.addEventListener("click", () =>
      localStorage.setItem("adminLastPage", a.dataset.nav)
    )
  );

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      if (window.supabase) {
        resolve();
        return;
      }
      const existing = document.querySelector(`script[src="${src}"]`);
      if (existing) {
        existing.addEventListener("load", resolve);
        existing.addEventListener("error", reject);
        return;
      }
      const s = document.createElement("script");
      s.src = src;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function formatPeso(amount) {
    const val = Number(amount || 0);
    return "₱" + val.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  async function initAdmin() {
    try {
      await loadScript("https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2");
    } catch (err) {
      console.error("Failed to load Supabase JS SDK:", err);
      return;
    }

    if (!window.supabase) {
      console.error("Supabase client is not available.");
      return;
    }

    const sb = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
    window.sbClient = sb;

    // Route based on present elements
    if (document.getElementById("stat-sales") || document.getElementById("recent-orders")) {
      initDashboard(sb);
    }
    if (document.getElementById("customers-body")) {
      initCustomers(sb);
    }
    if (document.getElementById("staff-body")) {
      initStaff(sb);
    }
    if (document.getElementById("orders-body")) {
      initOrders(sb);
    }
    if (document.getElementById("products-body")) {
      initProducts(sb);
    }
    if (document.getElementById("inventory-body")) {
      initInventory(sb);
    }
    if (document.getElementById("reviews-body")) {
      initReviews(sb);
    }
    if (document.getElementById("gcash-name") || document.getElementById("save-payment")) {
      initPaymentSettings(sb);
    }
  }

  // 1. DASHBOARD
  async function initDashboard(sb) {
    async function refreshMetrics() {
      try {
        const { data, error } = await sb.rpc("get_admin_dashboard_metrics");
        if (error) throw error;
        if (data) {
          const elSales = document.getElementById("stat-sales");
          const elOrders = document.getElementById("stat-orders");
          const elPending = document.getElementById("stat-pending");
          const elCustomers = document.getElementById("stat-customers");

          if (elSales) elSales.textContent = formatPeso(data.sales);
          if (elOrders) elOrders.textContent = String(data.orders || 0);
          if (elPending) elPending.textContent = String(data.pending || 0);
          if (elCustomers) elCustomers.textContent = String(data.customers || 0);
        }
      } catch (err) {
        console.warn("Metrics RPC fallback to query:", err);
        fallbackMetrics();
      }
    }

    async function fallbackMetrics() {
      const { data: orders } = await sb.from("orders").select("total, status");
      if (orders) {
        let totalSales = 0;
        let pending = 0;
        orders.forEach((o) => {
          const st = (o.status || "").toLowerCase();
          if (["completed", "delivered"].includes(st)) totalSales += Number(o.total || 0);
          if (["order placed", "pending", "confirmed", "preparing", "ready", "for verification"].includes(st)) pending++;
        });
        const elSales = document.getElementById("stat-sales");
        const elOrders = document.getElementById("stat-orders");
        const elPending = document.getElementById("stat-pending");
        if (elSales) elSales.textContent = formatPeso(totalSales);
        if (elOrders) elOrders.textContent = String(orders.length);
        if (elPending) elPending.textContent = String(pending);
      }
    }

    async function refreshRecentOrders() {
      const tbody = document.getElementById("recent-orders");
      if (!tbody) return;

      const { data, error } = await sb
        .from("orders")
        .select("id, customer_name, status, total, order_date")
        .order("order_date", { ascending: false })
        .limit(5);

      if (error || !data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="empty">No orders yet.</td></tr>';
        return;
      }

      tbody.innerHTML = data
        .map(
          (o) => `
        <tr>
          <td>#${o.id}</td>
          <td>${escapeHtml(o.customer_name || "Guest")}</td>
          <td><span class="status ${String(o.status || "").toLowerCase().replace(/\s+/g, "-")}">${escapeHtml(o.status || "Pending")}</span></td>
          <td><a class="eye" href="orders.php?id=${o.id}" title="View order">◉</a></td>
        </tr>
      `
        )
        .join("");
    }

    async function refreshInventoryWatch() {
      const tbody = document.getElementById("inventory-watch");
      if (!tbody) return;

      const { data, error } = await sb.rpc("get_products_with_inventory");
      let items = data;

      if (error || !items) {
        const res = await sb
          .from("products")
          .select("id, name, stock")
          .order("stock", { ascending: true })
          .limit(5);
        items = (res.data || []).map((p) => ({
          name: p.name,
          inventory_quantity: p.stock,
          low_stock_limit: 5,
        }));
      }

      if (!items || items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="empty">No inventory yet.</td></tr>';
        return;
      }

      const watchList = items.slice(0, 5);
      tbody.innerHTML = watchList
        .map((p) => {
          const qty = Number(p.inventory_quantity ?? p.stock ?? 0);
          const limit = Number(p.low_stock_limit ?? 5);
          const isLow = qty <= limit;
          return `
          <tr>
            <td>${escapeHtml(p.name)}</td>
            <td><strong>${qty}</strong></td>
            <td><span class="status ${isLow ? "pending" : "completed"}">${isLow ? (qty <= 0 ? "Out of Stock" : "Low Stock") : "In Stock"}</span></td>
          </tr>
        `;
        })
        .join("");
    }

    refreshMetrics();
    refreshRecentOrders();
    refreshInventoryWatch();

    // Subscribe to real-time changes
    sb.channel("admin-dashboard")
      .on("postgres_changes", { event: "*", schema: "public", table: "orders" }, () => {
        refreshMetrics();
        refreshRecentOrders();
      })
      .on("postgres_changes", { event: "*", schema: "public", table: "inventory" }, () => {
        refreshInventoryWatch();
        refreshMetrics();
      })
      .on("postgres_changes", { event: "*", schema: "public", table: "products" }, () => {
        refreshInventoryWatch();
      })
      .subscribe();
  }

  // 2. CUSTOMERS
  async function initCustomers(sb) {
    const tbody = document.getElementById("customers-body");
    if (!tbody) return;

    async function loadCustomers(search = "") {
      const { data, error } = await sb.rpc("get_admin_customers", {
        p_search: search || null,
      });

      if (error || !data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty">No customers found.</td></tr>';
        return;
      }

      tbody.innerHTML = data
        .map(
          (u) => `
        <tr>
          <td>#${u.id}</td>
          <td><strong>${escapeHtml(u.name)}</strong></td>
          <td>${escapeHtml(u.email || "—")}</td>
          <td>${escapeHtml(u.phone || "—")}</td>
          <td>${u.order_count || 0}</td>
          <td>${formatPeso(u.total_spent || 0)}</td>
          <td><a class="eye" href="customers.php?id=${u.id}${search ? '&search=' + encodeURIComponent(search) : ''}" title="View customer details">◉</a></td>
        </tr>
      `
        )
        .join("");
    }

    const searchInput = document.querySelector(".panel form input[name='search']");
    if (searchInput) {
      searchInput.addEventListener("input", (e) => loadCustomers(e.target.value.trim()));
    }
  }

  // 3. STAFF
  async function initStaff(sb) {
    const tbody = document.getElementById("staff-body");
    if (!tbody) return;

    async function loadStaff() {
      const { data, error } = await sb.rpc("get_admin_staff");

      if (error || !data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty">No staff accounts found.</td></tr>';
        return;
      }

      tbody.innerHTML = data
        .map(
          (u) => `
        <tr>
          <td>#${u.id}</td>
          <td><strong>${escapeHtml(u.name)}</strong></td>
          <td>${escapeHtml(u.email)}</td>
          <td><span class="badge ${String(u.role).toLowerCase() === "admin" ? "completed" : "pending"}">${escapeHtml(String(u.role).toUpperCase())}</span></td>
          <td>${escapeHtml(new Date(u.created_at).toLocaleDateString())}</td>
          <td><a href="staff.php" class="btn" style="padding:4px 8px;font-size:12px;">Manage</a></td>
        </tr>
      `
        )
        .join("");
    }

    sb.channel("admin-staff")
      .on("postgres_changes", { event: "*", schema: "public", table: "users" }, loadStaff)
      .subscribe();
  }

  // 4. ORDERS
  async function initOrders(sb) {
    const tbody = document.getElementById("orders-body");
    if (!tbody) return;

    const statuses = [
      "Order Placed",
      "Pending",
      "Confirmed",
      "Preparing",
      "Ready",
      "Out for Delivery",
      "Delivered",
      "Completed",
      "Cancelled",
      "Awaiting Payment",
      "For Verification",
    ];

    async function loadOrders(search = "", statusFilter = "") {
      const { data, error } = await sb.rpc("get_admin_orders", {
        p_search: search || null,
        p_status: statusFilter || null,
        p_limit: 15,
        p_offset: 0,
      });

      if (error || !data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="empty">No orders found.</td></tr>';
        return;
      }

      tbody.innerHTML = data
        .map(
          (o) => `
        <tr>
          <td><strong>#${o.id}</strong></td>
          <td>${escapeHtml(o.display_name || o.customer_name || "Guest")}</td>
          <td>${escapeHtml(new Date(o.order_date).toLocaleString())}</td>
          <td>${escapeHtml(o.payment_method || "COD")}</td>
          <td><strong>${formatPeso(o.total)}</strong></td>
          <td>
            <span class="badge ${['completed', 'delivered'].includes(String(o.status || '').toLowerCase()) ? 'completed' : 'pending'}">${escapeHtml(o.status || 'Pending')}</span>
          </td>
          <td>
            <select data-order-id="${o.id}" class="admin-status-select" style="padding:4px 8px;border-radius:4px;border:1px solid var(--line);font-size:12px;background:#fff;">
              ${statuses.map((s) => `<option value="${s}" ${s.toLowerCase() === String(o.status || "").toLowerCase() ? "selected" : ""}>${s}</option>`).join("")}
            </select>
          </td>
          <td><a class="eye" href="orders.php?id=${o.id}" title="View order details">◉</a></td>
        </tr>
      `
        )
        .join("");

      // Bind status select change to Supabase RPC
      tbody.querySelectorAll(".admin-status-select").forEach((sel) => {
        sel.addEventListener("change", async () => {
          const orderId = Number(sel.dataset.orderId);
          const newStatus = sel.value;
          sel.disabled = true;
          try {
            const { data: res, error: rpcErr } = await sb.rpc("update_order_status", {
              p_order_id: orderId,
              p_new_status: newStatus,
            });
            if (rpcErr) throw rpcErr;
            alert(`Order #${orderId} status updated to ${newStatus}`);
          } catch (err) {
            alert("Error updating order status: " + (err.message || err));
            loadOrders(search, statusFilter);
          } finally {
            sel.disabled = false;
          }
        });
      });
    }

    const searchInput = document.querySelector(".panel form input[name='search']");
    const statusSelect = document.querySelector(".panel form select[name='status']");

    if (searchInput) {
      searchInput.addEventListener("input", () => loadOrders(searchInput.value.trim(), statusSelect ? statusSelect.value : ""));
    }
    if (statusSelect) {
      statusSelect.addEventListener("change", () => loadOrders(searchInput ? searchInput.value.trim() : "", statusSelect.value));
    }

    // Live order updates
    sb.channel("admin-orders")
      .on("postgres_changes", { event: "*", schema: "public", table: "orders" }, () => {
        loadOrders(searchInput ? searchInput.value.trim() : "", statusSelect ? statusSelect.value : "");
      })
      .subscribe();
  }

  // 5. PRODUCTS
  async function initProducts(sb) {
    const tbody = document.getElementById("products-body");
    if (!tbody) return;

    async function loadProducts() {
      const { data, error } = await sb.rpc("get_products_with_inventory");
      let products = data;

      if (error || !products) {
        const res = await sb.from("products").select("*").neq("status", "deleted").order("id", { ascending: true });
        products = res.data || [];
      }

      if (!products || products.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty">No products found.</td></tr>';
        return;
      }

      tbody.innerHTML = products
        .map((p) => {
          const stock = Number(p.inventory_quantity ?? p.stock ?? 0);
          const isAvailable = (p.status || "").toLowerCase() === "active";
          return `
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                ${p.image ? `<img src="${escapeHtml(p.image)}" style="width:36px;height:36px;border-radius:6px;object-fit:cover;">` : '<span style="width:36px;height:36px;background:#eee;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:bold;">O</span>'}
                <strong>${escapeHtml(p.name)}</strong>
              </div>
            </td>
            <td>${formatPeso(p.price)}</td>
            <td><strong>${stock}</strong></td>
            <td><span class="status ${isAvailable ? "completed" : "pending"}">${isAvailable ? "Available" : "Unavailable"}</span></td>
            <td>
              <button class="btn" style="padding:4px 8px;font-size:12px;" onclick="window.location.href='products.php?edit=${p.id}'">Edit</button>
            </td>
          </tr>
        `;
        })
        .join("");
    }

    loadProducts();

    sb.channel("admin-products")
      .on("postgres_changes", { event: "*", schema: "public", table: "products" }, loadProducts)
      .on("postgres_changes", { event: "*", schema: "public", table: "inventory" }, loadProducts)
      .subscribe();
  }

  // 6. INVENTORY
  async function initInventory(sb) {
    const tbody = document.getElementById("inventory-body");
    if (!tbody) return;

    function updateRowState(row, newQty, newLimit) {
      if (!row) return;
      const qtyEl = row.querySelector(".stock-qty-val");
      const limitEl = row.querySelector(".stock-limit-val");
      const badgeEl = row.querySelector(".stock-badge-val");
      const limitInput = row.querySelector("input[name='low_stock_limit']");

      const isLow = newQty <= newLimit;
      if (qtyEl) {
        qtyEl.textContent = String(newQty);
        qtyEl.style.color = isLow ? "var(--red)" : "";
      }
      if (limitEl) limitEl.textContent = String(newLimit);
      if (limitInput) limitInput.value = String(newLimit);

      if (badgeEl) {
        const badgeClass = newQty <= 0 ? "out" : (isLow ? "pending" : "completed");
        const badgeText = newQty <= 0 ? "Out of stock" : (isLow ? "Low stock" : "In stock");
        badgeEl.className = `badge ${badgeClass} stock-badge-val`;
        badgeEl.textContent = badgeText;
      }
    }

    function updateStats() {
      let total = 0;
      let inStock = 0;
      let lowStock = 0;
      let outStock = 0;

      tbody.querySelectorAll("tr[data-product-id]").forEach((row) => {
        total++;
        const qty = Number(row.querySelector(".stock-qty-val")?.textContent || 0);
        const limit = Number(row.querySelector(".stock-limit-val")?.textContent || 5);
        if (qty <= 0) {
          outStock++;
        } else if (qty <= limit) {
          lowStock++;
        } else {
          inStock++;
        }
      });

      const elTotal = document.getElementById("stat-total-tracked");
      const elIn = document.getElementById("stat-in-stock");
      const elLow = document.getElementById("stat-low-stock");
      const elOut = document.getElementById("stat-out-stock");

      if (elTotal) elTotal.textContent = String(total);
      if (elIn) elIn.textContent = String(inStock);
      if (elLow) elLow.textContent = String(lowStock);
      if (elOut) elOut.textContent = String(outStock);
    }

    function bindRowActions() {
      // Quick +/- adjustments
      tbody.querySelectorAll(".btn-stock-adj").forEach((btn) => {
        if (btn.dataset.bound) return;
        btn.dataset.bound = "true";

        btn.addEventListener("click", async () => {
          const pid = Number(btn.dataset.pid);
          const adj = Number(btn.dataset.action);
          const row = btn.closest("tr");
          const limitInput = row ? row.querySelector("input[name='low_stock_limit']") : null;
          const currentLimit = limitInput ? Number(limitInput.value || 5) : 5;

          btn.disabled = true;
          try {
            const { data: res, error: rpcErr } = await sb.rpc("adjust_inventory", {
              p_product_id: pid,
              p_adjustment: adj,
              p_low_stock_limit: currentLimit,
            });
            if (rpcErr) throw rpcErr;
            if (res && res.success) {
              updateRowState(row, Number(res.new_quantity), Number(res.low_stock_limit));
              updateStats();
            }
          } catch (err) {
            alert("Error adjusting inventory: " + (err.message || err));
          } finally {
            btn.disabled = false;
          }
        });
      });

      // Custom form submission via AJAX / Supabase RPC
      tbody.querySelectorAll(".inventory-adjust-form").forEach((form) => {
        if (form.dataset.bound) return;
        form.dataset.bound = "true";

        form.addEventListener("submit", async (e) => {
          e.preventDefault();
          const pid = Number(form.dataset.pid);
          const adjInput = form.querySelector("input[name='adjustment']");
          const limitInput = form.querySelector("input[name='low_stock_limit']");
          const submitBtn = form.querySelector(".btn-apply-adj");
          const adj = Number(adjInput ? adjInput.value : 0);
          const limit = Number(limitInput ? limitInput.value : 5);

          if (submitBtn) submitBtn.disabled = true;
          try {
            const { data: res, error: rpcErr } = await sb.rpc("adjust_inventory", {
              p_product_id: pid,
              p_adjustment: adj,
              p_low_stock_limit: limit,
            });
            if (rpcErr) throw rpcErr;
            if (res && res.success) {
              const row = form.closest("tr");
              updateRowState(row, Number(res.new_quantity), Number(res.low_stock_limit));
              if (adjInput) adjInput.value = "0";
              updateStats();

              if (submitBtn) {
                const originalText = submitBtn.textContent;
                submitBtn.textContent = "Saved!";
                submitBtn.style.background = "var(--green)";
                setTimeout(() => {
                  submitBtn.textContent = originalText;
                  submitBtn.style.background = "";
                }, 1200);
              }
            }
          } catch (err) {
            alert("Error saving inventory: " + (err.message || err));
          } finally {
            if (submitBtn) submitBtn.disabled = false;
          }
        });
      });
    }

    // Client-side search and status filter
    const searchInput = document.getElementById("inventory-search");
    const statusSelect = document.getElementById("inventory-filter-status");

    function filterRows() {
      const term = (searchInput ? searchInput.value.toLowerCase().trim() : "");
      const filter = (statusSelect ? statusSelect.value : "all");

      tbody.querySelectorAll("tr[data-product-id]").forEach((row) => {
        const name = (row.querySelector("strong") ? row.querySelector("strong").textContent.toLowerCase() : "");
        const qty = Number(row.querySelector(".stock-qty-val")?.textContent || 0);
        const limit = Number(row.querySelector(".stock-limit-val")?.textContent || 5);

        let matchesStatus = true;
        if (filter === "in_stock") matchesStatus = (qty > limit);
        else if (filter === "low_stock") matchesStatus = (qty <= limit && qty > 0);
        else if (filter === "out_of_stock") matchesStatus = (qty <= 0);

        const matchesSearch = !term || name.includes(term);
        row.style.display = (matchesStatus && matchesSearch) ? "" : "none";
      });
    }

    if (searchInput) searchInput.addEventListener("input", filterRows);
    if (statusSelect) statusSelect.addEventListener("change", filterRows);

    // Initial binding to the server-rendered 5-column table
    bindRowActions();

    // Realtime Supabase channel for live inventory synchronization
    sb.channel("admin-inventory")
      .on("postgres_changes", { event: "*", schema: "public", table: "inventory" }, (payload) => {
        const pid = payload.new ? payload.new.product_id : null;
        if (pid) {
          const row = tbody.querySelector(`tr[data-product-id="${pid}"]`);
          if (row) {
            updateRowState(row, Number(payload.new.quantity), Number(payload.new.low_stock_limit));
            updateStats();
          }
        }
      })
      .subscribe();
  }


  // 7. REVIEWS
  async function initReviews(sb) {
    const tbody = document.getElementById("reviews-body");
    if (!tbody) return;

    async function loadReviews() {
      const { data, error } = await sb.rpc("get_admin_reviews", {
        p_limit: 50,
        p_offset: 0,
      });

      if (error || !data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty">No customer reviews yet.</td></tr>';
        return;
      }

      tbody.innerHTML = data
        .map(
          (r) => `
        <tr>
          <td><strong>${escapeHtml(r.customer_name || "Customer")}</strong></td>
          <td>${escapeHtml(r.product_name || "Product")}</td>
          <td>
            <span style="color:var(--orange);font-size:15px;letter-spacing:2px;">
              ${"★".repeat(Number(r.rating || 5))}<span style="color:var(--beige);">${"☆".repeat(Math.max(0, 5 - Number(r.rating || 5)))}</span>
            </span>
            <span style="font-size:11px;color:var(--muted);font-weight:700;margin-left:4px;">(${Number(r.rating || 5)}/5)</span>
          </td>
          <td style="max-width:320px;line-height:1.4;font-size:13px;">${escapeHtml(r.comment || "")}</td>
          <td>${escapeHtml(new Date(r.created_at).toLocaleDateString())}</td>
        </tr>
      `
        )
        .join("");
    }

    loadReviews();

    sb.channel("admin-reviews")
      .on("postgres_changes", { event: "*", schema: "public", table: "reviews" }, loadReviews)
      .subscribe();
  }

  // 8. PAYMENT SETTINGS
  async function initPaymentSettings(sb) {
    const nameInput = document.getElementById("gcash-name");
    const numInput = document.getElementById("gcash-number");
    const qrImg = document.getElementById("gcash-qr");
    const saveBtn = document.getElementById("save-payment");
    const gcashStatus = document.getElementById("gcash-status");
    const cashStatus = document.getElementById("cash-status");

    async function loadSettings() {
      const { data, error } = await sb.rpc("get_payment_settings");
      const setting = Array.isArray(data) ? data[0] : data;

      if (setting) {
        if (nameInput) nameInput.value = setting.gcash_name || "";
        if (numInput) numInput.value = setting.gcash_number || "";
        if (qrImg && setting.gcash_qr) qrImg.src = setting.gcash_qr;
        if (gcashStatus) {
          gcashStatus.innerHTML = `<span class="badge ${setting.gcash_enabled ? 'completed' : 'pending'}">${setting.gcash_enabled ? 'ACTIVE' : 'DISABLED'}</span>`;
        }
        if (cashStatus) {
          cashStatus.innerHTML = `<span class="badge ${setting.cash_enabled ? 'completed' : 'pending'}">${setting.cash_enabled ? 'ACTIVE' : 'DISABLED'}</span>`;
        }
      }
    }

    if (saveBtn) {
      saveBtn.addEventListener("click", async () => {
        saveBtn.disabled = true;
        const gName = nameInput ? nameInput.value.trim() : "";
        const gNum = numInput ? numInput.value.trim() : "";
        try {
          const { data, error } = await sb.rpc("update_payment_settings", {
            p_gcash_name: gName,
            p_gcash_number: gNum,
            p_gcash_qr: qrImg ? qrImg.getAttribute("src") : "assets/images/gcash-qr.png",
            p_gcash_enabled: true,
            p_cash_enabled: true,
          });
          if (error) throw error;
          alert("Payment settings saved successfully!");
          loadSettings();
        } catch (err) {
          alert("Error saving settings: " + (err.message || err));
        } finally {
          saveBtn.disabled = false;
        }
      });
    }

    loadSettings();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAdmin);
  } else {
    initAdmin();
  }
})();