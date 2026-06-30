<?php
// ملف: fetch_cr.php
header('Content-Type: application/json; charset=utf-8');

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $url = "https://qr.saudibusiness.gov.sa/viewcr?nCrNumber=" . urlencode($token);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // مهلة زمنية قصيرة لعدم تعليق الصفحة
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    
    $html = curl_exec($ch);
    curl_close($ch);

    $data = [
        'success' => false,
        'name' => '',
        'unified_id' => '',
        'owner' => '',
        'capital' => ''
    ];

    if ($html) {
        $data['success'] = true;
        
        // 1. استخراج اسم المنشأة (أحياناً يكون في H1 أو داخل Title)
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/su', $html, $matches)) {
            $data['name'] = trim(strip_tags($matches[1]));
        }

        // 2. استخراج الرقم الموحد (نمط 70XXXXXXXX)
        if (preg_match('/70\d{8}/', $html, $matches)) {
            $data['unified_id'] = $matches[0];
        }

        // 3. استخراج رأس المال
        if (preg_match('/([\d,]+\.\d{2})/', $html, $matches)) {
            $data['capital'] = str_replace(',', '', $matches[1]);
        }

        // 4. استخراج اسم المالك (نبحث في النص الصافي)
        $cleanText = strip_tags($html);
        if (preg_match('/اسم المالك\s+([^\n]+)/u', $cleanText, $matches)) {
            $data['owner'] = trim($matches[1]);
        }
        
        // إذا استخرجنا الاسم على الأقل نعتبرها ناجحة
        if (empty($data['name'])) {
            $data['success'] = false;
        }
    }

    echo json_encode($data);
    exit;
}