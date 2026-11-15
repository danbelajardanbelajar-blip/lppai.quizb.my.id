<?php
/** Render the listed user's own uploaded photo; never use the logged-in administrator's session photo. */
function renderUserTableName(array $user): string
{
    $photo = trim((string)($user['foto'] ?? ''));
    $url = null;
    if (preg_match('~^https?://~i', $photo) && filter_var($photo, FILTER_VALIDATE_URL)) {
        $url = $photo;
    } elseif (preg_match('~^data:image/(?:jpeg|png|webp);base64,[A-Za-z0-9+/=]+$~', $photo)) {
        $url = $photo;
    } elseif ($photo !== '') {
        $root = realpath(__DIR__ . '/..');
        $uploads = realpath(__DIR__ . '/../uploads');
        $file = realpath(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $photo), '/'));
        if ($root && $uploads && $file && is_file($file)
            && str_starts_with($file, $uploads . DIRECTORY_SEPARATOR)
            && preg_match('~\.(?:jpe?g|png|webp)$~i', $file)) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
            $url = rtrim(BASE_URL, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        }
    }
    $image = $url === null ? '' : '<img class="users-table-user-photo" src="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
        . '" alt="" loading="lazy" decoding="async" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" onerror="this.remove()">';
    return '<span class="users-table-user-cell" style="display:inline-flex;align-items:center;gap:10px;">'
        . '<span class="users-table-user-icon" aria-hidden="true" style="position:relative;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;flex:0 0 32px;width:32px;height:32px;border-radius:50%;background:#ecfdf5;color:#166534;">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>'
        . $image . '</span><span>' . htmlspecialchars((string)($user['nama_lengkap'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span></span>';
}
