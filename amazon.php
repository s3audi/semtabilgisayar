<?php
/**
 * Amazon TR Ürün Verisi Çekici
 * Kullanım: amazon.php?asin=B0BVP1P7SM
 *        veya amazon.php?url=https://www.amazon.com.tr/dp/B0BVP1P7SM
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Giriş parametrelerini al ---
$asin = trim($_GET['asin'] ?? '');
$url  = trim($_GET['url']  ?? '');

if ($asin === '' && $url === '') {
    echo json_encode(['error' => 'asin veya url parametresi gerekli'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ASIN'den URL oluştur
if ($asin !== '' && $url === '') {
    $url = "https://www.amazon.com.tr/dp/{$asin}";
} elseif ($url !== '' && $asin === '') {
    // URL'den ASIN çıkar
    if (preg_match('#/dp/([A-Z0-9]{10})#', $url, $m)) {
        $asin = $m[1];
    }
}

// --- cURL ile sayfayı çek ---
$user_agents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
];
$ua = $user_agents[array_rand($user_agents)];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_ENCODING       => '',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        "User-Agent: {$ua}",
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Cache-Control: max-age=0',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'DNT: 1',
    ],
    CURLOPT_COOKIE         => 'i18n-prefs=TRY; sp-cdn="L5Z9:TR"; ubid-acbtr=261-1234567-8901234',
]);

$html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($html === false || $curl_error) {
    echo json_encode(['error' => "cURL hatası: {$curl_error}"], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($http_code === 503 || $http_code === 429) {
    echo json_encode(['error' => 'Amazon bot koruması devreye girdi. Lütfen birkaç dakika bekleyin.', 'http_code' => $http_code], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($http_code !== 200) {
    echo json_encode(['error' => "HTTP {$http_code} hatası"], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- HTML'den veri çıkar ---

// Ürün adı
$title = '';
if (preg_match('/<span[^>]+id=["\']productTitle["\'][^>]*>(.*?)<\/span>/si', $html, $m)) {
    $title = trim(strip_tags($m[1]));
}

// Fiyat — birden fazla selektör dene
$price     = null;
$currency  = 'TRY';

$price_patterns = [
    // Yeni format: <span class="a-price-whole">437</span><span class="a-price-fraction">95</span>
    '/<span[^>]+class=["\'][^"\']*a-price[^"\']*["\'][^>]*>.*?<span[^>]+class=["\'][^"\']*a-price-whole["\'][^>]*>([\d\.,]+)<\/span>.*?<span[^>]+class=["\'][^"\']*a-price-fraction["\'][^>]*>(\d+)<\/span>/si',
    // Tek blok fiyat
    '/<span[^>]+class=["\'][^"\']*a-price[^"\']*["\'][^>]*>\s*<span[^>]*>\s*([\d\.\,]+)\s*(?:TL|₺|TRY)?\s*<\/span>/si',
    // corePriceDisplay
    '/"priceAmount"\s*:\s*([\d\.]+)/s',
    // buybox price
    '/<span[^>]+id=["\']priceblock_ourprice["\'][^>]*>\s*([\d\.\,]+)/si',
    // data-a-color="price"
    '/<span[^>]+class=["\'][^"\']*priceToPay["\'][^>]*>.*?<span[^>]+class=["\'][^"\']*a-price-whole["\'][^>]*>([\d\.,]+)<\/span>.*?<span[^>]+class=["\'][^"\']*a-price-fraction["\'][^>]*>(\d+)<\/span>/si',
];

foreach ($price_patterns as $i => $pattern) {
    if (preg_match($pattern, $html, $m)) {
        if ($i === 2) {
            // JSON değeri
            $price = (float) $m[1];
        } elseif (isset($m[2])) {
            // whole + fraction
            $whole = str_replace(['.', ','], ['', ''], $m[1]);
            $price = (float) ($whole . '.' . $m[2]);
        } else {
            $raw = str_replace(['.', ' '], '', $m[1]);
            $raw = str_replace(',', '.', $raw);
            $price = (float) $raw;
        }
        if ($price > 0) break;
    }
}

// Para birimi
if (preg_match('/₺|TL|TRY/', $html)) {
    $currency = 'TRY';
}

// Ana ürün görseli
$image = '';
$img_patterns = [
    // landingImageUrl JSON
    '/"landingImageUrl"\s*:\s*"([^"]+)"/s',
    // hiRes
    '/"hiRes"\s*:\s*"(https:[^"]+\.(?:jpg|png))"/s',
    // imgTagWrapper
    '/<div[^>]+id=["\']imgTagWrapperId["\'][^>]*>.*?<img[^>]+src=["\']([^"\']+)["\'][^>]*>/si',
    // main-image
    '/<img[^>]+id=["\']landingImage["\'][^>]+(?:src|data-old-hires)=["\']([^"\']+)["\'][^>]*>/si',
    // dynamic-image
    '/<img[^>]+class=["\'][^"\']*a-dynamic-image["\'][^>]+src=["\']([^"\']+)["\'][^>]*>/si',
];

foreach ($img_patterns as $pattern) {
    if (preg_match($pattern, $html, $m)) {
        $img_url = $m[1];
        // Boyut suffix'i kaldır (_SX300_ gibi) ve büyük versiyonu al
        $img_url = preg_replace('/\._[A-Z]{2}\d+_/', '', $img_url);
        if (strpos($img_url, 'amazon') !== false || strpos($img_url, 'media-amazon') !== false) {
            $image = $img_url;
            break;
        }
    }
}

// Puan
$stars  = null;
$rating_count = null;

if (preg_match('/(\d[\d,\.]+)\s*üzerinden\s*(\d[\d,\.]+)\s*yıldız/si', $html, $m)) {
    $stars = (float) str_replace(',', '.', $m[1]);
} elseif (preg_match('/"ratingScore"\s*:\s*"([\d\.,]+)"/s', $html, $m)) {
    $stars = (float) str_replace(',', '.', $m[1]);
} elseif (preg_match('/(\d[\d,\.]+) out of 5 stars/si', $html, $m)) {
    $stars = (float) str_replace(',', '.', $m[1]);
}

if (preg_match('/"ratingCount"\s*:\s*(\d+)/s', $html, $m)) {
    $rating_count = (int) $m[1];
} elseif (preg_match('/(\d[\d\.]+)\s*(?:değerlendirme|rating)/si', $html, $m)) {
    $rating_count = (int) str_replace('.', '', $m[1]);
}

// Stok durumu
$in_stock = true;
if (preg_match('/Stokta\s+yok|Tükendi|Currently\s+unavailable/si', $html)) {
    $in_stock = false;
}

// Marka
$brand = '';
if (preg_match('/"brandName"\s*:\s*"([^"]+)"/s', $html, $m)) {
    $brand = $m[1];
} elseif (preg_match('/<a[^>]+id=["\']bylineInfo["\'][^>]*>(.*?)<\/a>/si', $html, $m)) {
    $brand = trim(strip_tags($m[1]));
}

// --- Sonucu döndür ---
$result = [
    'success'      => true,
    'asin'         => $asin,
    'url'          => $url,
    'title'        => $title,
    'price'        => $price,
    'currency'     => $currency,
    'image'        => $image,
    'stars'        => $stars,
    'rating_count' => $rating_count,
    'in_stock'     => $in_stock,
    'brand'        => $brand,
    'scraped_at'   => date('c'),
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
