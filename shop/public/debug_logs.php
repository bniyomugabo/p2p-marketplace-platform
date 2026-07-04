<?php
// /public/debug_logs.php
// Debug log viewer (should be protected or removed in production)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

// Simple IP restriction for security (optional)
$allowedIps = ['127.0.0.1', '::1']; // Add your IP
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIps) && !DEBUG_MODE) {
    die('Access denied');
}

$logger = Logger::getInstance();
$logType = $_GET['type'] ?? 'error';
$lines = min(500, (int)($_GET['lines'] ?? 100));

$logs = $logger->getRecentLogs($logType, $lines);
$logSize = $logger->getLogSize($logType);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Logs - MarketHub</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Monaco', 'Menlo', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
        }
        
        .header {
            background: #252526;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        h1 {
            font-size: 1.5rem;
            color: #4ec9b0;
        }
        
        .controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        select, button, a {
            padding: 8px 15px;
            background: #3c3c3c;
            border: 1px solid #555;
            color: #d4d4d4;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            text-decoration: none;
        }
        
        select:hover, button:hover, a:hover {
            background: #4c4c4c;
        }
        
        .log-stats {
            background: #252526;
            padding: 10px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 20px;
            font-size: 0.85rem;
        }
        
        .log-stats span {
            color: #4ec9b0;
            font-weight: bold;
        }
        
        .log-entry {
            background: #252526;
            margin-bottom: 10px;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .log-header {
            padding: 10px 15px;
            background: #2d2d2d;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            border-left: 4px solid;
        }
        
        .log-header.error { border-left-color: #f48771; }
        .log-header.warning { border-left-color: #dcdcaa; }
        .log-header.info { border-left-color: #4ec9b0; }
        .log-header.debug { border-left-color: #9cdcfe; }
        .log-header.api { border-left-color: #ce9178; }
        .log-header.sql { border-left-color: #b5cea8; }
        
        .log-level {
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
        }
        
        .level-ERROR { background: #f48771; color: #1e1e1e; }
        .level-WARNING { background: #dcdcaa; color: #1e1e1e; }
        .level-INFO { background: #4ec9b0; color: #1e1e1e; }
        .level-DEBUG { background: #9cdcfe; color: #1e1e1e; }
        .level-API { background: #ce9178; color: #1e1e1e; }
        .level-SQL { background: #b5cea8; color: #1e1e1e; }
        
        .log-time {
            color: #858585;
            font-size: 0.8rem;
        }
        
        .log-message {
            color: #d4d4d4;
            font-weight: 500;
        }
        
        .log-details {
            padding: 15px;
            background: #1e1e1e;
            border-top: 1px solid #3c3c3c;
            display: none;
        }
        
        .log-details.open {
            display: block;
        }
        
        pre {
            background: #1e1e1e;
            padding: 10px;
            overflow-x: auto;
            font-size: 0.8rem;
            color: #ce9178;
        }
        
        .actions {
            display: flex;
            gap: 5px;
        }
        
        .btn-danger {
            background: #f48771;
            color: #1e1e1e;
            border-color: #f48771;
        }
        
        .btn-danger:hover {
            background: #e57373;
        }
        
        .refresh-btn {
            background: #4ec9b0;
            color: #1e1e1e;
        }
        
        .auto-refresh {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
        }
        
        @media (max-width: 768px) {
            .log-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📋 Debug Log Viewer</h1>
        <div class="controls">
            <select id="log-type" onchange="changeLogType()">
                <option value="error" <?php echo $logType === 'error' ? 'selected' : ''; ?>>Error Log</option>
                <option value="api" <?php echo $logType === 'api' ? 'selected' : ''; ?>>API Log</option>
                <option value="sql" <?php echo $logType === 'sql' ? 'selected' : ''; ?>>Database Log</option>
            </select>
            <select id="log-lines" onchange="changeLogLines()">
                <option value="50" <?php echo $lines === 50 ? 'selected' : ''; ?>>Last 50 lines</option>
                <option value="100" <?php echo $lines === 100 ? 'selected' : ''; ?>>Last 100 lines</option>
                <option value="200" <?php echo $lines === 200 ? 'selected' : ''; ?>>Last 200 lines</option>
                <option value="500" <?php echo $lines === 500 ? 'selected' : ''; ?>>Last 500 lines</option>
            </select>
            <button onclick="refreshLogs()" class="refresh-btn">🔄 Refresh</button>
            <button onclick="clearLogs()" class="btn-danger">🗑️ Clear Logs</button>
            <a href="/">← Back to Store</a>
        </div>
    </div>
    
    <div class="log-stats">
        <div>📁 Log file: <span><?php echo htmlspecialchars($logType); ?>.log</span></div>
        <div>📊 Size: <span><?php echo round($logSize / 1024, 2); ?> KB</span></div>
        <div>📝 Entries shown: <span><?php echo count($logs); ?></span></div>
        <div class="auto-refresh">
            🔄 Auto-refresh
            <input type="checkbox" id="auto-refresh" onchange="toggleAutoRefresh()">
            <span id="refresh-interval">(5s)</span>
        </div>
    </div>
    
    <div id="logs-container">
        <?php if (empty($logs)): ?>
            <div style="text-align: center; padding: 50px; color: #858585;">
                No logs found. Start using the application to generate logs.
            </div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <div class="log-entry">
                    <div class="log-header <?php echo strtolower($log['level'] ?? 'info'); ?>" onclick="toggleDetails(this)">
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <span class="log-level level-<?php echo $log['level'] ?? 'INFO'; ?>"><?php echo $log['level'] ?? 'INFO'; ?></span>
                            <span class="log-time"><?php echo $log['timestamp'] ?? 'Unknown'; ?></span>
                            <span class="log-message"><?php echo htmlspecialchars($log['message'] ?? 'No message'); ?></span>
                        </div>
                        <div class="actions">
                            <span style="color:#858585; font-size:0.75rem;">PID: <?php echo $log['pid'] ?? '?'; ?></span>
                            <span>▼</span>
                        </div>
                    </div>
                    <?php if (!empty($log['context'])): ?>
                        <div class="log-details">
                            <pre><?php echo htmlspecialchars(json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <script>
        let autoRefreshInterval = null;
        
        function toggleDetails(header) {
            const details = header.nextElementSibling;
            if (details && details.classList.contains('log-details')) {
                details.classList.toggle('open');
            }
        }
        
        function changeLogType() {
            const type = document.getElementById('log-type').value;
            const lines = document.getElementById('log-lines').value;
            window.location.href = `?type=${type}&lines=${lines}`;
        }
        
        function changeLogLines() {
            const type = document.getElementById('log-type').value;
            const lines = document.getElementById('log-lines').value;
            window.location.href = `?type=${type}&lines=${lines}`;
        }
        
        function refreshLogs() {
            const type = document.getElementById('log-type').value;
            const lines = document.getElementById('log-lines').value;
            window.location.href = `?type=${type}&lines=${lines}&t=${Date.now()}`;
        }
        
        function clearLogs() {
            if (confirm('Are you sure you want to clear the logs? This action cannot be undone.')) {
                const type = document.getElementById('log-type').value;
                fetch(`../src/api/clear_logs.php?type=${type}`, {
                    method: 'POST'
                }).then(() => {
                    refreshLogs();
                }).catch(error => {
                    console.error('Error clearing logs:', error);
                });
            }
        }
        
        function toggleAutoRefresh() {
            const checkbox = document.getElementById('auto-refresh');
            if (checkbox.checked) {
                autoRefreshInterval = setInterval(() => {
                    const type = document.getElementById('log-type').value;
                    const lines = document.getElementById('log-lines').value;
                    fetch(`?type=${type}&lines=${lines}&t=${Date.now()}`)
                        .then(response => response.text())
                        .then(html => {
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const newContainer = doc.getElementById('logs-container');
                            if (newContainer) {
                                document.getElementById('logs-container').innerHTML = newContainer.innerHTML;
                            }
                        });
                }, 5000);
            } else {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            }
        }
    </script>
</body>
</html>