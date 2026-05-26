<?php
// test_smtp.php
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->SMTPDebug = 2; // Enable verbose debug output
    $mail->isSMTP();
    $mail->Host       = 'ace-sedi.aiu.edu.my';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'acesediaiuedu';
    $mail->Password   = 'acesedi2024';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    
    $mail->setFrom('acesediaiuedu@ace-sedi.aiu.edu.my', 'Test');
    $mail->addAddress('chitkoko.ali@gmail.com', 'Test User');
    $mail->Subject = 'SMTP Test';
    $mail->Body    = 'This is a test email';
    
    $mail->send();
    echo 'Email sent successfully';
} catch (Exception $e) {
    echo "Error: {$e->getMessage()}";
    echo "SMTP Error: {$mail->ErrorInfo}";
}
?>
