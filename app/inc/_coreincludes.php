<?php

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/config/_confsys.php');
require_once(__DIR__ . '/config/_confdb.php');
require_once(__DIR__ . '/config/_confkeys.php');
require_once(__DIR__ . '/config/_confdefaults.php');
require_once(__DIR__ . '/mail/_mail.php');
require_once(__DIR__ . '/support/_tools.php');
// the AI classes
require_once(__DIR__ . '/ai/providers/_aiollama.php');
require_once(__DIR__ . '/ai/providers/_aigroq.php');
require_once(__DIR__ . '/ai/providers/_aianthropic.php');
require_once(__DIR__ . '/ai/providers/_aithehive.php');
require_once(__DIR__ . '/ai/providers/_aiopenai.php');
require_once(__DIR__ . '/ai/providers/_aigoogle.php');
// Triton works on systems with php/grpc installed and with
// protobuf installed. A triton server is needed! Works only with PHP8.4
// require_once(__DIR__ . '/ai/providers/_aitriton.php');

// incoming tools
require_once(__DIR__ . '/integrations/_wasender.php');
require_once(__DIR__ . '/integrations/wordpresswizard.php');
require_once(__DIR__ . '/mail/_myGMail.php');
require_once(__DIR__ . '/_xscontrol.php');
// oidc authentication
require_once(__DIR__ . '/api/_oidc.php');
require_once(__DIR__ . '/api/_logout.php');
// oauth (gmail, etc)
require_once(__DIR__ . '/api/_oauth.php');
// auth management classes
require_once(__DIR__ . '/auth/passwordhelper.php');
require_once(__DIR__ . '/auth/apikeymanager.php');
require_once(__DIR__ . '/auth/userregistration.php');
// file management classes
require_once(__DIR__ . '/domain/files/filemanager.php');
// document extraction (tika-first + rasterize/vision fallback)
require_once(__DIR__ . '/domain/files/tika_client.php');
require_once(__DIR__ . '/domain/files/rasterizer.php');
require_once(__DIR__ . '/domain/files/universal_file_handler.php');
// service classes
require_once(__DIR__ . '/mail/emailservice.php');
// api classes (only classes here; procedural API files are loaded by public/api.php)
require_once(__DIR__ . '/api/apiauthenticator.php');
require_once(__DIR__ . '/api/apirouter.php');
require_once(__DIR__ . '/api/_inboundconf.php');
// frontend tools
require_once(__DIR__ . '/_frontend.php');
// central tool
require_once(__DIR__ . '/support/_central.php');
// basic ai tools
require_once(__DIR__ . '/ai/core/_basicai.php');
// Load utility classes
require_once(__DIR__ . '/http/_curler.php');
require_once(__DIR__ . '/support/_listtools.php');
require_once(__DIR__ . '/support/_messagehistory.php');
require_once(__DIR__ . '/_processmethods.php');
require_once(__DIR__ . '/mail/_toolmailhandler.php');
require_once(__DIR__ . '/domain/_againlogic.php');

// ----------------------------------------------------- storage path
// https://flysystem.thephpleague.com/docs/getting-started/
// Determine project/public paths to locate storage under public/up/
$projectRoot = dirname(__DIR__, 2);
$publicRoot = $projectRoot . '/public/';
$rootPath = $publicRoot . 'up/';
// error_log("rootPath: " . $rootPath);

$adapter = new League\Flysystem\Local\LocalFilesystemAdapter($rootPath);
$GLOBALS['filesystem'] = new League\Flysystem\Filesystem($adapter, [
    'visibility' => 'public',
    'directory_visibility' => 'public'
]);
// -----------------------------------------------------
