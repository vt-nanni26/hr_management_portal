<?php
// test_password.php
$password = '1234567';
$hash = '$2y$10$cIQkcNhVDo0ay86fJDytku/gQNRMym6YT5WvOmYnRtiHq0B6ysGDG';

echo "Testing password: $password\n";
echo "Hash: $hash\n\n";

if (password_verify($password, $hash)) {
    echo "SUCCESS: Password '1234567' works with this hash!\n";
} else {
    echo "FAILED: Password verification failed.\n";
}

// Test wrong password
$wrong_password = 'wrong123';
if (password_verify($wrong_password, $hash)) {
    echo "ERROR: Wrong password should not work!\n";
} else {
    echo "CORRECT: Wrong password rejected.\n";
}
?>