<?php

function avsfRecentPageViewsEnsureTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS recent_page_views (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_path VARCHAR(500) NOT NULL,
        page_title VARCHAR(255) NOT NULL DEFAULT '',
        viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_recent_page_views_time (viewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return mysqli_query($conn, $sql) === true;
}

function avsfRecentPageViewsRecord($conn, $path, $title) {
    $pathLength = function_exists('mb_strlen') ? mb_strlen($path, 'UTF-8') : strlen($path);
    if ($pathLength > 500) {
        return false;
    }

    $title = trim(strip_tags($title));
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 255, 'UTF-8') : substr($title, 0, 255);
    $stmt = mysqli_prepare($conn, 'INSERT INTO recent_page_views (page_path, page_title) VALUES (?, ?)');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $path, $title);
    $saved = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$saved) {
        return false;
    }

    $retained = mysqli_query($conn, 'DELETE FROM recent_page_views WHERE id NOT IN (SELECT id FROM (SELECT id FROM recent_page_views ORDER BY id DESC LIMIT 500) AS retained_views)');
    return $retained === true;
}
