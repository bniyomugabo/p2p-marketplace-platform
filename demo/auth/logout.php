<?php
// auth/logout.php
session_name('demo');
session_start();
session_destroy();
header('Location: ./signin.php');
exit();
?>