<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$files = glob("*.{jpg,jpeg,png,gif,webp}", GLOB_BRACE) ?: [];
$files = array_values(array_filter($files, fn($f) => preg_match('/^\d+\.\d+\./', $f)));
echo json_encode($files);
