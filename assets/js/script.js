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

function addToCart(name, price, productId, image) {
    const items = getCartItems();
    const existing = items.find((item) => item.name === name);

    if (existing) {
        existing.qty += 1;
    } else {
        items.push({ id: Number(productId), name, price: Number(price), image, qty: 1 });
    }

    saveCartItems(items);
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

    const modal = document.getElementById('order-confirm-modal');
    if (!modal) return;
    modal.hidden = false;
    modal.querySelector('[data-confirm-items]').innerHTML = getCartItems().map((item) => `<div><span>${item.name} &times; ${item.qty}</span><strong>${formatPeso(item.price * item.qty)}</strong></div>`).join('');
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
        window.location.href = 'cart.php';
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
    button.addEventListener('click', () => {
        const name = button.dataset.name;
        const price = button.dataset.price;

        addToCart(name, price, button.dataset.productId, button.dataset.image);
        button.textContent = 'Added';
        button.disabled = true;

        setTimeout(() => {
            button.textContent = 'Add to cart';
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

const cartTableBody = document.querySelector('[data-cart-items]');
if (cartTableBody) {
    const renderCart = () => {
        const items = getCartItems();
        if (!items.length) {
            cartTableBody.innerHTML = `
            <tr>
                <td colspan="4" class="empty-cart">Your cart is empty.</td>
            </tr>
        `;
        } else {
            cartTableBody.innerHTML = items.map((item, index) => `
            <tr>
                <td class="cart-product"><img src="${escapeHtml(item.image || '')}" alt=""><span>${escapeHtml(item.name)}</span></td>
                <td><div class="quantity-control"><button type="button" data-cart-action="decrease" data-cart-index="${index}" aria-label="Decrease quantity">−</button><input type="number" inputmode="numeric" min="0" step="1" value="${Number(item.qty || 1)}" data-cart-qty-input data-cart-index="${index}" aria-label="Set quantity for ${escapeHtml(item.name)}"><button type="button" data-cart-action="increase" data-cart-index="${index}" aria-label="Increase quantity">+</button></div></td>
                <td>${formatPeso(item.price)}</td>
                <td>${formatPeso(item.price * item.qty)}</td>
            </tr>
        `).join('');
        }

        cartTableBody.querySelectorAll('[data-cart-action]').forEach((button) => button.addEventListener('click', () => {
            const items = getCartItems(); const index = Number(button.dataset.cartIndex); const action = button.dataset.cartAction;
            if (!items[index]) return;
            items[index].qty += action === 'increase' ? 1 : -1;
            if (items[index].qty <= 0) items.splice(index, 1);
            saveCartItems(items); renderCart();
        }));

        cartTableBody.querySelectorAll('[data-cart-qty-input]').forEach((input) => {
            input.addEventListener('input', () => {
                const items = getCartItems();
                const index = Number(input.dataset.cartIndex);
                if (!items[index]) return;

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

                items[index].qty = Math.min(99, parsedValue);
                saveCartItems(items); renderCart();
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

        const formData = new FormData(checkoutForm);
        formData.append('cart', JSON.stringify(getCartItems()));
        const response = await fetch('api-create-order.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (!response.ok) { alert(result.error || 'Unable to create the order.'); return; }
        localStorage.removeItem(CART_KEY);
        document.cookie = 'onisubi_cart=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        window.location.href = result.checkout_url;
    });
}

const orderStatus = document.querySelector('[data-order-status]');
if (orderStatus) {
    const orderId = new URLSearchParams(window.location.search).get('id');
    if (!orderId) { orderStatus.innerHTML = '<p>No order selected.</p>'; } else {
        fetch(`order-status.php?id=${encodeURIComponent(orderId)}&format=json`).then((response) => response.json()).then((order) => {
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
          `api-check-email.php?email=${encodeURIComponent(email)}`,
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
document.addEventListener("DOMContentLoaded", initMagicAuth);
initMagicAuth();
