<?php
// auth/2fa-verify.php - Complete version with verifyTwoFactorCode function

require_once __DIR__ . '/../config/autoload.php';
require_once __DIR__ . '/../config/TwoFactorAuth.php';

$session = SessionManager::getInstance();
$db = Database::getInstance();

// Check if 2FA is required
if (!$session->has('user_id') || !$session->get('2fa_required')) {
    header('Location: signin.php');
    exit;
}

$userId = $session->get('user_id');
$error = '';

/**
 * Verify 2FA code function
 */
function verifyTwoFactorCode($userId, $code)
{
    $db = Database::getInstance();

    // Get user's 2FA secret
    $stmt = $db->prepare("SELECT two_factor_secret, two_factor_backup_codes FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    // First try regular TOTP code
    if (!empty($user['two_factor_secret'])) {
        if (TwoFactorAuth::verifyCode($user['two_factor_secret'], $code)) {
            return true;
        }
    }

    // If not a valid TOTP code, try backup codes
    if (!empty($user['two_factor_backup_codes'])) {
        $backupCodes = json_decode($user['two_factor_backup_codes'], true);
        if (is_array($backupCodes)) {
            if (TwoFactorAuth::verifyBackupCode($code, $backupCodes)) {
                // Update remaining backup codes
                $stmt = $db->prepare("UPDATE users SET two_factor_backup_codes = ? WHERE id = ?");
                $stmt->execute([json_encode($backupCodes), $userId]);
                return true;
            }
        }
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'] ?? '';

    if (empty($code)) {
        $error = 'Please enter verification code';
    } else {
        if (verifyTwoFactorCode($userId, $code)) {
            $session->set('2fa_verified', true);
            $session->remove('2fa_required');

            // Clear any stored backup codes from session
            $session->remove('backup_codes');

            $redirect = $_SESSION['redirect_after_login'] ?? '../index.php?page=dashboard';
            unset($_SESSION['redirect_after_login']);

            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Invalid verification code';
        }
    }
}

// Get user email for display
$stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$userEmail = $user ? $user['email'] : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - SATI ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .verify-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
        }

        .verify-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .verify-body {
            padding: 30px;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .code-input {
            letter-spacing: 8px;
            font-size: 24px;
            font-weight: bold;
            text-align: center;
        }

        .backup-link {
            font-size: 14px;
            color: #6c757d;
            cursor: pointer;
            text-decoration: none;
        }

        .backup-link:hover {
            color: #667eea;
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="verify-card mx-auto">
                    <div class="verify-header">
                        <div class="logo">
                            <i class="fas fa-shield-alt fa-3x" style="color: #667eea;"></i>
                        </div>
                        <h4>Two-Factor Authentication</h4>
                        <p class="mb-0">Enter the verification code from your authenticator app</p>
                    </div>

                    <div class="verify-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <div class="text-center mb-4">
                            <i class="fas fa-mobile-alt fa-3x text-primary mb-3"></i>
                            <p class="text-muted">
                                Please enter the 6-digit code from your<br>
                                <strong>Google Authenticator</strong> or <strong>Authy</strong> app.
                            </p>
                            <small class="text-muted">
                                <i class="fas fa-envelope me-1"></i> Code sent to:
                                <?php echo htmlspecialchars($userEmail); ?>
                            </small>
                        </div>

                        <form method="POST" id="verifyForm">
                            <div class="mb-4">
                                <input type="text" class="form-control form-control-lg code-input" id="code" name="code"
                                    required autocomplete="off" pattern="[0-9]*" inputmode="numeric" maxlength="6"
                                    placeholder="000000" autofocus>
                                <div class="form-text text-center">
                                    Code expires in <span id="timer">30</span> seconds
                                </div>
                            </div>

                            <div class="d-grid gap-2 mb-3">
                                <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                    <i class="fas fa-check-circle me-2"></i>Verify & Continue
                                </button>
                            </div>

                            <div class="text-center mt-3">
                                <a href="#" class="backup-link" data-bs-toggle="collapse" data-bs-target="#backupForm">
                                    <i class="fas fa-key me-1"></i>Use backup code instead
                                </a>
                            </div>

                            <div class="collapse mt-3" id="backupForm">
                                <div class="card card-body bg-light">
                                    <p class="small mb-2">Enter one of your backup codes:</p>
                                    <div class="input-group mb-2">
                                        <input type="text" class="form-control" id="backupCode"
                                            placeholder="XXXXX-XXXXX">
                                        <button class="btn btn-outline-primary" type="button" onclick="useBackupCode()">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">
                                        Backup codes are single-use and will be disabled after use.
                                    </small>
                                </div>
                            </div>
                        </form>

                        <hr class="my-4">

                        <div class="text-center">
                            <a href="logout.php" class="text-danger text-decoration-none">
                                <i class="fas fa-sign-out-alt me-1"></i>Not you? Sign out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-submit when 6 digits entered
        const codeInput = document.getElementById('code');
        const submitBtn = document.getElementById('submitBtn');

        codeInput.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) {
                document.getElementById('verifyForm').submit();
            }
        });

        // Countdown timer
        let timeLeft = 30;
        const timerElement = document.getElementById('timer');

        function updateTimer() {
            timerElement.textContent = timeLeft;
            if (timeLeft === 0) {
                timerElement.textContent = 'expired';
                timerElement.classList.add('text-danger');
            } else {
                timeLeft--;
            }
        }

        setInterval(updateTimer, 1000);

        // Backup code handler
        function useBackupCode() {
            const backupCode = document.getElementById('backupCode').value;
            if (backupCode) {
                document.getElementById('code').value = backupCode;
                document.getElementById('verifyForm').submit();
            }
        }

        // Prevent form double submission
        document.getElementById('verifyForm').addEventListener('submit', function () {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verifying...';
        });
    </script>
</body>

</html>