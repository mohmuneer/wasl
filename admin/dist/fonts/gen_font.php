<?php
chdir("C:/xampp/htdocs/UltimatesolutionsCrm/admin/dist/fonts");
require "C:/xampp/htdocs/UltimatesolutionsCrm/vendor/setasign/fpdf/makefont/makefont.php";
MakeFont("C:/Windows/Fonts/tahoma.ttf", "cp1256", true, false);
echo "DONE";
?>
