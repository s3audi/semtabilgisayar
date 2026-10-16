<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Hem 1.3.mov hem 1.3.mov.url formatını yakala
$direct = glob("*.{mp4,mov,webm,avi,m4v,mkv,jpg,jpeg,png,gif,webp}", GLOB_BRACE) ?: [];
$urls   = glob("*.url") ?: [];

$files = array_values(array_filter(
    array_merge($direct, $urls),
    fn($f) => preg_match('/^\d+\.\d+\./', $f)
));
echo json_encode($files);