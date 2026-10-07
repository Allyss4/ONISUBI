const BASE_PATH = window.location.pathname.includes('/pages/') ? '../' : './';
const CART_KEY = 'onisubi-cart-items';

function getCartItems() {
  try {
    const storedItems = localStorage.getItem(CART_KEY);
    if (storedItems) return JSON.parse(storedItems);
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('onisubi_cart='));
    return cookie ? JSON.parse(decodeURIComponent(cookie.split('=').slice(1).join('='))) : [];
  } catch {
    return [];
  }
}

function syncCartCookie(items) {
  const value = encodeURIComponent(JSON.stringify(items));
  document.cookie = `onisubi_cart=${value}; path=/; max-age=86400`;
}

function saveCartItems(items) {
  localStorage.setItem(CART_KEY, JSON.stringify(items));
  syncCartCookie(items);
  updateCartCount();
  updateSummaryUI();
}

function updateCartCount() {
  const cartCount = document.getElementById('cart-count');
  if (!cartCount) return;

  const totalItems = getCartItems().reduce((sum, item) => sum + Number(item.qty || 1), 0);
  cartCount.textContent = totalItems;
}

function updateSummaryUI() {
  const { totalItems, total } = getCartSummary();

  const totalValue = document.querySelector('[data-cart-total]');
  if (totalValue) {
    totalValue.textContent = formatPeso(total);
  }

  const checkoutItems = document.getElementById('checkout-items');
  if (checkoutItems) {
    checkoutItems.textContent = totalItems;
  }

  const checkoutTotal = document.getElementById('checkout-total');
  if (checkoutTotal) {
    checkoutTotal.textContent = formatPeso(total);
  }
}

function getProductStock(item) {
  if (!item) return 99;
  const stocks = window.PRODUCT_STOCKS || {};
  const pid = Number(item.id || item.productId || 0);
  if (pid > 0 && stocks[pid] !== undefined) {
    const s = stocks[pid];
    return typeof s === 'object' && s !== null ? Number(s.stock ?? 0) : Number(s);
  }
  if (item.name) {
    const rawName = item.name.split('(')[0].trim().toLowerCase();
    for (const [id, data] of Object.entries(stocks)) {
      const pName = (typeof data === 'object' && data ? data.name : '').toLowerCase();
      if (pName && (pName === rawName || rawName.includes(pName) || pName.includes(rawName))) {
        return typeof data === 'object' && data ? Number(data.stock ?? 0) : Number(data);
      }
    }
  }
  return 99;
}

function addToCart(name, price, productId, image, qty = 1, optionsNote = '') {
  const items = getCartItems();
  const displayName = optionsNote ? `${name} (${optionsNote})` : name;
  const existing = items.find((item) => item.name === displayName);
  const quantityToAdd = Math.max(1, Number(qty || 1));
  const pid = Number(productId);
  const maxStock = getProductStock({ id: pid, name });

  if (maxStock <= 0) {
    showCartProcessingPopup('Out of Stock', `${name} is currently out of stock.`);
    return false;
  }

  if (existing) {
    if (existing.qty >= maxStock) {
      showCartProcessingPopup('Limit Reached', `Only ${maxStock} item(s) available in stock.`);
      return false;
    }
    const newQty = Math.min(maxStock, existing.qty + quantityToAdd);
    existing.qty = newQty;
  } else {
    items.push({
      id: pid,
      name: displayName,
      price: Number(price),
      image,
      qty: Math.min(maxStock, quantityToAdd)
    });
  }

  saveCartItems(items);
  return true;
}

function getCartTotal() {
  return getCartItems().reduce((sum, item) => sum + (Number(item.price) * Number(item.qty || 1)), 0);
}

function getCartSummary() {
  const items = getCartItems();
  const totalItems = items.reduce((sum, item) => sum + Number(item.qty || 1), 0);
  const total = getCartTotal();

  return { items, totalItems, total };
}

function escapeHtml(value) {
  return String(value).replace(/[&<>'"]/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[character]));
}

function openOrderConfirmModal() {
  const { totalItems, total } = getCartSummary();

  if (!totalItems) {
    showCartProcessingPopup('Your cart is empty.', 'Add an ONISUBI item before checkout.');
    return;
  }

  const items = getCartItems();
  const overStockItem = items.find((item) => {
    const stock = getProductStock(item);
    return stock <= 0 || item.qty > stock;
  });

  if (overStockItem) {
    const stock = getProductStock(overStockItem);
    showCartProcessingPopup(
      'Stock Limit',
      stock <= 0
        ? `"${overStockItem.name}" is out of stock. Please remove it before proceeding.`
        : `Only ${stock} of "${overStockItem.name}" available. Please adjust quantity.`
    );
    return;
  }

  const modal = document.getElementById('order-confirm-modal');
  if (!modal) return;
  modal.hidden = false;
  modal.querySelector('[data-confirm-items]').innerHTML = items.map((item) => `<div><span>${item.name} &times; ${item.qty}</span><strong>${formatPeso(item.price * item.qty)}</strong></div>`).join('');
  modal.querySelector('[data-confirm-total]').textContent = formatPeso(total);
}

function formatPeso(value) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2
  }).format(Number(value || 0));
}

function showCartProcessingPopup(title = 'Order is now processing', message = 'Your ONISUBI order is being prepared.') {
  const popup = document.getElementById('cart-processing-popup');
  if (!popup) return;

  popup.querySelector('strong').textContent = title;
  popup.querySelector('span').textContent = message;
  popup.classList.add('show');
  const timer = setTimeout(() => {
    popup.classList.remove('show');
    clearTimeout(timer);
  }, 2200);
}

const cartCount = document.getElementById('cart-count');
if (cartCount) {
  updateCartCount();
}

const cartButton = document.querySelector('.cart-pill');
if (cartButton) {
  cartButton.addEventListener('click', () => {
    window.location.href = BASE_PATH + 'pages/cart.php';
  });
}

const checkoutLinks = document.querySelectorAll('[data-checkout-link]');
checkoutLinks.forEach((link) => {
  link.addEventListener('click', (event) => {
    event.preventDefault();
    openOrderConfirmModal();
  });
});

const addButtons = document.querySelectorAll('[data-add-to-cart]');
addButtons.forEach((button) => {
  button.addEventListener('click', (event) => {
    event.stopPropagation();
    const name = button.dataset.name;
    const price = button.dataset.price;

    const added = addToCart(name, price, button.dataset.productId, button.dataset.image, 1);
    if (!added) return;
    const originalText = button.textContent;
    button.textContent = 'Added ✓';
    button.disabled = true;

    setTimeout(() => {
      button.textContent = originalText;
      button.disabled = false;
    }, 800);
  });
});

const newsletterForm = document.querySelector('.newsletter-form');
if (newsletterForm) {
  newsletterForm.addEventListener('submit', (event) => {
    event.preventDefault();
    alert('Thanks for subscribing!');
    newsletterForm.reset();
  });
}

let renderCart = null;
const cartTableBody = document.querySelector('[data-cart-items]');
if (cartTableBody) {
  renderCart = () => {
    const items = getCartItems();
    if (!items.length) {
      cartTableBody.innerHTML = `
            <tr>
                <td colspan="4" class="empty-cart">Your cart is empty.</td>
            </tr>
        `;
    } else {
      let hasAdjusted = false;
      cartTableBody.innerHTML = items.map((item, index) => {
        const maxStock = getProductStock(item);
        let qty = Number(item.qty || 1);
        if (qty > maxStock && maxStock >= 0) {
          qty = Math.max(0, maxStock);
          item.qty = qty;
          hasAdjusted = true;
        }
        const isOutOfStock = maxStock <= 0;
        const isAtMax = qty >= maxStock;

        let stockNoticeHtml = '';
        if (isOutOfStock) {
          stockNoticeHtml = `<span class="cart-stock-notice out-of-stock" data-cart-stock-notice>Out of stock (0 left)</span>`;
        } else if (isAtMax) {
          stockNoticeHtml = `<span class="cart-stock-notice max-reached" data-cart-stock-notice>Max stock reached (${maxStock} left)</span>`;
        } else if (maxStock <= 5) {
          stockNoticeHtml = `<span class="cart-stock-notice low-stock" data-cart-stock-notice>Only ${maxStock} left in stock</span>`;
        }

        return `
          <tr data-cart-row data-cart-index="${index}" data-product-id="${Number(item.id || 0)}" data-max-stock="${maxStock}">
              <td class="cart-product"><img src="${escapeHtml(item.image || `${BASE_PATH}assets/images/onisubi-logo.svg`)}" alt="${escapeHtml(item.name || '')}"><span>${escapeHtml(item.name)}</span></td>
              <td>
                <div class="cart-qty-wrap">
                  <div class="quantity-control ${isAtMax ? 'at-max-limit' : ''}">
                    <button type="button" data-cart-action="decrease" data-cart-index="${index}" aria-label="Decrease quantity">−</button>
                    <input type="number" inputmode="numeric" min="0" max="${maxStock}" step="1" value="${qty}" data-cart-qty-input data-cart-index="${index}" aria-label="Set quantity for ${escapeHtml(item.name)}">
                    <button type="button" data-cart-action="increase" data-cart-index="${index}" ${isAtMax ? 'disabled' : ''} aria-label="Increase quantity">+</button>
                  </div>
                  ${stockNoticeHtml}
                </div>
              </td>
              <td>${formatPeso(item.price)}</td>
              <td>${formatPeso(item.price * qty)}</td>
          </tr>
        `;
      }).join('');

      if (hasAdjusted) {
        saveCartItems(items);
        showCartProcessingPopup('Inventory Updated', 'Cart quantities adjusted to available product stock.');
      }
    }

    cartTableBody.querySelectorAll('[data-cart-action]').forEach((button) => button.addEventListener('click', () => {
      const items = getCartItems();
      const index = Number(button.dataset.cartIndex);
      const action = button.dataset.cartAction;
      if (!items[index]) return;

      const maxStock = getProductStock(items[index]);

      if (action === 'increase') {
        if (items[index].qty >= maxStock) {
          showCartProcessingPopup('Limit Reached', `Only ${maxStock} item(s) left in stock for ${items[index].name}.`);
          return;
        }
        items[index].qty += 1;
      } else {
        items[index].qty -= 1;
        if (items[index].qty <= 0) items.splice(index, 1);
      }

      saveCartItems(items);
      renderCart();
    }));

    cartTableBody.querySelectorAll('[data-cart-qty-input]').forEach((input) => {
      input.addEventListener('input', () => {
        const items = getCartItems();
        const index = Number(input.dataset.cartIndex);
        if (!items[index]) return;

        const maxStock = getProductStock(items[index]);
        const rawValue = input.value.trim();
        const parsedValue = Number(rawValue);

        if (rawValue === '' || !Number.isInteger(parsedValue) || parsedValue < 0) {
          input.value = items[index].qty || 1;
          return;
        }

        if (parsedValue === 0) {
          items.splice(index, 1);
          saveCartItems(items);
          renderCart();
          return;
        }

        if (parsedValue > maxStock) {
          items[index].qty = maxStock;
          input.value = maxStock;
          showCartProcessingPopup('Limit Reached', `Only ${maxStock} item(s) available in stock. Quantity adjusted.`);
        } else {
          items[index].qty = parsedValue;
        }

        saveCartItems(items);
        renderCart();
      });
    });
  };
  renderCart();
}

updateSummaryUI();

const orderConfirmModal = document.getElementById('order-confirm-modal');
if (orderConfirmModal) {
  orderConfirmModal.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => { orderConfirmModal.hidden = true; }));
  orderConfirmModal.addEventListener('click', (event) => { if (event.target === orderConfirmModal) orderConfirmModal.hidden = true; });
}

const paymentOptions = document.querySelectorAll('[data-payment-option]');
if (paymentOptions.length) {
  const setPaymentSelection = (selectedValue) => {
    paymentOptions.forEach((option) => {
      const input = option.querySelector('input[name="payment_method"]');
      const isSelected = input && input.value === selectedValue;
      option.classList.toggle('selected', isSelected);
      if (input) {
        input.checked = isSelected;
      }
    });

    const panels = document.querySelectorAll('[data-payment-panel]');
    panels.forEach((panel) => {
      const shouldShow = panel.dataset.paymentPanel === selectedValue;
      panel.classList.toggle('is-visible', shouldShow);
      panel.hidden = !shouldShow;
    });
  };

  paymentOptions.forEach((option) => {
    option.addEventListener('click', () => {
      const input = option.querySelector('input[name="payment_method"]');
      if (!input) return;
      setPaymentSelection(input.value);
    });
  });

  const checkedInput = document.querySelector('input[name="payment_method"]:checked');
  if (checkedInput) {
    setPaymentSelection(checkedInput.value);
  }
}

const checkoutForm = document.querySelector('[data-checkout-form]');
if (checkoutForm) {
  checkoutForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const { totalItems, total } = getCartSummary();
    if (!totalItems) {
      alert('Your cart is empty.');
      return;
    }

    const submitBtn = checkoutForm.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn ? submitBtn.textContent : 'Place Order';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Redirecting to payment...';
    }

    try {
      const formData = new FormData(checkoutForm);
      formData.append('cart', JSON.stringify(getCartItems()));
      const response = await fetch(BASE_PATH + 'api/create-order.php', { method: 'POST', body: formData });
      const result = await response.json();
      if (!response.ok) {
        alert(result.error || 'Unable to create the order.');
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = originalBtnText;
        }
        return;
      }
      localStorage.removeItem(CART_KEY);
      document.cookie = 'onisubi_cart=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
      window.location.href = result.checkout_url;
    } catch (err) {
      alert('Network error while processing checkout. Please try again.');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalBtnText;
      }
    }
  });
}

const orderStatus = document.querySelector('[data-order-status]');
if (orderStatus) {
  const orderId = new URLSearchParams(window.location.search).get('id');
  if (!orderId) { orderStatus.innerHTML = '<p>No order selected.</p>'; } else {
    fetch(`${BASE_PATH}pages/order-status.php?id=${encodeURIComponent(orderId)}&format=json`).then((response) => response.json()).then((order) => {
      const stages = [
        { label: 'Order Placed', active: order.status === 'Order Placed' },
        { label: 'Preparing', active: order.status === 'Preparing' },
        { label: 'Out for Delivery', active: order.status === 'Out for Delivery' },
        { label: 'Delivered', active: order.status === 'Delivered' }
      ];

      const mapStatus = {
        'Order Placed': 0,
        'Preparing': 1,
        'Out for Delivery': 2,
        'Delivered': 3,
      };
      const activeIndex = mapStatus[order.status] ?? 0;

      orderStatus.innerHTML = `
            <div class="status-header">
                <h2>Order is ${order.status || 'Order Placed'}</h2>
                <p>Thank you, ${order.customer_name || 'Customer'}! Your order is being prepared.</p>
            </div>
            <div class="status-progress">
                ${stages.map((stage, index) => `
                    <div class="status-step ${index <= activeIndex ? 'active' : ''}">
                        <span>${index + 1}</span>
                        <p>${stage.label}</p>
                    </div>
                `).join('')}
            </div>
            <div class="status-summary">
                <h3>Order Summary</h3>
                <ul>
                    ${Array.isArray(order.items) ? order.items.map((item) => `<li>${item.product_name} x ${item.quantity}</li>`).join('') : ''}
                </ul>
                <p class="status-total">Total: ${formatPeso(order.total)}</p>
            </div>
        `;
    }).catch(() => { orderStatus.innerHTML = '<p>Unable to load this order.</p>'; });
  }
}

function setFieldState(input, state, message) {
  input.classList.remove("is-invalid", "is-valid");
  let hint = input.nextElementSibling;
  if (!hint || !hint.classList.contains("field-hint")) {
    hint = document.createElement("p");
    hint.className = "field-hint";
    input.parentNode.insertBefore(hint, input.nextSibling);
  }
  hint.classList.remove("is-error", "is-ok");

  if (state === "error") {
    input.classList.add("is-invalid");
    hint.classList.add("is-error");
    hint.textContent = message;
  } else if (state === "ok") {
    input.classList.add("is-valid");
    hint.classList.add("is-ok");
    hint.textContent = message || "✓ Looks good";
  } else {
    hint.textContent = message || "";
  }
}

function validatePhone(input) {
  const raw = input.value.trim().replace(/[\s\-().]/g, "");
  if (!raw) {
    setFieldState(input, "error", "Phone number is required.");
    return false;
  }
  const ph = /^(\+?63|0)9\d{9}$/.test(raw);
  if (!ph) {
    setFieldState(
      input,
      "error",
      "Enter a valid Philippine number (e.g. 09XX XXX XXXX).",
    );
    return false;
  }
  setFieldState(input, "ok");
  return true;
}

function validatePassword(input, minLen = 8) {
  const val = input.value;
  if (!val) {
    setFieldState(input, "error", "Password is required.");
    return false;
  }
  if (val.length < minLen) {
    setFieldState(
      input,
      "error",
      `Password must be at least ${minLen} characters.`,
    );
    return false;
  }
  const strength = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter((r) =>
    r.test(val),
  ).length;
  if (strength < 2) {
    setFieldState(
      input,
      "error",
      "Add a mix of letters and numbers for a stronger password.",
    );
    return false;
  }
  setFieldState(input, "ok");
  return true;
}

function initMagicAuth() {
  document.querySelectorAll(".magic-auth-form").forEach((form) => {
    if (form.dataset.initialized) return;
    form.dataset.initialized = "true";

    const step1 = form.querySelector(".auth-step-1");
    const stepLogin = form.querySelector(".auth-step-login");
    const stepRegister = form.querySelector(".auth-step-register");

    const emailInput = form.querySelector(".auth-email-input");
    const continueBtn = form.querySelector(".auth-continue-btn");
    const backBtns = form.querySelectorAll(".auth-back-btn");

    const emailDisplays = form.querySelectorAll(".auth-email-display");
    const emailHiddens = form.querySelectorAll(".auth-email-hidden");

    if (stepRegister) {
      const phoneInput = stepRegister.querySelector('input[name="phone"]');
      const passInput = stepRegister.querySelector('input[name="password"]');

      if (phoneInput) {
        phoneInput.addEventListener("blur", () => validatePhone(phoneInput));
        phoneInput.addEventListener("input", () => {
          if (phoneInput.classList.contains("is-invalid"))
            validatePhone(phoneInput);
        });
      }

      if (passInput) {
        passInput.addEventListener("input", () => validatePassword(passInput));
        passInput.addEventListener("blur", () => validatePassword(passInput));
      }

      const registerSubmit = stepRegister.querySelector(
        'button[type="submit"]',
      );
      if (registerSubmit) {
        registerSubmit.addEventListener("click", (e) => {
          let ok = true;
          if (phoneInput && !validatePhone(phoneInput)) ok = false;
          if (passInput && !validatePassword(passInput)) ok = false;
          if (!ok) e.preventDefault();
        });
      }
    }
    if (stepLogin) {
      const passInput = stepLogin.querySelector('input[name="password"]');
      if (passInput) {
        passInput.addEventListener("blur", () => {
          if (!passInput.value)
            setFieldState(passInput, "error", "Password is required.");
          else setFieldState(passInput, "idle");
        });
      }

      const loginSubmit = stepLogin.querySelector('button[type="submit"]');
      if (loginSubmit) {
        loginSubmit.addEventListener("click", (e) => {
          if (passInput && !passInput.value) {
            setFieldState(passInput, "error", "Password is required.");
            e.preventDefault();
          }
        });
      }
    }

    async function checkEmail() {
      if (!emailInput.reportValidity()) return;
      const email = emailInput.value.trim();
      if (!email) return;

      continueBtn.disabled = true;
      continueBtn.textContent = "Checking...";

      try {
        const res = await fetch(
          `${BASE_PATH}api/check-email.php?email=${encodeURIComponent(email)}`,
        );
        const data = await res.json();

        step1.hidden = true;

        emailDisplays.forEach((el) => (el.textContent = email));
        emailHiddens.forEach((el) => {
          el.value = email;
          el.disabled = false;
        });

        if (data.exists) {
          stepLogin.hidden = false;
          stepLogin
            .querySelectorAll("input, button, textarea")
            .forEach((el) => (el.disabled = false));
          stepLogin.querySelector('input[name="action"]').disabled = false;
        } else {
          stepRegister.hidden = false;
          stepRegister
            .querySelectorAll("input, button, textarea")
            .forEach((el) => (el.disabled = false));
          stepRegister.querySelector('input[name="action"]').disabled = false;
        }
      } catch (err) {
        alert("Something went wrong. Please try again.");
      } finally {
        continueBtn.disabled = false;
        continueBtn.textContent = "Continue";
      }
    }

    if (continueBtn && emailInput) {
      continueBtn.addEventListener("click", checkEmail);
      emailInput.addEventListener("keypress", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          checkEmail();
        }
      });
    }

    backBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        if (stepLogin) stepLogin.hidden = true;
        if (stepRegister) stepRegister.hidden = true;
        if (step1) step1.hidden = false;

        if (stepLogin)
          stepLogin
            .querySelectorAll('input:not([type="hidden"]), button, textarea')
            .forEach((el) => (el.disabled = true));
        if (stepRegister)
          stepRegister
            .querySelectorAll('input:not([type="hidden"]), button, textarea')
            .forEach((el) => (el.disabled = true));
        if (stepLogin) {
          const a = stepLogin.querySelector('input[name="action"]');
          if (a) a.disabled = true;
        }
        if (stepRegister) {
          const a = stepRegister.querySelector('input[name="action"]');
          if (a) a.disabled = true;
        }
      });
    });
  });
}
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initMagicAuth);
} else {
  initMagicAuth();
}

function initMobileMenu() {
  const navToggle = document.getElementById("nav-toggle");
  const mainNav = document.getElementById("main-nav");
  const navOverlay = document.getElementById("nav-overlay");

  const siteHeader = navToggle ? navToggle.closest(".site-header") : null;

  if (!navToggle || !mainNav) return;
  if (navToggle.dataset.menuInitialized === "true") return;
  navToggle.dataset.menuInitialized = "true";

  function openMenu() {
    navToggle.classList.add("is-active");
    mainNav.classList.add("is-active");
    if (navOverlay) navOverlay.classList.add("is-active");
    if (siteHeader) siteHeader.classList.add("is-menu-open");
    document.body.classList.add("mobile-menu-open");
    navToggle.setAttribute("aria-expanded", "true");
  }

  function closeMenu() {
    navToggle.classList.remove("is-active");
    mainNav.classList.remove("is-active");
    if (navOverlay) navOverlay.classList.remove("is-active");
    if (siteHeader) siteHeader.classList.remove("is-menu-open");
    document.body.classList.remove("mobile-menu-open");
    navToggle.setAttribute("aria-expanded", "false");
    document.body.style.overflow = "";
  }

  function toggleMenu() {
    const isOpen = mainNav.classList.contains("is-active");
    if (isOpen) {
      closeMenu();
    } else {
      openMenu();
    }
  }

  navToggle.addEventListener("click", (e) => {
    e.stopPropagation();
    toggleMenu();
  });

  if (navOverlay) {
    navOverlay.addEventListener("click", (e) => {
      e.stopPropagation();
      closeMenu();
    });
  }

  mainNav.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => {
      closeMenu();
    });
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && mainNav.classList.contains("is-active")) {
      closeMenu();
    }
  });

  window.addEventListener("resize", () => {
    if (window.innerWidth > 980 && mainNav.classList.contains("is-active")) {
      closeMenu();
    }
  });
}

function initProductDetailModal() {
  const modal = document.getElementById('product-detail-modal');
  if (!modal) return;

  const modalCloseBtn = modal.querySelector('[data-product-modal-close]');
  const modalImage = document.getElementById('product-modal-image');
  const modalBadgesOverlay = document.getElementById('product-modal-badges-overlay');
  const modalTitle = document.getElementById('product-modal-title');
  const modalBasePrice = document.getElementById('product-modal-base-price');
  const modalDesc = document.getElementById('product-modal-description');
  const modalIngredients = document.getElementById('product-modal-ingredients');
  const modalAllergens = document.getElementById('product-modal-allergens');
  const modalSauces = document.getElementById('product-modal-sauces');
  const modalAddons = document.getElementById('product-modal-addons');

  const qtyMinusBtn = document.getElementById('modal-qty-minus');
  const qtyPlusBtn = document.getElementById('modal-qty-plus');
  const qtyValueEl = document.getElementById('modal-qty-value');
  const modalTotalPrice = document.getElementById('modal-total-price');
  const modalBtnPrice = document.getElementById('modal-btn-price');
  const addCartBtn = document.getElementById('modal-add-to-cart-btn');

  let currentProduct = null;
  let currentQty = 1;
  let selectedSauce = null;
  let selectedAddons = [];
  let modalScrollPosition = 0;

  function lockBodyScroll() {
    modalScrollPosition = window.scrollY || window.pageYOffset || 0;
    document.body.classList.add('modal-open');
    document.body.style.top = `-${modalScrollPosition}px`;
  }

  function unlockBodyScroll() {
    document.body.classList.remove('modal-open');
    document.body.style.top = '';

    // Restore scroll position instantly — html has scroll-behavior: smooth,
    // which would otherwise animate from the top down to modalScrollPosition.
    const htmlEl = document.documentElement;
    const previousScrollBehavior = htmlEl.style.scrollBehavior;
    htmlEl.style.scrollBehavior = 'auto';
    window.scrollTo(0, modalScrollPosition);
    htmlEl.style.scrollBehavior = previousScrollBehavior;
  }

  function updatePrice() {
    if (!currentProduct) return { unitPrice: 0, total: 0 };
    const base = Number(currentProduct.price || 0);
    const sauceExtra = selectedSauce ? Number(selectedSauce.price || 0) : 0;
    const addonsExtra = selectedAddons.reduce((sum, a) => sum + Number(a.price || 0), 0);
    const unitPrice = base + sauceExtra + addonsExtra;
    const total = unitPrice * currentQty;

    if (modalTotalPrice) modalTotalPrice.textContent = formatPeso(total);
    if (modalBtnPrice) modalBtnPrice.textContent = formatPeso(total);
    return { unitPrice, total };
  }

  function openModal(data) {
    currentProduct = data;
    currentQty = 1;
    selectedAddons = [];
    if (qtyValueEl) qtyValueEl.textContent = currentQty;

    if (modalImage) {
      modalImage.src = data.image || '';
      modalImage.alt = data.name || 'Product Image';
    }

    if (modalBadgesOverlay) {
      modalBadgesOverlay.innerHTML = '';
      if (Array.isArray(data.badges)) {
        data.badges
          .filter((b) => (b.type || '') === 'badge-bestseller')
          .forEach((b) => {
            const span = document.createElement('span');
            span.className = `badge-pill ${b.type || 'badge-info'}`;
            span.textContent = b.label || '';
            modalBadgesOverlay.appendChild(span);
          });
      }
    }

    if (modalTitle) modalTitle.textContent = data.name || '';
    if (modalBasePrice) modalBasePrice.textContent = formatPeso(data.price || 40);
    if (modalDesc) modalDesc.textContent = data.description || '';

    // Ingredients Chips
    if (modalIngredients) {
      modalIngredients.innerHTML = '';
      if (Array.isArray(data.ingredients) && data.ingredients.length) {
        data.ingredients.forEach((ing) => {
          const tag = document.createElement('span');
          tag.className = 'chip-tag';
          tag.textContent = ing;
          modalIngredients.appendChild(tag);
        });
      } else {
        modalIngredients.innerHTML = '<span class="chip-tag">Fresh Ingredients</span>';
      }
    }

    // Allergen & Dietary Badges
    if (modalAllergens) {
      modalAllergens.innerHTML = '';
      const allergens = Array.isArray(data.allergens) ? data.allergens : [];
      if (allergens.length) {
        allergens.forEach((alg) => {
          const span = document.createElement('span');
          span.className = `badge-pill ${alg.type || 'allergen'}`;
          span.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-exclamation-triangle-fill" viewBox="0 0 16 16">
                            <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
                            </svg>
           ${escapeHtml(alg.label || '')}`;
          modalAllergens.appendChild(span);
        });
      } else {
        modalAllergens.innerHTML = '<span class="badge-pill allergen">No common allergens reported</span>';
      }
    }

    // Customizations: Sauces
    if (modalSauces) {
      modalSauces.innerHTML = '';
      const sauces = data.customizations?.sauces || [];
      selectedSauce = sauces.find((s) => s.default) || sauces[0] || null;

      sauces.forEach((sauce) => {
        const label = document.createElement('label');
        const isChecked = selectedSauce && selectedSauce.name === sauce.name;
        label.className = `option-pill ${isChecked ? 'is-selected' : ''}`;
        label.innerHTML = `
          <div class="option-pill-left">
            <span class="option-indicator"></span>
            <input type="radio" name="modal_sauce" value="${escapeHtml(sauce.name)}" ${isChecked ? 'checked' : ''}>
            <span>${escapeHtml(sauce.name)}</span>
          </div>
          <span class="option-pill-price">${sauce.price > 0 ? `+${formatPeso(sauce.price)}` : 'Included'}</span>
        `;

        label.addEventListener('click', () => {
          selectedSauce = sauce;
          modalSauces.querySelectorAll('.option-pill').forEach((el) => el.classList.remove('is-selected'));
          label.classList.add('is-selected');
          const radio = label.querySelector('input');
          if (radio) radio.checked = true;
          updatePrice();
        });

        modalSauces.appendChild(label);
      });
    }

    // Customizations: Add-ons
    if (modalAddons) {
      modalAddons.innerHTML = '';
      const addons = data.customizations?.addons || [];
      addons.forEach((addon) => {
        const label = document.createElement('label');
        label.className = 'option-pill is-checkbox';
        label.innerHTML = `
          <div class="option-pill-left">
            <span class="option-indicator"></span>
            <input type="checkbox" value="${escapeHtml(addon.name)}">
            <span>${escapeHtml(addon.name)}</span>
          </div>
          <span class="option-pill-price">+${formatPeso(addon.price)}</span>
        `;

        const checkbox = label.querySelector('input');
        label.addEventListener('click', (e) => {
          e.preventDefault();
          checkbox.checked = !checkbox.checked;
          label.classList.toggle('is-selected', checkbox.checked);
          if (checkbox.checked) {
            selectedAddons.push(addon);
          } else {
            selectedAddons = selectedAddons.filter((a) => a.name !== addon.name);
          }
          updatePrice();
        });

        modalAddons.appendChild(label);
      });
    }

    updatePrice();

    // Reset Add to Cart button state based on stock
    if (addCartBtn) {
      const isOut = Number(data.stock ?? 1) <= 0;
      addCartBtn.classList.remove('is-success');
      const btnText = addCartBtn.querySelector('.btn-text');
      if (btnText) btnText.textContent = isOut ? 'Out of Stock' : 'Add to Cart';
      addCartBtn.disabled = isOut;
    }

    modal.hidden = false;
    lockBodyScroll();
  }

  function closeModal() {
    modal.hidden = true;
    unlockBodyScroll();
  }

  if (modalCloseBtn) {
    modalCloseBtn.addEventListener('click', closeModal);
  }

  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      closeModal();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });

  if (qtyMinusBtn) {
    qtyMinusBtn.addEventListener('click', () => {
      if (currentQty > 1) {
        currentQty -= 1;
        if (qtyValueEl) qtyValueEl.textContent = currentQty;
        updatePrice();
      }
    });
  }

  if (qtyPlusBtn) {
    qtyPlusBtn.addEventListener('click', () => {
      const maxStock = currentProduct ? getProductStock(currentProduct) : 99;
      if (currentQty < maxStock) {
        currentQty += 1;
        if (qtyValueEl) qtyValueEl.textContent = currentQty;
        updatePrice();
      } else {
        showCartProcessingPopup('Limit Reached', `Only ${maxStock} item(s) available in stock.`);
      }
    });
  }

  if (addCartBtn) {
    addCartBtn.addEventListener('click', () => {
      if (!currentProduct) return;
      const maxStock = getProductStock(currentProduct);
      if (maxStock <= 0) {
        showCartProcessingPopup('Out of Stock', `${currentProduct.name} is currently out of stock.`);
        return;
      }

      const existing = getCartItems().find((item) => Number(item.id) === Number(currentProduct.id));
      const inCartQty = existing ? Number(existing.qty || 0) : 0;
      if (inCartQty + currentQty > maxStock) {
        const canAdd = Math.max(0, maxStock - inCartQty);
        if (canAdd <= 0) {
          showCartProcessingPopup('Limit Reached', `You already have all ${maxStock} available item(s) in your cart.`);
          return;
        }
      }

      const { unitPrice } = updatePrice();

      const customParts = [];
      if (selectedSauce && selectedSauce.name) customParts.push(selectedSauce.name);
      selectedAddons.forEach((a) => customParts.push(a.name));
      const optionsNote = customParts.join(', ');

      const success = addToCart(currentProduct.name, unitPrice, currentProduct.id, currentProduct.image, currentQty, optionsNote);
      if (!success) return;

      addCartBtn.classList.add('is-success');
      const btnText = addCartBtn.querySelector('.btn-text');
      if (btnText) btnText.textContent = '✓ Added to Cart!';
      addCartBtn.disabled = true;

      showCartProcessingPopup('Added to Cart', `${currentQty}x ${currentProduct.name} added to your order.`);

      setTimeout(() => {
        closeModal();
      }, 700);
    });
  }

  // Bind product card clicks
  document.querySelectorAll('[data-product-card]').forEach((card) => {
    const handleOpen = () => {
      try {
        const raw = card.dataset.productJson;
        if (raw) {
          const data = JSON.parse(raw);
          openModal(data);
        }
      } catch (err) {
        console.error('Error parsing product JSON:', err);
      }
    };

    card.addEventListener('click', (e) => {
      if (e.target.closest('[data-add-to-cart]')) return;
      handleOpen();
    });

    card.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        handleOpen();
      }
    });
  });
}

function initRealtimeInventorySync() {
  const cfg = window.SUPABASE_CONFIG || {
    url: 'https://ghrexntoqduadflakijc.supabase.co',
    anonKey: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImdocmV4bnRvcWR1YWRmbGFraWpjIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODg4NjMyNjgsImV4cCI6MjEwNDQzOTI2OH0.CxORa-VSGILFmJF3T4BS_ZHSElVQ6FdStXv51XPBFeg'
  };

  if (!window.supabase) {
    console.warn('Supabase SDK not loaded yet for realtime inventory.');
    return;
  }

  const sb = window.supabase.createClient(cfg.url, cfg.anonKey);

  function applyProductStockUpdate(productId, newStock, lowStockLimit) {
    const pid = Number(productId);
    const qty = Number(newStock);
    if (!window.PRODUCT_STOCKS) window.PRODUCT_STOCKS = {};
    const existingEntry = window.PRODUCT_STOCKS[pid];
    window.PRODUCT_STOCKS[pid] = typeof existingEntry === 'object' && existingEntry !== null
      ? { ...existingEntry, stock: qty }
      : { id: pid, stock: qty };

    if (typeof renderCart === 'function') {
      renderCart();
    }

    const card = document.querySelector(`article[data-product-card][data-product-id="${productId}"]`);
    if (!card) return;

    const limit = lowStockLimit !== undefined ? Number(lowStockLimit) : (Number(card.dataset.lowLimit) || 5);
    const isOutOfStock = qty <= 0;
    const isLowStock = !isOutOfStock && qty <= limit;

    // 1. Update in-memory data JSON
    try {
      if (card.dataset.productJson) {
        const data = JSON.parse(card.dataset.productJson);
        data.stock = qty;
        data.low_stock_limit = limit;
        card.dataset.productJson = JSON.stringify(data);
      }
    } catch (e) {
      console.error(e);
    }

    card.dataset.stock = String(qty);

    // 2. Update stock status tag
    const badgeWrap = card.querySelector('.stock-status-wrap');
    if (badgeWrap) {
      let badgeHtml = '';
      if (isOutOfStock) {
        badgeHtml = '<span class="product-stock-tag out-of-stock" data-stock-indicator>Sold Out</span>';
      } else if (isLowStock) {
        badgeHtml = `<span class="product-stock-tag low-stock" data-stock-indicator>Only ${qty} left!</span>`;
      } else {
        badgeHtml = '<span class="product-stock-tag in-stock" data-stock-indicator>In Stock</span>';
      }
      badgeWrap.innerHTML = badgeHtml;
    }

    // 3. Update out of stock classes and buttons
    if (isOutOfStock) {
      card.classList.add('is-out-of-stock');
    } else {
      card.classList.remove('is-out-of-stock');
    }

    const addBtn = card.querySelector('[data-add-to-cart]');
    if (addBtn) {
      addBtn.disabled = isOutOfStock;
      addBtn.textContent = isOutOfStock ? 'Out of Stock' : 'Add to cart';
    }

    // 4. Trigger visual pulse
    card.classList.remove('stock-updated-pulse');
    void card.offsetWidth; // force reflow
    card.classList.add('stock-updated-pulse');
    setTimeout(() => card.classList.remove('stock-updated-pulse'), 1000);

    // 5. If modal is currently open for this product, update modal in real time
    const modal = document.getElementById('product-detail-modal');
    if (modal && !modal.hidden) {
      const modalBtn = document.getElementById('modal-add-to-cart-btn');
      if (modalBtn) {
        const btnText = modalBtn.querySelector('.btn-text');
        if (isOutOfStock) {
          modalBtn.disabled = true;
          if (btnText) btnText.textContent = 'Out of Stock';
        } else {
          modalBtn.disabled = false;
          if (btnText) btnText.textContent = 'Add to Cart';
        }
      }
    }
  }

  // Subscribe to real-time changes
  sb.channel('public-website-inventory')
    .on('postgres_changes', { event: '*', schema: 'public', table: 'inventory' }, (payload) => {
      const row = payload.new;
      if (row && row.product_id) {
        applyProductStockUpdate(row.product_id, row.quantity, row.low_stock_limit);
      }
    })
    .on('postgres_changes', { event: '*', schema: 'public', table: 'products' }, (payload) => {
      const row = payload.new;
      if (row && row.id) {
        applyProductStockUpdate(row.id, row.stock);
      }
    })
    .subscribe((status) => {
      if (status === 'SUBSCRIBED') {
        console.log('⚡ ONISUBI Real-Time Inventory Sync: Active & Connected to Supabase');
      }
    });
}

function initApp() {
  initMobileMenu();
  initProductDetailModal();
  initRealtimeInventorySync();

  if (!window.PRODUCT_STOCKS || Object.keys(window.PRODUCT_STOCKS).length === 0) {
    fetch(BASE_PATH + 'api/get-stock.php')
      .then((r) => r.json())
      .then((data) => {
        if (data && data.stocks) {
          window.PRODUCT_STOCKS = Object.assign({}, window.PRODUCT_STOCKS, data.stocks);
          if (typeof renderCart === 'function') {
            renderCart();
          }
        }
      })
      .catch(() => {});
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initApp);
} else {
  initApp();
}