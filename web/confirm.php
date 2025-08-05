<?php
require_once("inc/_confdb.php");

$id = intval($_GET['id']);
$c = DB::EscString($_GET['c']);

$getSQL = "SELECT * FROM BUSER WHERE BID = ".$id." AND BUSERDETAILS LIKE '%".$c."%'";
$getRes = DB::Query($getSQL);
$getArr = DB::FetchArr($getRes);

$confirmed = false;
if($getArr) {
    $getArr['DETAILS'] = json_decode($getArr['BUSERDETAILS'], true);
    if(isset($getArr['DETAILS']['MAILCHECKED']) AND $getArr['DETAILS']['MAILCHECKED'] == $c) {
        $getArr['DETAILS']['MAILCHECKED'] = 1;
        $userDetailsJson = json_encode($getArr['DETAILS'], JSON_UNESCAPED_UNICODE);
        $updateSQL = "UPDATE BUSER SET BUSERDETAILS = '".DB::EscString($userDetailsJson)."' WHERE BID = ".$id;
        DB::Query($updateSQL);
        $confirmed = true;
    }
}
?>
<html>
    <head>
        <title>Confirmation</title>
    </head>
    <body>
        <?php if($confirmed) { ?>
            <h1>Email confirmed</h1>
            You can now mail to <a href="mailto:smart@ralfs.ai">smart@ralfs.ai</a>.
        
        <?php } else { ?>
            <h1>Email not confirmed</h1>
            Either the entry does not exist or you have already confirmed your email.
        <?php } ?>
        <BR><BR>
        <b>Please join our <a href="/">mailing list</a> on the homepage.</b>
        <BR><BR>
        If something is not working, please contact us via email at <a href="mailto:info@metadist.de">info@metadist.de</a>.
    </body>
</html>

