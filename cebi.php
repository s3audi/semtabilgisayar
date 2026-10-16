<?php
/**
 * Genel Marketplace Scraper
 * Kullanım: cebi.php?url=https://www.trendyol.com/...
 * Destekler: Trendyol, Hepsiburada, Pazarama, Etsy, Shopify
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$url = trim($_GET['url'] ?? '');

if ($url === '') {
    echo json_encode(['success' => false, 'error' => 'url parametresi gerekli'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Geçersiz URL'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Hangi marketplace?
$host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
$host = preg_replace('/^www\./', '', $host);

$marketplace = 'unknown';
if (str_contains($host, 'trendyol.com'))    $marketplace = 'trendyol';
if (str_contains($host, 'hepsiburada.com')) $marketplace = 'hepsiburada';
if (str_contains($host, 'pazarama.com'))    $marketplace = 'pazarama';
if (str_contains($host, 'etsy.com'))        $marketplace = 'etsy';
if (str_contains($host, 'shopify.com'))     $marketplace = 'shopify';

// --- cURL ile sayfa çek ---
$user_agents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
];
$ua = $user_agents[array_rand($user_agents)];

// Marketplace'e göre Accept-Language ve Cookie
$lang    = 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7';
$cookies = 'locale=tr; currency=TRY';

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
        "Accept-Language: {$lang}",
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Cache-Control: max-age=0',
        'DNT: 1',
    ],
    CURLOPT_COOKIE         => $cookies,
]);

$html      = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($html === false || $curl_err) {
    echo json_encode(['success' => false, 'error' => "cURL: {$curl_err}"], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($http_code === 503 || $http_code === 429) {
    echo json_encode(['success' => false, 'error' => 'Bot koruması ('. $http_code .')'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($http_code !== 200) {
    echo json_encode(['success' => false, 'error' => "HTTP {$http_code}"], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------
// PARSE FONKSİYONLARI
// -------------------------

function extractTrendyol(string $html): array {
    $data = [];

    // JSON bloğunu bul: __PRODUCT_DETAIL_APP_INITIAL_STATE__
    if (preg_match('/__PRODUCT_DETAIL_APP_INITIAL_STATE__\s*=\s*(\{.+?\});\s*(?:window|<\/script>)/s', $html, $m)) {
        $json = json_decode($m[1], true);
        if ($json) {
            $product = $json['product'] ?? [];
            $data['productName'] = $product['name'] ?? null;
            $data['barcode']     = (string) ($product['productCode'] ?? '');

            // Fiyat
            $price = $product['priceInfo']['discountedPrice'] ?? $product['priceInfo']['price'] ?? null;
            $data['price'] = $price ? (float) $price : null;

            // Görsel
            $images = $product['images'] ?? [];
            if (!empty($images)) {
                $img = $images[0];
                // CDN URL oluştur
                if (!str_starts_with($img, 'http')) {
                    $img = 'https://cdn.dsmcdn.com/' . ltrim($img, '/');
                }
                $data['imageUrl'] = $img;
            }

            $data['sellerName'] = $product['merchant']['name'] ?? null;
            return $data;
        }
    }

    // Fallback: meta tag
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
        $data['productName'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    // Fiyat fallback
    if (preg_match('/"discountedPrice"\s*:\s*([\d\.]+)/s', $html, $m)) {
        $data['price'] = (float) $m[1];
    } elseif (preg_match('/"price"\s*:\s*([\d\.]+)/s', $html, $m)) {
        $data['price'] = (float) $m[1];
    }

    // Görsel fallback
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
        $data['imageUrl'] = $m[1];
    }

    return $data;
}

function extractHepsiburada(string $html): array {
    $data = [];

    // JSON-LD veya window.__INITIAL_STATE__
    if (preg_match('/window\.__LISTING_APP_INITIAL_STATE__\s*=\s*(\{.+?\});\s*</s', $html, $m)) {
        $json = json_decode($m[1], true);
        // hepsiburada state karmaşık, direkt parse et
    }

    // Basit pattern'ler
    if (preg_match('/<h1[^>]+class=[^>]*product-name[^>]*>([^<]+)<\/h1>/si', $html, $m)) {
        $data['productName'] = trim($m[1]);
    } elseif (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $data['productName'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    // Fiyat
    if (preg_match('/"currentPrice"\s*:\s*([\d\.]+)/s', $html, $m)) {
        $data['price'] = (float) $m[1];
    } elseif (preg_match('/class=["\'][^"\']*price[^"\']*["\'][^>]*>\s*([\d\.]+(?:,\d+)?)\s*TL/si', $html, $m)) {
        $raw = str_replace('.', '', $m[1]);
        $raw = str_replace(',', '.', $raw);
        $data['price'] = (float) $raw;
    }

    // Görsel
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $data['imageUrl'] = $m[1];
    }

    // Barkod
    if (preg_match('/productId["\s:]+([A-Z0-9\-]+)/i', $html, $m)) {
        $data['barcode'] = $m[1];
    }

    return $data;
}

function extractPazarama(string $html): array {
    $data = [];

    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $data['productName'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    if (preg_match('/"price"\s*:\s*"?([\d\.]+)"?/s', $html, $m)) {
        $data['price'] = (float) $m[1];
    }

    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $data['imageUrl'] = $m[1];
    }

    return $data;
}

function extractEtsy(string $html): array {
    $data = [];

    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $data['productName'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    // Fiyat: "price":"12.50"
    if (preg_match('/"price"\s*:\s*"([\d\.]+)"/s', $html, $m)) {
        $data['price'] = (float) $m[1];
    }

    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $data['imageUrl'] = $m[1];
    }

    return $data;
}

function extractGeneric(string $html): array {
    $data = [];

    // og:title
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $data['productName'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
        $data['productName'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    // JSON-LD price
    if (preg_match('/"price"\s*:\s*"?([\d\.]+)"?/s', $html, $m)) {
        $data['price'] = (float) $m[1];
    }

    // og:image
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        $data['imageUrl'] = $m[1];
    }

    return $data;
}

// -------------------------
// PARSE ÇALIŞTIR
// -------------------------
$extracted = match($marketplace) {
    'trendyol'    => extractTrendyol($html),
    'hepsiburada' => extractHepsiburada($html),
    'pazarama'    => extractPazarama($html),
    'etsy'        => extractEtsy($html),
    default       => extractGeneric($html),
};

$result = array_merge([
    'success'     => true,
    'marketplace' => $marketplace,
    'url'         => $url,
    'productName' => null,
    'price'       => null,
    'imageUrl'    => null,
    'barcode'     => null,
    'sellerName'  => null,
    'scraped_at'  => date('c'),
], $extracted);

// price 0 ise null yap
if (isset($result['price']) && $result['price'] === 0.0) {
    $result['price'] = null;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
