<?php
// =========================================================================
// PHP BACKEND: FILE I/O API LOGIC (Handles AJAX Requests)
// =========================================================================

// FIX FOR ANDROID/NO-SHELL: Use sys_get_temp_dir() for a writable location 
// to avoid strict file permission issues in the web root.
define('DATA_FILE', sys_get_temp_dir() . '/sightings.txt');

$response = ['status' => 'error', 'message' => 'Invalid Request'];

// Check if this script is being called as an API endpoint via AJAX
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Set JSON header immediately
    header('Content-Type: application/json');

    // --- 1. GET ALL SIGHTINGS (READ) ---
    if ($action === 'get_sightings') {
        $file_content = @file_get_contents(DATA_FILE) ?: '';
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
                'note' => htmlspecialchars(trim($data['note'])),
                'time' => date('Y-m-d H:i:s') // Used as a unique ID for deletion
            ];
            
            $json_line = json_encode($new_sighting) . "\n";
            
            // Append data to the file
            if (@file_put_contents(DATA_FILE, $json_line, FILE_APPEND | LOCK_EX) !== false) {
                $response = ['status' => 'success', 'message' => 'Sighting added.'];
            } else {
                $response['message'] = 'Critical Server Error: Cannot write to file.';
            }
        }
    }

    // --- 3. DELETE SIGHTING ---
    elseif ($action === 'delete_sighting' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['time'])) {
            $response['message'] = 'Missing sighting identifier (time).';
        } else {
            $delete_time = $data['time'];
            
            // 1. Read all existing sightings
            $file_content = @file_get_contents(DATA_FILE) ?: '';
            $lines = array_filter(explode("\n", $file_content));
            
            $new_lines = [];
            $deleted = false;
            
            // 2. Filter out the entry to delete
            foreach ($lines as $line) {
                $sighting = json_decode($line, true);
                
                if ($sighting['time'] !== $delete_time) {
                    $new_lines[] = $line;
                } else {
                    $deleted = true;
                }
            }
            
            if ($deleted) {
                // 3. Write the remaining entries back to the file (OVERWRITE)
                $new_content = implode("\n", $new_lines);
                
                if (@file_put_contents(DATA_FILE, $new_content . "\n", LOCK_EX) !== false) {
                    $response = ['status' => 'success', 'message' => 'Sighting successfully deleted.'];
                } else {
                    $response['message'] = 'Critical Server Error: Cannot write to file to complete deletion.';
                }
            } else {
                $response['message'] = 'Error: Sighting not found.';
            }
        }
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
    <title>Deer Sighting and Fishing Reporter</title>
    
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
        }
        #header {
            text-align: center;
            padding: 15px 0;
            background-color: var(--color-bg-secondary);
            border-bottom: 1px solid var(--border-color);
        }
        #map { 
            height: 70vh; 
            width: 100%; 
            border-bottom: 3px solid var(--color-accent); 
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
        
        button, textarea { 
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
    </style>
</head>
<body>

    <header id="header">
        <h1>🦌 Area Sighting and 🐟 Fish Map</h1>
    </header>

    <div id="map"></div>

    <div id="controls">
        <div class="flex-container">
            <button onclick="document.getElementById('sightingModal').style.display='block'">Report New Sighting</button>
            <button onclick="loadSightings()">Refresh Map</button>
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
                        // Create the delete button HTML, using the timestamp as the unique identifier
                        const deleteButtonHtml = `<button onclick="deleteSighting('${sighting.time}')" style="margin-top:5px; background-color:var(--color-error); border:none; color:white; padding:5px; border-radius:3px; cursor:pointer; width:auto;">Delete</button>`;
                        
                        L.marker([sighting.lat, sighting.lng]).addTo(map)
                            .bindPopup(`<b>Spotted: ${new Date(sighting.time).toLocaleString()}</b><br>${sighting.note}${deleteButtonHtml}`);
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

        // 5. Function to Delete a Sighting
        async function deleteSighting(timeIdentifier) {
            if (!confirm("Are you sure you want to delete this sighting? This action cannot be undone.")) {
                return;
            }

            try {
                const response = await fetch(API_URL + '?action=delete_sighting', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ time: timeIdentifier })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    // Update map status and refresh
                    document.getElementById('messageArea').textContent = result.message;
                    document.getElementById('messageArea').className = 'status-msg error'; 
                    loadSightings(); 
                } else {
                    alert(`Deletion failed: ${result.message}`);
                }
            } catch (error) {
                alert("Network error during deletion.");
            }
        }
        
        window.onload = loadSightings;

    </script>
</body>
</html>
