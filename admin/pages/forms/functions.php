<?php

function log_action($pdo, $action, $entity, $entity_id, $old_values = [], $new_values = [])
{
    try {
        $user_id = $_SESSION['user_id'] ?? 0;
        $user_name = $_SESSION['full_name'] ?? 'System';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, user_name, action, entity, entity_id, old_values, new_values, page_url, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $user_id,
            $user_name,
            $action,
            $entity,
            $entity_id,
            json_encode($old_values),
            json_encode($new_values),
            $_SERVER['REQUEST_URI'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        // سكون – لا نريد كسر الصفحة بسبب فشل التسجيل
    }
}

function set_success($message)
{
    $_SESSION['success_message'] = $message;
}

/**
 * stamp_approval_template — يطبع كتل التوقيع (pending) على الـ PDF فور رفع الوثيقة
 * مع بيانات الموظفين المرتبطين بسياسة الاعتماد.
 */
function stamp_approval_template($pdo, $document_id)
{
    try {
        require_once __DIR__ . '/../../../vendor/autoload.php';

        $docStmt = $pdo->prepare("SELECT * FROM " . TBL_DOCUMENTS . " WHERE id = ?");
        $docStmt->execute([$document_id]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc || empty($doc['workflow_id'])) return ['success' => false, 'error' => 'No workflow'];
        if (strtolower($doc['file_format'] ?? '') !== 'pdf') return ['success' => false, 'error' => 'Not PDF'];

        $currentFilePath = __DIR__ . '/../../../' . $doc['file_path'];
        $docDir          = dirname($currentFilePath);
        $origFile        = $docDir . '/original.pdf';
        if (!file_exists($origFile)) $origFile = $currentFilePath;
        if (!file_exists($origFile)) return ['success' => false, 'error' => 'File not found'];

        // جلب مراحل السياسة (كلها pending لأن الوثيقة جديدة)
        $wfStmt = $pdo->prepare("
            SELECT aps.stage_order, aps.stage_name,
                   e.id AS emp_id, e.full_name AS emp_name,
                   e.department AS emp_dept, e.emp_code,
                   'pending' AS appr_status, NULL AS signed_at
            FROM " . TBL_APPROVAL_STAGES . " aps
            JOIN " . TBL_EMPLOYEES . " e ON e.id = aps.employee_id
            WHERE aps.workflow_id = ? AND aps.is_active = 1
            ORDER BY aps.stage_order ASC
        ");
        $wfStmt->execute([$doc['workflow_id']]);
        $wfBlocks = $wfStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($wfBlocks)) return ['success' => false, 'error' => 'No stages'];

        $blockW = 36;
        $blockH = 28;
        $hGap = 4;
        $vGap = 3;
        $maxPerRow = 4;
        $rightMgn = 8;
        $bottomMgn = 6;

        $outputFile = $docDir . '/signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf';
        copy($origFile, $outputFile);

        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($outputFile);

        for ($i = 1; $i <= $pageCount; $i++) {
            $templateId = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($templateId);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            if ($i !== $pageCount) continue;

            foreach ($wfBlocks as $idx => $blk) {
                $col = $idx % $maxPerRow;
                $row = (int)floor($idx / $maxPerRow);
                $bX  = $size['width']  - $rightMgn  - $blockW - ($col * ($blockW + $hGap));
                $bY  = $size['height'] - $bottomMgn - $blockH - ($row * ($blockH + $vGap));
                $bX  = max(5, min($bX, $size['width'] - $blockW - 5));
                $bY  = max(5, $bY);

                _drawSignatureBlock(
                    $pdf,
                    null,
                    $blk['emp_name'] ?? '',
                    $blk['emp_dept'] ?? '',
                    $blk['emp_name'] ?? '',
                    '',
                    $bX,
                    $bY,
                    $blockW,
                    $blockH,
                    'pending'
                );
            }
        }

        $pdf->Output('F', $outputFile);

        // تحديث مسار الملف في قاعدة البيانات
        $signedRelPath = rtrim(dirname($doc['file_path']), '/') . '/signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf';
        $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET file_path = ?, file_name = ? WHERE id = ?")
            ->execute([$signedRelPath, 'signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf', $document_id]);

        return ['success' => true];
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function auto_sign_document($pdo, $document_id, $employee_id, $notes = '')
{
    try {
        require_once __DIR__ . '/../../../vendor/autoload.php';

        $docStmt = $pdo->prepare("SELECT * FROM " . TBL_DOCUMENTS . " WHERE id = ?");
        $docStmt->execute([$document_id]);
        $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) return ['success' => false, 'error' => 'Document not found'];

        $empStmt = $pdo->prepare("SELECT * FROM " . TBL_EMPLOYEES . " WHERE id = ?");
        $empStmt->execute([$employee_id]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) return ['success' => false, 'error' => 'Employee not found'];

        // اعتماد المرحلة أولاً
        $pdo->prepare(
            "UPDATE " . TBL_DOC_APPROVALS .
                " SET status = 'approved', signed_at = NOW()
              WHERE document_id = ? AND employee_id = ? AND status = 'pending'"
        )->execute([$document_id, $employee_id]);

        $sigId  = null;
        $signed = false;

        if (!empty($emp['signature_image'])) {
            $sigFile = __DIR__ . '/../../uploads/' . $emp['signature_image'];

            // ── مجلد الوثيقة (من file_path الحالي) ──────────────────────────────
            $currentFilePath = __DIR__ . '/../../../' . $doc['file_path'];
            $docDir          = dirname($currentFilePath);

            // ── دائماً نبدأ من النسخة الأصلية لتجنب مشاكل FPDI مع ملفاته ───────
            //    (FPDI لا يستطيع قراءة PDF أنشأه هو — سبب رسالة "غير مدعومة")
            $origFile = $docDir . '/original.pdf';
            if (!file_exists($origFile)) {
                // احتياط: أي ملف original.*
                $origCandidates = glob($docDir . '/original.*');
                $origFile = !empty($origCandidates) ? $origCandidates[0] : $currentFilePath;
            }

            if (file_exists($origFile) && file_exists($sigFile)) {
                $signed = true;

                // ── أبعاد كتلة التوقيع ──────────────────────────────────────────
                $blockW    = 36;   // عرض الكتلة (mm)
                $blockH    = 28;   // ارتفاع الكتلة (mm)
                $hGap      = 4;
                $vGap      = 3;
                $maxPerRow = 4;
                $rightMgn  = 8;
                $bottomMgn = 6;

                // ── التوقيعات السابقة ─────────────────────────────────────────────
                $prevStmt = $pdo->prepare(
                    "SELECT s.signature_image, s.signed_at,
                            e.full_name AS emp_name, e.department AS emp_dept,
                            e.emp_code
                       FROM " . TBL_SIGNATURES . " s
                       LEFT JOIN " . TBL_EMPLOYEES . " e ON s.employee_id = e.id
                      WHERE s.document_id = ? AND s.status = 'signed'
                      ORDER BY s.id ASC"
                );
                $prevStmt->execute([$document_id]);
                $prevSigs = $prevStmt->fetchAll(PDO::FETCH_ASSOC);

                $sigIndex = count($prevSigs);
                $newCol   = $sigIndex % $maxPerRow;
                $newRow   = (int)floor($sigIndex / $maxPerRow);

                $outputFile = $docDir . '/signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf';
                copy($origFile, $outputFile);

                $pdf = new \setasign\Fpdi\Fpdi();
                $pdf->SetAutoPageBreak(false); // منع انتقال التاريخ لصفحة جديدة
                $pageCount = $pdf->setSourceFile($outputFile);

                // ── جلب كل مراحل سياسة الاعتماد (موقّعة + معلّقة) ───────────────
                $signDate = date('Y/m/d H:i');
                $wfBlocks = [];

                if (!empty($doc['workflow_id'])) {
                    $wfStmt = $pdo->prepare("
                        SELECT
                            aps.stage_order,
                            aps.stage_name,
                            e.id             AS emp_id,
                            e.full_name      AS emp_name,
                            e.department     AS emp_dept,
                            e.emp_code,
                            e.signature_image AS emp_sig,
                            da.status        AS appr_status,
                            da.signed_at
                        FROM " . TBL_APPROVAL_STAGES . " aps
                        JOIN " . TBL_EMPLOYEES . " e ON e.id = aps.employee_id
                        LEFT JOIN " . TBL_DOC_APPROVALS . " da
                            ON da.stage_id = aps.id AND da.document_id = ?
                        WHERE aps.workflow_id = ? AND aps.is_active = 1
                        ORDER BY aps.stage_order ASC
                    ");
                    $wfStmt->execute([$document_id, $doc['workflow_id']]);
                    $wfBlocks = $wfStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    // بدون سياسة: نعرض التوقيع الحالي فقط
                    $wfBlocks = [[
                        'emp_name'    => $emp['full_name'] ?? '',
                        'emp_dept'    => $emp['department'] ?? '',
                        'emp_code'    => $emp['full_name'] ?? '',
                        'emp_sig'     => $emp['signature_image'] ?? '',
                        'appr_status' => 'approved',
                        'signed_at'   => $signDate,
                    ]];
                }

                for ($i = 1; $i <= $pageCount; $i++) {
                    $templateId = $pdf->importPage($i);
                    $size       = $pdf->getTemplateSize($templateId);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);

                    if ($i !== $pageCount) continue;

                    // ── رسم كتل التوقيع (من اليمين للشمال، بعدد المراحل) ────────
                    foreach ($wfBlocks as $idx => $blk) {
                        $col = $idx % $maxPerRow;
                        $row = (int)floor($idx / $maxPerRow);
                        $bX  = $size['width']  - $rightMgn  - $blockW - ($col * ($blockW + $hGap));
                        $bY  = $size['height'] - $bottomMgn - $blockH - ($row * ($blockH + $vGap));
                        $bX  = max(5, min($bX, $size['width'] - $blockW - 5));
                        $bY  = max(5, $bY);

                        // صورة التوقيع (فقط للموقّعين)
                        $blkSigFile = null;
                        if (($blk['appr_status'] ?? '') === 'approved' && !empty($blk['emp_sig'])) {
                            $cand = __DIR__ . '/../../uploads/' . $blk['emp_sig'];
                            if (file_exists($cand)) $blkSigFile = $cand;
                        }
                        // الموظف الحالي: اربط ملف التوقيع مباشرةً
                        if (
                            $blkSigFile === null
                            && !empty($blk['emp_id'])
                            && (int)$blk['emp_id'] === (int)$employee_id
                            && file_exists($sigFile)
                        ) {
                            $blkSigFile = $sigFile;
                        }

                        $blkDate = '';
                        if (($blk['appr_status'] ?? '') === 'approved') {
                            $blkDate = !empty($blk['signed_at'])
                                ? (strlen($blk['signed_at']) > 10
                                    ? date('Y/m/d H:i', strtotime($blk['signed_at']))
                                    : $blk['signed_at'])
                                : $signDate;
                        }

                        _drawSignatureBlock(
                            $pdf,
                            $blkSigFile,
                            $blk['emp_name']    ?? '',
                            $blk['emp_dept']    ?? '',
                            $blk['emp_name']    ?? '',
                            $blkDate,
                            $bX,
                            $bY,
                            $blockW,
                            $blockH,
                            $blk['appr_status'] ?? 'pending'
                        );
                    }
                }

                $pdf->Output('F', $outputFile);

                // ── تحديث file_path إلى النسخة الموقّعة ──────────────────────────
                $signedRelPath = rtrim(dirname($doc['file_path']), '/') .
                    '/signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf';
                $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET file_path = ?, file_name = ? WHERE id = ?")
                    ->execute([
                        $signedRelPath,
                        'signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf',
                        $document_id
                    ]);

                // ── حساب موضع كتلة التوقيع للتوقيع الحالي ──────────────────────
                $posX = max(5, min(
                    $size['width']  - $rightMgn  - $blockW - ($newCol * ($blockW + $hGap)),
                    $size['width'] - $blockW - 5
                ));
                $posY = max(5,
                    $size['height'] - $bottomMgn - $blockH - ($newRow * ($blockH + $vGap))
                );

                $sigWidth  = $blockW;
                $sigHeight = $blockH;

                $insStmt = $pdo->prepare(
                    "INSERT INTO " . TBL_SIGNATURES .
                        " (document_id, employee_id, signature_image, pos_x, pos_y,
                       page_number, width, height, sign_type, status, signed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'auto', 'signed', NOW())"
                );
                $insStmt->execute([
                    $document_id,
                    $employee_id,
                    $emp['signature_image'],
                    $posX,
                    $posY,
                    $pageCount,
                    $sigWidth,
                    $sigHeight
                ]);
                $sigId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    "UPDATE " . TBL_DOC_APPROVALS .
                        " SET signature_id = ?
                      WHERE document_id = ? AND employee_id = ? AND status = 'approved'"
                )->execute([$sigId, $document_id, $employee_id]);

                $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET version = version + 1 WHERE id = ?")
                    ->execute([$document_id]);

                log_action(
                    $pdo,
                    'auto_sign',
                    'وثيقة',
                    $document_id,
                    [],
                    ['employee_id' => $employee_id, 'signature_id' => $sigId]
                );
            }
        }

        return ['success' => true, 'signed' => $signed];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * _drawSignatureBlock — يرسم كتلة التوقيع الكاملة مباشرةً في كائن FPDI/FPDF.
 *
 * التخطيط (من الأعلى للأسفل ضمن blockH mm):
 *   ┌─────────────────────┐
 *   │   القسم (2.8 mm)    │  ← نص صغير بلون أزرق
 *   │   الاسم  (3.5 mm)   │  ← نص مميز
 *   ├─────────────────────┤  ← خط فاصل
 *   │                     │
 *   │   صورة التوقيع      │  ← الصورة مقيسة بدقة
 *   │                     │
 *   ├─────────────────────┤  ← خط رفيع
 *   │   التاريخ (2.8 mm)  │  ← ASCII دائماً مرئي
 *   └─────────────────────┘
 *
 * يستخدم صور GD صغيرة للنص العربي (تجاوز قيود ترميز FPDF).
 */
/**
 * _drawSignatureBlock
 *
 * @param object      $pdf      كائن FPDI/FPDF
 * @param string|null $sigFile  مسار صورة التوقيع (null = لم يُوقّع بعد)
 * @param string      $empName  اسم الموظف
 * @param string      $empDept  القسم
 * @param string      $empCode  كود الموظف
 * @param string      $signDate تاريخ التوقيع (فارغ للمعلّقين)
 * @param float       $posX     إحداثي X (mm)
 * @param float       $posY     إحداثي Y (mm)
 * @param float       $blockW   عرض الكتلة (mm)
 * @param float       $blockH   ارتفاع الكتلة (mm)
 * @param string      $status   'approved' | 'pending'
 */
function _drawSignatureBlock(
    $pdf,
    $sigFile,
    $empName,
    $empDept,
    $empCode,
    $signDate,
    $posX,
    $posY,
    $blockW,
    $blockH,
    $status = 'approved'
) {
    $isPending = ($status === 'pending');

    // ── ثوابت التخطيط (mm) ───────────────────────────────────────────────────
    $deptRowH = 2.8;
    $nameRowH = 3.5;
    $divH     = 0.4;
    $dateRowH = 2.8;
    $padV     = 0.3;

    $topH = $padV
        + (!empty($empDept) ? $deptRowH + $padV : 0)
        + (!empty($empName) ? $nameRowH + $padV : 0)
        + $divH;
    $botH = $dateRowH + $divH + $padV;
    $imgH = max(4.0, $blockH - $topH - $botH);

    // ── لون الإطار: أزرق للموقّع، رمادي للمعلّق ─────────────────────────────
    if ($isPending) {
        $pdf->SetFillColor(248, 248, 252);
        $pdf->SetDrawColor(170, 170, 190);
        $pdf->SetLineWidth(0.2);
    } else {
        $pdf->SetFillColor(252, 253, 255);
        $pdf->SetDrawColor(60, 100, 200);
        $pdf->SetLineWidth(0.3);
    }
    $pdf->Rect($posX, $posY, $blockW, $blockH, 'FD');

    $curY = $posY + $padV;

    // ── القسم ────────────────────────────────────────────────────────────────
    if (!empty($empDept)) {
        $txtColor = $isPending ? [140, 140, 160] : [50, 70, 180];
        $img = _arabicTextImage($empDept, $blockW, $deptRowH, $txtColor);
        if ($img) {
            try {
                $pdf->Image($img, $posX, $curY, $blockW, $deptRowH);
            } catch (\Exception $e) {
            }
            @unlink($img);
        }
        $curY += $deptRowH + $padV;
    }

    // ── اسم الموظف ───────────────────────────────────────────────────────────
    if (!empty($empName)) {
        $txtColor  = $isPending ? [120, 120, 140] : [20, 20, 20];
        $imgFile   = _arabicTextImage($empName, $blockW, $nameRowH, $txtColor, true);
        $imgShown  = false;
        if ($imgFile) {
            try {
                $pdf->Image($imgFile, $posX, $curY, $blockW, $nameRowH);
                $imgShown = true;
            } catch (\Exception $e) {
            }
            @unlink($imgFile);
        }
        // fallback: اعرض رقم الموظف + اسم قابل للقراءة إذا فشل GD
        if (!$imgShown) {
            $pdf->SetFont('Helvetica', 'B', 5);
            $pdf->SetTextColor($txtColor[0], $txtColor[1], $txtColor[2]);
            $pdf->SetXY($posX, $curY + ($nameRowH * 0.25));
            $label = !empty($empCode) ? '#' . $empCode : '---';
            $pdf->Cell($blockW, $nameRowH * 0.75, $label, 0, 0, 'C');
        }
        $curY += $nameRowH + $padV;
    }

    // ── خط فاصل علوي ─────────────────────────────────────────────────────────
    $pdf->SetDrawColor($isPending ? 170 : 60, $isPending ? 170 : 100, $isPending ? 190 : 200);
    $pdf->SetLineWidth($isPending ? 0.2 : 0.5);
    $pdf->Line($posX, $curY, $posX + $blockW, $curY);
    $pdf->SetLineWidth(0.1);
    $curY += $divH;

    // ── منطقة التوقيع / المعلّق ──────────────────────────────────────────────
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($posX, $curY, $blockW, $imgH, 'F');

    if ($sigFile && file_exists($sigFile) && $imgH > 2) {
        // موقّع: أظهر صورة التوقيع
        try {
            $pdf->Image($sigFile, $posX, $curY, $blockW, $imgH);
        } catch (\Exception $e) {
        }
    } elseif ($isPending) {
        // معلّق: أظهر نص "بانتظار التوقيع" ومستطيل رمادي
        $pdf->SetDrawColor(190, 190, 210);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect($posX + 1, $curY + 0.5, $blockW - 2, $imgH - 1);

        // نص "بانتظار"
        $pendingImg = _arabicTextImage('بانتظار التوقيع', $blockW - 2, min($imgH * 0.5, 4), [160, 160, 180]);
        if ($pendingImg) {
            $midY = $curY + ($imgH - min($imgH * 0.5, 4)) / 2;
            $pdf->Image($pendingImg, $posX + 1, $midY, $blockW - 2, min($imgH * 0.5, 4));
            @unlink($pendingImg);
        }
    }
    $curY += $imgH;

    // ── خط فاصل سفلي ─────────────────────────────────────────────────────────
    $pdf->SetDrawColor(180, 185, 215);
    $pdf->SetLineWidth(0.15);
    $pdf->Line($posX, $curY, $posX + $blockW, $curY);
    $curY += $divH;

    // ── التاريخ (ASCII) ───────────────────────────────────────────────────────
    $pdf->SetFont('Helvetica', '', 4.5);
    $pdf->SetTextColor($isPending ? 170 : 90, $isPending ? 170 : 90, $isPending ? 185 : 120);
    $pdf->SetXY($posX, $curY);
    $dateLabel = $isPending ? 'Pending' : ($signDate ?: '');
    $pdf->Cell($blockW, $dateRowH, $dateLabel, 0, 0, 'C');

    // ── رقم الموظف (صغير جداً — اختياري) ────────────────────────────────────
    if (!empty($empCode)) {
        $pdf->SetFont('Helvetica', '', 3.5);
        $pdf->SetTextColor(180, 180, 200);
        $pdf->SetXY($posX, $curY + $dateRowH - 1.5);
        $pdf->Cell($blockW, 2, $empCode, 0, 0, 'C');
    }

    // ── إعادة ضبط ─────────────────────────────────────────────────────────────
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetLineWidth(0.2);
}

/**
 * _arabicTextImage — ينشئ صورة PNG صغيرة تحتوي النص (عربي أو لاتيني)
 * باستخدام GD + Tahoma.ttf (يدعم Unicode العربي على Windows).
 *
 * @param  string $text      النص المراد رسمه
 * @param  float  $widthMm   عرض الصورة في mm (سيتحوّل لـ px)
 * @param  float  $heightMm  ارتفاع الصورة في mm
 * @param  int[]  $rgb       لون النص [R,G,B]
 * @param  bool   $bold      نص عريض (يزيد حجم الخط بـ 15%)
 * @return string|null       مسار الملف المؤقت أو null عند الفشل
 */
/**
 * _arabicBidi — معالجة كاملة للنص العربي لـ GD:
 *   1. إعادة الشكل السياقي للحروف (Arabic Reshaping → Presentation Forms-B)
 *   2. عكس ترتيب الحروف داخل كل كلمة + عكس ترتيب الكلمات
 *
 * imagettftext ترسم LTR ولا تطبّق Arabic shaping تلقائياً،
 * لذا نُعالج النص مسبقاً لنحصل على عرض صحيح للعربية.
 */
function _arabicBidi(string $text): string
{
    if (empty($text) || !preg_match('/[\x{0600}-\x{06FF}]/u', $text)) return $text;

    // ── جدول الأشكال: [isolated, final, initial, medial] ──────────────────────
    static $F = null;
    static $NC = null;   // Non-Connecting (لا تُعطي وصلاً لليسار)

    if ($F === null) {
        $F = [
            "\u{0621}"=>["\u{FE80}","\u{FE80}","\u{FE80}","\u{FE80}"], // ء
            "\u{0622}"=>["\u{FE81}","\u{FE82}","\u{FE81}","\u{FE82}"], // آ
            "\u{0623}"=>["\u{FE83}","\u{FE84}","\u{FE83}","\u{FE84}"], // أ
            "\u{0624}"=>["\u{FE85}","\u{FE86}","\u{FE85}","\u{FE86}"], // ؤ
            "\u{0625}"=>["\u{FE87}","\u{FE88}","\u{FE87}","\u{FE88}"], // إ
            "\u{0626}"=>["\u{FE89}","\u{FE8A}","\u{FE8B}","\u{FE8C}"], // ئ
            "\u{0627}"=>["\u{FE8D}","\u{FE8E}","\u{FE8D}","\u{FE8E}"], // ا
            "\u{0628}"=>["\u{FE8F}","\u{FE90}","\u{FE91}","\u{FE92}"], // ب
            "\u{0629}"=>["\u{FE93}","\u{FE94}","\u{FE93}","\u{FE94}"], // ة
            "\u{062A}"=>["\u{FE95}","\u{FE96}","\u{FE97}","\u{FE98}"], // ت
            "\u{062B}"=>["\u{FE99}","\u{FE9A}","\u{FE9B}","\u{FE9C}"], // ث
            "\u{062C}"=>["\u{FE9D}","\u{FE9E}","\u{FE9F}","\u{FEA0}"], // ج
            "\u{062D}"=>["\u{FEA1}","\u{FEA2}","\u{FEA3}","\u{FEA4}"], // ح
            "\u{062E}"=>["\u{FEA5}","\u{FEA6}","\u{FEA7}","\u{FEA8}"], // خ
            "\u{062F}"=>["\u{FEA9}","\u{FEAA}","\u{FEA9}","\u{FEAA}"], // د
            "\u{0630}"=>["\u{FEAB}","\u{FEAC}","\u{FEAB}","\u{FEAC}"], // ذ
            "\u{0631}"=>["\u{FEAD}","\u{FEAE}","\u{FEAD}","\u{FEAE}"], // ر
            "\u{0632}"=>["\u{FEAF}","\u{FEB0}","\u{FEAF}","\u{FEB0}"], // ز
            "\u{0633}"=>["\u{FEB1}","\u{FEB2}","\u{FEB3}","\u{FEB4}"], // س
            "\u{0634}"=>["\u{FEB5}","\u{FEB6}","\u{FEB7}","\u{FEB8}"], // ش
            "\u{0635}"=>["\u{FEB9}","\u{FEBA}","\u{FEBB}","\u{FEBC}"], // ص
            "\u{0636}"=>["\u{FEBD}","\u{FEBE}","\u{FEBF}","\u{FEC0}"], // ض
            "\u{0637}"=>["\u{FEC1}","\u{FEC2}","\u{FEC3}","\u{FEC4}"], // ط
            "\u{0638}"=>["\u{FEC5}","\u{FEC6}","\u{FEC7}","\u{FEC8}"], // ظ
            "\u{0639}"=>["\u{FEC9}","\u{FECA}","\u{FECB}","\u{FECC}"], // ع
            "\u{063A}"=>["\u{FECD}","\u{FECE}","\u{FECF}","\u{FED0}"], // غ
            "\u{0641}"=>["\u{FED1}","\u{FED2}","\u{FED3}","\u{FED4}"], // ف
            "\u{0642}"=>["\u{FED5}","\u{FED6}","\u{FED7}","\u{FED8}"], // ق
            "\u{0643}"=>["\u{FED9}","\u{FEDA}","\u{FEDB}","\u{FEDC}"], // ك
            "\u{0644}"=>["\u{FEDD}","\u{FEDE}","\u{FEDF}","\u{FEE0}"], // ل
            "\u{0645}"=>["\u{FEE1}","\u{FEE2}","\u{FEE3}","\u{FEE4}"], // م
            "\u{0646}"=>["\u{FEE5}","\u{FEE6}","\u{FEE7}","\u{FEE8}"], // ن
            "\u{0647}"=>["\u{FEE9}","\u{FEEA}","\u{FEEB}","\u{FEEC}"], // ه
            "\u{0648}"=>["\u{FEED}","\u{FEEE}","\u{FEED}","\u{FEEE}"], // و
            "\u{0649}"=>["\u{FEEF}","\u{FEF0}","\u{FEEF}","\u{FEF0}"], // ى
            "\u{064A}"=>["\u{FEF1}","\u{FEF2}","\u{FEF3}","\u{FEF4}"], // ي
        ];
        // حروف غير واصلة يساراً (لا تُعطي وصلاً للحرف التالي منطقياً)
        $NC = array_flip([
            "\u{0621}","\u{0622}","\u{0623}","\u{0624}","\u{0625}",
            "\u{0627}","\u{0629}","\u{062F}","\u{0630}","\u{0631}",
            "\u{0632}","\u{0648}","\u{0649}"
        ]);
    }

    // ── معالجة كل كلمة ────────────────────────────────────────────────────────
    $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $words  = [];
    foreach ($tokens as $tok) {
        if (!preg_match('/^\s+$/u', $tok)) $words[] = $tok;
    }

    $processed = [];
    foreach ($words as $word) {
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $word)) {
            $processed[] = $word;  // كلمة لاتينية: لا تعديل
            continue;
        }

        $chars  = mb_str_split($word, 1, 'UTF-8');
        $n      = count($chars);
        $shaped = [];

        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if (!isset($F[$c])) { $shaped[] = $c; continue; }

            $prev = ($i > 0)     ? $chars[$i-1] : null;
            $next = ($i < $n-1)  ? $chars[$i+1] : null;

            // الحرف السابق يُعطي وصلاً إذا كان عربياً ومن الحروف الواصلة
            $rConn = $prev !== null && isset($F[$prev]) && !isset($NC[$prev]);
            // الحرف الحالي يُعطي وصلاً إذا لم يكن من الحروف غير الواصلة
            // والحرف التالي عربي
            $lConn = $next !== null && isset($F[$next]) && !isset($NC[$c]);

            if ($rConn && $lConn) $shaped[] = $F[$c][3]; // متوسط
            elseif ($rConn)       $shaped[] = $F[$c][1]; // نهائي
            elseif ($lConn)       $shaped[] = $F[$c][2]; // أولي
            else                  $shaped[] = $F[$c][0]; // منفرد
        }

        // عكس الحروف المشكّلة داخل الكلمة للعرض البصري LTR
        $processed[] = implode('', array_reverse($shaped));
    }

    // عكس ترتيب الكلمات (RTL → LTR)
    return implode(' ', array_reverse($processed));
}

function _arabicTextImage($text, $widthMm, $heightMm, $rgb = [0, 0, 0], $bold = false)
{
    if (empty($text) || !extension_loaded('gd')) return null;

    $dpi      = 200;
    $widthPx  = (int)round($widthMm  * $dpi / 25.4);
    $heightPx = (int)round($heightMm * $dpi / 25.4);
    if ($widthPx < 10 || $heightPx < 4) return null;

    // ── تحضير النص: معالجة BIDI للعربية قبل الرسم ───────────────────────────
    $renderText = _arabicBidi($text);

    // ── إنشاء صورة GD ────────────────────────────────────────────────────────
    $img = @imagecreatetruecolor($widthPx, $heightPx);
    if ($img === false) return null;

    $bg = @imagecolorallocate($img, 252, 253, 255);
    $fg = @imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    @imagefill($img, 0, 0, $bg);

    // ── إيجاد خط TTF يدعم العربية ────────────────────────────────────────────
    $fontPath = null;
    $winDir   = str_replace('\\', '/', getenv('WINDIR') ?: getenv('SystemRoot') ?: 'C:/Windows');
    $fontList = $bold
        ? [$winDir . '/Fonts/tahomabd.ttf', 'C:/Windows/Fonts/tahomabd.ttf', $winDir . '/Fonts/arialbd.ttf', 'C:/Windows/Fonts/arialbd.ttf', $winDir . '/Fonts/tahoma.ttf']
        : [$winDir . '/Fonts/tahoma.ttf', 'C:/Windows/Fonts/tahoma.ttf', $winDir . '/Fonts/arial.ttf', 'C:/Windows/Fonts/arial.ttf', $winDir . '/Fonts/calibri.ttf'];
    foreach ($fontList as $f) {
        if (@file_exists($f)) { $fontPath = $f; break; }
    }

    // ── رسم النص بـ TTF ──────────────────────────────────────────────────────
    $rendered = false;
    if ($fontPath && function_exists('imagettftext')) {
        $fontSize = max(10, (int)($heightPx * 0.62 * ($bold ? 1.1 : 1.0)));
        $y = (int)($heightPx * 0.75);
        $x = 4;
        $bb = @imagettfbbox($fontSize, 0, $fontPath, $renderText);
        if ($bb !== false) {
            $tw = (int)abs($bb[4] - $bb[0]);
            $th = (int)abs($bb[5] - $bb[1]);
            $x  = ($tw < $widthPx - 4) ? (int)(($widthPx - $tw) / 2) : 4;
            $y  = (int)(($heightPx + $th) / 2);
        }
        $ok = @imagettftext($img, $fontSize, 0, $x, $y, $fg, $fontPath, $renderText);
        $rendered = ($ok !== false);
    }
    // إذا فشل imagettftext للعربية — نتوقف ونترك Cell() fallback يعمل
    if (!$rendered) {
        @imagedestroy($img);
        return null;
    }

    // ── تصدير الصورة: نجرب PNG ثم JPEG ثم GIF ───────────────────────────────
    $imgData = null;
    $ext     = '.png';

    ob_start();
    try {
        if (@imagepng($img)) {
            $imgData = ob_get_clean();
        } else {
            ob_end_clean();
        }
    } catch (\Throwable $e) {
        ob_end_clean();
    }

    if (!$imgData || strlen($imgData) < 50) {
        $ext = '.jpg';
        ob_start();
        try {
            if (@imagejpeg($img, null, 90)) {
                $imgData = ob_get_clean();
            } else {
                ob_end_clean();
            }
        } catch (\Throwable $e) {
            ob_end_clean();
        }
    }

    if (!$imgData || strlen($imgData) < 50) {
        $ext = '.gif';
        ob_start();
        try {
            if (@imagegif($img)) {
                $imgData = ob_get_clean();
            } else {
                ob_end_clean();
            }
        } catch (\Throwable $e) {
            ob_end_clean();
        }
    }

    @imagedestroy($img);
    if (!$imgData || strlen($imgData) < 10) return null;

    // ── كتابة الملف في uploads/temp (مسار مطلق بدون ..) ─────────────────────
    $formsDir    = str_replace('\\', '/', realpath(__DIR__) ?: str_replace('\\', '/', __DIR__));
    $projectRoot = dirname(dirname(dirname($formsDir)));
    $tmpDir      = $projectRoot . '/uploads/temp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

    $uid = md5($text . microtime(true));
    $out = $tmpDir . '/arimg_' . $uid . $ext;
    if (@file_put_contents($out, $imgData) !== false && @filesize($out) > 10) {
        return str_replace('\\', '/', $out);
    }

    // fallback: sys_get_temp_dir
    $sysOut = str_replace('\\', '/', sys_get_temp_dir()) . '/arimg_' . $uid . 'b' . $ext;
    if (@file_put_contents($sysOut, $imgData) !== false && @filesize($sysOut) > 10) {
        return $sysOut;
    }

    return null;
}

/**
 * createSignatureStamp — مُحتفظ به للتوافق مع الاستدعاءات الخارجية (legacy).
 * الاستخدام الجديد: _drawSignatureBlock() مباشرةً في FPDF.
 */
function createSignatureStamp($sigFile, $empName, $department = '', $signDate = '', $pdfWidthMm = 50.0)
{
    if (!extension_loaded('gd') || !file_exists($sigFile)) return $sigFile;

    $imgInfo = @getimagesize($sigFile);
    if (!$imgInfo) return $sigFile;

    $srcW = (int)$imgInfo[0];
    $srcH = (int)$imgInfo[1];
    if ($srcW < 1 || $srcH < 1) return $sigFile;

    // ── 1. تسطيح صورة التوقيع على خلفية بيضاء ──────────────────────────────
    $sigFlat = imagecreatetruecolor($srcW, $srcH);
    $white   = imagecolorallocate($sigFlat, 255, 255, 255);
    imagefill($sigFlat, 0, 0, $white);
    $raw = null;
    if ($imgInfo['mime'] === 'image/png')  $raw = @imagecreatefrompng($sigFile);
    elseif ($imgInfo['mime'] === 'image/jpeg') $raw = @imagecreatefromjpeg($sigFile);
    elseif ($imgInfo['mime'] === 'image/gif')  $raw = @imagecreatefromgif($sigFile);
    if ($raw) {
        imagecopy($sigFlat, $raw, 0, 0, 0, 0, $srcW, $srcH);
        imagedestroy($raw);
    }

    // ── 2. الخط TTF ───────────────────────────────────────────────────────────
    $font = null;
    $winDir = str_replace('\\', '/', getenv('WINDIR') ?: getenv('SystemRoot') ?: 'C:/Windows');
    foreach (
        [
            $winDir . '/Fonts/tahoma.ttf',
            $winDir . '/Fonts/arial.ttf',
            'C:/Windows/Fonts/tahoma.ttf',
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/calibri.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ] as $f
    ) {
        if (@file_exists($f)) {
            $font = $f;
            break;
        }
    }

    // ── 3. أحجام الخط (بكسل) بالنسبة للعرض الفعلي في PDF ────────────────────
    $ppm     = ($srcW > 0 && $pdfWidthMm > 0) ? ($srcW / $pdfWidthMm) : 6.0;
    $szDept  = max(14, (int)(2.8 * $ppm));  // القسم
    $szName  = max(15, (int)(3.0 * $ppm));  // الاسم
    $szDate  = max(12, (int)(2.4 * $ppm));  // التاريخ
    $gap     = max(5,  (int)(0.7 * $ppm));
    $pad     = max(8,  (int)(1.3 * $ppm));
    $lineH   = max(3,  (int)(0.6 * $ppm));

    // ── 4. حساب الارتفاعات ───────────────────────────────────────────────────
    $topH = $pad
        + (!empty($department) ? $szDept + $gap : 0)
        + $szName + $gap + $pad;
    $botH = !empty($signDate) ? ($pad + $szDate + $pad) : 0;
    $stampW = $srcW;
    $stampH = $topH + $lineH + $srcH + ($botH > 0 ? $lineH + $botH : 0);

    // ── 5. إنشاء اللوحة النهائية (بيضاء صلبة، بدون Alpha) ───────────────────
    $cv  = imagecreatetruecolor($stampW, $stampH);
    $cW  = imagecolorallocate($cv, 255, 255, 255);  // أبيض
    $cK  = imagecolorallocate($cv,   0,   0,   0);  // أسود — كل النصوص
    $cB  = imagecolorallocate($cv,   0,   0,   0);  // الخط الفاصل أسود
    imagefill($cv, 0, 0, $cW);

    // ── دالة رسم نص أسود متوسط (مع معالجة BIDI للعربية) ─────────────────────
    $put = function (string $txt, int $sz, int $yBase) use ($cv, $stampW, $font, $pad, $cK) {
        $txt = _arabicBidi($txt);  // إصلاح اتجاه النص العربي
        if (!$font || !function_exists('imagettftext')) {
            $fw = imagefontwidth(5) * strlen($txt);
            $x  = max($pad, (int)(($stampW - $fw) / 2));
            imagestring($cv, 5, $x, $yBase - imagefontheight(5), $txt, $cK);
            return;
        }
        $bb = @imagettfbbox($sz, 0, $font, $txt);
        $tw = $bb ? (int)abs($bb[4] - $bb[0]) : 0;
        $x  = ($tw > 2 && $tw < $stampW - 4) ? (int)(($stampW - $tw) / 2) : $pad;
        @imagettftext($cv, $sz, 0, $x, $yBase, $cK, $font, $txt);
    };

    // ── 6. النصوص العلوية: القسم ثم الاسم ───────────────────────────────────
    $y = $pad;
    if (!empty($department)) {
        $y += $szDept;
        $put($department, $szDept, $y);
        $y += $gap;
    }
    $y += $szName;
    $put($empName, $szName, $y);

    // ── 7. خط فاصل أسود ──────────────────────────────────────────────────────
    imagefilledrectangle($cv, 0, $topH, $stampW, $topH + $lineH - 1, $cB);

    // ── 8. صورة التوقيع ──────────────────────────────────────────────────────
    imagecopy($cv, $sigFlat, 0, $topH + $lineH, 0, 0, $srcW, $srcH);
    imagedestroy($sigFlat);

    // ── 9. خط فاصل ثم التاريخ ────────────────────────────────────────────────
    if (!empty($signDate)) {
        $lineY = $topH + $lineH + $srcH;
        imagefilledrectangle($cv, 0, $lineY, $stampW, $lineY + $lineH - 1, $cB);
        $put($signDate, $szDate, $lineY + $lineH + $pad + $szDate);
    }

    // ── 10. حفظ PNG بدون Alpha (مضمون الظهور في PDF) ─────────────────────────
    $flat = imagecreatetruecolor($stampW, $stampH);          // بدون Alpha
    imagecopy($flat, $cv, 0, 0, 0, 0, $stampW, $stampH);
    imagedestroy($cv);

    $out = tempnam(sys_get_temp_dir(), 'stamp_') . '.png';
    $ok  = imagepng($flat, $out);
    imagedestroy($flat);

    return ($ok && @file_exists($out) && @filesize($out) > 100) ? $out : $sigFile;
}

function check_permission($user_id, $page_link, $pdo)
{
    $sql = "SELECT upa.can_view, upa.can_add, upa.can_edit, upa.can_delete 
            FROM user_menu_access upa
            JOIN sys_menu sm ON upa.menu_id = sm.id
            WHERE upa.user_id = ? AND sm.link = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $page_link]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'can_view' => 0,
        'can_add' => 0,
        'can_edit' => 0,
        'can_delete' => 0
    ];
}
