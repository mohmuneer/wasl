<?php
session_start();
require __DIR__ . "/../../../config/db.php";
require __DIR__ . "/functions.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'add_department') {
            $name = trim($_POST['department_name']);
            $region_id = filter_var($_POST['region_id'], FILTER_SANITIZE_NUMBER_INT);

            if (!empty($name) && !empty($region_id)) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_name = ? AND region_id = ?");
                $check->execute([$name, $region_id]);
                if ($check->fetchColumn() > 0) {
                    $_SESSION['swal_type'] = 'error';
                    $_SESSION['swal_title'] = 'تنبيه';
                    $_SESSION['swal_text'] = 'اسم القسم موجود مسبقاً في هذه المنطقة';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO departments (department_name, region_id) VALUES (?, ?)");
                    $stmt->execute([$name, $region_id]);
                    log_action($pdo, 'create', 'قسم', $pdo->lastInsertId(), [], ['department_name' => $name, 'region_id' => $region_id]);
                    $_SESSION['swal_type'] = 'success';
                    $_SESSION['swal_title'] = 'تم!';
                    $_SESSION['swal_text'] = 'تم إضافة القسم بنجاح';
                }
            } else {
                $_SESSION['swal_type'] = 'warning';
                $_SESSION['swal_title'] = 'تنبيه';
                $_SESSION['swal_text'] = 'الرجاء تعبئة جميع الحقول';
            }

        } elseif ($action === 'edit_department') {
            $id = filter_var($_POST['department_id'], FILTER_SANITIZE_NUMBER_INT);
            $name = trim($_POST['department_name']);
            $region_id = filter_var($_POST['region_id'] ?? 0, FILTER_SANITIZE_NUMBER_INT);

            if (!empty($name)) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_name=? AND region_id=? AND id!=?");
                $chk->execute([$name, $region_id, $id]);
                if ($chk->fetchColumn() > 0) {
                    $_SESSION['swal_type'] = 'warning';
                    $_SESSION['swal_title'] = 'تنبيه';
                    $_SESSION['swal_text'] = 'اسم القسم موجود مسبقاً في هذه المنطقة';
                } else {
                    $stmt = $pdo->prepare("UPDATE departments SET department_name = ?, region_id = ? WHERE id = ?");
                    $stmt->execute([$name, $region_id, $id]);
                    log_action($pdo, 'update', 'قسم', $id, [], ['department_name' => $name]);
                    $_SESSION['swal_type'] = 'success';
                    $_SESSION['swal_title'] = 'تم!';
                    $_SESSION['swal_text'] = 'تم تحديث القسم بنجاح';
                }
            } else {
                $_SESSION['swal_type'] = 'warning';
                $_SESSION['swal_title'] = 'تنبيه';
                $_SESSION['swal_text'] = 'اسم القسم مطلوب';
            }

        } elseif ($action === 'delete_department') {
            $id = filter_var($_POST['department_id'], FILTER_SANITIZE_NUMBER_INT);
            $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
            $stmt->execute([$id]);
            log_action($pdo, 'delete', 'قسم', $id, [], []);
            $_SESSION['swal_type'] = 'success';
            $_SESSION['swal_title'] = 'تم!';
            $_SESSION['swal_text'] = 'تم حذف القسم وجميع الوظائف المرتبطة به';

        } elseif ($action === 'add_job') {
            $department_id = filter_var($_POST['department_id'], FILTER_SANITIZE_NUMBER_INT);
            $job_title = trim($_POST['job_title']);
            $job_description = trim($_POST['job_description'] ?? '');

            if (!empty($department_id) && !empty($job_title)) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM " . TBL_JOB_POSITIONS . " WHERE department_id=? AND job_title=?");
                $chk->execute([$department_id, $job_title]);
                if ($chk->fetchColumn() > 0) {
                    $_SESSION['swal_type'] = 'warning';
                    $_SESSION['swal_title'] = 'تنبيه';
                    $_SESSION['swal_text'] = 'هذا المسمى الوظيفي موجود مسبقاً في هذا القسم';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO " . TBL_JOB_POSITIONS . " (department_id, job_title, job_description) VALUES (?, ?, ?)");
                    $stmt->execute([$department_id, $job_title, $job_description ?: null]);
                    log_action($pdo, 'create', 'وظيفة', $pdo->lastInsertId(), [], [
                        'department_id' => $department_id, 'job_title' => $job_title
                    ]);
                    $_SESSION['swal_type'] = 'success';
                    $_SESSION['swal_title'] = 'تم!';
                    $_SESSION['swal_text'] = 'تم إضافة الوظيفة بنجاح';
                }
            } else {
                $_SESSION['swal_type'] = 'warning';
                $_SESSION['swal_title'] = 'تنبيه';
                $_SESSION['swal_text'] = 'المسمى الوظيفي مطلوب';
            }

        } elseif ($action === 'edit_job') {
            $id = filter_var($_POST['job_id'], FILTER_SANITIZE_NUMBER_INT);
            $job_title = trim($_POST['job_title']);
            $job_description = trim($_POST['job_description'] ?? '');

            if (!empty($job_title)) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM " . TBL_JOB_POSITIONS . " WHERE department_id=(SELECT department_id FROM " . TBL_JOB_POSITIONS . " WHERE id=?) AND job_title=? AND id!=?");
                $chk->execute([$id, $job_title, $id]);
                if ($chk->fetchColumn() > 0) {
                    $_SESSION['swal_type'] = 'warning';
                    $_SESSION['swal_title'] = 'تنبيه';
                    $_SESSION['swal_text'] = 'هذا المسمى الوظيفي موجود مسبقاً في هذا القسم';
                } else {
                    $stmt = $pdo->prepare("UPDATE " . TBL_JOB_POSITIONS . " SET job_title = ?, job_description = ? WHERE id = ?");
                    $stmt->execute([$job_title, $job_description ?: null, $id]);
                    log_action($pdo, 'update', 'وظيفة', $id, [], ['job_title' => $job_title]);
                    $_SESSION['swal_type'] = 'success';
                    $_SESSION['swal_title'] = 'تم!';
                    $_SESSION['swal_text'] = 'تم تحديث الوظيفة بنجاح';
                }
            } else {
                $_SESSION['swal_type'] = 'warning';
                $_SESSION['swal_title'] = 'تنبيه';
                $_SESSION['swal_text'] = 'المسمى الوظيفي مطلوب';
            }

        } elseif ($action === 'delete_job') {
            $id = filter_var($_POST['job_id'], FILTER_SANITIZE_NUMBER_INT);
            $stmt = $pdo->prepare("DELETE FROM " . TBL_JOB_POSITIONS . " WHERE id = ?");
            $stmt->execute([$id]);
            log_action($pdo, 'delete', 'وظيفة', $id, [], []);
            $_SESSION['swal_type'] = 'success';
            $_SESSION['swal_title'] = 'تم!';
            $_SESSION['swal_text'] = 'تم حذف الوظيفة بنجاح';
        }
    } catch (PDOException $e) {
        $_SESSION['swal_type'] = 'error';
        $_SESSION['swal_title'] = 'خطأ';
        $_SESSION['swal_text'] = 'حدث خطأ: ' . $e->getMessage();
    }

    $_SESSION['swal_icon'] = $_SESSION['swal_type'] ?? 'info';
    $_SESSION['success_message'] = $_SESSION['swal_text'] ?? '';
    echo "<script>
        sessionStorage.setItem('swal_icon', '" . ($_SESSION['swal_icon'] ?? 'info') . "');
        sessionStorage.setItem('swal_title', '" . ($_SESSION['swal_title'] ?? '') . "');
        sessionStorage.setItem('swal_text', '" . ($_SESSION['swal_text'] ?? '') . "');
        window.location.href = '../forms/add-jobs.php';
    </script>";
    exit;
}
