<?php
// --- PHP BACKEND LOGIC FOR FILE SYSTEM INTERACTION ---
$log_dir = 'logs/';
// We now initialize this to the JSON string 'null' instead of the literal null
$initial_config_json_js = 'null'; 
$saved_files = [];
$message = '';
$config_to_load_name = '';
$initial_config_name = 'Badge_Setup_1';
$show_load_modal = true; // Flag to control whether to show the file selection modal

// console_log("PHP: Script start. Log directory: $log_dir"); // Commented out to prevent redirect issues

// Helper function for PHP logging to the console (only useful during debugging, not production)
function console_log($message) {
    echo '<script>console.log("PHP Log: ' . str_replace(["\n", "\r", '"'], ['\\n', '', '\\"'], $message) . '");</script>' . "\n";
}

// 1. Check for a 'clear' parameter to force modal display (New feature)
if (isset($_GET['clear']) && $_GET['clear'] === 'true') {
    $show_load_modal = true;
    $initial_config_json_js = 'null';
    $message = 'Session cleared. Choose to load or create new.';
    $initial_config_name = 'Badge_Setup_1';
    // console_log("PHP: Session clear requested."); // Commented out to prevent redirect issues
}

// 2. Ensure the logs directory exists
if (!is_dir($log_dir)) {
    if (!mkdir($log_dir, 0777, true)) {
        $message = 'ERROR: The logs/ directory could not be created. Check server permissions.';
        console_log("PHP ERROR: Directory creation failed.");
    } else {
        console_log("PHP: Logs directory created successfully.");
    }
}

// 3. Scan the directory first to populate $saved_files array for overwrite validation
$files = glob($log_dir . '*.json');
if ($files) {
    // console_log("PHP: Found " . count($files) . " saved files."); // Commented out to prevent redirect issues
    
    // Sort files by modification time (most recent first)
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    // Store file list for the dropdown
    foreach ($files as $file) {
        $saved_files[] = basename($file);
    }
}

// 4. Handle POST request for saving the configuration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $config_data = json_decode($_POST['config_payload'], true);
    
    if ($config_data && isset($config_data['configName'])) {
        // Escape the JSON data for file storage (JSON_PRETTY_PRINT for readability)
        $json_payload = json_encode($config_data, JSON_PRETTY_PRINT);
        
        if ($action === 'save') {
            // Save as new file with timestamp
            $config_name_sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $config_data['configName']);
            $timestamp = date('Ymd_His');
            $filename = $log_dir . $config_name_sanitized . '_' . $timestamp . '.json';
            $success_message = 'Configuration saved successfully as ' . basename($filename) . '!';
            
        } else if ($action === 'overwrite' && isset($_POST['overwrite_filename'])) {
            // Overwrite existing file
            $overwrite_file = $_POST['overwrite_filename'];
            
            // Security check: ensure the filename exists and is in the logs directory
            if (in_array($overwrite_file, $saved_files)) {
                $filename = $log_dir . $overwrite_file;
                $success_message = 'Configuration updated successfully in ' . basename($filename) . '!';
            } else {
                $message = 'ERROR: Invalid file specified for overwrite.';
            }
        }
        
        if (isset($filename)) {
            if (file_put_contents($filename, $json_payload) !== false) {
                $message = $success_message;
                // Redirect to itself to prevent form resubmission and show the saved file as current
                $redirect_url = $_SERVER['SCRIPT_NAME'] . "?load_file=" . urlencode(basename($filename)) . "&msg=" . urlencode($message);
                header("Location: " . $redirect_url);
                exit();
            } else {
                $message = 'ERROR: Could not write file. Check directory permissions for ' . $log_dir;
            }
        }
    } else {
        $message = 'ERROR: Invalid configuration data received.';
    }
    
    // Log AFTER potential redirect to avoid output before headers
    console_log("PHP: Handling POST request for $action action.");
    if (isset($filename)) {
        console_log("PHP: Attempting to save to $filename. Payload size: " . strlen($json_payload ?? ''));
        if (file_exists($filename)) {
            console_log("PHP: Save success. Should have redirected to load the saved file.");
        }
    }
}

// 5. Handle explicit file loading (files already scanned above)
if ($files) {
    $file_to_load = null;
    if (isset($_GET['load_file']) && in_array($_GET['load_file'], $saved_files)) {
        // A file was explicitly requested (via redirection after save, or user selection)
        $file_to_load = $_GET['load_file'];
        $config_to_load_name = $file_to_load;
        $show_load_modal = false; // Do not show modal if a file is loading
        if (isset($_GET['msg'])) {
            $message = htmlspecialchars($_GET['msg']); // Use message from redirection
        }
        // console_log("PHP: Explicit file request: $file_to_load."); // Commented out to prevent redirect issues
    } else if (!isset($_GET['clear'])) {
        // No file requested, and not forced to clear, default to showing the modal to choose load or new.
        $show_load_modal = true;
        // console_log("PHP: No explicit load file. Modal display enabled."); // Commented out to prevent redirect issues
    }

    if ($file_to_load) {
        // Load the file content
        $content = file_get_contents($log_dir . $file_to_load);
        if ($content !== false) {
            // File content is already valid JSON - pass it directly to JavaScript as a JavaScript object
            // Don't JSON-encode it since we want it to be a JavaScript object, not a string
            $initial_config_json_js = $content; 
            // console_log("PHP: File content loaded. Passing directly as JavaScript object."); // Commented out to prevent redirect issues
            
            // Decode to get the configName for initial UI display (PHP side)
            $loaded_data = json_decode($content, true);
            if ($loaded_data && isset($loaded_data['configName'])) {
                $initial_config_name = $loaded_data['configName'];
            }
        } else {
            $message = "ERROR: Failed to read configuration file: $file_to_load. Starting fresh.";
            $initial_config_json_js = 'null';
            // console_log("PHP ERROR: Failed to read file content."); // Commented out to prevent redirect issues
        }
    }
} else {
    // No saved files exist, skip the modal and start fresh.
    $message = 'No saved configurations found on the server. Starting a new session.';
    $show_load_modal = false;
    console_log("PHP: No saved files found.");
}

// If we are showing the modal, we need a clean slate config
if ($show_load_modal) {
    $initial_config_json_js = 'null';
}

// Set initial name display value (before JS runs)
$config_input_value = htmlspecialchars($initial_config_name);
// Pass the modal flag to JavaScript
$show_load_modal_js = $show_load_modal ? 'true' : 'false';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Language Product Badge Generator</title>
    
    <style>
        :root {
            /* Color Palette */
            --color-primary: #116688; /* Blue */
            --color-secondary: #10b981; /* Emerald Green */
            --color-warning: #f59e0b; /* Amber/Yellow */
            --color-danger: #ef4444; /* Red */
            --color-text-dark: #1f2937;
            --color-text-light: #ffffff;
            --color-bg-light: #f9fafb; /* Gray-50 */
            --color-bg-white: #ffffff;
            --color-border-subtle: #d1d5db;
            --color-bg-accent: #eff6ff; /* Blue-50 */
        }

        body {
            font-family: Arial, sans-serif;
            background-color: var(--color-bg-light);
            padding: 1rem;
            line-height: 1.5;
        }
        
        @media (min-width: 640px) {
            body {
                padding: 2rem;
            }
        }

        /* --- Layout & Container --- */
        .container {
            max-width: 1280px;
            margin: 0 auto;
            background-color: var(--color-bg-white);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        
        @media (min-width: 768px) {
            .container {
                padding: 2.5rem;
            }
        }

        /* --- Header / Title --- */
        h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--color-primary);
            border-bottom: 4px solid var(--color-primary);
            padding-bottom: 0.75rem;
            margin-bottom: 1.5rem;
            margin: 0 0 1.5rem 0;
        }
        h1 a {
            display: block;
            text-decoration: none;
            transition: color 150ms ease-in-out;
        }
        h1 a:hover {
            color: #1e40af; /* Darker blue on hover */
        }
        @media (min-width: 640px) {
            h1 {
                font-size: 2.25rem;
            }
        }

        /* --- Form/Management Section --- */
        .config-management {
            padding: 1.5rem;
            background-color: var(--color-bg-accent);
            border-radius: 8px;
            border: 1px solid #bfdbfe;
            margin-bottom: 2rem;
        }
        .config-management h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1e40af; /* Darker blue */
            margin-bottom: 1rem;
        }

        .component-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        @media (min-width: 768px) {
            .component-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .component-card {
            padding: 1rem;
            background-color: var(--color-bg-white);
            border-radius: 8px;
            border: 1px solid var(--color-border-subtle);
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .component-card h3 {
            font-size: 1.25rem;
            font-weight: 600;
        }
        .save-component h3 { color: #065f46; /* Darker Green */ }
        .load-component h3 { color: #b45309; /* Darker Yellow */ }

        /* --- Inputs and Selects --- */
        .input-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--color-text-dark);
            margin-bottom: 0.25rem;
        }
        .input-field, .select-field {
            /* FIX: Ensure fields are constrained within their parent */
            width: 100%; 
            box-sizing: border-box; /* Crucial for width: 100% to include padding/border */
            padding: 0.75rem;
            border: 1px solid var(--color-border-subtle);
            border-radius: 6px;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            transition: border-color 150ms ease-in-out, box-shadow 150ms ease-in-out;
        }
        .input-field:focus, .select-field:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
            outline: none;
        }
        textarea.input-field {
            resize: vertical;
            min-height: 250px;
        }

        /* --- Buttons --- */
        button {
            display: flex;
            width: 100%;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 150ms ease-in-out;
            border: none;
            background-color: var(--color-primary);
            color: var(--color-text-light);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            min-height: 3.25rem;
            box-sizing: border-box;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }
        
        button:hover {
            background-color: #2563eb;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        button:active {
            transform: translateY(1px);
        }
        
        /* Special styling for delete button */
        .delete-btn {
            background-color: var(--color-danger);
            padding: 0.5rem;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            min-height: 40px;
            min-width: 40px;
        }
        
        .delete-btn:hover {
            background-color: #b91c1c;
        }

        /* --- Current File Indicator --- */
        .current-file-indicator {
            padding-top: 0.75rem;
            margin-top: 1rem;
            border-top: 1px solid var(--color-border-subtle);
            font-size: 0.875rem;
            color: #6b7280;
        }
        .current-file-indicator span {
            font-weight: 600;
            color: #1e40af;
        }

        /* --- Group Control --- */
        .group-control {
            background-color: var(--color-bg-white);
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--color-border-subtle);
            margin-bottom: 1.5rem; /* Add spacing between groups */
        }

        .group-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        @media (min-width: 768px) {
            .group-grid {
                grid-template-columns: 1fr 3fr; /* 25% and 75% width */
            }
        }
        .lang-inputs {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .lang-inputs > div {
            /* Individual language input rows */
        }
        
        /* --- H3 Styling for Column Headers --- */
        h3 {
            margin: 0 0 0.75rem 0;
            font-size: 1.125rem;
            font-weight: 600;
            color: #4b5563;
        }
        
        /* --- JSON Output --- */
        .output-container {
            margin-top: 2.5rem;
        }
        .output-container h2 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--color-secondary);
            margin-bottom: 1rem;
        }
        .output-container p {
            color: #4b5563;
            margin-bottom: 1.5rem;
        }
        .json-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        @media (min-width: 1024px) {
            .json-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        .json-section {
            background-color: #1f2937; /* Gray-800 */
            padding: 1.25rem;
            border-radius: 8px;
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.1);
        }
        .json-section h4 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--color-text-light);
            margin-bottom: 0.75rem;
        }
        .json-output {
            color: #e5e7eb; /* Gray-200 */
            overflow: auto;
            max-height: 400px;
            font-family: monospace;
            font-size: 0.875rem;
            white-space: pre;
            padding: 0.5rem;
            margin-bottom: 1rem;
            border: 1px solid #4b5563;
            border-radius: 4px;
        }
        /* Scrollbar styles for json-output */
        .json-output::-webkit-scrollbar { width: 8px; height: 8px; }
        .json-output::-webkit-scrollbar-thumb { background-color: #4b5563; border-radius: 4px; }
        .json-output::-webkit-scrollbar-track { background-color: #1f2937; }

        .btn-copy {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background-color: #3b82f6;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            transition: background-color 150ms ease-in-out;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            width: auto; /* Override button default width */
            min-height: auto; /* Override button default min-height */
            border: none;
            cursor: pointer;
        }
        .btn-copy:hover {
            background-color: #2563eb;
        }
        .btn-copy svg {
            width: 1.25rem;
            height: 1.25rem;
        }

        /* --- Modal Styling --- */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(31, 41, 55, 0.9);
            z-index: 999;
            display: none; /* Controlled by JS */
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: var(--color-bg-white);
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 90%;
            max-width: 512px;
            margin: 0 1rem;
        }
        .modal-content h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-primary);
            margin-bottom: 1.5rem;
        }
        .modal-content h4 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #4b5563;
        }
        .modal-space {
            margin-bottom: 1rem;
        }
        .modal-divider {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem 0;
        }
        .modal-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            border-top: 1px solid var(--color-border-subtle);
            z-index: 1;
        }
        .modal-divider span {
            position: relative;
            background-color: var(--color-bg-white);
            padding: 0 1rem;
            font-size: 0.875rem;
            color: #6b7280;
            z-index: 2;
        }

        /* --- Message Box --- */
        .message-box {
            position: fixed;
            top: 1rem;
            right: 1rem;
            background-color: var(--color-secondary);
            color: var(--color-text-light);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            transition: opacity 300ms ease-in-out;
            opacity: 0;
            pointer-events: none;
        }
    </style>
</head>
<body class="p-4 sm:p-8">

    <!-- Load/New Modal -->
    <div id="loadDecisionModal" class="modal-overlay">
        <div class="modal-content">
            <h3>Welcome! Load or Create?</h3>
            
            <div class="modal-space">
                <h4>Load Existing Configuration:</h4>
                
                <select id="modalLoadFile" class="select-field modal-space">
                    <option value="" disabled selected>Select a file to load...</option>
                    <?php foreach ($saved_files as $file): ?>
                        <option value="<?php echo $file; ?>">
                            <?php echo htmlspecialchars($file); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="button" onclick="loadSelectedFile(document.getElementById('modalLoadFile').value)">
                    📂 Load Selected File
                </button>

                <div class="modal-divider">
                    <span>OR</span>
                </div>

                <button type="button" onclick="createNewConfig()">
                    ✍️ Create New Configuration
                </button>
            </div>
        </div>
    </div>

    <div id="messageBox" class="message-box"></div>

    <div class="container">
        
        <!-- Main title as H1 with link functionality -->
        <h1><a href="?clear=true">
            🛍️ Product Badge Configuration Tool
        </a></h1>
        
        <form id="configForm" method="POST" action="" class="config-management">
            <h2 class="text-2xl font-semibold">Configuration Management (Server Files)</h2>
            
            <!-- Hidden inputs for form submission -->
            <input type="hidden" name="action" id="actionInput" value="">
            <input type="hidden" name="config_payload" id="configPayloadInput" value="">
            <input type="hidden" name="overwrite_filename" id="overwriteFilenameInput" value="">
            
            <!-- Separate Load and Save Components -->
            <div class="component-grid">
                <!-- Save Component -->
                <div class="component-card save-component">
                    <h3>💾 Save Current Configuration</h3>
                    
                    <div>
                        <label for="configName" class="input-label">Configuration Name:</label>
                        <input type="text" name="configNameDisplay" id="configName" value="<?php echo $config_input_value; ?>" 
                               class="input-field">
                    </div>
                    
                    <button type="button" onclick="saveConfigToServer()">
                        Save to Server
                    </button>
                </div>
                
                <!-- Load Component -->
                <div class="component-card load-component">
                    <h3>📂 Load Existing File</h3>
                    
                    <div>
                        <label for="loadServerFile" class="input-label">Select File:</label>
                        <select id="loadServerFile" 
                                class="select-field">
                            <option value="" disabled selected>Select a file to load...</option>
                            <?php foreach ($saved_files as $file): ?>
                                <option value="<?php echo $file; ?>" <?php echo ($file === $config_to_load_name) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($file); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Explicit Load Button -->
                    <button type="button" onclick="loadSelectedFile()">
                        Load Selected File
                    </button>
                </div>
            </div>
            
            <p class="current-file-indicator">
                **Current File:** <span id="currentLoadedFile"><?php echo $config_to_load_name ? htmlspecialchars($config_to_load_name) : 'New Session'; ?></span>
            </p>
        </form>

        <div id="groupsContainer" class="groups-container">
            <!-- Groups will be inserted here -->
        </div>

        <button style="margin-top: 2rem;" onclick="addGroup()">
            ➕ Add New Group
        </button>
        
        <hr style="margin: 2.5rem 0; border: 0; border-top: 1px solid var(--color-border-subtle);">

        <button onclick="generateJSONs()">
            ✨ Generate All 7 Language JSONs
        </button>

        <div id="outputContainer" class="output-container" style="display: none;">
            <h2>Generated JSON Output</h2>
            <p>These JSONs combine all groups and contain all product IDs. They are ready to be copied for deployment.</p>
            <div id="jsonOutputs" class="json-grid">
                <!-- JSON outputs will be inserted here -->
            </div>
        </div>
    </div>

    <script>
        const LANGS = ['en', 'de', 'fr', 'es', 'pl', 'nl', 'it'];
        let groupCounter = 0; 
        let currentLoadedFilename = null; // Track the currently loaded file 

        document.addEventListener('DOMContentLoaded', () => {
            initApp();
        });

        // --- Utility Functions ---

        function showMessage(message, duration = 3000) {
            console.log(`MESSAGE BOX: ${message}`);
            const box = document.getElementById('messageBox');
            box.textContent = message;
            box.style.opacity = '1';
            box.style.pointerEvents = 'auto';

            setTimeout(() => {
                box.style.opacity = '0';
                box.style.pointerEvents = 'none';
            }, duration);
        }

        function copyToClipboard(button) {
            const jsonElement = button.closest('.json-section').querySelector('.json-output');
            const textToCopy = jsonElement.innerText;
            
            const textArea = document.createElement('textarea');
            textArea.value = textToCopy;
            document.body.appendChild(textArea);
            textArea.select();
            
            try {
                document.execCommand('copy');
                console.log("Clipboard: JSON copied successfully.");
                const originalText = button.innerHTML;
                button.innerHTML = '✅ Copied!';
                setTimeout(() => {
                    button.innerHTML = originalText;
                }, 2000);
            } catch (err) {
                console.error('Clipboard Error: Could not copy text using execCommand: ', err);
                showMessage('Failed to copy. Please manually select and copy the text.', 4000);
            } finally {
                document.body.removeChild(textArea);
            }
        }


        // --- Data Handling ---

        function getGroupsData() {
            console.log("DATA FLOW: Reading all group data from UI.");
            const groupsContainer = document.getElementById('groupsContainer');
            const groupElements = groupsContainer.querySelectorAll('.group-control');
            const groupsData = [];

            groupElements.forEach((groupEl, index) => {
                const groupName = groupEl.querySelector('.group-name').value;
                const productIds = groupEl.querySelector('.product-ids').value;
                
                const langTexts = {};
                LANGS.forEach(lang => {
                    langTexts[lang] = groupEl.querySelector(`.lang-text-${lang}`).value;
                });

                groupsData.push({
                    name: groupName,
                    ids: productIds,
                    texts: langTexts
                });
                console.log(`DATA FLOW: Group ${index + 1} read. Name: ${groupName}`);
            });
            console.log(`DATA FLOW: Total groups read: ${groupsData.length}.`);
            return groupsData;
        }
        
        function startWithDefaultConfig() {
            console.log("Starting with default configuration.");
            currentLoadedFilename = null; // Clear loaded filename for new config
            document.getElementById('configName').value = 'Badge_Setup_1';
            document.getElementById('currentLoadedFile').textContent = 'New Session';
            addGroup(); // Start with one default group
        }
        
        // --- PHP Interaction Functions ---

        /**
         * FIX: loadSelectedFile is now defined globally and accepts an optional filename.
         * If no filename is provided (called from component card), it reads the dropdown.
         * If a filename is provided (called from modal), it uses that.
         */
        function loadSelectedFile(filename) {
            console.log("FUNCTION CALL: loadSelectedFile initiated.");
            
            let fileToLoad = filename;
            if (typeof fileToLoad !== 'string' || !fileToLoad.trim()) {
                // Read from the component-card dropdown if no valid argument was passed
                fileToLoad = document.getElementById('loadServerFile').value; 
                console.log("LOAD FILE: Reading from component card dropdown. Filename:", fileToLoad);
            } else {
                console.log("LOAD FILE: Using filename provided by argument (modal). Filename:", fileToLoad);
            }
            
            if (fileToLoad) {
                console.log(`REDIRECTING: Loading file: ${fileToLoad}`);
                window.location.href = `?load_file=${fileToLoad}`;
            } else {
                console.warn("LOAD FILE ABORT: No file selected.");
                showMessage("Please select a file to load.", 3000);
            }
        }
        
        function createNewConfig() {
            document.getElementById('loadDecisionModal').style.display = 'none';
            // Reset the UI to a clean state
            currentLoadedFilename = null; // Clear loaded filename for new config
            document.getElementById('configName').value = 'Badge_Setup_1';
            const container = document.getElementById('groupsContainer');
            container.innerHTML = ''; 
            addGroup(); // Add a single fresh group
            document.getElementById('currentLoadedFile').textContent = 'New Session';
            showMessage('Starting new configuration.', 3000);
            console.log("CREATE NEW: Cleared UI and started a new configuration.");
        }

        function saveConfigToServer() {
            console.log("SAVE SERVER: Preparing to save configuration to server.");
            console.log("SAVE SERVER: Current loaded filename:", currentLoadedFilename);
            
            // Check if we're working on a loaded file or creating new
            if (currentLoadedFilename) {
                // Overwrite existing file
                console.log("SAVE SERVER: Overwriting existing file:", currentLoadedFilename);
                const groups = getGroupsData();
                const configName = document.getElementById('configName').value || 'Unnamed_Config';
                console.log("SAVE SERVER: Config name:", configName);
                console.log("SAVE SERVER: Number of groups:", groups.length);
                
                const configData = {
                    configName: configName,
                    timestamp: new Date().toISOString(),
                    groups: groups
                };
                
                // Use overwrite action to keep the same filename
                document.getElementById('actionInput').value = 'overwrite';
                document.getElementById('overwriteFilenameInput').value = currentLoadedFilename;
                const payload = JSON.stringify(configData);
                document.getElementById('configPayloadInput').value = payload;
                console.log("SAVE SERVER: Overwrite payload size for POST:", payload.length);
                console.log("SAVE SERVER: Overwrite filename being sent:", currentLoadedFilename);
                
                // Submit the form
                console.log("SAVE SERVER: Submitting form for overwrite...");
                document.getElementById('configForm').submit();
            } else {
                // Save as new file - prompt for name first
                const configName = document.getElementById('configName').value.trim();
                console.log("SAVE SERVER: Creating new file with name:", configName);
                
                if (!configName) {
                    console.warn("SAVE SERVER: No config name provided");
                    showMessage('Please enter a configuration name before saving.', 4000);
                    document.getElementById('configName').focus();
                    return;
                }
                
                const groups = getGroupsData();
                console.log("SAVE SERVER: Number of groups for new file:", groups.length);
                
                const configData = {
                    configName: configName,
                    timestamp: new Date().toISOString(),
                    groups: groups
                };
                
                // Use save action to create new timestamped filename
                document.getElementById('actionInput').value = 'save';
                const payload = JSON.stringify(configData);
                document.getElementById('configPayloadInput').value = payload;
                console.log("SAVE SERVER: New file payload size for POST:", payload.length);

                // Submit the form
                console.log("SAVE SERVER: Submitting form for new file...");
                document.getElementById('configForm').submit();
            }
        }
        
        // --- UI Rendering ---

        function loadGroups(groups) {
            console.log("UI RENDER: Entering loadGroups. Number of groups to load:", groups.length);
            const container = document.getElementById('groupsContainer');
            container.innerHTML = ''; // Clear existing groups
            
            if (groups.length === 0) {
                console.warn("UI RENDER: Empty groups array. Adding a default group.");
                addGroup();
            } else {
                groups.forEach((groupData, index) => {
                    addGroup(groupData, false);
                    console.log(`UI RENDER: Group ${index + 1} rendered: ${groupData.name}`);
                });
            }

            console.log("UI RENDER: Exiting loadGroups. UI populated.");
        }

        function addGroup(groupData = {}, save = true) {
            groupCounter++;
            const groupId = `group-${Date.now()}-${groupCounter}`;

            const defaultData = {
                name: groupData.name || `New Group ${groupCounter}`,
                ids: groupData.ids || '',
                texts: groupData.texts || {}
            };
            
            console.log(`UI RENDER: Adding new group: ${groupId} with name: ${defaultData.name}`);

            const langInputsHTML = LANGS.map(lang => `
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <label for="${groupId}-text-${lang}" style="font-weight: 600; color: #4b5563; min-width: 2rem;">${lang.toUpperCase()}:</label>
                    <input type="text" id="${groupId}-text-${lang}" class="lang-text-${lang} input-field" style="flex: 1;" value="${defaultData.texts[lang] || ''}">
                </div>
            `).join('');

            const groupsContainer = document.getElementById('groupsContainer');
            const groupHTML = document.createElement('div');
            groupHTML.className = 'group-control';
            groupHTML.id = groupId;
            groupHTML.innerHTML = `
                <!-- Group Name Header and Input with delete button -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; gap: 1rem;">
                    <div style="display: flex; align-items: center; flex: 1; gap: 1rem;">
                        <h2 style="margin: 0; font-size: 1.5rem; font-weight: 600; color: var(--color-text-dark); white-space: nowrap;">Group Name:</h2>
                        <input type="text" id="${groupId}-name" class="group-name input-field" 
                               style="flex: 1;"
                               value="${defaultData.name}" placeholder="Enter group name...">
                    </div>
                    <button type="button" class="delete-btn" onclick="removeGroup('${groupId}')">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </div>

                <div class="group-grid">
                    <!-- Column 1: Product IDs (25% width) -->
                    <div class="product-ids-column">
                        <h3>Product IDs</h3>
                        <textarea id="${groupId}-ids" class="product-ids input-field" 
                                  rows="16" placeholder="Enter product IDs, one per line...">${defaultData.ids}</textarea>
                    </div>
                    
                    <!-- Column 2: Language Inputs (75% width) -->
                    <div class="lang-inputs">
                        <h3>Multi-Language Badge Text</h3>
                        ${langInputsHTML}
                    </div>
                </div>
            `;
            
            groupsContainer.appendChild(groupHTML);
        }

        function removeGroup(groupId) {
            const groupEl = document.getElementById(groupId);
            if (groupEl) {
                if (document.querySelectorAll('.group-control').length > 1) {
                    groupEl.remove();
                    showMessage('Group successfully removed.', 2000);
                    console.log(`UI ACTION: Group ${groupId} removed.`);
                } else {
                    showMessage('Cannot remove the last remaining group.', 3000);
                    console.warn(`UI ACTION: Attempted to remove last group ${groupId}. Blocked.`);
                }
            }
        }

        // --- JSON Generation (unchanged) ---

        function generateJSONs() {
            const groups = getGroupsData();
            if (groups.length === 0) {
                showMessage('No groups found to generate JSON. Please add a group first.', 3000);
                return;
            }

            console.log("JSON GENERATION: Starting process for all 7 languages.");
            const allLanguageJSONs = {};

            LANGS.forEach(lang => {
                const languageJSON = {};
                
                groups.forEach(group => {
                    const badgeText = group.texts[lang] || '';
                    if (badgeText) {
                        const productIds = group.ids
                            .split('\n')
                            .map(id => id.trim())
                            .filter(id => id.length > 0);

                        productIds.forEach(id => {
                            if (id) {
                                languageJSON[id] = {
                                    "badgeStyle": "custom",
                                    "badgeText": badgeText
                                };
                            }
                        });
                    }
                });

                allLanguageJSONs[lang] = languageJSON;
                console.log(`JSON GENERATION: ${lang.toUpperCase()} JSON created with ${Object.keys(languageJSON).length} products.`);
            });

            displayJSONOutputs(allLanguageJSONs);
            showMessage('JSONs generated successfully!', 3000);
            console.log("JSON GENERATION: Process complete.");
        }

        function displayJSONOutputs(allLanguageJSONs) {
            const outputContainer = document.getElementById('outputContainer');
            const jsonOutputs = document.getElementById('jsonOutputs');
            outputContainer.style.display = 'block';
            jsonOutputs.innerHTML = '';

            LANGS.forEach(lang => {
                const jsonString = JSON.stringify(allLanguageJSONs[lang], null, 4);
                
                const jsonSection = document.createElement('div');
                jsonSection.className = 'json-section';
                jsonSection.innerHTML = `
                    <h4>${lang.toUpperCase()} JSON (Products: ${Object.keys(allLanguageJSONs[lang]).length})</h4>
                    <pre class="json-output">${jsonString}</pre>
                    <button type="button" class="btn-copy" onclick="copyToClipboard(this)">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        Copy JSON
                    </button>
                `;
                jsonOutputs.appendChild(jsonSection);
            });
        }

        // Initialize with default or PHP-loaded data
        function initApp() {
            // FIX: Removed single quotes around PHP variable echo to prevent SyntaxError
            const initialConfigJson = <?php echo $initial_config_json_js; ?>; 
            const loadMessage = '<?php echo $message; ?>';
            const showLoadModal = <?php echo $show_load_modal_js; ?>; // PHP flag
            const loadedFilename = <?php echo $file_to_load ? "'" . $file_to_load . "'" : 'null'; ?>; // Currently loaded filename

            console.group("APP INITIALIZATION (initApp)");
            console.log("PHP message:", loadMessage);
            console.log("Show Load Modal flag:", showLoadModal);
            console.log("Loaded filename:", loadedFilename);
            console.log("Initial Config JSON variable value (should be 'null' or a JSON-quoted string):", initialConfigJson);
            
            if (showLoadModal) {
                document.getElementById('loadDecisionModal').style.display = 'flex';
                console.log("INIT: Modal displayed. Waiting for user input.");
            } else {
                document.getElementById('loadDecisionModal').style.display = 'none';
                loadInitialConfig(initialConfigJson, loadedFilename);
                if (loadMessage) {
                    showMessage(loadMessage, 4000);
                }
            }
            console.groupEnd();
        }

        function loadInitialConfig(initialConfigJson, loadedFilename = null) {
            console.group("--- CONFIG LOAD PROCESS ---");
            console.log("1. Input initialConfigJson:", typeof initialConfigJson, initialConfigJson);
            console.log("1. Loaded filename:", loadedFilename);

            // Set the current loaded filename for save behavior
            currentLoadedFilename = loadedFilename;

            // Check if we have valid data 
            if (initialConfigJson && initialConfigJson !== 'null' && initialConfigJson !== null) {
                try {
                    let config;
                    
                    // Since PHP now passes the JSON directly as a JavaScript object, we should have an object
                    if (typeof initialConfigJson === 'object' && initialConfigJson.configName) {
                        console.log("2. Input is a config object. Using directly.");
                        config = initialConfigJson;
                    } else {
                        throw new Error("Expected object with configName, got: " + typeof initialConfigJson);
                    }
                    
                    console.log("3. Final loaded config object:", config);
                    
                    document.getElementById('configName').value = config.configName || 'Server_Loaded_Config';
                    document.getElementById('currentLoadedFile').textContent = loadedFilename || 'Server Loaded';
                    
                    console.log("4. Configuration name updated in UI:", document.getElementById('configName').value);
                    console.log("4. Current loaded filename set to:", currentLoadedFilename);
                    
                    loadGroups(config.groups || []);
                    console.log("5. loadGroups called successfully.");
                    
                } catch(e) {
                    console.error("!!! FATAL LOAD FAILURE. Server data may be corrupted or format incorrect. Error:", e);
                    console.log("6. Starting with default configuration due to load failure.");
                    startWithDefaultConfig();
                }
            } else {
                console.log("1.5. No valid server file content found. Starting with default config.");
                startWithDefaultConfig();
            }
            console.groupEnd();
        }
    </script>
</body>
</html>