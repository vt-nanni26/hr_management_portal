<?php
// logout.php
session_start();
session_destroy();
header("Location: login_emp.php");
exit();
?>