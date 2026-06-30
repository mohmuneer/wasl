<?php
// هذه الصفحة تُعيد التوجيه لصفحة الموظفين — تاب مقدمو الطلبات مع فتح المودال تلقائياً
header("Location: ../tables/show-employees.php?open_req=1#req");
exit;
