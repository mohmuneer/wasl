<?php

/**
 * extract_logic.php
 * =================
 * دوال استخراج بيانات السجل التجاري من النص.
 * مفصّلة لتطابق حقول فورم add-cstmr.php
 *
 * الحقول المُرجعة:
 * customer_name   : اسم المنشأة
 * customer_type   : نوع المنشأة (مؤسسة/شركة)
 * cr_status       : حالة السجل
 * national_id     : الرقم الوطني الموحد
 * cr_number       : رقم السجل التجاري
 * cr_date         : تاريخ القيد
 * cr_expiry_g     : تاريخ الانتهاء
 * capital         : رأس المال
 * address         : العنوان المعتمد
 * owner_name      : اسم المالك
 * owner_id        : رقم هوية المالك
 * phone           : رقم الجوال
 * email           : البريد الإلكتروني
 */

function extractCrFields(string $text): array
{
    $f = [
        'customer_name' => null,
        'customer_type' => null,
        'cr_status' => null,
        'national_id' => null,
        'cr_number' => null,
        'cr_date' => null,
        'cr_expiry_g' => null,
        'capital' => null,
        'address' => null,
        'owner_name' => null,
        'owner_id' => null,
        'phone' => null,
        'email' => null,
    ];

    // تطبيع: توحيد المسافات + تحويل الأرقام العربية إلى لاتينية
    $text = normalizeArabicDigits($text);
    $clean = preg_replace('/[ \t]+/u', ' ', $text);

    // إنشاء السطور بناءً على النص المُطبع والمُنظف لضمان تطابق البيانات
    $lines = array_values(array_filter(array_map('trim', explode("\n", $clean)), fn($l) => $l !== ''));

    // ---------- الأرقام المعرّفة بكلمات مفتاحية (الأدق) ----------
    // الرقم الوطني الموحد
    if (preg_match('/الرقم\s*الوطني\s*الموحد\s*[:：]?\s*(\d{10})/u', $clean, $m)) {
        $f['national_id'] = $m[1];
    }
    // رقم السجل التجاري
    if (preg_match('/رقم\s*السجل\s*(?:التجاري)?\s*[:：]?\s*(\d{10})/u', $clean, $m)) {
        $f['cr_number'] = $m[1];
    }
    // رقم هوية المالك
    if (preg_match('/(?:رقم\s*)?(?:الهوية|هوية\s*المالك|السجل\s*المدني)\s*[:：]?\s*(\d{10})/u', $clean, $m)) {
        $f['owner_id'] = $m[1];
    }

    // ---------- احتياطي بالبادئة لأي رقم لم يُلتقط ----------
    // (السعودية: 7=الموحد، 10=السجل، 1=هوية مواطن، 2=إقامة)
    preg_match_all('/\b(\d{10})\b/u', $clean, $allNums);
    if (!empty($allNums[1])) {
        foreach ($allNums[1] as $num) {
            $p1 = $num[0];
            $p2 = substr($num, 0, 2);
            if ($p2 === '70' && !$f['national_id'])      $f['national_id'] = $num;
            elseif ($p2 === '10' && !$f['cr_number'])    $f['cr_number'] = $num;
            elseif (in_array($p1, ['1', '2']) && !$f['owner_id'] && $num !== ($f['national_id'] ?? '') && $num !== ($f['cr_number'] ?? '')) {
                $f['owner_id'] = $num;
            }
        }
    }

    // ---------- حالة السجل ----------
    if (preg_match('/حالة\s*السجل\s*[:：]?\s*([^\n\s]{2,15})/u', $clean, $m)) {
        $f['cr_status'] = trim($m[1]);
    } elseif (mb_strpos($clean, 'نشط') !== false) {
        $f['cr_status'] = 'نشط';
    }

    // ---------- نوع المنشأة ----------
    if (preg_match('/نوع\s*الكيان\s*[:：]?\s*([^\n]{2,40})/u', $clean, $m)) {
        $f['customer_type'] = trim(preg_split('/صفات|حالة|تاريخ/u', $m[1])[0]);
    } elseif (mb_strpos($clean, 'مؤسسة') !== false) {
        $f['customer_type'] = 'مؤسسة';
    } elseif (mb_strpos($clean, 'شركة') !== false) {
        $f['customer_type'] = 'شركة';
    }

    // ---------- اسم المنشأة ----------
    // تم إصلاح الموديفاير بإضافة الـ 'u' في نهاية الـ preg_split تفادياً للـ Fatal Error
    if (preg_match('/((?:مؤسسة|شركة)\s+[\x{0600}-\x{06FF}\s]{2,80})/u', $clean, $m)) {
        $name = preg_split('/الرقم|رقم|تاريخ|نوع|حالة|رأس/u', $m[1])[0];
        $f['customer_name'] = trim($name);
    }

    // ---------- التواريخ ----------
    if (preg_match('/تاريخ\s*القيد\s*[:：]?\s*(\d{2}[-\/]\d{2}[-\/]\d{4})/u', $clean, $m)) {
        $f['cr_date'] = $m[1];
    }
    if (preg_match('/تاريخ\s*الانتهاء\s*[:：]?\s*(\d{2}[-\/]\d{2}[-\/]\d{4})/u', $clean, $m)) {
        $f['cr_expiry_g'] = $m[1];
    }
    // احتياطي التواريخ
    if (!$f['cr_date'] || !$f['cr_expiry_g']) {
        preg_match_all('/\b(\d{2}[-\/]\d{2}[-\/]\d{4})\b/u', $clean, $dm);
        $dates = $dm[1] ?? [];
        if (count($dates) >= 2) {
            usort($dates, fn($a, $b) => toSortableDate($a) <=> toSortableDate($b));
            if (!$f['cr_date'])     $f['cr_date'] = $dates[0];
            if (!$f['cr_expiry_g']) $f['cr_expiry_g'] = end($dates);
        } elseif (count($dates) === 1 && !$f['cr_date']) {
            $f['cr_date'] = $dates[0];
        }
    }

    // ---------- رأس المال ----------
    if (preg_match('/رأس\s*المال\s*[:：]?\s*([\d,]+(?:\.\d{2})?)/u', $clean, $m)) {
        $f['capital'] = str_replace(',', '', $m[1]);
    } elseif (preg_match('/([\d,]{4,}\.\d{2})/u', $clean, $m)) {
        $f['capital'] = str_replace(',', '', $m[1]);
    }

    // ---------- العنوان المعتمد ----------
    foreach ($lines as $i => $line) {
        if (
            mb_strpos($line, 'العنوان المعتمد') !== false ||
            mb_strpos($line, 'مدينة عنوان') !== false ||
            mb_strpos($line, 'العنوان الوطني') !== false
        ) {

            $after = trim(preg_replace('/.*?(?:العنوان المعتمد|مدينة عنوان|العنوان الوطني)\s*[:：]?/u', '', $line));
            $candidate = $after !== '' ? $after : ($lines[$i + 1] ?? '');
            $candidate = trim(preg_replace('/\b\d{4}\b/u', '', $candidate)); // إزالة الرمز البريدي المكون من 4 أو 5 أرقام فقط بدقة
            if (mb_strlen($candidate) > 3) {
                $f['address'] = $candidate;
                break;
            }
        }
    }

    // ---------- اسم المالك ----------
    foreach ($lines as $i => $line) {
        if (
            mb_strpos($line, 'اسم المالك') !== false ||
            mb_strpos($line, 'بيانات مالك') !== false ||
            mb_strpos($line, 'مالك المنشأة') !== false
        ) {

            $after = trim(preg_replace('/.*?(?:اسم المالك|بيانات مالك المنشأة|مالك المنشأة)\s*[:：]?/u', '', $line));
            $candidate = $after !== '' ? $after : ($lines[$i + 1] ?? '');

            // إزالة الكلمات الزائدة المشهورة التي تلتقطها الـ OCR في نفس السطر
            $candidate = preg_split('/رقم|الهوية|الجنسية|الحصة/u', $candidate)[0];

            if (mb_strlen(trim($candidate)) > 4 && !preg_match('/\d/u', $candidate)) {
                $f['owner_name'] = trim($candidate);
                break;
            }
        }
    }

    // ---------- الجوال ----------
    if (preg_match('/(05\d{8})/u', $clean, $m)) {
        $f['phone'] = $m[1];
    }

    // ---------- البريد الإلكتروني ----------
    if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/u', $clean, $m)) {
        $f['email'] = $m[1];
    }

    return $f;
}

/**
 * تحويل الأرقام العربية (٠١٢..) إلى لاتينية لضمان عمل الـ regex.
 */
function normalizeArabicDigits(string $s): string
{
    $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($ar, $en, $s);
}

/**
 * تحويل dd-mm-yyyy إلى yyyymmdd للترتيب الزمني.
 */
function toSortableDate(string $d): string
{
    $parts = preg_split('/[-\/]/', $d);
    if (count($parts) === 3) {
        return $parts[2] . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
    }
    return $d;
}
