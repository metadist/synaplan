<?php
/*
$fileSQL = "SELECT TFILES.* FROM TFILES, TFILE2VEC WHERE TFILE2VEC.TDBID = '".($_SESSION["vectorDBID"])."' AND TFILE2VEC.TFILEID = TFILES.TID";
$fileRES = dbQuery($fileSQL);
if(dbCountRows($fileRES) >0 ) {
    print '<span id="fileNotesDiv" class="float-end">';
}
while($fileROW = dbFetchArr($fileRES)) { ?>
    <span class="badge bg-info text-dark float-end mx-1">&#128196; <?php print substr($fileROW["TNAME"],0, 12); ?></span>
    <?php
}
if(dbCountRows($fileRES) >0 ) {
    print '</span>';
}
*/
?>
<label for="shoutFile" class="form-label px-1 fst-italic">Datei anhängen</label>
<input class="form-control bg-body mt-1" type="file" id="shoutFile" name="shoutFile" onchange="uploadFile();">