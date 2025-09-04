<?php
/* 
* This class works with the JSON files created by prompts to different AIs.
* It takes the JSON and creates/changes/deletes database entries.
*/

Class listTools {
    // ****************************************************************************************************** 
    // list methods
    /*
    "AREA":"list",
    "ACTION":"add",
    "LISTNAME":"Shopping March 2025",
    "ENTRIES":[
        "Groceries",
        "Bananas",
        "Jamon Iberico"
    ],
    "ITEM":"Bread"

    "create" | "add" | "show" | "remove" | "trash" | "list"
    */
    // ****************************************************************************************************** 

    // take the tool call and decide what to do
    public static function listDirector($jsonArr, $msgArr) {
        $answerArr = $msgArr;

        $listAction = $jsonArr['ACTION'];
        $listName = $jsonArr['LISTNAME'];
        $listEntries = $jsonArr['ENTRIES'];
        $listItem = $jsonArr['ITEM'];
        // call the right function, depending on the action
        // create
        if($listAction == 'create') {
            if(count(self::getLists($jsonArr, $msgArr)) < 5) {
                $toolResArr = self::createList($jsonArr, $msgArr);
                $answerArr["BTEXT"] = "List created:";
                $answerArr = AIGroq::translateTo($answerArr);
                $answerArr["BTEXT"] .= "\n\n".$listName;
            } else {
                $answerArr["BTEXT"] = "More than 5 lists are not possible. Please remove some first.";
                $answerArr = AIGroq::translateTo($answerArr);
            }
        }
        // add
        if($listAction == 'add') {
            $toolResArr = self::addListEntry($jsonArr, $msgArr);
            $answerArr["BTEXT"] = "Entry added to list:";
            $answerArr = AIGroq::translateTo($answerArr);
            $answerArr["BTEXT"] .= " " . $listName;
            $answerArr["BTEXT"] .= "\n\n" . $listItem;
        }

        // show
        if($listAction == 'show') {
            $toolResArr = self::showList($jsonArr, $msgArr);
            $answerArr["BTEXT"] = "Showing list: ";
            $answerArr = AIGroq::translateTo($answerArr);
            $answerArr["BTEXT"] .= ($listName)."\n\n";
            foreach($toolResArr as $entry) {
                "* ".$answerArr["BTEXT"] .= $entry['BENTRY']."\n\n";
            }
        }
        // remove
        if($listAction == 'remove') {
            $toolResArr = self::removeListEntry($jsonArr, $msgArr);
            $answerArr["BTEXT"] = "Entry removed from ".$listName;
            $answerArr = AIGroq::translateTo($answerArr);
        }
        // trash
        if($listAction == 'trash') {
            $toolResArr = self::trashList($jsonArr, $msgArr);
            $answerArr["BTEXT"] = "List deleted:";
            $answerArr = AIGroq::translateTo($answerArr);
            $answerArr["BTEXT"] .= " \n".$listName."\n\n";
        }
        // list
        if($listAction == 'list') {
            $toolResArr = self::listLists($jsonArr, $msgArr);
            $answerArr["BTEXT"] = "List of lists:";
            $answerArr = AIGroq::translateTo($answerArr);
            $answerArr["BTEXT"] .= "\n\n";
            foreach($toolResArr as $entry) {
                "* ".$answerArr["BTEXT"] .= $entry['BLNAME']."\n\n";
            }
        }
        if($answerArr["BTEXT"] == "") {
            $answerArr["BTEXT"] = "Nothing found, looks like this list operation failed!";
        }
        return $answerArr;
    }

    // ****************************************************************************************************** 
    // check if a list exits for the user
    // ****************************************************************************************************** 
    public static function listExists($jsonArr, $msgArr): array {
        // set vars first
        $userId = $msgArr['BUSERID'];
        $listName = $jsonArr['LISTNAME'];
        // --
        $cSql = "SELECT * FROM BLISTS WHERE BOWNERID = ".intval($userId)." AND BLNAME like '".(db::EscString($listName))."' LIMIT 1";
        $cRes = db::Query($cSql);
        $cArr = db::FetchArr($cRes);
        return $cArr;
    }

    // ****************************************************************************************************** 
    // get all lists for a user
    // ****************************************************************************************************** 
    public static function getLists($jsonArr, $msgArr): array {
        $listArr = [];
        $lSql = "SELECT DISTINCT BLNAME, BLISTKEY FROM BLISTS WHERE BOWNERID = ".intval($msgArr['BUSERID'])." GROUP BY BLNAME ORDER BY BID desc";
        $lRes = db::Query($lSql);
        while($lArr = db::FetchArr($lRes)) {
            $listArr[] = $lArr;
        }
        return $listArr;
    }

    // ****************************************************************************************************** 
    // get all entries for a list
    // ****************************************************************************************************** 
    public static function getListEntries($jsonArr, $msgArr): array {
        $listName = $jsonArr['LISTNAME'];
        $listArr = [];
        $eSql = "SELECT * FROM BLISTS WHERE BOWNERID = ".intval($msgArr['BUSERID'])." AND BLNAME like '".(db::EscString($listName))."' ORDER BY BLNAME, BID asc";
        $eRes = db::Query($eSql);
        while($eArr = db::FetchArr($eRes)) {
            $listArr[] = $eArr;
        }
        //print_r($listArr);
        return $listArr;
    }

    // ****************************************************************************************************** 
    // create a new list
    // ****************************************************************************************************** 
    public static function createList($jsonArr, $msgArr): array {
        $listName = $jsonArr['LISTNAME'];
        $userId = $msgArr['BUSERID'];
        $listKey = date('YmdHis');
        if(is_array($jsonArr["ENTRIES"]) AND count($jsonArr["ENTRIES"])>0) {
            foreach($jsonArr["ENTRIES"] as $entry) {
                $cSql = "INSERT INTO BLISTS (BID, BOWNERID, BLISTKEY, BLISTFORM, BLNAME, BENTRY) VALUES ";
                $cSql .= "(DEFAULT, ".intval($userId).", '".$listKey."', 'STANDARD', '".(db::EscString($listName))."', '".(db::EscString($entry))."')";
                $cRes = db::Query($cSql);    
            }
        } else {
            $cSql = "INSERT INTO BLISTS (BID, BOWNERID, BLISTKEY, BLISTFORM, BLNAME, BENTRY) VALUES ";
            $cSql .= "(DEFAULT, ".intval($userId).", '".$listKey."', 'STANDARD', '".(db::EscString($listName))."', '')";
            print $cSql;
            $cRes = db::Query($cSql);
        }
        // give back the complete list
        $answerArr = self::getListEntries($jsonArr, $msgArr);
        return $answerArr;
    }

    // ****************************************************************************************************** 
    // add a list entry
    // ****************************************************************************************************** 
    public static function addListEntry($jsonArr, $msgArr): array {
        $listArr = self::listExists($jsonArr, $msgArr);
        if(count($listArr) > 0) {
            $listKey = $listArr['BLISTKEY'];
            $listName = $listArr['BLNAME'];
            $listEntry = $jsonArr['ITEM'];
            $cSql = "INSERT INTO BLISTS (BID, BOWNERID, BLISTKEY, BLISTFORM, BLNAME, BENTRY) VALUES ";
            $cSql .= "(DEFAULT, ".intval($msgArr['BUSERID']).", '".$listKey."', 'STANDARD', '".(db::EscString($listName))."', '".(db::EscString($listEntry))."')";
            $cRes = db::Query($cSql);
        } else {
            $answerArr = self::createList($jsonArr, $msgArr);
            $listKey = $answerArr[0]['BLISTKEY'];
            $listName = $answerArr[0]['BLNAME'];
            $listEntry = $jsonArr['ITEM'];
            $cSql = "INSERT INTO BLISTS (BID, BOWNERID, BLISTKEY, BLISTFORM, BLNAME, BENTRY) VALUES ";
            $cSql .= "(DEFAULT, ".intval($msgArr['BUSERID']).", '".$listKey."', 'STANDARD', '".(db::EscString($listName))."', '".(db::EscString($listEntry))."')";
            $cRes = db::Query($cSql);

        }
        // delete entries with '' as ENTRY
        $dSql = "DELETE FROM BLISTS WHERE  BOWNERID = ".intval($msgArr['BUSERID'])." AND BLISTKEY = '".$listKey."' AND BENTRY = ''";
        $dRes = db::Query($dSql);
        //
        return self::getListEntries($jsonArr, $msgArr);
    }

    // ****************************************************************************************************** 
    // show a list
    // ****************************************************************************************************** 
    public static function showList($jsonArr, $msgArr): array {
        $listArr = self::getListEntries($jsonArr, $msgArr);
        return $listArr;
    }

    // ****************************************************************************************************** 
    // remove a list entry
    // ****************************************************************************************************** 
    public static function removeListEntry($jsonArr, $msgArr): array {
        $listArr = self::getListEntries($jsonArr, $msgArr);
        $listKey = $listArr['BLISTKEY'];
        $listName = $listArr['BLNAME'];
        $listEntry = $jsonArr['ITEM'];
        $cSql = "DELETE FROM BLISTS WHERE BOWNERID = ".intval($msgArr['BUSERID'])." AND BLISTKEY = '".$listKey."' AND BENTRY like '".(db::EscString($listEntry))."'";
        $cRes = db::Query($cSql);
        return self::getListEntries($jsonArr, $msgArr);
    }

    // ****************************************************************************************************** 
    // trash a list
    // ****************************************************************************************************** 
    public static function trashList($jsonArr, $msgArr): array {
        $listArr = self::getListEntries($jsonArr, $msgArr);
        if(count($listArr) > 0) {
            $listKey = $listArr[0]['BLISTKEY'];
            $listName = $listArr[0]['BLNAME'];
            $cSql = "DELETE FROM BLISTS WHERE BOWNERID = ".intval($msgArr['BUSERID'])." AND BLISTKEY = '".$listKey."'";
            $cRes = db::Query($cSql);
        }
        return self::getListEntries($jsonArr, $msgArr);
    }

    // ****************************************************************************************************** 
    // list all lists
    // ****************************************************************************************************** 
    public static function listLists($jsonArr, $msgArr): array {
        $listArr = self::getLists($jsonArr, $msgArr);
        return $listArr;
    }
}
