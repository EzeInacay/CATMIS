<?php
session_start();

// If they already accepted T&C this session, redirect to login
if (!empty($_SESSION['terms_accepted'])) {
    $redirect = $_GET['redirect'] ?? 'login.php';
    header('Location: ' . $redirect);
    exit;
}

// Handle acceptance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept'])) {
    $_SESSION['terms_accepted'] = true;
    $redirect = $_POST['redirect'] ?? 'login.php';
    // Sanitize redirect — only allow relative paths within the app
    $redirect = preg_replace('/[^a-zA-Z0-9_.?=&\/]/', '', $redirect);
    header('Location: ' . $redirect);
    exit;
}

$redirect = $_GET['redirect'] ?? 'login.php';
$role     = $_GET['role']     ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Terms & Conditions | CATMIS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'DM Sans', sans-serif;
    background: linear-gradient(160deg, #0f2027 0%, #203a43 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
}

/* Background grid */
body::before {
    content: '';
    position: fixed; inset: 0;
    background-image:
        linear-gradient(rgba(0,180,216,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,180,216,0.04) 1px, transparent 1px);
    background-size: 48px 48px;
    pointer-events: none;
}

.container {
    position: relative; z-index: 1;
    background: white;
    border-radius: 18px;
    width: 100%; max-width: 720px;
    box-shadow: 0 24px 80px rgba(0,0,0,0.3);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 90vh;
}

.header {
    background: linear-gradient(90deg, #0f2027, #203a43);
    color: white;
    padding: 28px 36px 24px;
    flex-shrink: 0;
}
.header .logo { font-family: 'Playfair Display', serif; font-size: 28px; letter-spacing: -0.5px; margin-bottom: 4px; }
.header .subtitle { font-size: 13px; color: rgba(255,255,255,0.5); letter-spacing: 0.5px; }
.header h2 { font-size: 18px; font-weight: 500; margin-top: 16px; color: rgba(255,255,255,0.85); }

.body {
    flex: 1;
    overflow-y: auto;
    padding: 28px 36px;
    font-size: 14px;
    color: #374151;
    line-height: 1.75;
}

.section { margin-bottom: 24px; }
.section h3 {
    font-size: 14px;
    font-weight: 700;
    color: #0f2027;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    padding-left: 10px;
    border-left: 3px solid #0077b6;
}
.section p { color: #4b5563; margin-bottom: 8px; }
.section ul { padding-left: 20px; color: #4b5563; }
.section ul li { margin-bottom: 5px; }

.scroll-hint {
    text-align: center;
    font-size: 12px;
    color: #94a3b8;
    padding: 8px 0 4px;
    flex-shrink: 0;
}
.scroll-hint.hidden { display: none; }

.footer {
    border-top: 1px solid #f1f5f9;
    padding: 20px 36px;
    flex-shrink: 0;
    background: #f8fafc;
}
.checkbox-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}
.checkbox-row input[type=checkbox] {
    width: 18px; height: 18px;
    margin-top: 2px; flex-shrink: 0;
    accent-color: #0f2027; cursor: pointer;
}
.checkbox-row label { font-size: 13px; color: #374151; cursor: pointer; line-height: 1.5; }

.btn-row { display: flex; gap: 10px; }
.btn-accept {
    flex: 1; padding: 13px;
    background: linear-gradient(135deg, #0f2027, #203a43);
    color: white; border: none; border-radius: 9px;
    font-size: 14px; font-weight: 600;
    font-family: 'DM Sans', sans-serif;
    cursor: pointer; transition: all 0.2s;
}
.btn-accept:hover:not(:disabled) { background: linear-gradient(135deg, #1a3a35, #0f2027); transform: translateY(-1px); }
.btn-accept:disabled { background: #cbd5e1; cursor: not-allowed; transform: none; }
.btn-decline {
    padding: 13px 24px;
    background: white; color: #64748b;
    border: 1.5px solid #cbd5e1; border-radius: 9px;
    font-size: 14px; font-family: 'DM Sans', sans-serif;
    cursor: pointer; transition: all 0.2s;
}
.btn-decline:hover { background: #f8fafc; border-color: #94a3b8; }

@media (max-width: 540px) {
    .header { padding: 22px 20px 18px; }
    .header .logo { font-size: 22px; }
    .body { padding: 20px; }
    .footer { padding: 16px 20px; }
    .btn-row { flex-direction: column; }
    .btn-decline { text-align: center; }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">CATMIS</div>
        <div class="subtitle">Centralized Assessment and Tuition Management Information System</div>
        <h2>Terms &amp; Conditions of Use</h2>
    </div>

    <div class="scroll-hint" id="scrollHint">↓ Please scroll down to read all terms</div>

    <div class="body" id="termsBody">

        <div class="section">
            <h3>1. Acceptance of Terms</h3>
            <p>By accessing and using the CATMIS portal, you agree to be bound by these Terms and Conditions. If you do not agree to these terms, you must not use this system. These terms apply to all users including students, teachers, and administrators.</p>
        </div>

        <div class="section">
            <h3>2. System Purpose</h3>
            <p>CATMIS is a school-managed information system designed to facilitate tuition assessment, fee management, payment tracking, and academic records for the College of Computer Studies (CCS). The system is provided exclusively for official school-related purposes.</p>
        </div>

        <div class="section">
            <h3>3. User Accounts and Security</h3>
            <ul>
                <li>You are responsible for maintaining the confidentiality of your login credentials.</li>
                <li>You must not share your account or password with any other person.</li>
                <li>You must notify the administrator immediately if you suspect unauthorized use of your account.</li>
                <li>The school reserves the right to suspend or terminate accounts found in violation of these terms.</li>
            </ul>
        </div>

        <div class="section">
            <h3>4. Acceptable Use</h3>
            <p>You agree to use CATMIS only for lawful purposes and in a manner that does not infringe the rights of others. You must not:</p>
            <ul>
                <li>Attempt to gain unauthorized access to any part of the system.</li>
                <li>Upload or submit false, misleading, or fraudulent information.</li>
                <li>Interfere with the proper functioning of the system.</li>
                <li>Use the system to harass, threaten, or harm any other user.</li>
                <li>Attempt to reverse engineer, modify, or exploit any part of the system.</li>
            </ul>
        </div>

        <div class="section">
            <h3>5. Data Privacy</h3>
            <p>The school collects and processes personal information in accordance with the Republic Act No. 10173 (Data Privacy Act of 2012). Your personal data — including your name, contact information, and financial records — is used solely for academic and administrative purposes within the school.</p>
            <ul>
                <li>Your data will not be sold or disclosed to unauthorized third parties.</li>
                <li>You may request access to or correction of your personal data through the school administration.</li>
                <li>Payment records and tuition assessments are retained as required by school policy.</li>
            </ul>
        </div>

        <div class="section">
            <h3>6. Payment Proof Submissions</h3>
            <p>When submitting payment proofs through this system, you certify that all information provided is accurate and truthful. Submitting falsified payment screenshots or forged transaction references constitutes fraud and may result in disciplinary action and legal consequences.</p>
        </div>

        <div class="section">
            <h3>7. System Availability</h3>
            <p>The school makes reasonable efforts to ensure CATMIS is available at all times, but does not guarantee uninterrupted access. Scheduled maintenance, technical issues, or unforeseen circumstances may result in temporary downtime. The school is not liable for any inconvenience caused by system unavailability.</p>
        </div>

        <div class="section">
            <h3>8. Modifications to Terms</h3>
            <p>The school reserves the right to modify these Terms and Conditions at any time. Continued use of CATMIS after changes are posted constitutes acceptance of the revised terms. Users will be prompted to re-accept when terms are significantly updated.</p>
        </div>

        <div class="section">
            <h3>9. Consequences of Violation</h3>
            <p>Violation of these terms may result in:</p>
            <ul>
                <li>Immediate suspension or permanent termination of your CATMIS account.</li>
                <li>Referral to the school's Student Disciplinary Committee.</li>
                <li>Legal action in cases involving fraud, data tampering, or unauthorized access.</li>
            </ul>
        </div>

        <div class="section">
            <h3>10. Contact</h3>
            <p>For questions or concerns regarding these terms or your account, please contact the CCS Administrator or visit the school's finance office.</p>
        </div>

        <p style="font-size:12px;color:#94a3b8;margin-top:24px;padding-top:16px;border-top:1px solid #f1f5f9;">
            Last updated: <?= date('F Y') ?> &nbsp;·&nbsp; CATMIS &nbsp;·&nbsp; College of Computer Studies
        </p>
    </div>

    <div class="footer">
        <form method="POST" action="terms.php" id="termsForm">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
            <?php if ($role): ?>
            <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">
            <?php endif; ?>

            <div class="checkbox-row">
                <input type="checkbox" id="agreeCheck" name="accept" onchange="toggleBtn()">
                <label for="agreeCheck">I have read and agree to the CATMIS Terms and Conditions of Use, and I consent to the collection and processing of my personal data as described above.</label>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn-accept" id="acceptBtn" disabled>
                    ✓ I Agree — Continue to Login
                </button>
                <button type="button" class="btn-decline" onclick="window.location.href='index.php'">
                    Decline
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleBtn() {
    document.getElementById('acceptBtn').disabled = !document.getElementById('agreeCheck').checked;
}

// Hide scroll hint once user scrolls near bottom
const body = document.getElementById('termsBody');
const hint = document.getElementById('scrollHint');
body.addEventListener('scroll', () => {
    const nearBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 60;
    if (nearBottom) hint.classList.add('hidden');
});
</script>
</body>
</html>
