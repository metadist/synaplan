<?php

namespace App;

// Note: keep this file minimal; it will be called from public/index.php after session & autoload.

class Director
{
    public static function dispatch(): void
    {
        $contentInc = "unknown";

        $cleanUriArr = explode("?", $_SERVER['REQUEST_URI']);
        $urlParts = explode("index.php/", $cleanUriArr[0]);
        if (count($urlParts) > 1) {
            $commandParts = explode("/", $urlParts[1]);
        } else {
            $commandParts = [];
        }

        if (!isset($_SESSION['USERPROFILE'])) {
            if (isset($_REQUEST['lid']) && strlen($_REQUEST['lid']) > 3) {
                if (\Frontend::setUserFromTicket()) {
                    $contentInc = "chat";
                } else {
                    $contentInc = "login";
                }
            } else {
                if (count($commandParts) > 0 && $commandParts[0] == "register") {
                    $contentInc = "register";
                } else {
                    $contentInc = "login";
                }
            }
        } else {
            if (count($_SESSION['USERPROFILE']) > 0) {
                $contentInc = "chat";
                if (count($commandParts) > 0) {
                    if ($commandParts[0] == "confirm") {
                        $contentInc = "confirm";
                    } elseif (strlen($commandParts[0]) > 2) {
                        $contentInc = $commandParts[0];
                    }
                }
            } else {
                if (count($commandParts) > 0 && $commandParts[0] == "register") {
                    $contentInc = "register";
                } else {
                    $contentInc = "login";
                }
            }
        }

        if ($contentInc != "login" && $contentInc != "register" && $contentInc != "confirm") {
            include(__DIR__ . "/../frontend/c_menu.php");
            include(__DIR__ . "/../frontend/c_" . $contentInc . ".php");
            $serverIp = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
            echo "\n<!-- SERVER: " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "_" . $serverIp . " -->\n";
        } elseif ($contentInc == "register") {
            include(__DIR__ . "/../frontend/c_register.php");
        } elseif ($contentInc == "confirm") {
            include(__DIR__ . "/../frontend/c_confirm.php");
        } else {
            include(__DIR__ . "/../frontend/c_login.php");
        }
    }
}
