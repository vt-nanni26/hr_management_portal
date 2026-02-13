<?php
// get_hash.php
$password = '1234567';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: $password\n";
echo "Hash: $hash\n\n";

echo "SQL Query:\n";
echo "UPDATE users SET password_hash = '$hash' WHERE id > 0;";
?>