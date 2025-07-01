<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Test Page</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="file"] { width: 100%; padding: 8px; }
        button { padding: 10px 20px; background-color: #4CAF50; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #45a049; }
        .results { margin-top: 20px; padding: 15px; border: 1px solid #ddd; }
        .error { color: red; }
        .success { color: green; }
    </style>
</head>
<body>
<div class="container">
    <h1>Audit Test Page</h1>

    <!-- Upload Form -->
    <div class="form-group">
        <h2>Upload Audit PDF</h2>
        <form action="{{ secure_url(route('upload-audit')) }}" method="POST" enctype="multipart/form-data">
            @csrf
            <label for="file">Select PDF File (max 10MB):</label>
            <input type="file" name="file" id="file" accept=".pdf" required>
            <button type="submit">Upload</button>
        </form>
        @if(session('success'))
            <p class="success">{{ session('success') }}</p>
        @endif
        @if(session('error'))
            <p class="error">{{ session('error') }}</p>
        @endif
    </div>

    <!-- Display Audit Data -->
    <div class="form-group">
        <h2>View Audits</h2>
        <button onclick="fetchAudits()">Load All Audits</button>
        <button onclick="fetchAuditDetail()">Load Audit Detail (ID: 1)</button>
        <div id="auditResults" class="results"></div>
    </div>
</div>

<script>
    function fetchAudits() {
        fetch('/audits')
            .then(response => response.json())
            .then(data => {
                const results = document.getElementById('auditResults');
                results.innerHTML = '<h3>All Audits</h3>' +
                    (data.length ? JSON.stringify(data, null, 2) : 'No audits found');
            })
            .catch(error => {
                document.getElementById('auditResults').innerHTML =
                    '<p class="error">Error: ' + error.message + '</p>';
            });
    }

    function fetchAuditDetail() {
        fetch('/audits/1')
            .then(response => response.json())
            .then(data => {
                const results = document.getElementById('auditResults');
                results.innerHTML = '<h3>Audit Detail (ID: 1)</h3>' +
                    JSON.stringify(data, null, 2);
            })
            .catch(error => {
                document.getElementById('auditResults').innerHTML =
                    '<p class="error">Error: ' + error.message + '</p>';
            });
    }
</script>
</body>
</html>
