<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');

//ダウンロードさせる
download("./sql/cr.sql", "application/sql");

/**
 * ファイルダウンロードさせる
 * $path : ファイルのパス
 * $mimetype  : mimetypeを渡す
 */
function download($path, $this_mimetype = null)
{
    if (!is_readable($path)) {
        die($path);
    }
    if (isset($this_mimetype)) {
        $mimetype = $this_mimetype;
    } else {
        $mimetype = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    }
    if (!preg_match('/\A\S+?\/\S+/', $mimetype)) {
        $mimetype = 'application/octet-stream';
    }
    header('Content-Type: ' . $mimetype);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Connection: close');
    while (ob_get_level()) {
        ob_end_clean();
    }
    readfile($path);
    exit;
}