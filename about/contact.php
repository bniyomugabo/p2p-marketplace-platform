<?php
// contact.php
// ============================================
// CONTACT PAGE - STANDALONE
// ============================================

session_name('sati');
session_start();

$pageTitle = 'Contact Us - SATI ERP';
$currentPage = 'contact';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        $error = 'All fields are required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Valid email is required';
    } else {
        // Send email (configure with your email)
        $to = 'support@sati.com';
        $headers = "From: $email\r\n";
        $headers .= "Reply-To: $email\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $fullMessage = "Name: $name\n";
        $fullMessage .= "Email: $email\n";
        $fullMessage .= "Subject: $subject\n\n";
        $fullMessage .= $message;

        if (mail($to, "Contact Form: $subject", $fullMessage, $headers)) {
            $success = true;
        } else {
            $error = 'Failed to send message. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo $pageTitle; ?>
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        /* ===== NAVIGATION STYLES ===== */
        .navbar {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 15px 0;
            min-height: 80px;
            display: flex;
            align-items: center;
        }

        .navbar .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
        }

        .navbar-brand {
            color: white;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            height: 100%;
            line-height: 1;
            padding: 0;
            margin: 0;
        }

        .navbar-brand i {
            margin-right: 10px;
            font-size: 28px;
            display: flex;
            align-items: center;
        }

        .nav-links {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            height: 100%;
            margin: 0;
            padding: 0;
        }

        .nav-link {
            color: white !important;
            font-weight: 500;
            padding: 8px 16px !important;
            margin: 0 2px;
            border-radius: 50px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1.5;
            white-space: nowrap;
            position: relative;
            height: 100%;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: rgba(255, 255, 255, 0.15);
            position: relative;
        }

        .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 25%;
            width: 50%;
            height: 2px;
            background: white;
            border-radius: 2px;
        }

        .btn-login {
            border: 2px solid white;
            color: white;
            padding: 8px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            line-height: 1.5;
            white-space: nowrap;
            height: 42px;
            margin-right: 10px;
        }

        .btn-login:hover {
            background: white;
            color: #667eea;
            transform: translateY(-2px);
        }

        .btn-register {
            background: white;
            color: #667eea;
            padding: 8px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1.5;
            white-space: nowrap;
            height: 42px;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        /* ===== PAGE CONTENT STYLES ===== */
        .page-header {
            text-align: center;
            color: white;
            padding: 60px 20px 40px;
        }

        .page-header h1 {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .page-header p {
            font-size: 18px;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        .contact-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
        }

        .contact-info {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            color: white;
        }

        .contact-info h3 {
            font-size: 28px;
            margin-bottom: 30px;
        }

        .info-item {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
        }

        .info-icon {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 20px;
        }

        .info-content h4 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .info-content p {
            opacity: 0.8;
            margin: 0;
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background: white;
            color: #667eea;
            transform: translateY(-3px);
        }

        .contact-form {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-control {
            height: 50px;
            border-radius: 10px;
            border: 1px solid #e1e5e9;
            padding: 10px 15px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.1);
        }

        textarea.form-control {
            height: 120px;
            resize: vertical;
        }

        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }

        .back-home {
            text-align: center;
            padding: 40px 20px 60px;
        }

        .btn-home {
            background: white;
            color: #667eea;
            padding: 12px 40px;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .btn-dashboard {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 12px 40px;
            border-radius: 50px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s;
            margin-left: 15px;
            border: 1px solid white;
        }

        .btn-dashboard:hover {
            background: white;
            color: #667eea;
        }

        .alert {
            border-radius: 10px;
            margin-bottom: 20px;
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 991px) {
            .navbar .container {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }

            .contact-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 36px;
            }

            .btn-dashboard {
                margin-left: 0;
                margin-top: 15px;
            }
        }

        /* Animation for page load */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .navbar,
        .page-header,
        .contact-container,
        .back-home {
            animation: fadeIn 0.8s ease-out forwards;
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="./index.html">
                <i class="fas fa-warehouse"></i>
                SATI ERP
            </a>
            <div class="nav-links">
                <a href="./index.html" class="nav-link">Home</a>
                <a href="./features.php" class="nav-link">Features</a>
                <a href="./contact.php" class="nav-link active">Contact</a>
                <a href="./index.html#services" class="nav-link">Services</a>
            </div>
            <div>
                <a href="./app/auth/signin.php" class="btn-login">Login</a>
                <a href="./app/auth/register.php" class="btn-register">Join Now</a>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <h1>Get in Touch</h1>
        <p>Have questions? We'd love to hear from you. Send us a message and we'll respond as soon as possible.</p>
    </div>

    <!-- Contact Section -->
    <div class="contact-container">
        <!-- Contact Info -->

        <div class="contact-info">
            <h3>Contact Information</h3>
            <p style="margin-bottom: 30px; opacity: 0.9;">Choose your preferred way to reach out. Our technical team is
                available for platform support 24/7.</p>

            <div class="info-item">
                <div class="info-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="info-content">
                    <h4>Email Us</h4>
                    <p>support@satierp.com</p>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">
                    <i class="fas fa-headset"></i>
                </div>
                <div class="info-content">
                    <h4>Support Portal</h4>
                    <p>Available in your Dashboard</p>
                </div>
            </div>

            <div class="info-item">
                <div class="info-icon">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <div class="info-content">
                    <h4>Headquarters</h4>
                    <p>Global Cloud Infrastructure<br>Silicon Valley, CA</p>
                </div>
            </div>

            <div class="social-links">
                <a href="#"><i class="fab fa-linkedin-in"></i></a>
                <a href="#"><i class="fab fa-twitter"></i></a>
                <a href="#"><i class="fab fa-github"></i></a>
            </div>
        </div>

        <!-- Contact Form -->
        <div class="contact-form">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    Thank you for your message! We'll get back to you soon.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <input type="text" class="form-control" name="name" placeholder="Your Name *" required
                        value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <input type="email" class="form-control" name="email" placeholder="Your Email *" required
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <input type="text" class="form-control" name="subject" placeholder="Subject *" required
                        value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <textarea class="form-control" name="message" placeholder="Your Message *"
                        required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane me-2"></i>Send Message
                </button>
            </form>
        </div>
    </div>

    <!-- Back to Home / Dashboard -->
    <div class="back-home">
        <a href="./index.html" class="btn-home">
            <i class="fas fa-home me-2"></i>Back to Home
        </a>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="./app/index.php?page=dashboard" class="btn-dashboard">
                <i class="fas fa-tachometer-alt me-2"></i>Go to Dashboard
            </a>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>