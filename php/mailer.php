<?php
/**
 * mailer.php — Reusable email sender using PHPMailers
 * Usage:
 *   include 'mailer.php';
 *   sendMail('student@email.com', 'Subject here', '<p>HTML body here</p>');
 *
 * Returns true on success, error string on failure.
 * Failures are logged to php/mail_errors.log — never crash the main flow.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool|string {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->Timeout    = 30;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Sender
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        // Recipient
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = emailWrapper($subject, $htmlBody);
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $htmlBody));

        $mail->send();
        return true;

    } catch (Exception $e) {
        $error = date('Y-m-d H:i:s') . " | To: {$toEmail} | {$mail->ErrorInfo}\n";
        file_put_contents(__DIR__ . '/mail_errors.log', $error, FILE_APPEND);
        return $mail->ErrorInfo;
    }
}

// ── Consistent HTML email wrapper ───────────────────────────────
function emailWrapper(string $title, string $content): string {
    return "
<!DOCTYPE html>
<html>
<head>
  <meta charset='UTF-8'>
  <style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #eef1f4; margin: 0; padding: 30px 0; }
    .container { max-width: 560px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(90deg, #0f2027, #203a43); padding: 28px 32px; }
    .header h1 { color: white; margin: 0; font-size: 20px; letter-spacing: -0.5px; }
    .header p  { color: rgba(255,255,255,0.45); margin: 4px 0 0; font-size: 12px; letter-spacing: 1px; }
    .body { padding: 28px 32px; color: #0f2027; font-size: 15px; line-height: 1.6; }
    .body p { margin: 0 0 14px; }
    .info-box { background: #f8fafc; border-left: 4px solid #0077b6; border-radius: 0 8px 8px 0; padding: 14px 18px; margin: 16px 0; font-size: 14px; }
    .info-box strong { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 4px; }
    .amount { font-size: 26px; font-weight: 700; color: #0f2027; }
    .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    .badge-green  { background: #d1fae5; color: #065f46; }
    .badge-blue   { background: #e0f2fe; color: #0369a1; }
    .badge-amber  { background: #fef3c7; color: #92400e; }
    .badge-red    { background: #fee2e2; color: #991b1b; }
    .footer { background: #f8fafc; padding: 18px 32px; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
  </style>
</head>
<body>
  <div class='container'>
    <div class='header'>
      <h1>CATMIS</h1>
      <p>CCS PORTAL · AUTOMATED NOTIFICATION</p>
    </div>
    <div class='body'>
      {$content}
    </div>
    <div class='footer'>
      This is an automated message from the CATMIS portal. Please do not reply to this email.
    </div>
  </div>
</body>
</html>";
}

// ── Pre-built email templates ────────────────────────────────────

function mailPaymentPosted(string $toEmail, string $toName, string $orNumber, float $amount, string $method, float $remainingBalance): bool|string {
    $subject = "Payment Received = OR# {$orNumber}";
    $paid    = $remainingBalance <= 0;
    $body = "
        <p>Dear <strong>{$toName}</strong>,</p>
        <p>We have received your payment. Here are the details:</p>
        <div class='info-box'>
            <strong>OR Number</strong>{$orNumber}
        </div>
        <div class='info-box'>
            <strong>Amount Paid</strong>
            <span class='amount'>₱" . number_format($amount, 2) . "</span>
        </div>
        <div class='info-box'>
            <strong>Payment Method</strong>{$method}
        </div>
        <div class='info-box'>
            <strong>Remaining Balance</strong>
            <span class='amount' style='color:" . ($paid ? '#198754' : '#dc2626') . "'>₱" . number_format($remainingBalance, 2) . "</span>
            &nbsp;<span class='badge " . ($paid ? 'badge-green' : 'badge-amber') . "'>" . ($paid ? 'Fully Paid' : 'Balance Due') . "</span>
        </div>
        <p>Please keep your OR number for your records. If you have questions, contact the school finance office.</p>
    ";
    return sendMail($toEmail, $toName, $subject, $body);
}

function mailAccountCreated(string $toEmail, string $toName, string $studentNumber, string $password): bool|string {
    $subject = "Your CATMIS Account Has Been Created";
    $body = "
        <p>Dear <strong>{$toName}</strong>,</p>
        <p>An account has been created for you on the CATMIS portal. You can now log in to view your tuition balance and payment history.</p>
        <div class='info-box'>
            <strong>Student ID (Login)</strong>{$studentNumber}
        </div>
        <div class='info-box'>
            <strong>Temporary Password</strong>
            <span style='font-family:monospace;font-size:16px;letter-spacing:2px;'>{$password}</span>
        </div>
        <p>Please log in and change your password as soon as possible. Do not share your credentials with anyone.</p>
        <p><span class='badge badge-blue'>catmis.yourschool.edu.ph</span></p>
    ";
    return sendMail($toEmail, $toName, $subject, $body);
}

function mailEditRequestSubmitted(string $adminEmail, string $adminName, string $studentName, string $fieldName, string $newValue): bool|string {
    $subject = "Edit Request Submitted — {$studentName}";
    $body = "
        <p>Dear <strong>{$adminName}</strong>,</p>
        <p>A student has submitted an information edit request that requires your review.</p>
        <div class='info-box'>
            <strong>Student</strong>{$studentName}
        </div>
        <div class='info-box'>
            <strong>Field Requested to Change</strong>" . ucwords(str_replace('_', ' ', $fieldName)) . "
        </div>
        <div class='info-box'>
            <strong>Requested New Value</strong>{$newValue}
        </div>
        <p>Please log in to the admin panel to review and approve or reject this request.</p>
    ";
    return sendMail($adminEmail, $adminName, $subject, $body);
}

function mailEditRequestReviewed(string $toEmail, string $toName, string $fieldName, string $newValue, string $status, string $rejectNote = ''): bool|string {
    $approved = $status === 'approved';
    $subject  = "Edit Request " . ($approved ? 'Approved' : 'Rejected') . " — " . ucwords(str_replace('_', ' ', $fieldName));
    $body = "
        <p>Dear <strong>{$toName}</strong>,</p>
        <p>Your request to update your <strong>" . ucwords(str_replace('_', ' ', $fieldName)) . "</strong> has been reviewed.</p>
        <div class='info-box'>
            <strong>Status</strong>
            <span class='badge " . ($approved ? 'badge-green' : 'badge-red') . "'>" . ucfirst($status) . "</span>
        </div>
        <div class='info-box'>
            <strong>Requested Value</strong>{$newValue}
        </div>"
        . ($rejectNote ? "<div class='info-box'><strong>Note from Admin</strong>{$rejectNote}</div>" : '') .
        "<p>" . ($approved
            ? "Your information has been updated in the system."
            : "If you believe this is an error, please contact the school office.") . "</p>
    ";
    return sendMail($toEmail, $toName, $subject, $body);
}
?>