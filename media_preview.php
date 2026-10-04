<?php
// The media.php endpoint checks the logged-in account before serving bytes.
function renderMessageMedia(array $row): void {
    $id = (string)($row['media_id'] ?? '');
    $kind = (string)($row['kind'] ?? 'text');
    if ($kind === 'text' || !preg_match('/^[a-f0-9]{32}$/', $id)) return;
    $url = 'media.php?id=' . $id;
    $name = htmlspecialchars((string)($row['media_filename'] ?? ucfirst($kind)), ENT_QUOTES, 'UTF-8');
    echo '<div class="my-1" style="white-space:normal">';
    if (in_array($kind, ['image', 'sticker'], true)) {
        echo '<a href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '&amp;v=thumb" alt="' . $name . '" loading="lazy" style="max-width:180px;max-height:140px;border-radius:6px"></a>';
    } elseif ($kind === 'audio') {
        echo '<audio controls preload="none" src="' . $url . '" style="max-width:100%"></audio>';
    } elseif ($kind === 'video') {
        echo '<video controls preload="none" src="' . $url . '" style="max-width:240px;max-height:180px"></video>';
    }
    echo '<a class="d-block small" href="' . $url . '" target="_blank" rel="noopener">' . $name . '</a></div>';
}
