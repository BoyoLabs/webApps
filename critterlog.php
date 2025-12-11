<?php
// =========================================================================
// PHP BACKEND: FILE I/O API LOGIC (Handles AJAX Requests)
// =========================================================================

// --- 0. SESSION & SECURITY SETUP ---
session_start();
// NOTE: For better security, replace this with a strong hash stored externally, am not doing this for this app because its not terribly concerned with it here.
define('APP_PASSWORD', 'chooseapassword'); 
//NOTE: you do need a second blank file in the same directory named "sightings.txt"
define('DATA_FILE', __DIR__ . '/sightings.txt');
// NEW: Mass Delete Password
define('MASS_DELETE_PASSWORD', 'chooseanotherpassword');

$response = ['status' => 'error', 'message' => 'Invalid Request'];
$is_authenticated = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;


// Check if this script is being called as an API endpoint via AJAX
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Set JSON header immediately
    header('Content-Type: application/json');

    // --- NON-PROTECTED ACTIONS (LOGIN/LOGOUT) ---
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (isset($data['password']) && $data['password'] === APP_PASSWORD) {
            $_SESSION['logged_in'] = true;
            $response = ['status' => 'success', 'message' => 'Login successful.'];
        } else {
            $response = ['status' => 'error', 'message' => 'Invalid password.'];
        }
    } elseif ($action === 'logout') {
        session_unset();
        session_destroy();
        $response = ['status' => 'success', 'message' => 'Logged out.'];
    }

    // --- PROTECTED API ACTIONS ---
    elseif ($is_authenticated) {

        // --- 1. GET ALL SIGHTINGS (READ) ---
        if ($action === 'get_sightings') {
            // Read file content and normalize line endings for safe splitting
            $file_content = @file_get_contents(DATA_FILE);
            
            // Only process if reading the file was successful
            if ($file_content !== false) {
                $file_content = str_replace(["\r\n", "\r"], "\n", $file_content);
                $lines = array_filter(explode("\n", $file_content));
                
                $sightings = [];
                foreach ($lines as $line) {
                    $decoded_line = json_decode($line, true);
                    // Only add valid JSON objects
                    if ($decoded_line !== null) {
                        $sightings[] = $decoded_line;
                    }
                }
                
                $response = ['status' => 'success', 'data' => $sightings];
            } else {
                 $response = ['status' => 'error', 'message' => 'Critical Server Error: Cannot read data file.'];
            }
        } 
        
        // --- 2. ADD NEW SIGHTING (WRITE) ---
        elseif ($action === 'add_sighting' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents("php://input"), true);
            
            if (!isset($data['lat']) || !isset($data['lng'])) {
                $response['message'] = 'Missing coordinates.';
            } else {
                $new_sighting = [
                    'lat' => (float)$data['lat'],
                    'lng' => (float)$data['lng'],
                    // Ensure 'note' is set to prevent errors if empty
                    'note' => htmlspecialchars(trim($data['note'] ?? '')), 
                    'time' => date('Y-m-d H:i:s') // Used as a unique ID for deletion
                ];
                
                $json_line = json_encode($new_sighting) . "\n";
                
                // FIX: Removed LOCK_EX, keeping FILE_APPEND
                if (@file_put_contents(DATA_FILE, $json_line, FILE_APPEND) !== false) {
                    $response = ['status' => 'success', 'message' => 'Sighting added.'];
                } else {
                    $response['message'] = 'Critical Server Error: Cannot write to file. (Permissions or path issue)';
                }
            }
        }

        // --- 3. MASS DELETE ALL SIGHTINGS (NEW PROTECTED ACTION) ---
        elseif ($action === 'mass_delete_sightings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents("php://input"), true);

            if (!isset($data['password']) || $data['password'] !== MASS_DELETE_PASSWORD) {
                $response = ['status' => 'unauthorized', 'message' => 'Incorrect mass delete password.'];
            } else {
                // FIX: Removed LOCK_EX. Overwrite the file with an empty string to clear all data
                if (@file_put_contents(DATA_FILE, '') !== false) {
                    $response = ['status' => 'success', 'message' => 'All sightings successfully deleted.'];
                } else {
                    $response['message'] = 'Critical Server Error: Cannot clear the data file.';
                }
            }
        }
        
        // --- 4. DELETE SIGHTING (OLD - REMOVED FUNCTIONALITY) ---
        // The `delete_sighting` action is no longer supported by the frontend.
        /*
        elseif ($action === 'delete_sighting' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // ... (original delete logic)
        }
        */
    }
    // Handle unauthenticated request to a protected endpoint
    elseif (!$is_authenticated) {
        $response = ['status' => 'unauthorized', 'message' => 'Authentication required.'];
    }
    
    // Output the JSON response and terminate PHP execution
    echo json_encode($response);
    exit; 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Critter Log</title>
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    
    <style>
        /* =========================================================================
           DARK THEME CSS STYLING
           ========================================================================= */
        :root {
            --color-bg-primary: #1e1e1e;
            --color-bg-secondary: #252526;
            --color-text-light: #ffffff;
            --color-accent: #007acc; 
            --color-accent-hover: #005f99;
            --color-success: #38b44a;
            --color-error: #e81123;
            --border-color: #333;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            background-color: var(--color-bg-primary); 
            color: var(--color-text-light);
            display: flex; /* Setup for login center */
            flex-direction: column;
            min-height: 100vh;
        }
        #header {
            text-align: center;
            padding: 15px 0;
            background-color: var(--color-bg-secondary);
            border-bottom: 1px solid var(--border-color);
        }
        #map { 
            height: 35vh; 
            width: 100%; 
            border-bottom: 3px solid var(--color-accent); 
            flex-grow: 1; /* Allow map to take available space */
        }
        #controls { 
            padding: 15px; 
            background-color: var(--color-bg-secondary); 
            border-top: 1px solid var(--border-color);
        }
        .flex-container { 
            display: flex; 
            justify-content: space-around; 
            gap: 10px; 
            margin-bottom: 15px; 
        }
        
        button, textarea, input[type="password"] { /* Added password input */
            padding: 12px; 
            border-radius: 6px; 
            border: 1px solid var(--border-color); 
            font-size: 16px; 
            width: 100%; 
            box-sizing: border-box; 
            background-color: #3c3c3c;
            color: var(--color-text-light);
        }
        button { 
            background-color: var(--color-accent); 
            font-weight: bold; 
            cursor: pointer; 
            transition: background-color 0.3s;
        }
        button:hover { background-color: var(--color-accent-hover); }
        
        .modal {
            display: none; 
            position: fixed; top: 0; left: 0; 
            width: 100%; height: 100%; 
            background-color: rgba(0,0,0,0.8); 
            z-index: 1000;
        }
        .modal-content {
            background-color: var(--color-bg-secondary); 
            margin: 10% auto; padding: 25px;
            border-radius: 8px; width: 90%; max-width: 450px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.5);
        }
        .close { 
            float: right; 
            font-size: 28px; 
            font-weight: bold; 
            color: var(--color-text-light);
            cursor: pointer; 
        }

        .status-msg { 
            margin-top: 10px; padding: 10px; border-radius: 4px; 
            font-weight: bold;
        }
        .success { background-color: var(--color-success); color: var(--color-bg-primary); }
        .error { background-color: var(--color-error); color: var(--color-text-light); }
        
        /* LOGIN PAGE STYLES */
        .login-container {
            flex-grow: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-box {
            background-color: var(--color-bg-secondary);
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.7);
            max-width: 350px;
            width: 100%;
            text-align: center;
        }
        .login-box h2 { margin-top: 0; }
        .login-box button { margin-top: 20px; }
        .login-box input { margin-bottom: 15px; }

    </style>
</head>
<body>

<?php if (!$is_authenticated): ?>

    <header id="header">
        <h1>Critter Log - Login</h1>
    </header>
    <div class="login-container">
        <div class="login-box">
            <h2>Access Required</h2>
            <input type="password" id="loginPassword" placeholder="Enter Password" required>
            <button onclick="attemptLogin()">Log In</button>
            <div id="loginStatus" class="status-msg"></div>
            <p style="margin-top: 20px; font-size: 0.9em; color: #aaa;">An App by Dave of Boyo Labs: for my friends and family :)</p>
        </div>
    </div>

    <script>
        // =========================================================================
        // JAVASCRIPT FRONTEND: LOGIN LOGIC
        // =========================================================================
        const API_URL = '<?php echo basename(__FILE__); ?>'; 

        async function attemptLogin() {
            const password = document.getElementById('loginPassword').value;
            const loginStatus = document.getElementById('loginStatus');

            loginStatus.textContent = 'Attempting login...';
            loginStatus.className = 'status-msg';

            try {
                const response = await fetch(API_URL + '?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: password })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    loginStatus.textContent = 'Success! Redirecting...';
                    loginStatus.className = 'status-msg success';
                    // Reload page to load the main content now that the session is set
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    loginStatus.textContent = result.message;
                    loginStatus.className = 'status-msg error';
                }
            } catch (error) {
                loginStatus.textContent = 'Network Error: Could not connect to server.';
                loginStatus.className = 'status-msg error';
            }
        }
        
        // Allow pressing Enter to submit
        document.getElementById('loginPassword').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                attemptLogin();
            }
        });

    </script>

<?php else: ?>

    <header id="header">
        <h1>Critter Log</h1>
    </header>

    <div id="map"></div>

    <div id="controls">
        <div class="flex-container">
            <button onclick="document.getElementById('sightingModal').style.display='block'">Report New Sighting</button>
            <button onclick="loadSightings()">Refresh Map</button>
            <button onclick="document.getElementById('massDeleteModal').style.display='block'" style="background-color: #8B0000;">Delete All Sightings</button>
            <button onclick="handleLogout()" style="background-color: var(--color-error);">Log Out</button>
        </div>
        <div id="messageArea" class="status-msg">Map is loading.</div> 
    </div>

    <div id="sightingModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="document.getElementById('sightingModal').style.display='none'">&times;</span>
            <h2>Report Deer Sighting</h2>
            <p>1. **Click** a spot on the map.<br>2. **Fill** out the notes.</p>
            <input type="hidden" id="sightingLat">
            <input type="hidden" id="sightingLng">
            
            <p><strong>Location:</strong> <span id="coordsDisplay">Waiting for map click...</span></p>
            <p><label for="sightingNote">Notes (e.g., # of deer, direction):</label></p>
            <textarea id="sightingNote" placeholder="E.g., 3 bucks moving east" rows="3"></textarea>
            
            <button style="margin-top: 15px;" onclick="submitSighting()">Submit Sighting</button>
            <div id="modalStatus" class="status-msg"></div>
        </div>
    </div>
    
    <div id="massDeleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="document.getElementById('massDeleteModal').style.display='none'">&times;</span>
            <h2>⚠️ CONFIRM DELETE ALL ⚠️</h2>
            <p>This action will **PERMANENTLY DELETE** every single sighting.</p>
            <p>Enter the mass delete password to proceed:</p>
            <input type="password" id="massDeletePassword" placeholder="Mass Delete Password" required>
            
            <button style="margin-top: 15px; background-color: var(--color-error);" onclick="massDeleteSightings()">Confirm Delete All</button>
            <div id="massDeleteStatus" class="status-msg"></div>
        </div>
    </div>


    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        
    <script>
        // =========================================================================
        // JAVASCRIPT FRONTEND: MAP & SPA LOGIC
        // =========================================================================
        const API_URL = '<?php echo basename(__FILE__); ?>'; 
        
        // 1. Map Initialization 
        const map = L.map('map').setView([46.4312, -95.6318], 12); 

        // Using OpenStreetMap with HTTP protocol
        L.tileLayer('http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        let tempMarker = null; 

        // 2. Map Click Handler (Allows map click regardless of modal state)
        map.on('click', function(e) {
            
            if (tempMarker) {
                map.removeLayer(tempMarker);
            }
            
            tempMarker = L.marker(e.latlng, { draggable: true }).addTo(map)
                .bindPopup("Drag me if needed").openPopup();
            
            const updateCoords = (latlng) => {
                document.getElementById('sightingLat').value = latlng.lat.toFixed(6);
                document.getElementById('sightingLng').value = latlng.lng.toFixed(6);
                
                document.getElementById('messageArea').textContent = 
                    `Location Selected: Lat ${latlng.lat.toFixed(4)}, Lng ${latlng.lng.toFixed(4)}. Click "Report New Sighting" to finalize.`;
                document.getElementById('messageArea').className = 'status-msg success'; 

                document.getElementById('coordsDisplay').textContent = 
                    `Lat: ${latlng.lat.toFixed(4)}, Lng: ${latlng.lng.toFixed(4)}`;
            };

            updateCoords(e.latlng);
            tempMarker.on('dragend', (event) => updateCoords(event.target.getLatLng()));
        });
        
        // 3. Function to Load and Plot Existing Sightings
        async function loadSightings() {
            map.eachLayer(function(layer) {
                if (layer instanceof L.Marker && layer !== tempMarker) { 
                    map.removeLayer(layer);
                }
            });

            document.getElementById('messageArea').className = 'status-msg';
            document.getElementById('messageArea').textContent = 'Loading sightings...';

            try {
                const response = await fetch(API_URL + '?action=get_sightings');
                const result = await response.json(); 
                
                if (result.status === 'success') {
                    result.data.forEach(sighting => {
                        // The delete button HTML is now REMOVED
                        L.marker([sighting.lat, sighting.lng]).addTo(map)
                            .bindPopup(`<b>Spotted: ${new Date(sighting.time).toLocaleString()}</b><br>${sighting.note}`);
                    });
                    document.getElementById('messageArea').textContent = `Loaded ${result.data.length} historical sightings.`;
                } else {
                    document.getElementById('messageArea').className = 'status-msg error';
                    document.getElementById('messageArea').textContent = `Error loading data: ${result.message}`;
                }
            } catch (error) {
                document.getElementById('messageArea').className = 'status-msg error';
                document.getElementById('messageArea').textContent = `Network Error: Could not parse server response as JSON.`;
            }
        }

        // 4. Function to Submit a New Sighting via AJAX
        async function submitSighting() {
            const lat = document.getElementById('sightingLat').value;
            const lng = document.getElementById('sightingLng').value;
            const note = document.getElementById('sightingNote').value;
            const modalStatus = document.getElementById('modalStatus');

            if (!lat || !lng) {
                modalStatus.textContent = 'Error: Please click a location on the map first.';
                modalStatus.className = 'status-msg error';
                return;
            }

            modalStatus.textContent = 'Submitting...';
            
            try {
                const response = await fetch(API_URL + '?action=add_sighting', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ lat: lat, lng: lng, note: note })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    modalStatus.textContent = 'Success! Sighting reported.';
                    modalStatus.className = 'status-msg success';
                    
                    document.getElementById('sightingNote').value = ''; 
                    
                    if (tempMarker) {
                        map.removeLayer(tempMarker);
                        tempMarker = null;
                        document.getElementById('sightingLat').value = '';
                        document.getElementById('sightingLng').value = '';
                        document.getElementById('coordsDisplay').textContent = 'Waiting for map click...';
                        document.getElementById('messageArea').textContent = 'Location recorded.';
                        document.getElementById('messageArea').className = 'status-msg';
                    }
                    
                    setTimeout(() => {
                        document.getElementById('sightingModal').style.display='none';
                        loadSightings(); 
                    }, 1500);
                } else {
                    modalStatus.textContent = `Error: ${result.message}`;
                    modalStatus.className = 'status-msg error';
                }
            } catch (error) {
                modalStatus.textContent = `Network Error: Could not parse server response as JSON.`;
                modalStatus.className = 'status-msg error';
            }
        }
        
        // 5. Function to Mass Delete All Sightings (NEW)
        async function massDeleteSightings() {
            const passwordInput = document.getElementById('massDeletePassword');
            const password = passwordInput.value;
            const modalStatus = document.getElementById('massDeleteStatus');

            if (!password) {
                modalStatus.textContent = 'Please enter the password.';
                modalStatus.className = 'status-msg error';
                return;
            }
            
            modalStatus.textContent = 'Deleting all sightings...';

            try {
                const response = await fetch(API_URL + '?action=mass_delete_sightings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: password })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    modalStatus.textContent = result.message;
                    modalStatus.className = 'status-msg success'; 
                    
                    passwordInput.value = ''; // Clear password field
                    
                    setTimeout(() => {
                        document.getElementById('massDeleteModal').style.display='none';
                        loadSightings(); // Refresh map (will be empty)
                    }, 1500);
                } else {
                    modalStatus.textContent = `Deletion failed: ${result.message}`;
                    modalStatus.className = 'status-msg error';
                }
            } catch (error) {
                modalStatus.textContent = "Network error during mass deletion.";
                modalStatus.className = 'status-msg error';
            }
        }
        
        // 6. Logout function
        async function handleLogout() {
            if (!confirm("Are you sure you want to log out?")) {
                return;
            }
            try {
                const response = await fetch(API_URL + '?action=logout');
                const result = await response.json(); 
                
                if (result.status === 'success') {
                    window.location.reload(); // Reload to show the login screen
                } else {
                    alert(`Logout failed: ${result.message}`);
                }
            } catch (error) {
                alert("Network error during logout.");
            }
        }
        
        window.onload = loadSightings;

    </script>
    
<?php endif; ?>

</body>
</html>