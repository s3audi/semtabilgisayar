<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$direct = glob("*.{mp4,mov,webm,avi,m4v,mkv}", GLOB_BRACE) ?: [];
$urls   = glob("*.{mp4,mov,webm,avi,m4v,mkv}.url", GLOB_BRACE) ?: [];
// Alternatif: *.url tek glob ile de tutulabilir
// $urls = glob("*.url") ?: [];

$files = array_values(array_filter(
    array_merge($direct, $urls),
    fn($f) => preg_match('/^\d+\.\d+\./', $f)
));
echo json_encode($files);