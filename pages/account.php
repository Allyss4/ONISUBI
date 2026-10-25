<?php
require_once __DIR__ . '/../config/bootstrap.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!current_user_id()) {
  header('Location: auth.php');
  exit;
}

$userId = current_user_id();

// Fetch user profile
$stmt = $conn->prepare('SELECT name, email, phone, address, role FROM users WHERE id = :id');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && in_array(strtolower((string)($user['role'] ?? '')), ['admin', 'staff'], true)) {
  header('Location: ../admin/dashboard.php');
  exit;
}

// Fetch user orders ordered by newest first
$ordersStmt = $conn->prepare('
  SELECT id, total, status, order_date, payment_method, payment_status, payment_reference 
  FROM orders 
  WHERE user_id = :user_id 
  ORDER BY order_date DESC, id DESC
');
$ordersStmt->execute([':user_id' => $userId]);
$orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Fetch items for each order
$orderItemsMap = [];
if (!empty($orders)) {
  $orderIds = array_column($orders, 'id');
  $inClause = implode(',', array_fill(0, count($orderIds), '?'));
  $itemsStmt = $conn->prepare("
    SELECT oi.order_id, oi.product_id, oi.quantity, p.name 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id IN ({$inClause}) 
    ORDER BY oi.id
  ");
  $itemsStmt->execute($orderIds);
  while ($item = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
    $orderItemsMap[$item['order_id']][] = $item;
  }
}

$statusFlow = ['Order Placed', 'Preparing', 'Out for Delivery', 'Delivered'];

// Tally counts for each category
$stageCounts = [
  'All' => count($orders),
  'Order Placed' => 0,
  'Preparing' => 0,
  'Out for Delivery' => 0,
  'Delivered' => 0,
  'Cancelled' => 0,
];

foreach ($orders as $o) {
  $track = map_order_status_to_tracking((string)($o['status'] ?? ''));
  $st = $track['stage'];
  if (isset($stageCounts[$st])) {
    $stageCounts[$st]++;
  }
}

// Identify the order to track:
$trackId = (int)($_GET['track_id'] ?? $_GET['order_id'] ?? 0);
$currentOrder = null;
if ($trackId > 0) {
  foreach ($orders as $o) {
    if ((int)$o['id'] === $trackId) {
      $currentOrder = $o;
      break;
    }
  }
}

// If no explicit order requested, find first in-progress order
if (!$currentOrder && !empty($orders)) {
  foreach ($orders as $o) {
    $stLower = strtolower(trim((string)($o['status'] ?? '')));
    if (!in_array($stLower, ['delivered', 'completed', 'cancelled', 'canceled', 'rejected'], true)) {
      $currentOrder = $o;
      break;
    }
  }
  // Otherwise default to the most recent order
  if (!$currentOrder) {
    $currentOrder = $orders[0];
  }
}

$currentTracking = $currentOrder ? map_order_status_to_tracking($currentOrder['status'] ?? '') : null;
$currentIndex = $currentTracking ? $currentTracking['index'] : -1;
?><!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ONISUBI | My Account</title>
  <link rel="icon" type="image/png" href="../assets/images/Onisubi_Logo.png">
  <link rel="apple-touch-icon" href="../assets/images/Onisubi_Logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
  <header class="site-header">
    <div class="container nav-wrap">
      <a class="brand" href="../index.php" aria-label="Go to ONISUBI home">
        <img class="brand-logo-image" src="../assets/images/onisubi-logo.svg" alt="ONISUBI logo">
        <span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span>
      </a>
      <nav class="main-nav" id="main-nav" aria-label="Main navigation">
        <a href="../index.php">Home</a>
        <a href="../index.php#menu">Menu</a>
        <a href="settings.php">Settings</a>
        <?php if (in_array(strtolower((string)($user['role'] ?? '')), ['admin', 'staff'], true)): ?>
          <a href="../admin/dashboard.php" style="font-weight: 700; color: #ff6b35;">Admin Panel</a>
        <?php endif; ?>
        <?php if ($userId): ?>
          <a href="auth.php?action=logout" class="mobile-only-link">Log Out</a>
        <?php else: ?>
          <a href="auth.php?redirect=account.php" class="mobile-only-link">Log In</a>
        <?php endif; ?>
      </nav>
      <div class="nav-actions">
        <?php if ($userId): ?>
          <a class="secondary-btn desktop-only-btn" href="auth.php?action=logout">Log Out</a>
        <?php else: ?>
          <a class="secondary-btn desktop-only-btn" href="auth.php?redirect=account.php">Log In</a>
        <?php endif; ?>
        <button class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation" aria-expanded="false" aria-controls="main-nav">
          <span class="hamburger-bar"></span>
          <span class="hamburger-bar"></span>
          <span class="hamburger-bar"></span>
        </button>
      </div>
    </div>
    <div class="nav-overlay" id="nav-overlay"></div>
  </header>

  <main class="cart-page">
    <div class="container">
      <section class="panel account-summary">
        <div class="account-header">
          <div>
            <span class="eyebrow">My Account</span>
            <h2><?= e($user['name'] ?? 'Customer') ?>'s profile</h2>
          </div>
          <div class="account-actions">
            <?php if (in_array(strtolower((string)($user['role'] ?? '')), ['admin', 'staff'], true)): ?>
              <a class="btn btn-primary" href="../admin/dashboard.php" style="background: #e63946; border-color: #e63946;">⚡ Admin Dashboard</a>
            <?php endif; ?>
            <a class="btn btn-primary" href="order-status.php<?= $currentOrder ? '?id=' . (int)$currentOrder['id'] : '' ?>">Track Order</a>
            <a class="btn btn-secondary" href="settings.php">Account Settings</a>
          </div>
        </div>

        <div class="account-profile-grid">
          <div class="account-profile-item">
            <label>Full name</label>
            <p><?= e($user['name'] ?? 'Not provided') ?></p>
          </div>
          <div class="account-profile-item">
            <label>Email</label>
            <p><?= e($user['email'] ?? 'Not provided') ?></p>
          </div>
          <div class="account-profile-item">
            <label>Phone</label>
            <p><?= e($user['phone'] ?? 'No phone number saved') ?></p>
          </div>
          <div class="account-profile-item">
            <label>Delivery address</label>
            <p><?= e($user['address'] ?? 'No delivery address saved') ?></p>
          </div>
        </div>
      </section>

      <section class="panel account-order-panel" id="order-tracking-panel" style="margin-top: 28px;">

        <div class="account-header" style="margin-top: 28px;">
          <div>
            <h2>Order History</h2>
          </div>
        </div>

        <?php if (empty($orders)): ?>
          <p class="empty-cart">You have no orders yet.</p>
        <?php else: ?>
          <div class="order-category-tabs" id="order-category-tabs" role="tablist" aria-label="Order status categories">
            <button type="button" class="order-cat-tab active" data-filter="all" role="tab" aria-selected="true">
              <span>All Orders</span>
              <span class="cat-tab-badge" id="badge-count-all"><?= count($orders) ?></span>
            </button>
            <button type="button" class="order-cat-tab" data-filter="Order Placed" role="tab" aria-selected="false">
              <span>Order Placed</span>
              <span class="cat-tab-badge" id="badge-count-order-placed"><?= $stageCounts['Order Placed'] ?? 0 ?></span>
            </button>
            <button type="button" class="order-cat-tab" data-filter="Preparing" role="tab" aria-selected="false">
              <span>Preparing</span>
              <span class="cat-tab-badge" id="badge-count-preparing"><?= $stageCounts['Preparing'] ?? 0 ?></span>
            </button>
            <button type="button" class="order-cat-tab" data-filter="Out for Delivery" role="tab" aria-selected="false">
              <span>Out for Delivery</span>
              <span class="cat-tab-badge" id="badge-count-out-for-delivery"><?= $stageCounts['Out for Delivery'] ?? 0 ?></span>
            </button>
            <button type="button" class="order-cat-tab" data-filter="Delivered" role="tab" aria-selected="false">
              <span>Delivered</span>
              <span class="cat-tab-badge" id="badge-count-delivered"><?= $stageCounts['Delivered'] ?? 0 ?></span>
            </button>
            <?php if (($stageCounts['Cancelled'] ?? 0) > 0): ?>
              <button type="button" class="order-cat-tab" data-filter="Cancelled" role="tab" aria-selected="false">
                <span>Cancelled</span>
                <span class="cat-tab-badge" id="badge-count-cancelled"><?= $stageCounts['Cancelled'] ?></span>
              </button>
            <?php endif; ?>
          </div>

          <div id="order-filter-status-bar" class="order-filter-status-bar" style="display: none;">
            <span>Filtering orders by: <strong id="current-filter-name">All</strong> (<span id="current-filter-count">0</span> matching)</span>
            <button type="button" class="clear-filter-link" onclick="window.setOrderFilter('all');">Show All Orders ✕</button>
          </div>

          <div id="order-category-empty" class="order-category-empty" style="display: none;">
            <p>No orders currently categorized under <strong id="empty-filter-name">this status</strong>.</p>
            <button type="button" class="btn btn-secondary" onclick="window.setOrderFilter('all');" style="margin-top: 10px; font-size: 0.9rem; padding: 8px 18px;">View All Orders</button>
          </div>

          <div class="order-history-list" id="order-history-list">
            <?php foreach ($orders as $order):
              $items = $orderItemsMap[$order['id']] ?? [];
              $orderTimestamp = strtotime((string) $order['order_date']);
              $dateTime = new DateTime('@' . $orderTimestamp);
              $dateTime->setTimezone(new DateTimeZone('Asia/Manila'));
              $formattedDate = $dateTime->format('F j, Y');
              $formattedTime = $dateTime->format('g:i A');
              $orderTrack = map_order_status_to_tracking((string) $order['status']);
              $isCurrentlyTracked = ($currentOrder && (int)$currentOrder['id'] === (int)$order['id']);
              ?>
              <div class="order-history-item <?= $isCurrentlyTracked ? 'is-tracked' : '' ?>" id="order-card-<?= (int)$order['id'] ?>" data-order-stage="<?= e($orderTrack['stage']) ?>" data-order-id="<?= (int)$order['id'] ?>" style="<?= $isCurrentlyTracked ? 'border-color: rgba(240, 146, 26, 0.4); background: rgba(255, 250, 241, 0.6);' : '' ?>">
                <div class="order-history-meta">
                  <strong>Order #<?= (int) $order['id'] ?></strong>
                  <span><?= e($formattedDate) ?> • <?= e($formattedTime) ?></span>
                  <span class="order-status-pill <?= e($orderTrack['badge_class']) ?>" data-order-status-id="<?= (int) $order['id'] ?>">
                    <?= e($orderTrack['status_label']) ?>
                  </span>
                </div>
        
                <div class="order-history-products">
                  <strong>Items:</strong>
                  <div class="product-list">
                    <?php if (!empty($items)): ?>
                      <?php foreach ($items as $item): ?>
                        <div class="product-item">
                          <span><?= e($item['name']) ?> × <?= (int) $item['quantity'] ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <span>No items</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="order-history-meta">
                  <div>
                    <span>Payment Method</span>
                    <strong><?= e($order['payment_method'] ?? 'Cash on Delivery') ?></strong>
                  </div>
                  <div>
                    <span>Total</span>
                    <strong>₱<?= number_format((float) $order['total'], 2) ?></strong>
                  </div>
                </div>
                  
                <div class="order-card-actions" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                  <?php if (!$isCurrentlyTracked): ?>
                    <a class="secondary-btn" href="account.php?track_id=<?= (int) $order['id'] ?>#order-tracking-panel" style="font-size: 0.85rem; padding: 7px 14px;">Track Status</a>
                  <?php endif; ?>
                  <a class="secondary-btn" href="order-status.php?id=<?= (int) $order['id'] ?>" style="font-size: 0.85rem; padding: 7px 14px;">View receipt</a>
                  <?php if ($orderTrack['stage'] === 'Delivered'): ?>
                    <a class="secondary-btn order-feedback-btn" href="https://docs.google.com/forms/d/e/1FAIpQLSf9Hs0l2NOYnmvjf_OM_8LWLOTRCcuezd4UWxwGSJInlHb1eQ" target="_blank" rel="noopener noreferrer" style="font-size: 0.85rem; padding: 7px 14px; background: rgba(42, 157, 143, 0.12); color: #2a9d8f; border-color: rgba(42, 157, 143, 0.4); font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                      <span>Feedback Form</span>
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    </a>
                  <?php endif; ?>
                </div>

                <?php if ($orderTrack['stage'] === 'Delivered'): ?>
                  <div class="order-delivered-notice" style="margin-top: 14px; padding: 12px 16px; background: rgba(42, 157, 143, 0.08); border: 1px solid rgba(42, 157, 143, 0.25); border-radius: 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                      <span style="font-size: 1.15rem;">🎉</span>
                      <div>
                        <strong style="display: block; font-size: 0.88rem; color: #1e3a2f;">Order Delivered!</strong>
                        <span style="font-size: 0.8rem; color: #4a5568;">We hope you enjoyed your meal! Help us improve with your feedback.</span>
                      </div>
                    </div>
                    <a class="btn btn-primary" href="https://docs.google.com/forms/d/e/1FAIpQLSf9Hs0l2NOYnmvjf_OM_8LWLOTRCcuezd4UWxwGSJInlHb1eQ" target="_blank" rel="noopener noreferrer" style="font-size: 0.82rem; padding: 7px 16px; background: #2a9d8f; border-color: #2a9d8f; text-decoration: none; white-space: nowrap;">Rate &amp; Review</a>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
  <?= supabase_frontend_config_tag() ?>
  <script src="../assets/js/script.js"></script>
  <script>
    (function () {
      const CURRENT_USER_ID = <?= (int) $userId ?>;
      const CURRENT_TRACK_ID = <?= (int) ($currentOrder['id'] ?? 0) ?>;
      let currentFilter = 'all';

      function mapStatusToStage(statusStr) {
        const clean = (statusStr || '').trim().toLowerCase();
        if (['cancelled', 'canceled', 'rejected'].includes(clean)) return 'Cancelled';
        if (['delivered', 'completed'].includes(clean)) return 'Delivered';
        if (['out for delivery', 'out_for_delivery', 'in transit', 'on the way', 'dispatched'].includes(clean)) return 'Out for Delivery';
        if (['preparing', 'ready', 'processing', 'in kitchen', 'cooking'].includes(clean)) return 'Preparing';
        return 'Order Placed';
      }

      function setOrderFilter(filterName, scrollIntoView) {
        const normalized = (filterName || 'all').trim();
        currentFilter = normalized;

        // 1. Update tabs active state
        document.querySelectorAll('#order-category-tabs .order-cat-tab').forEach(tab => {
          const f = tab.getAttribute('data-filter') || 'all';
          const isMatch = (normalized.toLowerCase() === 'all' && f.toLowerCase() === 'all') || (f.toLowerCase() === normalized.toLowerCase());
          tab.classList.toggle('active', isMatch);
          tab.setAttribute('aria-selected', isMatch ? 'true' : 'false');
        });

        // 2. Update status flow step buttons highlight
        document.querySelectorAll('#order-status-flow .status-flow-step').forEach(btn => {
          const sf = btn.getAttribute('data-stage-filter') || '';
          const isMatch = (normalized.toLowerCase() !== 'all' && sf.toLowerCase() === normalized.toLowerCase());
          btn.classList.toggle('is-filter-selected', isMatch);
        });

        // 3. Filter order cards
        const items = document.querySelectorAll('.order-history-list .order-history-item');
        let matchCount = 0;
        items.forEach(card => {
          const stage = card.getAttribute('data-order-stage') || '';
          const matches = (normalized.toLowerCase() === 'all') || (stage.toLowerCase() === normalized.toLowerCase());
          card.style.display = matches ? '' : 'none';
          if (matches) matchCount++;
        });

        // 4. Update status bar and empty state
        const statusBar = document.getElementById('order-filter-status-bar');
        const emptyState = document.getElementById('order-category-empty');
        const filterNameEl = document.getElementById('current-filter-name');
        const filterCountEl = document.getElementById('current-filter-count');
        const emptyFilterNameEl = document.getElementById('empty-filter-name');

        if (normalized.toLowerCase() === 'all') {
          if (statusBar) statusBar.style.display = 'none';
          if (emptyState) emptyState.style.display = 'none';
        } else {
          if (statusBar) {
            statusBar.style.display = 'flex';
            if (filterNameEl) filterNameEl.textContent = normalized;
            if (filterCountEl) filterCountEl.textContent = matchCount;
          }
          if (emptyState) {
            if (matchCount === 0) {
              emptyState.style.display = 'block';
              if (emptyFilterNameEl) emptyFilterNameEl.textContent = normalized;
            } else {
              emptyState.style.display = 'none';
            }
          }
        }

        if (scrollIntoView) {
          const tabs = document.getElementById('order-category-tabs');
          if (tabs) {
            tabs.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        }
      }
      window.setOrderFilter = setOrderFilter;

      // Tab click events
      document.querySelectorAll('#order-category-tabs .order-cat-tab').forEach(tab => {
        tab.addEventListener('click', function () {
          const f = this.getAttribute('data-filter') || 'all';
          setOrderFilter(f, false);
        });
      });

      // Status Flow step click events
      document.querySelectorAll('#order-status-flow .status-flow-step').forEach(btn => {
        btn.addEventListener('click', function () {
          const stage = this.getAttribute('data-stage-filter');
          if (!stage) return;
          if (currentFilter.toLowerCase() === stage.toLowerCase()) {
            setOrderFilter('all', true);
          } else {
            setOrderFilter(stage, true);
          }
        });
      });

      // Check URL search params for deep-linked status filter
      const urlParams = new URLSearchParams(window.location.search);
      const initialFilter = urlParams.get('filter') || urlParams.get('status') || '';
      if (initialFilter) {
        const validStages = ['order placed', 'preparing', 'out for delivery', 'delivered', 'cancelled'];
        const normalizedParam = initialFilter.toLowerCase().replace(/_/g, ' ');
        const matched = validStages.find(s => s === normalizedParam);
        if (matched) {
          const formatted = matched.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
          setOrderFilter(formatted, false);
        }
      }

      async function syncAccountOrders() {
        if (!CURRENT_USER_ID) return;
        try {
          const url = `../api/user-orders.php?track_id=${encodeURIComponent(CURRENT_TRACK_ID)}&_t=${Date.now()}`;
          const res = await fetch(url);
          if (!res.ok) return;
          const data = await res.json();
          if (!data || !data.success) return;

          // 1. Update current tracked order flow
          if (data.tracking && data.current_order) {
            const tr = data.tracking;
            const cur = data.current_order;

            const badge = document.getElementById('current-order-badge');
            if (badge) {
              badge.className = `order-live-badge ${tr.badge_class}`;
              badge.textContent = `#${cur.id} • ${tr.status_label}`;
            }

            const cancelledBanner = document.getElementById('status-cancelled-banner');
            if (tr.is_cancelled) {
              if (!cancelledBanner) {
                const banner = document.createElement('div');
                banner.id = 'status-cancelled-banner';
                banner.style.cssText = 'background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; font-weight: 600;';
                banner.textContent = `⚠️ Order #${cur.id} has been Cancelled.`;
                const flow = document.getElementById('order-status-flow');
                if (flow && flow.parentNode) flow.parentNode.insertBefore(banner, flow);
              }
            } else if (cancelledBanner) {
              cancelledBanner.remove();
            }

            const flowSteps = document.querySelectorAll('#order-status-flow .status-flow-step');
            flowSteps.forEach((step, idx) => {
              if (!tr.is_cancelled && idx <= tr.index) {
                step.classList.add('active');
              } else {
                step.classList.remove('active');
              }
            });
          }

          // 2. Update status pills and category counts in Order History
          if (Array.isArray(data.orders)) {
            const counts = {
              'all': data.orders.length,
              'Order Placed': 0,
              'Preparing': 0,
              'Out for Delivery': 0,
              'Delivered': 0,
              'Cancelled': 0
            };

            data.orders.forEach(o => {
              const pill = document.querySelector(`[data-order-status-id="${o.id}"]`);
              if (pill) {
                pill.textContent = o.status;
                const lower = (o.status || '').toLowerCase();
                let bClass = 'badge-placed';
                if (['completed', 'delivered'].includes(lower)) bClass = 'badge-completed';
                else if (['out for delivery', 'out_for_delivery', 'in transit', 'on the way'].includes(lower)) bClass = 'badge-delivery';
                else if (['preparing', 'ready', 'processing'].includes(lower)) bClass = 'badge-preparing';
                else if (['cancelled', 'canceled'].includes(lower)) bClass = 'badge-cancelled';
                pill.className = `order-status-pill ${bClass}`;
              }

              const stage = mapStatusToStage(o.status);
              if (counts[stage] !== undefined) counts[stage]++;
              const card = document.getElementById(`order-card-${o.id}`);
              if (card) {
                card.setAttribute('data-order-stage', stage);

                const actions = card.querySelector('.order-card-actions');
                let fbBtn = card.querySelector('.order-feedback-btn');
                let fbNotice = card.querySelector('.order-delivered-notice');

                if (stage === 'Delivered') {
                  if (!fbBtn && actions) {
                    const btn = document.createElement('a');
                    btn.className = 'secondary-btn order-feedback-btn';
                    btn.href = 'https://docs.google.com/forms/d/e/1FAIpQLSf9Hs0l2NOYnmvjf_OM_8LWLOTRCcuezd4UWxwGSJInlHb1eQ';
                    btn.target = '_blank';
                    btn.rel = 'noopener noreferrer';
                    btn.style.cssText = 'font-size: 0.85rem; padding: 7px 14px; background: rgba(42, 157, 143, 0.12); color: #2a9d8f; border-color: rgba(42, 157, 143, 0.4); font-weight: 600; display: inline-flex; align-items: center; gap: 6px;';
                    btn.innerHTML = '<span>Feedback Form</span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>';
                    actions.appendChild(btn);
                  }
                  if (!fbNotice) {
                    const notice = document.createElement('div');
                    notice.className = 'order-delivered-notice';
                    notice.style.cssText = 'margin-top: 14px; padding: 12px 16px; background: rgba(42, 157, 143, 0.08); border: 1px solid rgba(42, 157, 143, 0.25); border-radius: 10px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;';
                    notice.innerHTML = '<div style="display: flex; align-items: center; gap: 8px;"><span style="font-size: 1.15rem;">🎉</span><div><strong style="display: block; font-size: 0.88rem; color: #1e3a2f;">Order Delivered!</strong><span style="font-size: 0.8rem; color: #4a5568;">We hope you enjoyed your meal! Help us improve with your feedback.</span></div></div><a class="btn btn-primary" href="https://docs.google.com/forms/d/e/1FAIpQLSf9Hs0l2NOYnmvjf_OM_8LWLOTRCcuezd4UWxwGSJInlHb1eQ" target="_blank" rel="noopener noreferrer" style="font-size: 0.82rem; padding: 7px 16px; background: #2a9d8f; border-color: #2a9d8f; text-decoration: none; white-space: nowrap;">Rate &amp; Review</a>';
                    card.appendChild(notice);
                  }
                } else {
                  if (fbBtn) fbBtn.remove();
                  if (fbNotice) fbNotice.remove();
                }
              }
            });

            // Update badge counters
            const bAll = document.getElementById('badge-count-all');
            if (bAll) bAll.textContent = counts['all'];
            const bPlaced = document.getElementById('badge-count-order-placed');
            if (bPlaced) bPlaced.textContent = counts['Order Placed'];
            const bPrep = document.getElementById('badge-count-preparing');
            if (bPrep) bPrep.textContent = counts['Preparing'];
            const bDeliv = document.getElementById('badge-count-out-for-delivery');
            if (bDeliv) bDeliv.textContent = counts['Out for Delivery'];
            const bDelivered = document.getElementById('badge-count-delivered');
            if (bDelivered) bDelivered.textContent = counts['Delivered'];
            const bCanc = document.getElementById('badge-count-cancelled');
            if (bCanc) bCanc.textContent = counts['Cancelled'];

            // Update flow step counts
            document.querySelectorAll('[data-stage-count-for]').forEach(el => {
              const st = el.getAttribute('data-stage-count-for');
              if (counts[st] !== undefined) el.textContent = `(${counts[st]})`;
            });

            // Reapply current filter with updated data
            setOrderFilter(currentFilter, false);
          }
        } catch (err) {
          console.warn('Realtime sync error:', err);
        }
      }

      // Realtime Supabase subscription
      if (window.supabase && typeof window.supabase.createClient === 'function' && window.SUPABASE_URL && window.SUPABASE_ANON_KEY) {
        try {
          const sb = window.supabase.createClient(window.SUPABASE_URL, window.SUPABASE_ANON_KEY);
          sb.channel('customer-account-orders-' + CURRENT_USER_ID)
            .on('postgres_changes', { event: '*', schema: 'public', table: 'orders', filter: `user_id=eq.${CURRENT_USER_ID}` }, () => {
              syncAccountOrders();
            })
            .subscribe();
        } catch (e) {
          console.warn('Supabase realtime init failed:', e);
        }
      }

      // Periodic auto-sync fallback every 5 seconds if an active in-progress order exists
      <?php if ($currentOrder && !$currentTracking['is_cancelled'] && $currentTracking['index'] < 3): ?>
        setInterval(syncAccountOrders, 5000);
      <?php endif; ?>
    })();
  </script>
</body>

</html>
