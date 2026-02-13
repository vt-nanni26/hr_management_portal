<?php
echo "<h3>Downloading and Setting Up FPDF...</h3>";

// Create directories
$base_dir = __DIR__ . '/fpdf/';
$font_dir = $base_dir . 'font/';

if (!is_dir($base_dir)) {
    mkdir($base_dir, 0777, true);
    echo "Created: fpdf/<br>";
}

if (!is_dir($font_dir)) {
    mkdir($font_dir, 0777, true);
    echo "Created: fpdf/font/<br>";
}

// Files to download
$files = [
    'fpdf.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/fpdf.php',
    'font/courier.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/courier.php',
    'font/helvetica.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/helvetica.php',
    'font/helveticab.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/helveticab.php',
    'font/helveticai.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/helveticai.php',
    'font/helveticabi.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/helveticabi.php',
    'font/times.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/times.php',
    'font/timesb.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/timesb.php',
    'font/timesi.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/timesi.php',
    'font/timesbi.php' => 'https://raw.githubusercontent.com/Setasign/FPDF/master/font/timesbi.php',
];

// Download each file
foreach ($files as $local => $url) {
    $content = @file_get_contents($url);
    if ($content) {
        file_put_contents($base_dir . $local, $content);
        echo "✓ Downloaded: $local<br>";
    } else {
        echo "✗ Failed: $local<br>";
    }
}

echo "<hr><h3>Setup Complete!</h3>";
echo "<p>FPDF is now installed at: <code>$base_dir</code></p>";
echo '<p><a href="test_fpdf.php">Test FPDF</a></p>';

// Create test file
$test_content = '<?php
require("fpdf/fpdf.php");

$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetFont("Arial","B",16);
$pdf->Cell(40,10,"FPDF is working!");
$pdf->Output("I", "test.pdf");
?>';

file_put_contents(__DIR__ . '/test_fpdf.php', $test_content);
echo "<p>Test file created: test_fpdf.php</p>";
?>