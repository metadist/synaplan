<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Synaplan Admin - Test Suite</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 900px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .header h1 {
            font-size: 36px;
            margin-bottom: 10px;
        }
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        .content {
            padding: 40px;
        }
        .test-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .test-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: pointer;
        }
        .test-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border-color: #667eea;
        }
        .test-card h3 {
            color: #2d3748;
            font-size: 20px;
            margin-bottom: 10px;
        }
        .test-card p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .test-card a {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .test-card a:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .master-run {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            margin-top: 30px;
        }
        .master-run h3 {
            font-size: 24px;
            margin-bottom: 15px;
        }
        .master-run p {
            margin-bottom: 20px;
            opacity: 0.95;
        }
        .master-run a {
            display: inline-block;
            background: white;
            color: #667eea;
            text-decoration: none;
            padding: 15px 40px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        .master-run a:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .info-section {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .info-section h4 {
            color: #856404;
            margin-bottom: 10px;
            font-size: 18px;
        }
        .info-section ul {
            color: #856404;
            padding-left: 20px;
        }
        .info-section li {
            margin-bottom: 5px;
        }
        .badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        .docs-link {
            text-align: center;
            padding: 20px;
            border-top: 1px solid #e0e0e0;
        }
        .docs-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .docs-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🧪 Synaplan Test Suite</h1>
            <p>Automated testing and validation tools</p>
        </div>

        <div class="content">
            <div class="info-section">
                <h4>ℹ️ Important Information</h4>
                <ul>
                    <li>All tests use <strong>User ID 3</strong> with email <code>team@synaplan.com</code></li>
                    <li>Test reports are sent to <code>team@synaplan.com</code></li>
                    <li>Tests can be run individually or as a complete suite</li>
                    <li>Each test returns JSON for programmatic validation</li>
                </ul>
            </div>

            <h2 style="margin-bottom: 20px; color: #2d3748;">Individual Tests</h2>
            
            <div class="test-grid">
                <div class="test-card">
                    <h3>Test 1<span class="badge">Create</span></h3>
                    <p>Creates test user ID 3 with credentials and generates an API key.</p>
                    <a href="test-createuser.php" target="_blank">Run Test →</a>
                </div>

                <div class="test-card">
                    <h3>Test 2<span class="badge">API</span></h3>
                    <p>Tests API inference with a simple prompt using the generated API key.</p>
                    <a href="test-simpleinference.php" target="_blank">Run Test →</a>
                </div>

                <div class="test-card">
                    <h3>Test 3<span class="badge">Delete</span></h3>
                    <p>Deletes user completely, sends email report to team@synaplan.com.</p>
                    <a href="test-deleteuser.php" target="_blank">Run Test →</a>
                </div>
            </div>

            <div class="master-run">
                <h3>🚀 Run Complete Test Suite</h3>
                <p>Execute all three tests in sequence with combined reporting</p>
                <a href="run-all-tests.php" target="_blank">Run All Tests →</a>
            </div>
        </div>

        <div class="docs-link">
            <a href="README-TESTS.md" target="_blank">📖 View Documentation</a>
        </div>
    </div>
</body>
</html>