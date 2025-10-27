<?php

use ArdaGnsrn\Ollama\Ollama;

//==================================================================================
require_once(__DIR__ . '/vendor/autoload.php');
// ------------------------------------------------------ lang config
session_start();
if (!isset($_SESSION['LANG'])) {
    $_SESSION['LANG'] = 'en';
}
if (isset($_REQUEST['lang'])) {
    $_SESSION['LANG'] = $_REQUEST['lang'];
}
// ------------------------------------------------------ include files
// basic config and system tools
require_once(__DIR__ . '/inc/_confsys.php');
require_once(__DIR__ . '/inc/_confdb.php');
require_once(__DIR__ . '/inc/_mail.php');
require_once(__DIR__ . '/inc/_tools.php');
// the AI classes
require_once(__DIR__ . '/inc/_aiollama.php');
require_once(__DIR__ . '/inc/_aigroq.php');
require_once(__DIR__ . '/inc/_aianthropic.php');
require_once(__DIR__ . '/inc/_aithehive.php');
require_once(__DIR__ . '/inc/_aiopenai.php');
// incoming tools
require_once(__DIR__ . '/inc/_wasender.php');
require_once(__DIR__ . '/inc/_xscontrol.php');
// central tool
require_once(__DIR__ . '/inc/_central.php');
// basic ai tools
require_once(__DIR__ . '/inc/_basicai.php');
// frontend tools
require_once(__DIR__ . '/inc/_frontend.php');
// Load utility classes
require_once(__DIR__ . '/inc/_curler.php');
require_once(__DIR__ . '/inc/_listtools.php');
require_once(__DIR__ . '/inc/_processmethods.php');

?>
<!doctype html>
<html lang="en">
  <head>
    <base href="<?php echo $GLOBALS['baseUrl']; ?>">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Ralfs.AI Dashboard">
    <meta name="author" content="Ralf Schwoebel, based on Bootstrap 5">
    <meta name="generator" content="Manually crafted by Ralf">
    <title>synaplan - digital thinking</title>
    <link href="node_modules/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom styles for this template -->
    <link href="css/dashboard.css" rel="stylesheet">
    <!-- JQuery we need quickly, sorry SEO -->
    <script src="node_modules/jquery/dist/jquery.min.js"></script>
  </head>
  <body>   
    <div class="container">
        <h1>Testing Stuff</h1>
        <?php
        $msgArr = [];
$fillSQL = "SELECT * FROM BMESSAGES WHERE BDIRECT = 'IN' ORDER BY BID DESC LIMIT 1";
$fillRes = db::Query($fillSQL);
$msgArr = db::FetchArr($fillRes);
$msgArr['BTEXT'] = 'Welches Modell bist Du?';

$msgArr = AIOpenAI::topicPrompt($msgArr, []);

?>
        <pre><?php print_r($msgArr); ?></pre>
    </div>
  </body>
</html>