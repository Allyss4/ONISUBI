<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$userId = current_user_id();
if (!$userId) { header('Location: login.php'); exit; }

$orderId = (int) ($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT id, total, status, payment_method, payment_reference, payment_proof FROM orders WHERE id = :id AND user_id = :uid LIMIT 1');
$stmt->execute([':id' => $orderId, ':uid' => $userId]);
$order = $stmt->fetch();
if (!$order) { http_response_code(404); exit('Order not found.'); }

// COD orders have no GCash payment step.
if ($order['payment_method'] !== 'GCash QR') {
    header('Location: order-status.php?id=' . (int) $order['id']);
    exit;
}

$status    = (string) $order['status'];
$canUpload = in_array($status, ['Awaiting Payment', 'Payment Rejected'], true);
$reference = 'ONISUBI-' . (int) $order['id'];
$error     = trim((string) ($_GET['error'] ?? ''));

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pay with GCash | ONISUBI</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<header class="site-header">
  <div class="container nav-wrap">
    <a class="brand" href="../index.php" aria-label="ONISUBI home">
      <span class="brand-wordmark"><span class="brand-oni">ONI</span><span class="brand-subi">SUBI</span></span>
    </a>
    <div class="nav-actions">
      <?php if ($canUpload): ?>
        <form action="cancel-gcash-order.php" method="post" class="header-back-form"
              onsubmit="return confirm('Go back to checkout? This GCash order will be cancelled so you can choose another payment method.');">
          <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
          <button type="submit" class="back-btn">&larr; Back to checkout</button>
        </form>
      <?php else: ?>
        <a class="back-btn" href="../index.php#menu">&larr; Back to menu</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="gcash-page">
  <div class="container">

    <div class="gcash-heading">
      <span class="eyebrow">GCash Payment</span>
      <h1>Complete your payment</h1>
      <p>Order <strong><?= h($reference) ?></strong> is reserved. Pay with GCash, then upload your receipt so we can verify it.</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($status === 'Payment Rejected'): ?>
      <div class="alert alert-error" role="alert">
        We couldn't verify your last payment proof. Please check the amount and reference number, then upload it again.
      </div>
    <?php elseif ($status === 'For Verification'): ?>
      <div class="alert alert-success" role="status">
        Payment proof received. We'll confirm your order once the payment is verified.
      </div>
    <?php endif; ?>

    <div class="gcash-layout">

      <!-- Step 1: QR -->
      <section class="panel gcash-qr-card">
        <div class="gcash-step-label"><span>1</span> Scan &amp; pay</div>

        <div class="gcash-amount">
          <small>Amount to pay</small>
          <strong>₱<?= number_format((float) $order['total'], 2) ?></strong>
        </div>

        <div class="qr-frame">
          <img src="../assets/images/gcash-qr.png" alt="ONISUBI GCash QR code">
        </div>

        <ol class="gcash-steps">
          <li>Open GCash and tap <strong>Scan QR</strong>.</li>
          <li>Pay the <strong>exact amount</strong> shown above.</li>
          <li>In the message/notes, type <strong><?= h($reference) ?></strong>.</li>
          <li>Screenshot the receipt and upload it here.</li>
        </ol>
      </section>

      <!-- Step 2: proof -->
      <section class="panel gcash-proof-card">
        <div class="gcash-step-label"><span>2</span> Upload proof of payment</div>

        <?php if ($canUpload): ?>
          <form class="checkout-form proof-form" action="upload-proof.php" method="post" enctype="multipart/form-data" id="proof-form">
            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">

            <div class="form-group">
              <label for="reference">GCash reference number</label>
              <input id="reference" name="reference" type="text" inputmode="numeric"
                     pattern="[0-9]{10,20}" maxlength="20" placeholder="e.g. 1234567890123" required>
              <p class="field-hint">Found on your GCash receipt (digits only).</p>
            </div>

            <div class="form-group">
              <label for="proof">Receipt screenshot</label>
              <label class="file-drop" for="proof" id="file-drop">
                <span class="file-drop-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-image" viewBox="0 0 16 16">
								<path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/>
								<path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1z"/>
								</svg></span>
                <span class="file-drop-text" id="file-text">Tap to choose an image</span>
                <small>JPG, PNG or WEBP · max 5 MB</small>
              </label>
              <input id="proof" class="sr-only" name="proof" type="file" accept="image/jpeg,image/png,image/webp" required>
              <img id="proof-preview" class="proof-preview" alt="Receipt preview" hidden>
            </div>

            <div class="checkout-actions">
              <a class="secondary-btn" href="order-status.php?id=<?= (int) $order['id'] ?>">View order status</a>
              <button class="btn btn-primary" type="submit" id="proof-submit">Submit proof</button>
            </div>
          </form>
        <?php else: ?>
          <div class="gcash-done">
            <div class="gcash-done-icon" aria-hidden="true">✓</div>
            <p>Status: <strong><?= h($status) ?></strong></p>
            <?php if (!empty($order['payment_reference'])): ?>
              <p>Reference no.: <strong><?= h((string) $order['payment_reference']) ?></strong></p>
            <?php endif; ?>
            <?php if (!empty($order['payment_proof'])): ?>
              <img class="proof-preview" src="../uploads/proofs/<?= h(rawurlencode(basename((string) $order['payment_proof']))) ?>" alt="Submitted payment receipt">
            <?php endif; ?>
            <a class="btn btn-primary" href="order-status.php?id=<?= (int) $order['id'] ?>">View order status</a>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>
</main>

<script>
(function () {
  var input = document.getElementById('proof');
  if (!input) return;

  var text = document.getElementById('file-text');
  var drop = document.getElementById('file-drop');
  var preview = document.getElementById('proof-preview');
  var form = document.getElementById('proof-form');
  var submit = document.getElementById('proof-submit');
  var ref = document.getElementById('reference');
  var MAX = 5 * 1024 * 1024;

  ref.addEventListener('input', function () {
    ref.value = ref.value.replace(/\D/g, '');
  });

  function reset(message) {
    input.value = '';
    text.textContent = 'Tap to choose an image';
    drop.classList.remove('has-file');
    preview.removeAttribute('src');
    preview.hidden = true;
    if (message) { alert(message); }
  }

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) { reset(); return; }

    if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
      reset('Please choose a JPG, PNG or WEBP image (HEIC photos are not supported).');
      return;
    }
    if (file.size > MAX) {
      reset('Image is too large (max 5 MB).');
      return;
    }

    text.textContent = file.name;
    drop.classList.add('has-file');

    var reader = new FileReader();
    reader.onload = function (e) {
      preview.src = e.target.result;
      preview.hidden = false;
    };
    reader.onerror = function () {
      reset('Could not read that image. Please try another one.');
    };
    reader.readAsDataURL(file);
  });

  form.addEventListener('submit', function () {
    submit.disabled = true;
    submit.textContent = 'Uploading…';
  });
})();
</script>
</body>
</html>
