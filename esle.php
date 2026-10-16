<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$base      = realpath(__DIR__);
$domain    = 'https://semtabilgisayar.com';
$debug     = isset($_GET['debug']);
$log       = [];
$result    = [];
$errors    = [];

$videoExts = ['mp4','mov','webm','avi','m4v','mkv'];
$extPat    = 'mp4|mov|webm|avi|m4v|mkv|jpg|jpeg|png|gif|webp';

function scanFiles($dirPath, &$log) {
    if (!is_dir($dirPath)) { $log[] = "DIZIN YOK: $dirPath"; return []; }
    $files = [];
    foreach (scandir($dirPath) as $f) {
        if ($f[0] === '.') continue;
        if (!is_file("$dirPath/$f")) continue;
        $files[] = $f;
    }
    $log[] = "DIZIN OK $dirPath (" . count($files) . "): " . implode(', ', $files);
    return $files;
}

function parseFilename($f, $extPat, &$ext, &$isUrlFile) {
    if (preg_match('/^(\d+)\.(\d+)\.(' . $extPat . ')\.url$/i', $f, $m)) {
        $ext = strtolower($m[3]); $isUrlFile = true;
        return [(int)$m[1], (int)$m[2]];
    }
    if (preg_match('/^(\d+)\.(\d+)\.(' . $extPat . ')$/i', $f, $m)) {
        $ext = strtolower($m[3]); $isUrlFile = false;
        return [(int)$m[1], (int)$m[2]];
    }
    return false;
}

// Dosya içindeki URL'yi bul — satır başı http şartı yok, regex ile tarar
function readUrl($path, &$log, &$errors) {
    $content = @file_get_contents($path);
    if ($content === false) {
        $msg = "OKUNAMADI: $path";
        $log[] = $msg; $errors[] = $msg;
        return '';
    }
    // BOM temizle
    $content = ltrim($content, "\xEF\xBB\xBF\r\n ");
    // İçinde https?:// geçen ilk şeyi çıkar
    if (preg_match('/(https?:\/\/[^\s\'"<>]+)/i', $content, $m)) {
        return rtrim($m[1], '/.,;');
    }
    $msg = "URL BULUNAMADI: $path — icerik[" . strlen($content) . "]: " . substr($content, 0, 150);
    $log[] = $msg; $errors[] = $msg;
    return '';
}

function processDir($dirPath, $dirName, $domain, $alwaysRead, $extPat, $videoExts, &$result, &$log, &$errors) {
    foreach (scanFiles($dirPath, $log) as $f) {
        $ext = ''; $isUrlFile = false;
        $parsed = parseFilename($f, $extPat, $ext, $isUrlFile);
        if (!$parsed) { $log[] = "$dirName FORMAT YOK: $f"; continue; }
        [$sid, $aid] = $parsed;

        if ($isUrlFile || $alwaysRead) {
            $url = readUrl("$dirPath/$f", $log, $errors);
            if (!$url) continue;
        } else {
            $url = "$domain/$dirName/$f";
        }

        $type = in_array($ext, $videoExts) ? 'video' : 'image';
        $result["$sid.$aid"] = [
            'surahId' => $sid, 'ayahId' => $aid,
            'type'    => $type, 'url'    => $url,
            'ext'     => $ext,  'source' => $dirName,
        ];
        $log[] = "$dirName OK: $f → $url";
    }
}

processDir("$base/fai", 'fai', $domain, false, $extPat, $videoExts, $result, $log, $errors);
processDir("$base/vai", 'vai', $domain, false, $extPat, $videoExts, $result, $log, $errors);
processDir("$base/sai", 'sai', $domain, true,  $extPat, $videoExts, $result, $log, $errors);

$out = array_values($result);

if ($debug) {
    echo json_encode(
        ['count' => count($out), 'errors' => $errors, 'results' => $out, 'log' => $log],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
} else {
    // Normal çıktıya hata varsa errors alanını ekle (boşsa gelmez)
    $response = $out;
    if (!empty($errors)) {
        echo json_encode(['results' => $out, 'errors' => $errors],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}