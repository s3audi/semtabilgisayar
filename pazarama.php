<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$url = trim($_GET['url'] ?? '');
if (!$url) { echo json_encode(['success'=>false,'error'=>'url gerekli'],JSON_UNESCAPED_UNICODE); exit; }

$barcode = null;
if (preg_match('/-p-(\d{8,})/i',$url,$m)) $barcode = $m[1];

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$ch = curl_init();
curl_setopt_array($ch,[
    CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_MAXREDIRS=>5, CURLOPT_TIMEOUT=>20, CURLOPT_ENCODING=>'',
    CURLOPT_HTTPHEADER=>["User-Agent: {$ua}",'Accept-Language: tr-TR,tr;q=0.9','Accept: text/html,application/xhtml+xml,*/*'],
    CURLOPT_COOKIE=>'locale=tr',
]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $code !== 200) { echo json_encode(['success'=>false,'error'=>"HTTP {$code}"],JSON_UNESCAPED_UNICODE); exit; }

// --- FİYAT ---
// Gerçek HTML yapısı (div tabanlı, p değil):
//   <div ...>Sepette</div>
//   <div ...>10.125,33 TL</div>
// "Sepette" birden fazla yerde geçer (Diğer Satıcılar), bu yüzden İLK eşleşmeyi alıyoruz.
$price = null;

// 1) İlk "Sepette" div'inin hemen ardındaki fiyat div'i
if (preg_match('/Sepette\s*<\/div>\s*<div[^>]*>\s*([\d]{1,3}(?:\.[\d]{3})*,\d{2})\s*TL/s',$html,$m)) {
    $price = (float)str_replace(['.', ','], ['', '.'], $m[1]);
}

// 2) "Sepette" kelimesinden sonraki 200 karakter içindeki ilk fiyat
if (!$price || $price <= 0) {
    $pos = strpos($html, 'Sepette');
    if ($pos !== false) {
        $after = substr($html, $pos, 200);
        if (preg_match('/([\d]{1,3}(?:\.[\d]{3})*,\d{2})\s*TL/', $after, $m)) {
            $price = (float)str_replace(['.', ','], ['', '.'], $m[1]);
        }
    }
}

// 3) "Sepete Ekle" butonundan önceki son fiyat (buybox alanı)
if (!$price || $price <= 0) {
    $pos = strpos($html, 'Sepete Ekle');
    if ($pos === false) $pos = strpos($html, 'sepete-ekle');
    if ($pos !== false) {
        $before = substr($html, max(0, $pos - 3000), 3000);
        preg_match_all('/([\d]{1,3}(?:\.[\d]{3})*,\d{2})\s*TL/', $before, $allP);
        if (!empty($allP[1])) {
            $vals = array_map(fn($p) => (float)str_replace(['.', ','], ['', '.'], $p), $allP[1]);
            $vals = array_filter($vals, fn($v) => $v > 1);
            if ($vals) $price = end($vals);
        }
    }
}

// 4) JSON-LD offers
if (!$price || $price <= 0) {
    preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/s',$html,$lds);
    foreach ($lds[1] as $block) {
        $ld = json_decode($block, true);
        if (!$ld) continue;
        if (($ld['@type']??'') === 'Product') {
            $offers = $ld['offers'] ?? [];
            $p = $offers['price'] ?? ($offers[0]['price'] ?? null);
            if ($p && (float)$p > 1) { $price = (float)$p; break; }
        }
    }
}

// --- ÜRÜN ADI ---
$productName = null;
if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i',$html,$m)) {
    $productName = html_entity_decode(trim($m[1]),ENT_QUOTES,'UTF-8');
} elseif (preg_match('/<h1[^>]*>\s*([^<]+)\s*<\/h1>/si',$html,$m)) {
    $productName = trim(strip_tags($m[1]));
}

// --- GÖRSEL ---
// Pazarama gerçek yapısı: img.pzrmcdn.com/mnresize/1180/1182/asset/BARKOD/...
// Önce büyük boyutlu (1180 veya benzeri) görseli yakala, yoksa herhangi mnresize URL'i al
$imageUrl = null;

// 1) Büyük boyutlu görsel (1180x veya 800x ve üstü)
if (preg_match('/"(https:\/\/img\.pzrmcdn\.com\/mnresize\/(?:1180|900|800)\/[^"]+\.(?:jpg|jpeg|png|webp))"/i',$html,$m)) {
    $imageUrl = $m[1];
}

// 2) Herhangi bir pzrmcdn görseli — en büyük boyuta çevir
if (!$imageUrl && preg_match('/"(https:\/\/img\.pzrmcdn\.com\/mnresize\/\d+\/\d+\/[^"]+)"/s',$html,$m)) {
    $imageUrl = preg_replace('/mnresize\/\d+\/\d+\//', 'mnresize/1180/1180/', $m[1]);
}

// 3) og:image fallback
if (!$imageUrl && preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',$html,$m)) {
    $imageUrl = $m[1];
}

// --- BARKOD (asset path'ten) ---
if (!$barcode && preg_match('/asset\/(\d{8,})\//',$html,$m)) {
    $barcode = $m[1];
}

echo json_encode([
    'success'=>true,'marketplace'=>'pazarama','url'=>$url,
    'productName'=>$productName,'price'=>$price,'currency'=>'TRY',
    'imageUrl'=>$imageUrl,'barcode'=>$barcode,'scraped_at'=>date('c'),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
