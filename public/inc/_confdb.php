<?php
// -----------------------------------------------------
// Database connection
// -----------------------------------------------------
try {
    if (!defined("DB_HOST")) {
        // Load database configuration from environment variables with fallback to defaults
        define('DB_NAME', getenv('DB_NAME') ?: 'synaplan');
        define('DB_USER', getenv('DB_USER') ?: 'synaplan');
        define('DB_PASSWORD', getenv('DB_PASSWORD') ?: 'synaplan');
        
        // Determine DB_HOST based on environment
        if (substr_count($server, "localhost") > 0) {
            define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
        } else {
            define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
        }

        // Database charset and collation
        define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
        define('DB_COLLATE', getenv('DB_COLLATE') ?: 'utf8_general_ci');
    }
} catch (Exception $e) {
    if($GLOBALS["debug"]) error_log('Database configuration error: ' . $e->getMessage());
    echo 'Database configuration error: ', $e->getMessage(), "\n";
}

// Connect to database with persistent connection
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // $GLOBALS["dbcon"] = mysqli_connect("p:" . DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $GLOBALS["dbcon"] = mysqli_connect("p:".DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, 3306);
    $GLOBALS["dbcon"]->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    exit("Internal Server Error: Database connection failed");
}
// Set connection parameters
mysqli_set_charset($GLOBALS["dbcon"], DB_CHARSET);
mysqli_autocommit($GLOBALS["dbcon"], TRUE);
// -----------------------------------------------------

// global translation tool

function _s($str, $file='', $t='en') {
    // -----------------------------------------------------
    if($t != 'en') {
        $strArr = [];
        $myToken = str_replace(".php","",basename($file));
        $myToken = substr($myToken . "_" . db::sysString(db::EscString($str)), 0, 63);

        // -----------------------------------------------------
        $searchSQL = "select * from BTRANSLATE where BTARGET = '".$t."' and BTOKEN = '".$myToken."'";
        $searchRES = db::Query($searchSQL);
        if($searchARR = db::FetchArr($searchRES)) {
            $strArr["BTEXT"] = $searchARR["BTEXT"];
        } else {
            $strArr["BTEXT"] = $str;
            $strArr["BLANG"] = $t;
            $strArr = AIGroq::translateTo($strArr);
            $newSQL = "insert into BTRANSLATE (BID, BTARGET, BTOKEN, BTEXT) values (DEFAULT, '".$t."', '".$myToken."', '".$strArr["BTEXT"]."')";
            $res = db::Query($newSQL);    
        }
        $str = $strArr["BTEXT"];
    }
    // -----------------------------------------------------
    print $str;
}

// -----------------------------------------------------
// Database functions ... abstraction for standard commands
// -----------------------------------------------------
class db {
    // sends a query to the database and returns the result
    public static function Query($strSQL): mysqli_result|bool {
        // Guard against empty SQL
        if (!is_string($strSQL) || trim($strSQL) === '') {
            error_log('db::Query called with empty SQL');
            return false;
        }
        
        //error_log("****************************** QUERY: " . $strSQL);
        $adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@example.com'; // Fallback to a generic email if not set
        
        $result = mysqli_query($GLOBALS["dbcon"],$strSQL);
        
        if (!$result) {
            $errorDetails = __FILE__." ".__LINE__."\n<BR>".$strSQL."<BR>\n".print_r(debug_backtrace(),true) . db::Error();
            EmailService::sendAdminNotification("SQL Query Failed", $errorDetails, $adminEmail);
        }

        // myDebug(substr(strip_tags($strSQL),0,800),__FUNCTION__,__LINE__);
        if(!$result) {
            return false;
        }
        else {
            return $result;
        }
    }

    // fetches an array from the database result
    public static function FetchArr($res,$dummy=""): array|null|bool {
        return mysqli_fetch_array($res, MYSQLI_ASSOC);
    }

    // counts the rows in the database result
    public static function CountRows($res): int|null|bool {
        return mysqli_num_rows($res);
    }

    // counts the rows affected by the last query
    public static function AffectedRows(): int|null|bool {
        return mysqli_affected_rows($GLOBALS["dbcon"]);
    }

    // escapes a string for the database
    public static function EscString($str): string {
        // Handle null values
        if (is_null($str)) return "";
        
        // Handle boolean values
        if (is_bool($str)) return $str ? '1' : '0';
        
        // Convert to string if not already
        if (!is_string($str)) $str = strval($str);
        
        // Ensure proper UTF-8 encoding
        if (!mb_check_encoding($str, 'UTF-8')) {
            $str = mb_convert_encoding($str, 'UTF-8', mb_detect_encoding($str, 'UTF-8, ISO-8859-1, ISO-8859-15', true));
        }
        
        // Normalize line endings to Unix style
        $str = str_replace(["\r\n", "\r"], "\n", $str);
        
        // Use mysqli_real_escape_string for proper SQL escaping
        // This handles all SQL injection attempts while preserving Unicode
        $escapedStr = mysqli_real_escape_string($GLOBALS["dbcon"], $str);
        
        return $escapedStr;
    }

    // returns the last inserted id
    public static function LastId(): int|null|bool {
        return mysqli_insert_id($GLOBALS["dbcon"]);
    }

    // returns the last error message
    public static function Error(): string|null|bool {
        return mysqli_error($GLOBALS["dbcon"]);
    }

    // ------------------------------------------------- other base tools
    // removes special characters from a string
    public static function sysString($inStr): string|null|bool {
        $outStr = preg_replace('([\ \n\r\t\v\f\b\'\"\<\>\*\$\&\;\[\]\{\}\(\)\:\,\!\?\;\.]+)', '', $inStr);
        return $outStr;
    }

}
