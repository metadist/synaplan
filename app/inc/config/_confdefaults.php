<?php
// ----------------------------------------------------- All default models

$confSQL = "SELECT * FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BOWNERID = 0";
$confRES = db::Query($confSQL);
while($confARR = db::FetchArr($confRES)) {
    $detailSQL = "SELECT * FROM BMODELS WHERE BID = ".intval($confARR["BVALUE"]);
    $detailRES = db::Query($detailSQL);
    $detailARR = db::FetchArr($detailRES);
    $GLOBALS["AI_".$confARR["BSETTING"]]["SERVICE"] = "AI".$detailARR["BSERVICE"];
    $GLOBALS["AI_".$confARR["BSETTING"]]["MODEL"] = $detailARR["BPROVID"];
    $GLOBALS["AI_".$confARR["BSETTING"]]["MODELID"] = $detailARR["BID"];
    //error_log(__FILE__.": AI_models: ".$confARR["BSETTING"].": ".$detailARR["BSERVICE"].": ".$detailARR["BPROVID"]);
}
//error_log(__FILE__.": AI_models: ".print_r($GLOBALS, true));

// Initialize extra servicecredentials
$GLOBALS['WAtoken'] = ApiKeys::getWhatsApp();
$GLOBALS['braveKey'] = ApiKeys::getBraveSearch();
