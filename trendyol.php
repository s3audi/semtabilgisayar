<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$url = trim($_GET['url'] ?? '');
if (!$url) { echo json_encode(['success'=>false,'error'=>'url gerekli'],JSON_UNESCAPED_UNICODE); exit; }

$parts = parse_url($url);
$cleanUrl = ($parts['scheme']??'https').'://'.($parts['host']??'').($parts['path']??'');

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $cleanUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        "User-Agent: {$ua}",
        'Accept-Language: tr-TR,tr;q=0.9',
        'Accept: text/html,application/xhtml+xml,*/*',
        'Cache-Control: no-cache',
    ],
    CURLOPT_COOKIE => 'locale=tr; platform=web',
]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $code !== 200) {
    echo json_encode(['success'=>false,'error'=>"HTTP {$code}"], JSON_UNESCAPED_UNICODE);
    exit;
}

// FIYAT
$price = null;

if (preg_match('/"discountedPrice"\s*:\s*([\d]+(?:\.[\d]+)?)\s*[,}]/', $html, $m)) {
    $price = (float)$m[1];
}

if (!$price || $price <= 0) {
    if (preg_match('/<span[^>]+class=["\'][^"\']*discounted[^"\']*["\'][^>]*>\s*([\d]+(?:[.,][\d]+)?)\s*TL/i', $html, $m)) {
        $price = (float)str_replace(',', '.', $m[1]);
    }
}

if (!$price || $price <= 0) {
    preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/s', $html, $lds);
    foreach ($lds[1] as $block) {
        $ld = json_decode($block, true);
        if (!$ld) continue;
        if (($ld['@type'] ?? '') === 'Product') {
            $offers = $ld['offers'] ?? [];
            $p = is_array($offers) ? ($offers[0]['price'] ?? $offers['price'] ?? null) : ($offers['price'] ?? null);
            if ($p && (float)$p > 0) { $price = (float)$p; break; }
        }
    }
}

if (!$price || $price <= 0) {
    if (preg_match('/<meta[^>]+property=["\']product:price:amount["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $price = (float)str_replace(',', '.', $m[1]);
    }
}

// URUN ADI
$productName = null;
if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
    $productName = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
}

// GORSEL
$imageUrl = null;
if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
    $imageUrl = $m[1];
}
if (!$imageUrl) {
    if (preg_match('/"(https:\/\/cdn\.dsmcdn\.com\/[^"]+\.(?:jpg|jpeg|png|webp))"/i', $html, $m)) {
        $imageUrl = $m[1];
    }
}

// BARKOD
$barcode = null;
if (preg_match('/"productCode"\s*:\s*(\d+)/', $html, $m)) {
    $barcode = $m[1];
} elseif (preg_match('/-p-(\d+)(?:[?\/]|$)/', $cleanUrl, $m)) {
    $barcode = $m[1];
}

// BUYBOX SATICI + MERCHANT 387438 FİYAT/BUYBOX DURUMU
$sellerName    = null;
$yourPrice     = null;   // Merchant 387438'in kendi fiyatı
$isBuyboxWinner = false; // Merchant 387438 buybox'ta mı?

$turqId = 387438;

$spPos = strpos($html, '__envoy__SHARED_PROPS');
if ($spPos !== false) {
    $rsStart = strpos($html, ']=', $spPos) + 2;
    $rsEnd   = strpos($html, '</script>', $rsStart);
    if ($rsEnd > $rsStart) {
        $raw = substr($html, $rsStart, $rsEnd - $rsStart);

        // Listing sahibinin adı ve ID'si
        if (preg_match('/"merchant"\s*:\s*\{"id"\s*:\s*(\d+)\s*,\s*"name"\s*:\s*"([^"]+)"/', $raw, $nm)) {
            $sellerName      = $nm[2];
            $listingOwnerId  = (int)$nm[1];

            if ($listingOwnerId === $turqId) {
                // Turqstore listing sahibi = buybox'ta
                $isBuyboxWinner = true;
                // Fiyatı winnerVariant'tan al
                $wvPos = strpos($raw, '"winnerVariant"');
                if ($wvPos !== false) {
                    $wvChunk = substr($raw, $wvPos, 1200);
                    if (preg_match('/"discountedPrice"\s*:\s*\{"value"\s*:\s*([\d.]+)/', $wvChunk, $pm)) {
                        $yourPrice = (float)$pm[1];
                    }
                }
            }
        }

        // Turqstore listing sahibi değilse otherMerchants'ta ara
        if (!$isBuyboxWinner) {
            // "id":387438 olan satıcının fiyatını bul
            $omSearch = '"id"\s*:\s*' . $turqId . '\s*,';
            if (preg_match('/' . $omSearch . '/', $raw)) {
                $omPos = strpos($raw, '"id":' . $turqId . ',');
                if ($omPos === false) {
                    // boşluklu format
                    preg_match('/("id"\s*:\s*' . $turqId . '\s*,)/', $raw, $idMatch, PREG_OFFSET_CAPTURE);
                    $omPos = $idMatch[0][1] ?? false;
                }
                if ($omPos !== false) {
                    $omChunk = substr($raw, $omPos, 1000);
                    if (preg_match('/"discountedPrice"\s*:\s*\{"value"\s*:\s*([\d.]+)/', $omChunk, $pm)) {
                        $yourPrice = (float)$pm[1];
                    }
                }
                $isBuyboxWinner = false;
            }
        }
    }
}

if (!$sellerName) {
    $slPos = strpos($html, 'data-testid="store-link"');
    if ($slPos !== false) {
        $chunk = substr($html, $slPos, 500);
        if (preg_match('/merchant-name[^>]*>\s*([^<]{2,80})\s*<\//s', $chunk, $m)) {
            $sellerName = trim($m[1]);
        }
    }
}

echo json_encode([
    'success'        => true,
    'marketplace'    => 'trendyol',
    'url'            => $cleanUrl,
    'productName'    => $productName,
    'price'          => $price,          // buybox/piyasa fiyatı
    'yourPrice'      => $yourPrice,      // merchant 387438'in kendi fiyatı (null ise satmıyor)
    'isBuyboxWinner' => $isBuyboxWinner, // merchant 387438 buybox'ta mı?
    'currency'       => 'TRY',
    'imageUrl'       => $imageUrl,
    'barcode'        => $barcode,
    'sellerName'     => $sellerName,
    'scraped_at'     => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
