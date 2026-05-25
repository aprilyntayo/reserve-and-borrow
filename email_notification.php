<?php
/**
 * EMAIL NOTIFICATION SYSTEM - Gmail SMTP
 */

// Load PHPMailer — checks multiple paths to be safe
foreach ([
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    'vendor/autoload.php',
] as $_path) {
    if (file_exists($_path)) { require_once $_path; break; }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ─── CREDENTIALS — only change here ────────────────────────────────────────
// Paste your 16-char Gmail App Password below (no spaces).
// Generate one at: https://myaccount.google.com/apppasswords
define('ASSETEASE_SMTP_USER', 'aprilyntayo@gmail.com');
define('ASSETEASE_SMTP_PASS', 'wwes zxfx ghgl ptsm'); // ← replace this
define('ASSETEASE_FROM_NAME', 'AssetEase Reserve and Borrow System');
// ───────────────────────────────────────────────────────────────────────────

function sendEmail($toEmail, $subject, $htmlBody) {
    if (empty(trim($toEmail))) {
        error_log('[AssetEase] Skipped — empty recipient. Subject: ' . $subject);
        return false;
    }

    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log('[AssetEase] FATAL: PHPMailer not found. Run: composer require phpmailer/phpmailer');
        return false;
    }

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'aprilyntayo@gmail.com';
        $mail->Password   = 'wwes zxfx ghgl ptsm';   // ← uses the constant above
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom('aprilyntayo10@gmail.com', 'AssetEAse: Reserve and Borrow System');
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody
        ));

        $mail->send();
        error_log('[AssetEase] Email sent OK → ' . $toEmail);
        return true;

    } catch (Exception $e) {
        error_log('[AssetEase] Email FAILED → ' . $toEmail
            . ' | ' . $e->getMessage()
            . ' | ' . (isset($mail) ? $mail->ErrorInfo : ''));
        return false;
    }
}

function sendApprovalEmail($userName, $userEmail, $resourceName, $resourceType = 'room') {
    $subject = "Reservation Approved - AssetEase";
    
    $icon = ($resourceType === 'equipment') ? '📦' : '🚪';
    
    $htmlBody = "
    <html>
    <body style='font-family:Arial, sans-serif;'>
        <div style='max-width:600px;margin:auto;'>
            <div style='background:#28a745;color:white;padding:20px;text-align:center;border-radius:5px;'>
                <h2>$icon Reservation Approved</h2>
            </div>
            <div style='padding:20px;background:#f9f9f9;border-radius:5px;margin-top:10px;'>
                <p>Hello <strong>$userName</strong>,</p>
                <p>Your reservation for <strong>$resourceName</strong> has been <strong>APPROVED</strong>.</p>
                <p>Please ensure you arrive on time and follow all guidelines.</p>
                <p style='margin-top:20px;color:#999;font-size:12px;'>
                    Thank you,<br>AssetEase Reserve and Borrow System
                </p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendEmail($userEmail, $subject, $htmlBody);
}

function sendRejectionEmail($userName, $userEmail, $resourceName, $reason = '') {
    $subject = "Reservation Rejected - AssetEase";
    
    $reasonText = $reason ? "<p><strong>Reason:</strong> $reason</p>" : '';
    
    $htmlBody = "
    <html>
    <body style='font-family:Arial, sans-serif;'>
        <div style='max-width:600px;margin:auto;'>
            <div style='background:#dc3545;color:white;padding:20px;text-align:center;border-radius:5px;'>
                <h2>❌ Reservation Rejected</h2>
            </div>
            <div style='padding:20px;background:#f9f9f9;border-radius:5px;margin-top:10px;'>
                <p>Hello <strong>$userName</strong>,</p>
                <p>Unfortunately, your reservation for <strong>$resourceName</strong> has been <strong>REJECTED</strong>.</p>
                $reasonText
                <p>Please contact the administrator for more information or try booking another time.</p>
                <p style='margin-top:20px;color:#999;font-size:12px;'>
                    Thank you,<br>AssetEase Reserve and Borrow System
                </p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendEmail($userEmail, $subject, $htmlBody);
}

function sendPendingNotificationEmail($userName, $userEmail, $resourceName, $resourceType, $dateRange, $time, $purpose) {
    $subject = "Booking Submitted - AssetEase";
    
    $resourceLabel = ($resourceType === 'equipment') ? 'Equipment' : 'Room';
    $icon = ($resourceType === 'equipment') ? '📦' : '🚪';
    
    $htmlBody = "
    <html>
    <body style='font-family:Arial, sans-serif;'>
        <div style='max-width:600px;margin:auto;'>
            <div style='background:#95122C;color:white;padding:20px;text-align:center;border-radius:5px;'>
                <h2>$icon Booking Submitted</h2>
            </div>
            <div style='padding:20px;background:#f9f9f9;border-radius:5px;margin-top:10px;'>
                <p>Hello <strong>$userName</strong>,</p>
                <p>Your $resourceLabel reservation has been submitted successfully and is awaiting admin approval.</p>
                <table style='width:100%;border-collapse:collapse;margin:20px 0;' border='1' cellpadding='10'>
                    <tr style='background:#f0f0f0;'>
                        <td><strong>$resourceLabel</strong></td>
                        <td>$resourceName</td>
                    </tr>
                    <tr>
                        <td><strong>Date</strong></td>
                        <td>$dateRange</td>
                    </tr>
                    <tr style='background:#f0f0f0;'>
                        <td><strong>Time</strong></td>
                        <td>$time</td>
                    </tr>
                    <tr>
                        <td><strong>Purpose</strong></td>
                        <td>$purpose</td>
                    </tr>
                </table>
                <p>You will receive an email notification once your request is reviewed.</p>
                <p style='margin-top:20px;color:#999;font-size:12px;'>
                    Thank you,<br>AssetEase Reserve and Borrow System
                </p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendEmail($userEmail, $subject, $htmlBody);
}

function sendReminderEmail($userName, $userEmail, $resourceName, $bookingDate) {
    $subject = "Booking Reminder - AssetEase";
    
    $htmlBody = "
    <html>
    <body style='font-family:Arial, sans-serif;'>
        <div style='max-width:600px;margin:auto;'>
            <div style='background:#ff9800;color:white;padding:20px;text-align:center;border-radius:5px;'>
                <h2>🔔 Booking Reminder</h2>
            </div>
            <div style='padding:20px;background:#f9f9f9;border-radius:5px;margin-top:10px;'>
                <p>Hello <strong>$userName</strong>,</p>
                <p>This is a reminder for your upcoming booking.</p>
                <table style='width:100%;border-collapse:collapse;margin:20px 0;' border='1' cellpadding='10'>
                    <tr>
                        <td><strong>Resource</strong></td>
                        <td>$resourceName</td>
                    </tr>
                    <tr style='background:#f0f0f0;'>
                        <td><strong>Date</strong></td>
                        <td>$bookingDate</td>
                    </tr>
                </table>
                <p>Please make sure you're prepared for your booking.</p>
                <p style='margin-top:20px;color:#999;font-size:12px;'>
                    Thank you,<br>AssetEase Reserve and Borrow System
                </p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendEmail($userEmail, $subject, $htmlBody);
}

function sendReturnReminderEmail($userName, $userEmail, $resourceName) {
    $subject = "Return Reminder - AssetEase";
    
    $htmlBody = "
    <html>
    <body style='font-family:Arial, sans-serif;'>
        <div style='max-width:600px;margin:auto;'>
            <div style='background:#17a2b8;color:white;padding:20px;text-align:center;border-radius:5px;'>
                <h2>📦 Return Reminder</h2>
            </div>
            <div style='padding:20px;background:#f9f9f9;border-radius:5px;margin-top:10px;'>
                <p>Hello <strong>$userName</strong>,</p>
                <p>Please return <strong>$resourceName</strong> by the scheduled end time.</p>
                <p>Thank you for using AssetEase!</p>
                <p style='margin-top:20px;color:#999;font-size:12px;'>
                    Thank you,<br>AssetEase Reserve and Borrow System
                </p>
            </div>
        </div>
    </body>
    </html>";
    
    return sendEmail($userEmail, $subject, $htmlBody);
}
?>
