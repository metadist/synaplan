<?php
require_once(__DIR__ . '/../../vendor/autoload.php');
require_once($root . 'inc/_confsys.php');
require_once($root . 'inc/_confdb.php');
require_once($root . 'inc/_confkeys.php');
require_once($root . 'inc/_confdefaults.php');
require_once($root . 'inc/_mail.php');
require_once($root . 'inc/_tools.php');
// the AI classes
require_once($root . 'inc/_aiollama.php');
require_once($root . 'inc/_aigroq.php');
require_once($root . 'inc/_aianthropic.php');
require_once($root . 'inc/_aithehive.php');  
require_once($root . 'inc/_aiopenai.php');
require_once($root . 'inc/_aigoogle.php');
// incoming tools
require_once($root . 'inc/_wasender.php');
require_once($root . 'inc/_myGMail.php');
require_once($root . 'inc/_xscontrol.php');
// oidc authentication
require_once($root . 'inc/_oidc.php');
require_once($root . 'inc/_logout.php');
// auth management classes
require_once($root . 'inc/auth/ApiKeyManager.php');
require_once($root . 'inc/auth/UserRegistration.php');
// file management classes
require_once($root . 'inc/files/FileManager.php');
// service classes
require_once($root . 'inc/services/EmailService.php');
// api classes
require_once($root . 'inc/api/ApiAuthenticator.php');
require_once($root . 'inc/api/ApiRouter.php');
// frontend tools
require_once($root . 'inc/_frontend.php');
// central tool
require_once($root . 'inc/_central.php');
// basic ai tools
require_once($root . 'inc/_basicai.php');
// Load utility classes
require_once($root . 'inc/_curler.php');
require_once($root . 'inc/_listtools.php');
require_once($root . 'inc/_processmethods.php');
require_once($root . 'inc/_toolmailhandler.php');
require_once($root . 'inc/_againlogic.php');

// ----------------------------------------------------- storage path
// https://flysystem.thephpleague.com/docs/getting-started/
// Use the caller-provided $root (points to public/) so storage is always under public/up/
$appPath = $root;
$rootPath = $root . 'up/';
// error_log("rootPath: " . $rootPath);

$adapter = new League\Flysystem\Local\LocalFilesystemAdapter($rootPath);
$GLOBALS["filesystem"] = new League\Flysystem\Filesystem($adapter, [
    'visibility' => 'public',
    'directory_visibility' => 'public'
]);
// -----------------------------------------------------