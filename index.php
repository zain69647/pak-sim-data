<?php
/**
 * PAK SIM DATABASE 
 * Real Database Integration for Authentic Statistics
 */

// Disable error display for a cleaner user experience in production
error_reporting(0);
ini_set('display_errors', 0);

session_start();

// Include configuration
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists('config.php')) {
    require_once 'config.php';
} else {
    die("Error: config.php not found. Please ensure config.php is in the same directory as index.php.");
}

$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$result = null;
$error = null;
$lookupNote = null;

function fetchSimRecords($searchValue, &$fetchError) {
    $apiUrl = "https://wasifali-sim-info.netlify.app/api/search?phone=" . urlencode($searchValue);

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curl_error)) {
            $fetchError = "Unable to connect to the database. Please try again later.";
            return null;
        }
    } else {
        $opts = array(
            'http' => array(
                'method'  => 'GET',
                'header'  => "Content-type: application/json\r\nUser-Agent: Mozilla/5.0",
                'timeout' => 15
            ),
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
            )
        );
        $context = stream_context_create($opts);
        $response = @file_get_contents($apiUrl, false, $context);

        if ($response === false) {
            $fetchError = "Unable to connect to the database. Please try again later.";
            return null;
        }
    }

    $decoded = json_decode($response, true);

    if (!$decoded || (empty($decoded['success']) && empty($decoded['records']))) {
        $fetchError = "No records found for this search.";
        return null;
    }

    return $decoded;
}

function extractRecordCnic($record) {
    $cnic = $record['CNIC'] ?? $record['cnic'] ?? $record['id'] ?? '';
    return preg_replace('/\D/', '', $cnic);
}

if (!empty($query)) {
    // Basic validation for phone or CNIC
    if (!preg_match('/^[0-9]{10,13}$/', $query)) {
        $error = "Please enter a valid Phone Number or CNIC (digits only).";
    } else {
        // Check if query is a CNIC (13 digits) or phone (10-12 digits)
        $isCNIC = (strlen($query) === 13);

        $fetchError = null;
        $result = fetchSimRecords($query, $fetchError);

        if (!$result) {
            $error = $fetchError;
        } else {
            if (!$isCNIC && !empty($result['records'][0])) {
                $linkedCnic = extractRecordCnic($result['records'][0]);

                if (preg_match('/^[0-9]{13}$/', $linkedCnic)) {
                    $cnicFetchError = null;
                    $cnicResult = fetchSimRecords($linkedCnic, $cnicFetchError);

                    if ($cnicResult && !empty($cnicResult['records'])) {
                        $result = $cnicResult;
                        $lookupNote = "Showing all available records linked to CNIC " . $linkedCnic . ".";
                    } else {
                        $lookupNote = "Showing the phone record found. No additional records were available for its CNIC.";
                    }
                }
            }

            $results_count = isset($result['records']) ? count($result['records']) : 0;
            if (function_exists('logSearch')) {
                logSearch($query, $results_count);
            }
        }
    }
}

$displayRecords = [];

if ($result && isset($result['records']) && count($result['records']) > 0) {
    foreach ($result['records'] as $record) {
        $displayRecords[] = [
            'Name' => $record['Name'] ?? $record['NAME'] ?? $record['name'] ?? 'N/A',
            'CNIC' => $record['CNIC'] ?? $record['cnic'] ?? $record['id'] ?? 'N/A',
            'Phone' => $record['Mobile'] ?? $record['NUMBER'] ?? $record['phone'] ?? 'N/A',
            'Network' => $record['Network'] ?? $record['NETWORK'] ?? 'Unknown',
            'Address' => $record['Address'] ?? $record['ADDRESS'] ?? $record['address'] ?? 'N/A'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PAK SIM DATABASE</title>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.php">
</head>
<body>
    <main class="page-shell">
        <header class="app-header">
            <div>
                <div class="brand-mark">Live Lookup Portal</div>
                <h1 class="app-title premium-font">PAK SIM DATABASE</h1>
                <p class="app-subtitle">Search by phone number or CNIC using a simple, readable interface built for desktop and mobile screens.</p>
            </div>
            <div class="status-pill">System Live</div>
        </header>

        <section class="content-area">
            <div>
                <div class="panel search-panel">
                    <h2 class="panel-title">Find a Record</h2>
                    <p class="panel-copy">Enter digits only. Phone numbers can be 10 to 12 digits, and CNIC searches use 13 digits.</p>
                    <form action="index.php" method="GET" class="search-form" id="searchForm">
                        <div class="input-wrap">
                            <svg class="input-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-4.35-4.35m1.1-5.4a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
                            </svg>
                            <input type="text" name="query" id="searchInput" value="<?php echo htmlspecialchars($query); ?>" placeholder="03001234567 or 3520212345678" class="search-input" inputmode="numeric" pattern="[0-9]{10,13}" required>
                        </div>
                        <button type="submit" class="primary-button">Search</button>
                <?php if (!empty($query)): ?>
                            <a href="index.php" class="secondary-button">Clear</a>
                <?php endif; ?>
                    </form>

                    <div class="helper-row">
                        <span class="helper-chip">Digits only</span>
                        <span class="helper-chip">10-13 characters</span>
                        <span class="helper-chip">Mobile friendly</span>
                    </div>

                    <div id="loadingContainer" class="loading-container hidden">
                        <div class="loading-meta">
                            <span>Searching database...</span>
                            <span id="loadingPercent">0%</span>
                        </div>
                        <div class="loading-track">
                            <div id="loadingBar" class="loading-bar"></div>
                        </div>
                    </div>

                    <?php if ($error): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
                    <?php if ($lookupNote): ?><p class="notice"><?php echo htmlspecialchars($lookupNote); ?></p><?php endif; ?>
                    <?php if (isset($db_error) && $db_error): ?><p class="notice">Database: Using file logs in fallback mode.</p><?php endif; ?>
                </div>

        <?php if (count($displayRecords) > 0): ?>
                <section class="results-panel">
                    <div class="results-header">
                        <div>
                            <h2 class="results-title">Search Results</h2>
                            <span class="count-badge"><?php echo count($displayRecords); ?> Found</span>
                        </div>
                        <div class="bulk-actions">
                            <button class="action-button primary-action" onclick='copyAllRecords(this, <?php echo htmlspecialchars(json_encode($displayRecords), ENT_QUOTES, "UTF-8"); ?>)'>Copy All</button>
                            <button class="action-button" onclick='shareAllRecords(<?php echo htmlspecialchars(json_encode($displayRecords), ENT_QUOTES, "UTF-8"); ?>)'>Share All</button>
                        </div>
                    </div>

                    <div class="results-list">
                        <?php foreach ($displayRecords as $record): ?>
                            <article class="result-item">
                                <div class="record-grid">
                                    <div class="record-field">
                                        <div class="label-small">Name</div>
                                        <div class="value-small"><?php echo htmlspecialchars($record['Name']); ?></div>
                                    </div>
                                    <div class="record-field">
                                        <div class="label-small">Phone Number</div>
                                        <div class="value-small value-accent"><?php echo htmlspecialchars($record['Phone']); ?></div>
                                    </div>
                                    <div class="record-field">
                                        <div class="label-small">ID Number (CNIC)</div>
                                        <div class="value-small value-accent"><?php echo htmlspecialchars($record['CNIC']); ?></div>
                                    </div>
                                    <div class="record-field">
                                        <div class="label-small">Network</div>
                                        <div class="value-small"><?php echo htmlspecialchars($record['Network']); ?></div>
                                    </div>
                                    <div class="record-field full">
                                        <div class="label-small">Address</div>
                                        <div class="value-small"><?php echo htmlspecialchars($record['Address']); ?></div>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
        <?php elseif ($result && isset($result['records'])): ?>
                <div class="empty-state">
                    No records found. Try another phone number or CNIC.
                </div>
        <?php endif; ?>
            </div>

        </section>
    </main>

    <script>
        document.getElementById('searchForm').addEventListener('submit', function(e) {
            const loadingContainer = document.getElementById('loadingContainer');
            const loadingBar = document.getElementById('loadingBar');
            const loadingPercent = document.getElementById('loadingPercent');
            
            loadingContainer.classList.remove('hidden');
            let progress = 0;
            const interval = setInterval(() => {
                progress += Math.random() * 15;
                if (progress > 95) {
                    progress = 95;
                    clearInterval(interval);
                }
                loadingBar.style.width = progress + '%';
                loadingPercent.innerText = Math.round(progress) + '%';
            }, 150);
        });

        function formatRecords(records) {
            return records.map((data, index) => {
                return `Record ${index + 1}\nName: ${data.Name}\nPhone: ${data.Phone}\nCNIC: ${data.CNIC}\nNetwork: ${data.Network}\nAddress: ${data.Address}`;
            }).join('\n\n');
        }

        function copyAllRecords(btn, records) {
            const text = formatRecords(records);
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    handleCopySuccess(btn);
                }).catch(err => {
                    console.error('Clipboard API failed: ', err);
                    fallbackCopyTextToClipboard(text, btn);
                });
            } else {
                fallbackCopyTextToClipboard(text, btn);
            }
        }

        function fallbackCopyTextToClipboard(text, btn) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.left = "-9999px";
            textArea.style.top = "0";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                const successful = document.execCommand('copy');
                if (successful) handleCopySuccess(btn);
            } catch (err) {
                console.error('Fallback copy failed: ', err);
            }
            document.body.removeChild(textArea);
        }

        function handleCopySuccess(btn) {
            showNotification('✓ Copied!');
            const originalText = btn.innerText;
            btn.innerText = 'Copied!';
            setTimeout(() => { btn.innerText = originalText; }, 2000);
        }
        
        function shareAllRecords(records) {
            const text = `SIM Info Results:\n\n${formatRecords(records)}\n\nVisit: ${window.location.href}`;
            if (navigator.share) { 
                navigator.share({ title: 'PAK SIM DB', text: text }); 
            } else { 
                navigator.clipboard.writeText(text).then(() => { showNotification('✓ Results Copied!'); }); 
            }
        }
        
        function showNotification(message) {
            const notification = document.createElement('div');
            notification.className = 'copy-success';
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 500);
            }, 2000);
        }
    </script>
</body>
</html>
