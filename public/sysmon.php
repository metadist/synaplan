<?php
// ------------------------------------------------------ base config
require_once __DIR__ . '/../app/inc/_coreincludes.php';
// -------------------------------------------------- LOCAL SERVICES CHECK
$output = [];
exec('ps afx', $output);
// $output is now an array of lines from "ps afx"

$keyfound = false;
$errors = [];

$keywords = [];
$keywords[] = 'apache2';
$keywords[] = 'mariadb';
$keywords[] = 'memcached';

foreach ($keywords as $keyword) {
    foreach ($output as $line) {
        if (stripos($line, $keyword) !== false) {
            $keyfound = true;
        }
    }
    if (!$keyfound) {
        $errors[] = $keyword.": ERROR - task not running\n";
    } else {
        $errors[] = $keyword.": OK - task running\n";
    }
    $keyfound = false;
}

// -------------------------------------------------- OTHER STUFF
/*
$sites = [];
$sites[] = 'https://www.metadist.de/';
//$sites[] = 'https://www.metadist.com/';
$sites[] = 'https://ralfs.ai/';

$keywords = [];
$keywords[] = 'Digitale Eleganz';
//$keywords[] = 'elegance';
$keywords[] = 'WhatsApp';

$counter = 0;
foreach ($sites as $site) {
    $response = file_get_contents($site);
    if ($response === false) {
        $errors[] = $site.": ERROR - site not reachable\n";
    } else {
        if(substr_count($response, $keywords[$counter]) > 0) {
            $errors[] = $site.": OK - site response contains keyword\n";
        } else {
            $errors[] = $site.": ERROR - site response does not contain keyword\n";
        }
    }
    $counter++;
}
*/
// now count incoming messages to BMESSAGES in database
$sql = "SELECT COUNT(*) ANZ FROM BMESSAGES WHERE BUNIXTIMES > ".(time()-180);
$res = db::query($sql);
$countArr = db::FetchArr($res);
$count = $countArr["ANZ"];
if($count > 50) {
    $errors[] = "Incoming messages: ERROR - ".($count)." messages in the last 3 minutes\n";
} else {
    $errors[] = "Incoming messages: OK - ".($count)." messages in the last 3 minutes\n";
}
$myServer = ApiKeys::get("OLLAMA_SERVER");
$host = 'http://'.$myServer;
$ollama = file_get_contents($host);
if(strpos($ollama, 'running') >0 ) {
    $errors[] = "Ollama: OK - ".$ollama."\n";
} else {
    $errors[] = "Ollama: ERROR - ".$ollama."\n";
}
?>
<html>
    <body>  
        <h1>System Monitor</h1>
        <pre><?php
                print_r($errors); ?>
        </pre>
    </body>
</html>