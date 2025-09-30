<?php
// -----------------------------------------------------
// Inbound configuration
// -----------------------------------------------------

Class InboundConf {
    public static function getWhatsAppNumbers() {
        $numArr = [];
        $userId = $_SESSION["USERPROFILE"]["BID"];
        $waSQL = "select * from BWAPHONES where BOWNERID = ".$userId." OR BOWNERID = 0 ORDER BY BOWNERID DESC";
        $waRes = db::Query($waSQL);
        while($row = db::FetchArr($waRes)) {
            $numArr[] = $row;
        }
        return $numArr;
    }
    // *****************************************************
    // set the widget domain(s) in the BCONFIG table
    public static function setWidgetDomain($domain) {
        $userId = $_SESSION["USERPROFILE"]["BID"];
        $waSQL = "";
        $waRes = db::Query($waSQL);
    }
}
?>