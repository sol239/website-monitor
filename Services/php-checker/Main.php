<?php

// Load .env variables manually if not loaded
$envPath = __DIR__.'/../../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv("$name=$value");
    }
}

// Set timezone to UTC for all date/time operations
date_default_timezone_set('UTC');

/**
 * Class MonitorChecker
 *
 * Handles monitoring checks for ping and website monitors,
 * manages database connections, scheduling, and logging.
 */
class MonitorChecker
{
    /**
     * A list of ping monitors to check.
     * @var array
     */
    private array $pingMonitors;

    /**
     * A list of website monitors to check.
     * @var array
     */
    private array $websiteMonitors;

    /**
     * The path to the SQLite database file.
     * @var string
     */
    public static string $DB_PATH = __DIR__.'/../../database/database.sqlite';

    /**
     * The PDO instance for database connections.
     * @var PDO
     */
    private PDO $pdo;

    /**
     * Stores next check times for all monitors.
     * @var array
     */
    public array $monitorNextChecks = [];

    /**
     * MonitorChecker constructor.
     * Initializes database connection and loads monitors.
     */
    public function __construct()
    {
        $this->connectToDatabase();
        $this->pingMonitors = $this->loadMonitors('ping');
        $this->websiteMonitors = $this->loadMonitors('website');
    }

    private function connectToDatabase()
    {
        // Load DB config from environment variables
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbPort = getenv('DB_PORT') ?: '3306';
        $dbName = getenv('DB_DATABASE') ?: 'monitor';
        $dbUser = getenv('DB_USERNAME') ?: 'root';
        $dbPass = getenv('DB_PASSWORD') ?: '';

        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        print_r("Connecting to MySQL database at: {$dbHost}:{$dbPort}, DB: {$dbName}\n");

        try {
            $this->pdo = new PDO($dsn, $dbUser, $dbPass);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo "Connected successfully.\n";
        } catch (PDOException $e) {
            exit('Could not connect to database: '.$e->getMessage()."\n");
        }
    }

    private function loadMonitors(string $type)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM monitors WHERE monitor_type = :monitor_type');
        $stmt->execute(['monitor_type' => $type]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasLog($monitor): bool
    {
        if (!$monitor || !isset($monitor['id'])) {
            echo "Warning: Invalid monitor data provided to hasLog\n";
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM monitor_logs WHERE monitor_id = :monitor_id');
        $stmt->execute(['monitor_id' => $monitor['id']]);

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Removes a monitor from all internal lists if it was deleted from DB.
     *
     * @param int $monitorId The ID of the monitor to remove.
     * @return void
     */
    private function removeMonitorFromLoop(int $monitorId): void
    {
        echo "Removing monitor ID {$monitorId} from monitoring loop due to deletion\n";

        // Remove from pingMonitors
        $this->pingMonitors = array_filter($this->pingMonitors, function($m) use ($monitorId) {
            return isset($m['id']) && $m['id'] != $monitorId;
        });

        // Remove from websiteMonitors
        $this->websiteMonitors = array_filter($this->websiteMonitors, function($m) use ($monitorId) {
            return isset($m['id']) && $m['id'] != $monitorId;
        });

        // Remove from monitorNextChecks
        $this->monitorNextChecks = array_filter($this->monitorNextChecks, function($mc) use ($monitorId) {
            return isset($mc['monitor']['id']) && $mc['monitor']['id'] != $monitorId;
        });
    }

    /**
     * Prints information about all ping and website monitors.
     *
     * @return void
     */
    public function printInformation(): void
    {
        echo 'Ping Monitors ['.count($this->pingMonitors)."]:\n";
        foreach ($this->pingMonitors as $monitor) {
            echo "Monitor ID: {$monitor['id']}, Label: {$monitor['label']}, Hostname: {$monitor['hostname']}, Port: {$monitor['port']}\n";
        }
        echo 'Website Monitors ['.count($this->websiteMonitors)."]:\n";
        foreach ($this->websiteMonitors as $monitor) {
            echo "Monitor ID: {$monitor['id']}, Label: {$monitor['label']}, URL: {$monitor['url']}\n";
        }
    }

    /**
     * Runs a check for a ping monitor and logs the result.
     *
     * @param array $pingMonitor The ping monitor configuration.
     * @return void
     */
    private function runPingMonitorCheck($pingMonitor): void
    {
        if (!$pingMonitor || !isset($pingMonitor['id']) || !isset($pingMonitor['hostname']) || !isset($pingMonitor['port'])) {
            echo "Warning: Invalid ping monitor data provided\n";
            return;
        }

        $host = $pingMonitor['hostname'];
        $port = (int) $pingMonitor['port'];

        $start = microtime(true);
        $connection = @fsockopen($host, $port, $errno, $errstr, 5); // 5s timeout
        $responseTime = round((microtime(true) - $start) * 1000); // in ms

        if ($connection) {
            fclose($connection);
            $status = 'succeeded';
            // echo "Connection successful! Response time: {$responseTime} ms\n";
        } else {
            $status = 'failed';
            // echo "Connection failed: {$errstr} ({$errno})\n";
        }

        // INSERTING RESULT INTO DB
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO monitor_logs (monitor_id, started_at, status, response_time_ms, created_at, updated_at)
                VALUES (:monitor_id, :started_at, :status, :response_time_ms, :created_at, :updated_at)
            ');
            $stmt->execute([
                'monitor_id' => $pingMonitor['id'],
                'started_at' => gmdate('Y-m-d H:i:s', $start),
                'status' => $status,
                'response_time_ms' => $responseTime,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            echo "Log saved for monitor ID {$pingMonitor['id']}.\n";
        } catch (PDOException $e) {
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Integrity constraint violation') !== false || strpos($errorMessage, 'foreign key constraint') !== false || $e->getCode() == 23000) {
                echo "Monitor ID {$pingMonitor['id']} appears to be deleted from DB, removing from loop\n";
                $this->removeMonitorFromLoop($pingMonitor['id']);
            } else {
                echo "Failed to save log for monitor ID {$pingMonitor['id']}: {$errorMessage}\n";
            }
        }
    }

    /**
     * Runs a check for a website monitor and logs the result.
     *
     * @param array $websiteMonitor The website monitor configuration.
     * @return void
     */
    public function runWebsiteMonitorCheck($websiteMonitor): void
    {
        if (!$websiteMonitor || !isset($websiteMonitor['id']) || !isset($websiteMonitor['url'])) {
            echo "Warning: Invalid website monitor data provided\n";
            return;
        }

        $url = $websiteMonitor['url'];
        $checkStatus = ! empty($websiteMonitor['check_status']) && $websiteMonitor['check_status'];
        $keywords = [];
        if (! empty($websiteMonitor['keywords'])) {
            // Assume keywords stored as comma-separated string
            $keywords = json_decode($websiteMonitor['keywords'], true);
        }

        $start = microtime(true);
        $status = 'succeeded';
        $responseTime = null;
        $httpCode = null;
        $missingKeywords = [];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // allow self-signed certs

        $response = curl_exec($ch);
        $responseTime = round((microtime(true) - $start) * 1000); // ms

        if ($response === false) {
            $status = 'failed';
            $error = curl_error($ch);
            echo "Website connection failed: {$error}\n";
        } else {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            echo "Website connection successful! HTTP status: {$httpCode}, Response time: {$responseTime} ms\n";
            if ($checkStatus && ($httpCode < 200 || $httpCode >= 300)) {
                $status = 'failed';
                echo "Status code check failed.\n";
            }
            if ($status === 'succeeded' && $keywords) {
                echo $keywords;

                foreach ($keywords as $keyword) {
                    echo "Keyword check for: {$keyword}\n";
                    echo strpos($response, $keyword);
                    if (! empty($keyword) && strpos($response, $keyword) === false) {
                        $missingKeywords[] = $keyword;
                    }
                }
                if ($missingKeywords) {
                    $status = 'failed';
                    echo 'Missing keywords: '.implode(', ', $missingKeywords)."\n";
                }
            }
        }
        curl_close($ch);

        // INSERTING RESULT INTO DB
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO monitor_logs (monitor_id, started_at, status, response_time_ms, created_at, updated_at)
                VALUES (:monitor_id, :started_at, :status, :response_time_ms, :created_at, :updated_at)
            ');
            $stmt->execute([
                'monitor_id' => $websiteMonitor['id'],
                'started_at' => gmdate('Y-m-d H:i:s', $start),
                'status' => $status,
                'response_time_ms' => $responseTime,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            echo "Log saved for website monitor ID {$websiteMonitor['id']}.\n";
        } catch (PDOException $e) {
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Integrity constraint violation') !== false || strpos($errorMessage, 'foreign key constraint') !== false || $e->getCode() == 23000) {
                echo "Monitor ID {$websiteMonitor['id']} appears to be deleted from DB, removing from loop\n";
                $this->removeMonitorFromLoop($websiteMonitor['id']);
            } else {
                echo "Failed to save log for website monitor ID {$websiteMonitor['id']}: {$errorMessage}\n";
            }
        }
    }

    /**
     * Initializes monitor checks, runs first checks if needed,
     * and schedules next check times.
     *
     * @return void
     */
    public function initializeMonitorChecks(): void
    {
        $currentTime = gmdate('Y-m-d H:i:s');
        echo "=== Initialization started at: {$currentTime} ===\n";

        foreach ($this->pingMonitors as $pingMonitor) {
            $_hasLog = $this->hasLog($pingMonitor);
            echo 'Monitor ['.$pingMonitor['label'].'] has log: '.($_hasLog ? 'Yes' : 'No')."\n";

            if (! $_hasLog) {
                try {
                    $this->runPingMonitorCheck($pingMonitor);
                } catch (PDOException $e) {
                    $errorMessage = $e->getMessage();
                    if (strpos($errorMessage, 'Integrity constraint violation') !== false || strpos($errorMessage, 'foreign key constraint') !== false || $e->getCode() == 23000) {
                        echo "Monitor ID {$pingMonitor['id']} appears to be deleted from DB during initialization, removing from loop\n";
                        $this->removeMonitorFromLoop($pingMonitor['id']);
                        continue; // Skip scheduling
                    } else {
                        echo "Failed to run initial check for monitor ID {$pingMonitor['id']}: {$errorMessage}\n";
                    }
                }
            }

            $nextCheckTime = $this->calculateNextCheckTime($pingMonitor);
            $this->monitorNextChecks[] = [
                'monitor' => $pingMonitor,
                'nextCheckTime' => $nextCheckTime,
            ];

            echo "Monitor [{$pingMonitor['label']}] next check scheduled at: {$nextCheckTime}\n";
        }

        foreach ($this->websiteMonitors as $websiteMonitor) {
            $_hasLog = $this->hasLog($websiteMonitor);
            echo 'Website Monitor ['.$websiteMonitor['label'].'] has log: '.($_hasLog ? 'Yes' : 'No')."\n";

            if (! $_hasLog) {
                try {
                    $this->runWebsiteMonitorCheck($websiteMonitor);
                } catch (PDOException $e) {
                    $errorMessage = $e->getMessage();
                    if (strpos($errorMessage, 'Integrity constraint violation') !== false || strpos($errorMessage, 'foreign key constraint') !== false || $e->getCode() == 23000) {
                        echo "Monitor ID {$websiteMonitor['id']} appears to be deleted from DB during initialization, removing from loop\n";
                        $this->removeMonitorFromLoop($websiteMonitor['id']);
                        continue; // Skip scheduling
                    } else {
                        echo "Failed to run initial check for website monitor ID {$websiteMonitor['id']}: {$errorMessage}\n";
                    }
                }
            }

            $nextCheckTime = $this->calculateNextCheckTime($websiteMonitor);
            $this->monitorNextChecks[] = [
                'monitor' => $websiteMonitor,
                'nextCheckTime' => $nextCheckTime,
            ];

            echo "Website Monitor [{$websiteMonitor['label']}] next check scheduled at: {$nextCheckTime}\n";
        }

        echo '=== Initialization completed at: '.gmdate('Y-m-d H:i:s')." ===\n";
    }

    /**
     * Calculates next check time for a monitor.
     * Uses latest started_at from monitor_logs + monitor['periodicity'] (in seconds).
     *
     * @param array $monitor The monitor configuration.
     * @return string Next check time in UTC (Y-m-d H:i:s).
     */
    private function calculateNextCheckTime($monitor): string
    {
        if (!$monitor || !isset($monitor['id'])) {
            echo "Warning: Invalid monitor passed to calculateNextCheckTime\n";
            return gmdate('Y-m-d H:i:s'); // Return current time as fallback
        }

        $stmt = $this->pdo->prepare('SELECT started_at FROM monitor_logs WHERE monitor_id = :monitor_id ORDER BY started_at DESC LIMIT 1');
        $stmt->execute(['monitor_id' => $monitor['id']]);
        $latestStartedAt = $stmt->fetchColumn();
        $periodicity = isset($monitor['periodicity']) ? (int) $monitor['periodicity'] : 300; // default 5 minutes (300 seconds)

        if ($latestStartedAt) {
            $nextCheckTimestamp = strtotime($latestStartedAt . ' UTC') + $periodicity;

            return gmdate('Y-m-d H:i:s', $nextCheckTimestamp);
        } else {
            // If no log, schedule immediately
            return gmdate('Y-m-d H:i:s');
        }
    }

    /**
     * Checks for monitor updates and runs immediate checks if needed.
     *
     * @return void
     */
    private function checkMonitorUpdates(): void
    {
        $stmt = $this->pdo->prepare('SELECT monitor_id FROM monitor_updates WHERE must_update = 1');
        $stmt->execute();
        $monitorIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($monitorIds)) {
            return;
        }

        echo "Found " . count($monitorIds) . " monitor(s) requiring updates: " . implode(", ", $monitorIds) . "\n";

        foreach ($monitorIds as $monitorId) {
            // Reload monitor from DB
            $stmtMonitor = $this->pdo->prepare('SELECT * FROM monitors WHERE id = :id');
            $stmtMonitor->execute(['id' => $monitorId]);
            $monitor = $stmtMonitor->fetch(PDO::FETCH_ASSOC);

            if (! $monitor) {
                echo "Monitor ID {$monitorId} not found in database, skipping.\n";
                continue;
            }

            echo "Processing update for monitor ID {$monitorId} [{$monitor['label']}]\n";

            // Update or add monitor in pingMonitors or websiteMonitors
            if ($monitor['monitor_type'] === 'ping') {
                $found = false;
                foreach ($this->pingMonitors as $i => $m) {
                    if (isset($m['id']) && $m['id'] == $monitorId) {
                        $this->pingMonitors[$i] = $monitor;
                        echo "Updated ping monitor ID {$monitorId} in main list\n";
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->pingMonitors[] = $monitor;
                    echo "Added new ping monitor ID {$monitorId} to main list\n";
                }
            } elseif ($monitor['monitor_type'] === 'website') {
                $found = false;
                foreach ($this->websiteMonitors as $i => $m) {
                    if (isset($m['id']) && $m['id'] == $monitorId) {
                        $this->websiteMonitors[$i] = $monitor;
                        echo "Updated website monitor ID {$monitorId} in main list\n";
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->websiteMonitors[] = $monitor;
                    echo "Added new website monitor ID {$monitorId} to main list\n";
                }
            }

            // Update or add monitorNextChecks entry
            $found = false;
            foreach ($this->monitorNextChecks as $i => $mc) {
                if (isset($mc['monitor']['id']) && $mc['monitor']['id'] == $monitorId) {
                    $this->monitorNextChecks[$i]['monitor'] = $monitor;
                    $this->monitorNextChecks[$i]['nextCheckTime'] = gmdate('Y-m-d H:i:s'); // run immediately
                    echo "Updated monitor ID {$monitorId} in scheduling queue for immediate check\n";
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->monitorNextChecks[] = [
                    'monitor' => $monitor,
                    'nextCheckTime' => gmdate('Y-m-d H:i:s'), // run immediately
                ];
                echo "Added new monitor ID {$monitorId} to scheduling queue for immediate check\n";
            }

            // Set must_update to 0
            $stmtUpdate = $this->pdo->prepare('UPDATE monitor_updates SET must_update = 0 WHERE monitor_id = :monitor_id');
            $stmtUpdate->execute(['monitor_id' => $monitorId]);

            // Run check immediately
            echo "Running immediate check for updated monitor ID {$monitorId}\n";
            try {
                if ($monitor['monitor_type'] === 'ping') {
                    $this->runPingMonitorCheck($monitor);
                } elseif ($monitor['monitor_type'] === 'website') {
                    $this->runWebsiteMonitorCheck($monitor);
                }
            } catch (PDOException $e) {
                $errorMessage = $e->getMessage();
                if (strpos($errorMessage, 'Integrity constraint violation') !== false || strpos($errorMessage, 'foreign key constraint') !== false || $e->getCode() == 23000) {
                    echo "Monitor ID {$monitorId} appears to be deleted from DB during update check, removing from loop\n";
                    $this->removeMonitorFromLoop($monitorId);
                    continue; // Skip further processing for this monitor
                } else {
                    echo "Failed to run check for monitor ID {$monitorId}: {$errorMessage}\n";
                }
            }
            
            // Update next check time after immediate check
            foreach ($this->monitorNextChecks as $i => $mc) {
                if (isset($mc['monitor']['id']) && $mc['monitor']['id'] == $monitorId) {
                    $this->monitorNextChecks[$i]['nextCheckTime'] = $this->calculateNextCheckTime($monitor);
                    echo "Rescheduled monitor ID {$monitorId} for next check at: {$this->monitorNextChecks[$i]['nextCheckTime']}\n";
                    break;
                }
            }
        }
    }

    /**
     * Runs the main monitoring loop, checking monitors at scheduled times.
     *
     * @return void
     */
    public function runChecks(): void
    {
        echo "=== Running main loop ===\n";

        while (true) {
            $currentTime = time();

            // Check for monitor updates every loop
            $this->checkMonitorUpdates();

            foreach ($this->monitorNextChecks as $index => $monitorCheck) {
                if (!isset($monitorCheck['monitor']) || !is_array($monitorCheck['monitor'])) {
                    echo "Warning: Invalid monitor check entry at index {$index}, skipping\n";
                    continue;
                }

                $monitor = $monitorCheck['monitor'];
                if (!isset($monitor['id']) || !isset($monitor['monitor_type'])) {
                    echo "Warning: Monitor at index {$index} is missing required fields, skipping\n";
                    continue;
                }

                $nextCheckTimestamp = strtotime($monitorCheck['nextCheckTime'] . ' UTC');
                $x = $currentTime >= $nextCheckTimestamp;
                // echo "Current: " . date('Y-m-d H:i:s', $currentTime) . " - Next Check: " . $monitorCheck['nextCheckTime'] . " - " . ($x ? 'true' : 'false') . "\n";
                if ($currentTime >= $nextCheckTimestamp) {
                    echo "Running check for monitor ID {$monitor['id']} at ".gmdate('Y-m-d H:i:s')."\n";

                    try {
                        if ($monitor['monitor_type'] === 'ping') {
                            $this->runPingMonitorCheck($monitor);
                        } elseif ($monitor['monitor_type'] === 'website') {
                            $this->runWebsiteMonitorCheck($monitor);
                        }
                    } catch (PDOException $e) {
                        $errorMessage = $e->getMessage();
                        if (strpos($errorMessage, 'Integrity constraint violation') !== false || strpos($errorMessage, 'foreign key constraint') !== false || $e->getCode() == 23000) {
                            echo "Monitor ID {$monitor['id']} appears to be deleted from DB, removing from loop\n";
                            $this->removeMonitorFromLoop($monitor['id']);
                            continue; // Skip updating next check time
                        } else {
                            echo "Failed to run check for monitor ID {$monitor['id']}: {$errorMessage}\n";
                        }
                    }

                    // Update next check for this specific monitor
                    $this->monitorNextChecks[$index]['nextCheckTime'] = $this->calculateNextCheckTime($monitor);
                    echo "Next check for monitor ID {$monitor['id']} scheduled at: ".$this->monitorNextChecks[$index]['nextCheckTime']."\n";
                }
            }
            sleep(5); // Wait 5 seconds before next loop
        }

    }
}

$checker = new MonitorChecker;
$checker->initializeMonitorChecks();

$checker->runChecks();
