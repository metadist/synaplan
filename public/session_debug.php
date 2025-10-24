<?php

// Session configuration diagnostic script
echo '<h2>PHP Session Configuration</h2>';

// Check session save handler
echo '<h3>Session Save Handler</h3>';
echo 'session.save_handler: ' . ini_get('session.save_handler') . '<br>';
echo 'session.save_path: ' . ini_get('session.save_path') . '<br>';

// Check if memcached extension is loaded
echo '<h3>Memcached Extension</h3>';
if (extension_loaded('memcached')) {
    echo '✓ Memcached extension is loaded<br>';

    // Test memcached connection
    echo '<h3>Memcached Connection Test</h3>';
    $memcached = new Memcached();

    // Parse the save_path (format: host1:port1,host2:port2)
    $servers = explode(',', ini_get('session.save_path'));
    foreach ($servers as $server) {
        $parts = explode(':', trim($server));
        $host = $parts[0];
        $port = isset($parts[1]) ? $parts[1] : 11211;

        echo "Testing connection to $host:$port... ";
        $result = $memcached->addServer($host, $port);
        if ($result) {
            echo '✓ Connected<br>';
        } else {
            echo '✗ Failed to connect<br>';
        }
    }

    // Test basic operations
    echo '<h3>Memcached Operations Test</h3>';
    $testKey = 'test_' . time();
    $testValue = 'test_value_' . time();

    if ($memcached->set($testKey, $testValue, 60)) {
        echo '✓ Set operation successful<br>';

        $retrieved = $memcached->get($testKey);
        if ($retrieved === $testValue) {
            echo '✓ Get operation successful<br>';
        } else {
            echo '✗ Get operation failed<br>';
        }

        $memcached->delete($testKey);
    } else {
        echo '✗ Set operation failed<br>';
    }
} else {
    echo '✗ Memcached extension is NOT loaded<br>';
}

// Check session configuration
echo '<h3>Session Configuration</h3>';
echo 'session.gc_maxlifetime: ' . ini_get('session.gc_maxlifetime') . '<br>';
echo 'session.cookie_lifetime: ' . ini_get('session.cookie_lifetime') . '<br>';
echo 'session.use_cookies: ' . ini_get('session.use_cookies') . '<br>';
echo 'session.use_only_cookies: ' . ini_get('session.use_only_cookies') . '<br>';

// Check if we can start a session
echo '<h3>Session Start Test</h3>';
try {
    $result = session_start();
    if ($result) {
        echo '✓ Session started successfully<br>';
        echo 'Session ID: ' . session_id() . '<br>';
        session_destroy();
    } else {
        echo '✗ Session start failed<br>';
    }
} catch (Exception $e) {
    echo '✗ Session start exception: ' . $e->getMessage() . '<br>';
}

// Show PHP info for session
echo '<h3>PHP Session Info</h3>';
echo '<pre>';
print_r(session_get_cookie_params());
echo '</pre>';
