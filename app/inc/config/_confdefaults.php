<?php

// ----------------------------------------------------- All default models

// Load global defaults (owner 0)
$confSQL = "SELECT * FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BOWNERID = 0";
$confRES = db::Query($confSQL);
while ($confARR = db::FetchArr($confRES)) {
    $detailSQL = 'SELECT * FROM BMODELS WHERE BID = '.intval($confARR['BVALUE']);
    $detailRES = db::Query($detailSQL);
    if ($detailRES && db::CountRows($detailRES) > 0) {
        $detailARR = db::FetchArr($detailRES);
        $GLOBALS['AI_'.$confARR['BSETTING']]['SERVICE'] = 'AI'.$detailARR['BSERVICE'];
        $GLOBALS['AI_'.$confARR['BSETTING']]['MODEL'] = $detailARR['BPROVID'];
        $GLOBALS['AI_'.$confARR['BSETTING']]['MODELID'] = $detailARR['BID'];
        //error_log(__FILE__.": AI_models (global): ".$confARR["BSETTING"].": ".$detailARR["BSERVICE"].": ".$detailARR["BPROVID"]);
    }
}

// Overlay with per-user overrides, if available
$currentOwnerId = 0;
if (isset($_SESSION['USERPROFILE']['BID'])) {
    $currentOwnerId = intval($_SESSION['USERPROFILE']['BID']);
}
if ($currentOwnerId > 0) {
    $userConfSQL = "SELECT * FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BOWNERID = ".$currentOwnerId;
    $userConfRES = db::Query($userConfSQL);
    while ($userConfARR = db::FetchArr($userConfRES)) {
        $detailSQL = 'SELECT * FROM BMODELS WHERE BID = '.intval($userConfARR['BVALUE']);
        $detailRES = db::Query($detailSQL);
        if ($detailRES && db::CountRows($detailRES) > 0) {
            $detailARR = db::FetchArr($detailRES);
            $GLOBALS['AI_'.$userConfARR['BSETTING']]['SERVICE'] = 'AI'.$detailARR['BSERVICE'];
            $GLOBALS['AI_'.$userConfARR['BSETTING']]['MODEL'] = $detailARR['BPROVID'];
            $GLOBALS['AI_'.$userConfARR['BSETTING']]['MODELID'] = $detailARR['BID'];
            //error_log(__FILE__.": AI_models (user ".$currentOwnerId."): ".$userConfARR["BSETTING"].": ".$detailARR["BSERVICE"].": ".$detailARR["BPROVID"]);
        }
    }
}
//error_log(__FILE__.": AI_models: ".print_r($GLOBALS, true));

// Initialize extra servicecredentials
$GLOBALS['WAtoken'] = ApiKeys::getWhatsApp();
$GLOBALS['braveKey'] = ApiKeys::getBraveSearch();
