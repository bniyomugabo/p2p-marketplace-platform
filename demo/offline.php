<?php
// offline.php - Offline fallback page
$pageTitle = 'Offline - SATI ERP';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }

        .offline-card {
            background: white;
            border-radius: 20px;
            padding: 50px 40px;
            text-align: center;
            max-width: 500px;
            margin: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .offline-icon {
            font-size: 80px;
            color: #667eea;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 28px;
            margin-bottom: 15px;
            color: #2d3748;
        }

        p {
            color: #718096;
            line-height: 1.6;
        }

        .btn-retry {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border-radius: 30px;
            text-decoration: none;
            display: inline-block;
            margin-top: 20px;
            border: none;
            cursor: pointer;
        }

        .btn-retry:hover {
            transform: translateY(-2px);
            color: white;
        }
    </style>
</head>

<body>
    <div class="offline-card">
        <div class="offline-icon"><i class="fas fa-wifi-slash"></i></div>
        <h1>You're Offline</h1>
        <p>Please check your internet connection and try again.</p>
        <button class="btn-retry" onclick="location.reload()">
            <i class="fas fa-sync-alt me-2"></i>Try Again
        </button>
        <div class="mt-3">
            <a href="/index.php?page=dashboard" class="text-muted text-decoration-none">
                <i class="fas fa-home me-1"></i>Go to Dashboard
            </a>
        </div>
    </div>
    <script>
        window.addEventListener('online', () => { location.reload(); });
    </script>
</body>

</html>