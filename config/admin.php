<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function admin_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_require_user(): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        header('Location: ../pages/auth.php');
        exit;
    }

    $stmt = $GLOBALS['conn']->prepare('SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !in_array(strtolower((string) ($user['role'] ?? '')), ['admin', 'staff'], true)) {
        http_response_code(403);
        exit('Administrator access is required.');
    }

    return $user;
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function admin_verify_csrf(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['admin_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(419);
        exit('Your session expired. Reload the page and try again.');
    }
}

function admin_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . admin_escape(admin_csrf_token()) . '">';
}

function admin_pagination(int $currentPage, int $totalPages, array $query = []): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $link = static function (int $page) use ($query): string {
        $parameters = array_merge($query, ['page' => $page]);
        return '?' . http_build_query($parameters);
    };
    $html = '<nav class="admin-pagination" aria-label="Pagination">';
    $previous = max(1, $currentPage - 1);
    $next = min($totalPages, $currentPage + 1);
    $html .= '<a class="admin-page-link' . ($currentPage <= 1 ? ' is-disabled' : '') . '" href="' . admin_escape($link($previous)) . '"' . ($currentPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '') . '>Previous</a>';
    for ($page = 1; $page <= $totalPages; $page++) {
        $html .= '<a class="admin-page-link' . ($page === $currentPage ? ' is-current' : '') . '" href="' . admin_escape($link($page)) . '"' . ($page === $currentPage ? ' aria-current="page"' : '') . '>' . $page . '</a>';
    }
    $html .= '<a class="admin-page-link' . ($currentPage >= $totalPages ? ' is-disabled' : '') . '" href="' . admin_escape($link($next)) . '"' . ($currentPage >= $totalPages ? ' aria-disabled="true" tabindex="-1"' : '') . '>Next</a>';
    return $html . '</nav>';
}
