<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// --- IMAGE PROXY MODU ---
// Kullanım: hepsiburada.php?imgproxy=https://productimages.hepsiburada.net/...
$imgproxy = trim($_GET['imgproxy'] ?? '');
if ($imgproxy) {
    // Sadece Hepsiburada CDN URL'lerine izin ver
    if (!preg_match('#^https?://productimages\.hepsiburada\.net/#', $imgproxy)) {
        http_response_code(403); exit;
    }
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $imgproxy,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            "User-Agent: {$ua}",
            'Referer: https://www.hepsiburada.com/',
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language: tr-TR,tr;q=0.9',
            'sec-fetch-dest: image',
            'sec-fetch-mode: no-cors',
            'sec-fetch-site: cross-site',
        ],
    ]);
    $imgData = curl_exec($ch);
    $mime    = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($imgData && $httpCode === 200) {
        header('Content-Type: ' . ($mime ?: 'image/jpeg'));
        header('Cache-Control: public, max-age=86400');
        echo $imgData;
    } else {
        http_response_code(502);
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$url = trim($_GET['url'] ?? '');
if (!$url) { echo json_encode(['success'=>false,'error'=>'url gerekli'],JSON_UNESCAPED_UNICODE); exit; }

// Barkod — HBV, HBCV, HBC... tüm formatları yakala
$barcode = null;
if (preg_match('/-p[m]?-(HB[A-Z0-9]+)/i', $url, $m)) $barcode = strtoupper($m[1]);

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        "User-Agent: {$ua}",
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8',
        'Accept-Encoding: gzip, deflate, br',
        'Referer: https://www.google.com/',
        'sec-ch-ua: "Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'sec-fetch-dest: document',
        'sec-fetch-mode: navigate',
        'sec-fetch-site: cross-site',
        'sec-fetch-user: ?1',
        'Upgrade-Insecure-Requests: 1',
        'Cache-Control: max-age=0',
    ],
    CURLOPT_COOKIE         => 'locale=tr-TR',
]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $code !== 200) { echo json_encode(['success'=>false,'error'=>"HTTP {$code}"],JSON_UNESCAPED_UNICODE); exit; }

// --- JSON-LD BLOKLARINI TOPLA (tüm metodlar bunları kullanır) ---
preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/s', $html, $lds);
$productLd = null;
foreach ($lds[1] as $block) {
    $ld = json_decode($block, true);
    if (!$ld) continue;
    // @graph içinde Product ara
    $nodes = isset($ld['@graph']) ? $ld['@graph'] : [$ld];
    foreach ($nodes as $node) {
        if (($node['@type'] ?? '') === 'Product') { $productLd = $node; break 2; }
    }
}

// --- FİYAT ---
// Öncelik: data-test-id="default-price" (indirimli fiyat) → data-hbus buybox → finalPrice → schema.org
$price = null;

// 1) data-test-id="default-price" içindeki ilk sayı — indirimli güncel fiyat
if (preg_match('/data-test-id=["\']default-price["\'][^>]*>.*?<span[^>]*>([\d]{1,3}(?:\.[\d]{3})*(?:,\d{1,2})?)\s*TL/s', $html, $m)) {
    $price = (float)str_replace(['.', ','], ['', '.'], $m[1]);
}

// 2) data-hbus JSON'unda placement=buybox ve price
if (!$price || $price <= 0) {
    // &quot;placement&quot;:&quot;buybox&quot; ... &quot;price&quot;:&quot;NNN&quot;
    if (preg_match('/placement&quot;:&quot;buybox&quot;.*?&quot;price&quot;:&quot;([\d.]+)&quot;/s', $html, $m)) {
        $price = (float)$m[1];
    }
}

// 3) "finalPrice" JSON state
if (!$price || $price <= 0) {
    if (preg_match('/"finalPrice"\s*:\s*([\d]+(?:\.[\d]+)?)/', $html, $m)) {
        $price = (float)$m[1];
    }
}

// 4) Schema.org JSON-LD offers — eski/liste fiyatı, son çare
if (!$price || $price <= 0) {
    if ($productLd && isset($productLd['offers'])) {
        $offers = $productLd['offers'];
        $p = $offers['price'] ?? ($offers[0]['price'] ?? null);
        if ($p && (float)$p > 1) $price = (float)$p;
    }
}

// --- ÜRÜN ADI ---
$productName = null;
if ($productLd && isset($productLd['name'])) {
    $productName = $productLd['name'];
}
if (!$productName && preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']]/i', $html, $m)) {
    $productName = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
}

// --- GÖRSEL ---
// /s/SATICINO/BOYUT/ kısmını /s/777/424-600/ ile değiştiren helper
// /375/ gibi tek sayıyı da, /424-600/ gibi boyut çiftini de yakalar
function normalizeHbImageUrl($url) {
    $url = preg_replace('#/format:webp$#i', '', $url); // webp suffix kaldır
    $url = preg_replace('#/s/\d+/[\d]+(?:-[\d]+)?/#', '/s/777/424-600/', $url);
    return $url;
}

$imageUrl = null;
if ($productLd && isset($productLd['image'])) {
    $img = is_array($productLd['image']) ? $productLd['image'][0] : $productLd['image'];
    $imageUrl = normalizeHbImageUrl($img);
}
// Fallback: sayfadaki ilk productimages URL'i (jpg veya webp)
if (!$imageUrl && preg_match('#"(https?://productimages\.hepsiburada\.net/[^"]+)"#i', $html, $m)) {
    $imageUrl = normalizeHbImageUrl($m[1]);
}
// Fallback 2: og:image
if (!$imageUrl && preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']]/i', $html, $m)) {
    $imageUrl = normalizeHbImageUrl($m[1]);
}

// --- BARKOD & MARKA ---
$brand = null;
if ($productLd) {
    if (!$barcode && isset($productLd['sku']))          $barcode = $productLd['sku'];
    if (!$barcode && isset($productLd['gtin']))         $barcode = $productLd['gtin'];
    if (isset($productLd['brand']['name']))             $brand   = $productLd['brand']['name'];
}

echo json_encode([
    'success'     => true,
    'marketplace' => 'hepsiburada',
    'url'         => $url,
    'productName' => $productName,
    'price'       => $price,
    'currency'    => 'TRY',
    'imageUrl'    => $imageUrl,
    'barcode'     => $barcode,
    'brand'       => $brand,
    'scraped_at'  => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
