<?php
header('Content-Type: application/json; charset=utf-8');

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $url = "https://qr.saudibusiness.gov.sa/viewcr?nCrNumber=" . urlencode($token);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    // لضمان جلب اللغة العربية بشكل صحيح
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept-Language: ar,en;q=0.9']);
    
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
        
        // 1. محاولة صيد اسم المنشأة (غالباً يكون في وسم H1 أو كلاس محدد)
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/su', $html, $matches)) {
            $data['name'] = trim(strip_tags($matches[1]));
        }

        // 2. صيد الرقم الوطني الموحد (نمط 70XXXXXXXX)
        if (preg_match('/70\d{8}/', $html, $matches)) {
            $data['unified_id'] = $matches[0];
        }

        // 3. صيد رأس المال (رقم يتبعه "ريال")
        if (preg_match('/([\d,]+\.\d{2})\s*ريال/u', $html, $matches)) {
            $data['capital'] = $matches[1];
        }

        // 4. صيد اسم المالك (طريقة متقدمة: البحث عن الكلمة وما يليها)
        // نزيل وسوم HTML أولاً لتسهيل البحث النصي
        $plainText = strip_tags($html);
        if (preg_match('/اسم المالك\s+(.*?)(\n|$)/u', $plainText, $matches)) {
            $data['owner'] = trim($matches[1]);
        }
    }

    echo json_encode($data);
}