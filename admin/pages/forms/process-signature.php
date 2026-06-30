<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/../../lang/init.php';

use setasign\Fpdi\Fpdi;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => __('method_not_allowed')]);
    exit;
}

$document_id = (int) ($_POST['document_id'] ?? 0);
$employee_id = (int) ($_POST['employee_id'] ?? 0);
$pos_x       = (float) ($_POST['pos_x'] ?? 0);
$pos_y       = (float) ($_POST['pos_y'] ?? 0);
$page_number = (int) ($_POST['page_number'] ?? 1);
$width       = 50.0;   // حجم موحد 50mm لكل التوقيعات
$height      = 0.0;    // auto من نسبة أبعاد الطابع
$sign_type   = $_POST['sign_type'] ?? 'auto';

if (!$document_id || !$employee_id) {
    echo json_encode(['success' => false, 'message' => __('sig_invalid_data')]);
    exit;
}

try {
    $docStmt = $pdo->prepare("SELECT * FROM " . TBL_DOCUMENTS . " WHERE id = ?");
    $docStmt->execute([$document_id]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => __('doc_not_found')]);
        exit;
    }

    $empStmt = $pdo->prepare("SELECT * FROM " . TBL_EMPLOYEES . " WHERE id = ?");
    $empStmt->execute([$employee_id]);
    $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$emp) {
        echo json_encode(['success' => false, 'message' => __('emp_not_found')]);
        exit;
    }

    if (empty($emp['signature_image'])) {
        echo json_encode(['success' => false, 'message' => __('sig_no_image')]);
        exit;
    }

    // التحقق من سياسة الاعتماد: إن كانت الوثيقة مرتبطة بسياسة، يجب أن يكون الموظف في دوره
    $docWorkflowId = (int)($doc['workflow_id'] ?? 0);
    if ($docWorkflowId > 0) {
        $apprCheck = $pdo->prepare(
            "SELECT da.id, ast.stage_order
             FROM " . TBL_DOC_APPROVALS . " da
             JOIN " . TBL_APPROVAL_STAGES . " ast ON da.stage_id = ast.id
             WHERE da.document_id = ? AND da.employee_id = ? AND da.workflow_id = ? AND da.status = 'pending'"
        );
        $apprCheck->execute([$document_id, $employee_id, $docWorkflowId]);
        $myApproval = $apprCheck->fetch(PDO::FETCH_ASSOC);

        if (!$myApproval) {
            echo json_encode(['success' => false, 'message' => __('not_in_workflow')]);
            exit;
        }

        // التحقق من اكتمال المراحل السابقة
        $prevPendingStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM " . TBL_DOC_APPROVALS . " da
             JOIN " . TBL_APPROVAL_STAGES . " ast ON da.stage_id = ast.id
             WHERE da.document_id = ? AND da.workflow_id = ? AND ast.stage_order < ? AND da.status != 'approved'"
        );
        $prevPendingStmt->execute([$document_id, $docWorkflowId, (int)$myApproval['stage_order']]);
        if ((int)$prevPendingStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => __('previous_stage_pending')]);
            exit;
        }
    }

    $sourceFile = __DIR__ . '/../../../' . $doc['file_path'];
    if (!file_exists($sourceFile)) {
        echo json_encode(['success' => false, 'message' => __('doc_file_missing')]);
        exit;
    }

    require_once __DIR__ . '/../../../vendor/autoload.php';

    $sigFile = __DIR__ . '/../../uploads/' . $emp['signature_image'];
    if (!file_exists($sigFile)) {
        echo json_encode(['success' => false, 'message' => __('sig_file_missing')]);
        exit;
    }

    $docDir    = dirname($sourceFile);
    $outputFile = $docDir . '/signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf';

    // ابدأ من original.pdf لتجنب مشاكل FPDI مع الملفات الموقعة مسبقاً
    $origFile = $docDir . '/original.pdf';
    if (!file_exists($origFile)) {
        $origCandidates = glob($docDir . '/original.*');
        $origFile = !empty($origCandidates) ? $origCandidates[0] : $sourceFile;
    }
    copy($origFile, $outputFile);

    // جلب جميع التوقيعات السابقة
    $prevStmt = $pdo->prepare(
        "SELECT s.signature_image, s.signed_at,
                e.full_name AS emp_name, e.department AS emp_dept
           FROM " . TBL_SIGNATURES . " s
           LEFT JOIN " . TBL_EMPLOYEES . " e ON s.employee_id = e.id
          WHERE s.document_id = ? AND s.status = 'signed'
          ORDER BY s.id ASC"
    );
    $prevStmt->execute([$document_id]);
    $prevSigs = $prevStmt->fetchAll(PDO::FETCH_ASSOC);

    $sigIndex = count($prevSigs);
    $blockW    = 52;
    $blockH    = 44;
    $hGap      = 8;
    $vGap      = 6;
    $maxPerRow = 3;
    $rightMgn  = 12;
    $bottomMgn = 10;
    $newCol = $sigIndex % $maxPerRow;
    $newRow = (int)floor($sigIndex / $maxPerRow);

    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($outputFile);

    for ($i = 1; $i <= $pageCount; $i++) {
        $templateId = $pdf->importPage($i);
        $size       = $pdf->getTemplateSize($templateId);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);

        if ($i === $pageCount) {
            // إعادة رسم جميع التوقيعات السابقة في grid
            foreach ($prevSigs as $idx => $prev) {
                $pSigFile = __DIR__ . '/../../uploads/' . $prev['signature_image'];
                if (!file_exists($pSigFile)) continue;

                $pCol = $idx % $maxPerRow;
                $pRow = (int)floor($idx / $maxPerRow);
                $pX   = $size['width'] - $rightMgn - $blockW - ($pCol * ($blockW + $hGap));
                $pY   = $size['height'] - $bottomMgn - $blockH - ($pRow * ($blockH + $vGap));
                $pX   = max(5, min($pX, $size['width'] - $blockW - 5));
                $pY   = max(5, $pY);

                $pDate = !empty($prev['signed_at'])
                       ? date('Y/m/d H:i', strtotime($prev['signed_at']))
                       : date('Y/m/d H:i');

                $stamp = createSignatureStamp($pSigFile, $prev['emp_name'] ?? '', $prev['emp_dept'] ?? '', $pDate, $blockW);
                $isTemp = ($stamp !== $pSigFile);
                $pdf->Image($stamp, $pX, $pY, $blockW, 0);
                if ($isTemp && file_exists($stamp)) @unlink($stamp);
            }

            // التوقيع الجديد في grid
            $posX = $size['width'] - $rightMgn - $blockW - ($newCol * ($blockW + $hGap));
            $posY = $size['height'] - $bottomMgn - $blockH - ($newRow * ($blockH + $vGap));
            $posX = max(5, min($posX, $size['width'] - $blockW - 5));
            $posY = max(5, $posY);

            $signDate  = date('Y/m/d  H:i');
            $stampFile = createSignatureStamp($sigFile, $emp['full_name'] ?? '', $emp['department'] ?? '', $signDate, $blockW);
            $isStampTemp = ($stampFile !== $sigFile);
            $pdf->Image($stampFile, $posX, $posY, $blockW, 0);
            if ($isStampTemp && file_exists($stampFile)) @unlink($stampFile);
        }
    }

    $pdf->Output('F', $outputFile);

    // تحديث file_path ليشير إلى النسخة الموقّعة
    $signedRelPath = rtrim(dirname($doc['file_path']), '/') . '/signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf';
    $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET file_path = ?, file_name = ? WHERE id = ?")
        ->execute([$signedRelPath, 'signed_' . $doc['id'] . '_v' . $doc['version'] . '.pdf', $document_id]);

    // حفظ سجل التوقيع (باستخدام إحداثيات grid)
    $sigStmt = $pdo->prepare("SELECT id FROM " . TBL_SIGNATURES . " WHERE document_id = ? AND employee_id = ? AND status = 'pending'");
    $sigStmt->execute([$document_id, $employee_id]);
    $existing = $sigStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $updStmt = $pdo->prepare("UPDATE " . TBL_SIGNATURES . " SET signature_image = ?, pos_x = ?, pos_y = ?, page_number = ?, width = ?, height = ?, sign_type = ?, status = 'signed', signed_at = NOW() WHERE id = ?");
        $updStmt->execute([$emp['signature_image'], $posX, $posY, $pageCount, $blockW, $blockH, $sign_type, $existing['id']]);
        $sigId = $existing['id'];
    } else {
        $insStmt = $pdo->prepare("INSERT INTO " . TBL_SIGNATURES . " (document_id, employee_id, signature_image, pos_x, pos_y, page_number, width, height, sign_type, status, signed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'signed', NOW())");
        $insStmt->execute([$document_id, $employee_id, $emp['signature_image'], $posX, $posY, $pageCount, $blockW, $blockH, $sign_type]);
        $sigId = (int) $pdo->lastInsertId();
    }

    // تحديث سجل الاعتماد في سياسة الاعتماد
    if ($docWorkflowId > 0) {
        $pdo->prepare(
            "UPDATE " . TBL_DOC_APPROVALS . "
             SET status = 'approved', signed_at = NOW(), signature_id = ?
             WHERE document_id = ? AND employee_id = ? AND workflow_id = ? AND status = 'pending'"
        )->execute([$sigId, $document_id, $employee_id, $docWorkflowId]);

        // إذا اكتملت جميع مراحل الاعتماد → تحديث حالة الوثيقة إلى approved
        $remainStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM " . TBL_DOC_APPROVALS . " WHERE document_id = ? AND workflow_id = ? AND status = 'pending'"
        );
        $remainStmt->execute([$document_id, $docWorkflowId]);
        if ((int)$remainStmt->fetchColumn() === 0) {
            $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET status = 'approved' WHERE id = ?")->execute([$document_id]);
        }
    }

    $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET version = version + 1 WHERE id = ?")->execute([$document_id]);

    log_action($pdo, 'sign', 'وثيقة', $document_id, [], ['signed_by' => $employee_id, 'signature_file' => $outputFile]);

    echo json_encode([
        'success' => true,
        'message' => __('sig_success'),
        'file'    => $doc['file_path']
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => __('sig_error') . ': ' . $e->getMessage()]);
}
