<?php
// ------------------------------------ my mail sender
function _mymail($strFrom, $strTo, $subject, $htmltext, $plaintext, $strReplyTo='',$strFileAttach='') {
    // available SMTP providers
    $arrAWScreds = ApiKeys::getAWS();
    $arrSmtpSrvs = array(
        'aws'    => array('h' => 'email-smtp.eu-west-1.amazonaws.com',
            'auth' => true, 'po'=>587, 'u' => $arrAWScreds['access_key'],
            'p' => $arrAWScreds['secret_key'], 'f'=>'info@metadist.de',
            'fn' => 'Ralfs.AI Mail Service'),
    );

    // create the mail
    $mail = new PHPMailer\PHPMailer\PHPMailer;
    $mail->isSMTP();

    if (($p=strpos($strTo, ";")) !== false) {
        list($strTo, $strToName) = explode(";", $strTo);
        $mail->addAddress($strTo, $strToName);
    } else {
        $mail->addAddress($strTo, "Forjo App Service");
    }

    // - reply
    if ($strReplyTo) {
        $mail->addReplyTo($strReplyTo);
    }
    elseif(substr_count($strFrom,";")>0) {
        $fromparts=explode(";",$strFrom);
        $mail->addReplyTo($fromparts[0], $fromparts[1]);
    }
    else {
        $mail->addReplyTo($strFrom, "Forjo Mail Service");
    }

    if(strlen($strFileAttach)>5)
    {
        if(file_exists($strFileAttach))
            $mail->addAttachment($strFileAttach);
    }

    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->WordWrap = 70;
    $mail->isHTML(true);

    $strUseProvider = 'aws';

    list($mail->Host, $mail->SMTPAuth, $mail->Port, $mail->Username, $mail->Password, $mail->From, $mail->FromName) = array_values($arrSmtpSrvs[$strUseProvider]);
    $mail->SMTPSecure = 'tls';
    //$mail->SMTPDebug = 2;

    $mail->Subject = $subject;
    $mail->Body    = $htmltext;

    $plaintext = $plaintext."\n\nRalfs.AI by info@metadist.de";
    $mail->AltBody = $plaintext;

    $fSend = $mail->Send();

    if ($fSend) {
        return true;
    }
    else {
        error_log($mail->ErrorInfo);
        // error, fall back on SENDMAIL on the Linux OS
        //echo $mail->ErrorInfo;
        //print_r($arrSmtpSrvs[$strUseProvider]);
        mail("rs@metadist.de","Synaplan.org Mailing Error",date("YmdHis").": Could not send to ".$strTo." via ".$strUseProvider."\n\n".$mail->ErrorInfo."\n\n".$plaintext);
        mail($strTo,"Plain: ".$subject,$plaintext);
    }
}
// ------------------------------------------------------------------------------
