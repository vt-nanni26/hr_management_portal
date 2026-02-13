<?php
// send_email.php - Simple email helper function

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// CORRECTED: Load PHPMailer files from same directory
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

/**
 * Send Email Function
 *
 * @param string $to      Receiver email
 * @param string $subject Email subject
 * @param string $body    HTML email body
 * @param string $altBody Plain text email body (optional)
 * @return bool
 */
function sendEmail($to, $subject, $body, $altBody = '')
{
    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'svmedicaps@gmail.com';  // Your Gmail
        $mail->Password   = 'idsehhtvafsciitg';      // Your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Email headers
        $mail->setFrom('svmedicaps@gmail.com', 'HR Management Portal');
        $mail->addAddress($to);

        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        if (!empty($altBody)) {
            $mail->AltBody = $altBody;
        }

        return $mail->send();

    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Test function
function sendTestEmail($to = 'hr@hrportal.com') {
    $subject = "Test Email from HR Portal";
    $body = "<h1>Test Email</h1><p>This is a test email to verify email configuration.</p>";
    
    return sendEmail($to, $subject, $body);
}
?>