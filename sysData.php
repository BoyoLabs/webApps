<?php
// =================================================================
// 1. PHP BACKEND LOGIC (REVISED FOR ANDROID MEMORY ACCURACY & TRAFFIC)
// =================================================================

// Function to safely execute a shell command (Linux based)
function execute_command($command) {
    if (function_exists('shell_exec')) {
        return trim(@shell_exec($command));
    }
    return 'N/A (shell_exec disabled)';
}

// Function to format bytes into human-readable format (e.g., 1.5 GB)
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Function to gather and return all statistics as a PHP array
function get_server_stats() {
    $stats = [];
    $path = dirname(__FILE__); 

    // --- CPU Load Average ---
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $stats['cpu_load'] = sprintf('%s (1m), %s (5m), %s (15m)', $load[0], $load[1], $load[2]);
    } else {
        $stats['cpu_load'] = execute_command('uptime | awk -F\'load average:\' \'{ print $2 }\'');
    }

    // --- Memory Usage (REVISED for true "Used" memory on Linux/Android) ---
    // Using 'free -m -w' to expose the 'available' column, which is the best metric.
    $memory_info = execute_command('free -m -w | grep Mem:');
    // Regex matches: Total (\d+), Used (\d+), Free (\d+), Shared (\d+), Buff/Cache (\d+), Available (\d+)
    if (preg_match('/(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $memory_info, $matches)) {
        $total = (int)$matches[1];
        $available = (int)$matches[6]; // True free memory (not actively used and not cache)
        
        // Calculate true used memory: Total - Available
        $true_used = $total - $available;
        $percent = round(($true_used / $total) * 100, 2);
        
        $stats['memory_usage'] = sprintf('%s MB Used / %s MB Total (%s%%)', $true_used, $total, $percent);
    } else {
        $stats['memory_usage'] = 'N/A (Memory calculation failed or command output changed)';
    }

    // --- Storage Space Left ---
    if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
        $total_space = @disk_total_space($path);
        $free_space = @disk_free_space($path);
        
        if ($total_space > 0 && $free_space !== false) {
            $used_space = $total_space - $free_space;
            $used_percent = round(($used_space / $total_space) * 100, 2);
            
            $stats['disk_space'] = sprintf('%s Available / %s Total (%s%% Used)', 
                                          formatBytes($free_space), 
                                          formatBytes($total_space), 
                                          $used_percent);
        } else {
            $stats['disk_space'] = 'N/A (Filesystem check failed)';
        }
    } else {
         $stats['disk_space'] = 'N/A (disk_* functions disabled)';
    }

    // --- Server Uptime ---
    $stats['uptime'] = execute_command('uptime -p');

    // --- Server Software/PHP Version ---
    $stats['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $stats['php_version'] = PHP_VERSION;

    // --- NEW: Traffic/Network Data (DUMMY/PLACEHOLDER) ---
    // Generates a random value for demonstration. Replace with actual shell command parsing 
    // (e.g., parsing /proc/net/dev or a custom traffic logging tool) for production use.
    $stats['network_traffic'] = sprintf('In: %s / Out: %s', 
                                        formatBytes(rand(5000000, 500000000)), // 5MB to 500MB random in
                                        formatBytes(rand(1000000, 100000000))); // 1MB to 100MB random out
    
    return $stats;
}

// Check if the script is being called via AJAX for data refresh
if (isset($_GET['action']) && $_GET['action'] === 'fetch_stats') {
    header('Content-Type: application/json');
    $stats = get_server_stats();
    echo json_encode(['success' => true, 'data' => $stats]);
    exit;
}

// Get initial stats for the first page render
$initial_stats = get_server_stats();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Status SPA (Dark Theme)</title>
    
    <style>
        /* Define Dark Theme Colors */
        :root {
            --color-bg-primary: #1e1e1e;
            --color-bg-secondary: #252526;
            --color-text-light: #f0f0f0;
            --color-text-medium: #bbbbbb;
            --color-accent: #00bcd4; /* Cyan/Teal for primary accents */
            --color-card-bg: #2d2d30;
            --color-border: #333333;
            --color-disk-accent: #4caf50; /* Green for Disk */
            --color-traffic-accent: #ff9800; /* Orange for Traffic */
        }

        /* General reset and body styling */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--color-bg-primary); /* Dark Background */
            color: var(--color-text-light); /* Light Text */
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
        }

        /* Dashboard container */
        .dashboard {
            background-color: var(--color-bg-secondary); /* Slightly lighter dark container */
            padding: 30px;
            margin: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5); /* Stronger shadow for depth */
            max-width: 1200px;
            width: 95%;
        }

        h1 {
            color: var(--color-accent);
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid var(--color-border);
            padding-bottom: 15px;
        }

        /* Grid layout for statistics cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        /* Individual statistic card styling */
        .stat-card {
            background: var(--color-card-bg); /* Card background */
            padding: 20px;
            border-radius: 8px;
            border-left: 5px solid var(--color-accent); /* Default border color */
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 188, 212, 0.3); /* Accent shadow */
        }

        .stat-card h2 {
            margin-top: 0;
            color: var(--color-text-medium);
            font-size: 1.1em;
            font-weight: 600;
        }

        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: var(--color-text-light);
            margin: 10px 0 0 0;
        }

        /* Styling for specific cards */
        #disk-space {
             border-left-color: var(--color-disk-accent); /* Green accent */
        }
        
        #network-traffic {
             border-left-color: var(--color-traffic-accent); /* Orange accent */
        }

        /* Status message styles */
        #status-message {
            text-align: center;
            margin-top: 25px;
            padding: 10px;
            border-radius: 4px;
            font-weight: bold;
        }
        
        #status-message.loading {
            background-color: #333311;
            color: #ffeb3b; /* Yellow text */
            border: 1px solid #444422;
        }
        
        #status-message.error {
            background-color: #442222;
            color: #ff4444; /* Red text */
            border: 1px solid #663333;
        }
        
        #status-message.success {
            background-color: #224422;
            color: #44ff44; /* Green text */
            border: 1px solid #336633;
        }

        /* Media query for smaller screens */
        @media (max-width: 600px) {
            .dashboard {
                padding: 20px;
                margin: 15px;
            }
        }
    </style>
</head>
<body>

    <div class="dashboard">
        <h1>Boyo Labs Server Status Dashboard v.1.1</h1>
        <div id="stats-container" class="stats-grid">
            
            <div class="stat-card" id="cpu-load">
                <h2>CPU Load (1m, 5m, 15m)</h2>
                <p class="stat-value"><?= htmlspecialchars($initial_stats['cpu_load'] ?? '--') ?></p>
            </div>
            
            <div class="stat-card" id="memory-usage">
                <h2>Memory Usage (True Used)</h2>
                <p class="stat-value"><?= htmlspecialchars($initial_stats['memory_usage'] ?? '--') ?></p>
            </div>

            <div class="stat-card" id="disk-space">
                <h2>Disk Space (Script Partition)</h2>
                <p class="stat-value"><?= htmlspecialchars($initial_stats['disk_space'] ?? '--') ?></p>
            </div>
            
            <div class="stat-card" id="uptime">
                <h2>Uptime</h2>
                <p class="stat-value"><?= htmlspecialchars($initial_stats['uptime'] ?? '--') ?></p>
            </div>

            <div class="stat-card" id="network-traffic">
                <h2>Network Traffic (In / Out)</h2>
                <p class="stat-value"><?= htmlspecialchars($initial_stats['network_traffic'] ?? '--') ?></p>
            </div>

            <div class="stat-card" id="server-software">
                <h2>Server Software</h2>
                <p class="stat-value"><?= htmlspecialchars($initial_stats['server_software'] ?? '--') ?></p>
            </div>

            <div class="stat-card" id="php-version">
                <h2>PHP Version</h2>
                <p class="stat-value"><?= htmlspecialchars($initial_stats['php_version'] ?? '--') ?></p>
            </div>
        </div>

        <div id="status-message" class="loading">Loading statistics...</div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const statusMessage = document.getElementById('status-message');

            const STAT_MAPPING = {
                'cpu_load': 'cpu-load',
                'memory_usage': 'memory-usage',
                'disk_space': 'disk-space', 
                'uptime': 'uptime',
                'server_software': 'server-software',
                'php_version': 'php-version',
                // Added new mapping for traffic
                'network_traffic': 'network-traffic' 
            };

            /**
             * Fetches server statistics asynchronously and updates the DOM.
             */
            async function fetchStats() {
                try {
                    statusMessage.textContent = 'Refreshing data...';
                    statusMessage.className = 'loading';
                    
                    const response = await fetch('?action=fetch_stats');
                    const result = await response.json();

                    if (result.success && result.data) {
                        for (const key in result.data) {
                            const cardId = STAT_MAPPING[key];
                            const cardElement = document.getElementById(cardId);
                            
                            if (cardElement) {
                                const valueElement = cardElement.querySelector('.stat-value');
                                if (valueElement) {
                                    valueElement.textContent = result.data[key];
                                }
                            }
                        }
                        
                        statusMessage.textContent = `Last update: ${new Date().toLocaleTimeString()}`;
                        statusMessage.className = 'success';
                    } else {
                        throw new Error('Server returned an error.');
                    }
                } catch (error) {
                    console.error('Error fetching statistics:', error);
                    statusMessage.textContent = `Error: Could not connect to server or parse data.`;
                    statusMessage.className = 'error';
                }
            }

            setTimeout(fetchStats, 1000); 
            setInterval(fetchStats, 5000); // Refreshes stats every 5 seconds
        });
    </script>
</body>
</html>