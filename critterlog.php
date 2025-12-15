<?php
// =========================================================================
// PHP BACKEND: FILE I/O API LOGIC (Handles AJAX Requests)
// =========================================================================

// --- 0. SESSION & SECURITY SETUP ---
session_start();
// NOTE: For better security, replace this with a strong hash stored externally
define('APP_PASSWORD', 'christmas'); 
define('DATA_FILE', __DIR__ . '/sightings.txt');
// REMOVED: Mass Delete Password constant and logic

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
                        // Ensure 'category' is set for old data compatibility
                        if (!isset($decoded_line['category'])) {
                            $decoded_line['category'] = 'other';
                        }
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
            
            if (!isset($data['lat']) || !isset($data['lng']) || !isset($data['category'])) {
                $response['message'] = 'Missing coordinates or category.';
            } else {
                // Validate category input
                $valid_categories = ['fish', 'deer', 'other'];
                $category = strtolower(trim($data['category']));
                if (!in_array($category, $valid_categories)) {
                    $category = 'other'; // Default to 'other' if invalid
                }
                
                $new_sighting = [
                    'lat' => (float)$data['lat'],
                    'lng' => (float)$data['lng'],
                    'category' => $category, // NEW: Sighting Category
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

        // --- 3. MASS DELETE ALL SIGHTINGS (REMOVED) ---
        // elseif ($action === 'mass_delete_sightings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // ... (removed logic)
        // }
        
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

            /* NEW: Custom Marker Colors */
            --color-fish: #1e90ff; /* Dodger Blue */
            --color-deer: #b8860b; /* Dark Goldenrod */
            --color-other: #6a5acd; /* Slate Blue */
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
        
        button, textarea, input[type="password"], select { /* Added select for category */
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
        
        /* New CSS to style the custom markers */
        .leaflet-marker-icon.deer-icon { background-color: var(--color-deer); }
        .leaflet-marker-icon.fish-icon { background-color: var(--color-fish); }
        .leaflet-marker-icon.other-icon { background-color: var(--color-other); }

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
            <button onclick="handleLogout()" style="background-color: var(--color-error);">Log Out</button>
        </div>
        <div id="messageArea" class="status-msg">Map is loading.</div> 
    </div>

    <div id="sightingModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="document.getElementById('sightingModal').style.display='none'">&times;</span>
            <h2>Report Critter Sighting</h2>
            <p>1. **Click** a spot on the map.<br>2. **Select** category & **Fill** out notes.</p>
            <input type="hidden" id="sightingLat">
            <input type="hidden" id="sightingLng">
            
            <p><strong>Location:</strong> <span id="coordsDisplay">Waiting for map click...</span></p>

            <p><label for="sightingCategory">Category:</label></p>
            <select id="sightingCategory">
                <option value="deer">Deer 🦌</option>
                <option value="fish">Fish 🐟</option>
                <option value="other">Other 🐾</option>
            </select>
            
            <p><label for="sightingNote">Notes (e.g., #, size, direction):</label></p>
            <textarea id="sightingNote" placeholder="E.g., 3 bucks moving east" rows="3"></textarea>
            
            <button style="margin-top: 15px;" onclick="submitSighting()">Submit Sighting</button>
            <div id="modalStatus" class="status-msg"></div>
        </div>
    </div>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        
    <script>
        // =========================================================================
        // JAVASCRIPT FRONTEND: MAP & SPA LOGIC
        // =========================================================================
        const API_URL = '<?php echo basename(__FILE__); ?>'; 
        
        // 1. Custom Icon Setup (for colored markers)
        const getCustomIcon = (category) => {
            let colorClass = 'other-icon';
            if (category === 'fish') {
                colorClass = 'fish-icon';
            } else if (category === 'deer') {
                colorClass = 'deer-icon';
            }
            
            return L.divIcon({
                className: `custom-marker ${colorClass}`,
                html: '<div></div>', // Empty div, will be styled in CSS
                iconSize: [20, 20], // Size of the icon
                iconAnchor: [10, 20], // Point of the icon which will correspond to marker's location
                popupAnchor: [0, -20] // Point from which the popup should open relative to the iconAnchor
            });
        };

        // 2. Map Initialization 
        const map = L.map('map').setView([46.4312, -95.6318], 12); 

        // Using OpenStreetMap with HTTP protocol
        L.tileLayer('http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        let tempMarker = null; 

        // 3. Map Click Handler (Allows map click regardless of modal state)
        map.on('click', function(e) {
            
            if (tempMarker) {
                map.removeLayer(tempMarker);
            }
            
            // Use a temporary default icon for the selection marker
            tempMarker = L.marker(e.latlng, { 
                draggable: true,
                icon: getCustomIcon('other') 
            }).addTo(map)
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
        
        // 4. Function to Load and Plot Existing Sightings
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
                        const icon = getCustomIcon(sighting.category); // Use custom icon
                        L.marker([sighting.lat, sighting.lng], { icon: icon }).addTo(map)
                            .bindPopup(`<b>${sighting.category.toUpperCase()} Spotted: ${new Date(sighting.time).toLocaleString()}</b><br>${sighting.note}`);
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

        // 5. Function to Submit a New Sighting via AJAX
        async function submitSighting() {
            const lat = document.getElementById('sightingLat').value;
            const lng = document.getElementById('sightingLng').value;
            const category = document.getElementById('sightingCategory').value; // NEW: Get category
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
                    // NEW: Pass category in the body
                    body: JSON.stringify({ lat: lat, lng: lng, note: note, category: category })
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
        
        // 6. Logout function (No change)
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
