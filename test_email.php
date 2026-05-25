<?php
/**
 * AssetEase — Email Diagnostic Test
 * 
 * HOW TO USE:
 * 1. Put this file in your project root (same folder as email_notification.php)
 * 2. Open it in your browser: http://localhost/your-project/test_email.php
 * 3. Read the output — it will tell you exactly what is wrong
 * 4. DELETE this file after testing (it contains your credentials)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>AssetEase Email Diagnostic</h2>";
echo "<pre style='background:#f4f4f4;padding:15px;border-radius:8px;font-size:14px;'>";

// ── Step 1: Check PHPMailer ───────────────────────────────────────────────
echo "STEP 1: Checking PHPMailer...\n";

$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    'vendor/autoload.php',
];

$autoloadFound = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        echo "  ✅ Found vendor/autoload.php at: $path\n";
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    echo "  ❌ ERROR: vendor/autoload.php NOT FOUND in any of these locations:\n";
    foreach ($autoloadPaths as $path) echo "       - $path\n";
    echo "\n  FIX: Open a terminal in your project folder and run:\n";
    echo "       composer require phpmailer/phpmailer\n";
    echo "\n  If you don't have Composer, download it from https://getcomposer.org\n";
    echo "</pre>";
    exit;
}

if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    echo "  ❌ ERROR: PHPMailer class not found even after autoload.\n";
    echo "  FIX: Run: composer require phpmailer/phpmailer\n";
    echo "</pre>";
    exit;
}
echo "  ✅ PHPMailer class found.\n\n";

// ── Step 2: Check Gmail App Password format ───────────────────────────────
echo "STEP 2: Checking App Password format...\n";

// ⚠️ UPDATE THESE TWO LINES with your actual credentials
$gmailUser     = 'aprilyntayo@gmail.com';  // sender Gmail
$appPassword   = 'wwes zxfx ghgl ptsm';          // 16-char App Password, no spaces
$recipientTest = 'aprilyntayo10@gmail.com';  // where to send the test (can be same address)

$passwordClean = str_replace(' ', '', $appPassword);
if (strlen($passwordClean) !== 16) {
    echo "  ⚠️  WARNING: App Password is " . strlen($passwordClean) . " characters (expected 16).\n";
    echo "  It may be wrong. Re-generate it from myaccount.google.com → Security → App passwords.\n\n";
} else {
    echo "  ✅ App Password is 16 characters.\n\n";
}

// ── Step 3: Try sending ───────────────────────────────────────────────────
echo "STEP 3: Attempting to send test email to $recipientTest ...\n";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

try {
    $mail = new PHPMailer(true);

    // Show full SMTP conversation in output
    $mail->SMTPDebug  = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function($str, $level) {
        $str = htmlspecialchars($str);
        $color = (strpos($str, 'SMTP ERROR') !== false || strpos($str, 'Failed') !== false)
                 ? 'red' : '#555';
        echo "<span style='color:$color'>$str</span>";
    };

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $gmailUser;
    $mail->Password   = $passwordClean;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'allow_self_signed'=> true,
        ],
    ];

    $mail->setFrom($gmailUser, 'AssetEase Test');
    $mail->addAddress($recipientTest);
    $mail->Subject = 'AssetEase Test Email — ' . date('Y-m-d H:i:s');
    $mail->isHTML(true);
    $mail->Body    = "<h2 style='color:#95122C;'>✅ AssetEase Email is Working!</h2>
                      <p>This test email confirms your Gmail SMTP setup is correct.</p>
                      <p>Sent at: " . date('Y-m-d H:i:s') . "</p>";
    $mail->AltBody = 'AssetEase Email Test — sent at ' . date('Y-m-d H:i:s');

    $mail->send();
    echo "\n\n  ✅ SUCCESS! Email sent to $recipientTest\n";
    echo "  Check your inbox (and Spam folder).\n";

} catch (Exception $e) {
    echo "\n\n  ❌ SEND FAILED: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "  PHPMailer Info: " . htmlspecialchars($mail->ErrorInfo) . "\n\n";

    echo "  COMMON FIXES:\n";
    echo "  • 'Username and Password not accepted' → Your App Password is wrong or expired.\n";
    echo "    Go to myaccount.google.com → Security → App passwords → generate a new one.\n\n";
    echo "  • 'Could not connect to SMTP host' → Your server blocks outbound port 587.\n";
    echo "    Ask your hosting provider to open port 587, or try port 465 with SMTPS.\n\n";
    echo "  • '2-Step Verification' errors → Enable 2FA on the Gmail account first,\n";
    echo "    then App passwords will appear under Security settings.\n";
}

echo "</pre>";
echo "<p style='color:#888;font-size:12px;'>⚠️ Delete this file from your server after testing.</p>";
?>
