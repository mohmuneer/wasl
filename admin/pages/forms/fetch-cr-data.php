<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

$nCrNumber = isset($_GET['ncr']) ? trim($_GET['ncr']) : '';

if (empty($nCrNumber)) {
    echo json_encode(['success' => false, 'error' => 'missing ncr']);
    exit;
}

// Try multiple approaches to get CR data

// 1. Try to decode nCrNumber as base64 (old format or simple encoding)
$decoded = base64_decode($nCrNumber, true);
$crInfo = [];

// 2. Try fetching the government page server-side
// Some Saudi gov APIs work with proper headers
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Accept: application/json, text/html\r\n" .
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n" .
                    "Accept-Language: ar-SA,ar;q=0.9,en;q=0.8\r\n",
        'timeout' => 10,
        'ignore_errors' => true
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ]
]);

// Try government CR page
$url = "https://qr.saudibusiness.gov.sa/viewcr?nCrNumber=" . urlencode($nCrNumber);
$html = @file_get_contents($url, false, $context);

if ($html && strlen($html) > 100) {
    // Try to extract any meaningful data from the HTML
    // It's a React SPA, but there might be some server-side rendered data
    preg_match('/"crNumber":"([^"]+)"/', $html, $m);
    if (!empty($m[1])) $crInfo['cr_number'] = $m[1];
    
    preg_match('/"establishmentName":"([^"]+)"/', $html, $m);
    if (!empty($m[1])) $crInfo['customer_name'] = $m[1];
    
    preg_match('/"tradeName":"([^"]+)"/', $html, $m);
    if (!empty($m[1])) $crInfo['customer_name'] = $m[1];
    
    preg_match('/"establishmentType":"([^"]+)"/', $html, $m);
    if (!empty($m[1])) $crInfo['customer_type'] = $m[1];
    
    preg_match('/"status":"([^"]+)"/', $html, $m);
    if (!empty($m[1])) $crInfo['cr_status'] = $m[1];
    
    preg_match('/"city":"([^"]+)"/', $html, $m);
    if (!empty($m[1])) $crInfo['address'] = $m[1];
    
    preg_match('/"capital":([\d.]+)/', $html, $m);
    if (!empty($m[1])) $crInfo['capital'] = $m[1];
    
    preg_match('/"expiryDate":"([^"]+)"/', $html, $m);
    if (!empty($m[1])) $crInfo['cr_expiry_g'] = $m[1];
    
    preg_match('/"issueDate":"([^"]+)"/', $html, $m);
    if (!empty($m[1])) $crInfo['cr_date'] = $m[1];
}

// 3. If decoded base64 contains readable data (old style QR)
if ($decoded && !empty($crInfo)) {
    $decodedStr = $decoded;
    // Try to parse if it looks like JSON
    $jsonData = json_decode($decodedStr, true);
    if ($jsonData) {
        $crInfo = array_merge($crInfo, [
            'customer_name' => $jsonData['name'] ?? $jsonData['tradeName'] ?? $crInfo['customer_name'] ?? '',
            'cr_number' => $jsonData['crNumber'] ?? $jsonData['cr'] ?? $crInfo['cr_number'] ?? '',
            'customer_type' => $jsonData['type'] ?? $crInfo['customer_type'] ?? '',
            'national_id' => $jsonData['nationalId'] ?? $jsonData['vat'] ?? '',
            'cr_expiry_g' => $jsonData['expiryDate'] ?? $jsonData['expiry'] ?? $crInfo['cr_expiry_g'] ?? '',
            'address' => $jsonData['city'] ?? $jsonData['address'] ?? $crInfo['address'] ?? '',
        ]);
    }
}

if (!empty($crInfo)) {
    $crInfo['success'] = true;
    echo json_encode($crInfo, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Unable to fetch CR data from government portal. The QR code is a URL-based code. Open it manually in your browser.',
        'url' => $url
    ], JSON_UNESCAPED_UNICODE);
}
