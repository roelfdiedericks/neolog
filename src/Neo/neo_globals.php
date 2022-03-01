<?php

function l_debug($msg,$context=array())
{
    \Neo\NeoLog::debug($msg,$context);
}

function l_hex($msg,$context=array())
{
    \Neo\NeoLog::info("\n" . neo_hex_dump($msg),$context);
}

function l_info($msg,$context=array())
{
    \Neo\NeoLog::info($msg,$context);
}

function l_notice($msg,$context=array())
{
    \Neo\NeoLog::notice($msg,$context);
}

function l_warning($msg,$context=array())
{
    \Neo\NeoLog::warning($msg,$context);
}

function l_warn($msg,$context=array())
{
    \Neo\NeoLog::warning($msg,$context);
}

function l_error($msg,$context=array())
{
    \Neo\NeoLog::error($msg,$context);
}
function l_err($msg,$context=array())
{
    \Neo\NeoLog::$error($msg,$context);
}

function l_critical($msg,$context=array())
{        
    \Neo\NeoLog::critical($msg,$context);
}

function l_crit($msg,$context=array())
{    
    \Neo\NeoLog::critical($msg,$context);
}

function l_alert($msg,$context=array())
{
    \Neo\NeoLog::alert($msg,$context);
}

function l_panic($msg,$context=array())
{
    \Neo\NeoLog::panic($msg,$context);
}

//log a message, and exit cleanly
function l_exit($msg,$context=array())
{
    \Neo\NeoLog::exitCleanly($msg,$context);
}

function l_print_r($var,$context=array()) {
    l_debug("\n".print_r($var,true),$context);
}

//maintain some pear_ backward compatible constants

function msg($msg,$level=PEAR_LOG_DEBUG,$method="",$function="") {
    global $logger;
    $fn_sig="";
    if ($method!="")
        $fn_sig="$method:";
    if ($function!="")
        $fn_sig="$method::$function:";
    \Neo\NeoLog::log(\Neo\NeoLog::mapPearLevel($level),"{$fn_sig}{$msg}");
}

function msg_r($var,$level=PEAR_LOG_DEBUG,$method="",$function="") {
    global $logger;
    $fn_sig="";
    if ($method!="")
        $fn_sig="$method:";
    if ($function!="")
        $fn_sig="$method::$function:";

    \Neo\NeoLog::log(\Neo\NeoLog::mapPearLevel($level), "{$fn_sig}msg_r:\n" . print_r($var,true));
}

function msg_raddump($var,$level=PEAR_LOG_DEBUG,$method="",$function="") {
    global $logger;
    $fn_sig="";
    if ($method!="")
        $fn_sig="$method:";
    if ($function!="")
        $fn_sig="$method::$function:";

    $tmp=$var;
    if (is_array($var)) {
        unset($tmp['_full']);
    }

    \Neo\NeoLog::log(\Neo\NeoLog::mapPearLevel($level), "{$fn_sig}:msg_raddump:\n" . print_r($tmp,true));
}

function panic($msg,$level=PEAR_LOG_ALERT,$method="",$function="") {
    global $panic_logger;
    $fn_sig="";
    if ($method!="")
        $fn_sig="$method:";
    if ($function!="")
        $fn_sig="$method::$function:";

    if (\Neo\NeoLog::$panic_logger) {
        \Neo\NeoLog::panic_log(\Neo\NeoLog::mapPearLevel($level),"{$fn_sig}{$msg}");
    } else {
        fprintf(STDERR,"***PANIC**:{$fn_sig}{$msg}");
    }
}

function panic_r($var,$level=PEAR_LOG_ALERT,$method="",$function="") {
    global $panic_logger;
    $fn_sig="";
    if ($method!="")
        $fn_sig="$method:";
    if ($function!="")
        $fn_sig="$method::$function:";

    if (\Neo\NeoLog::$panic_logger) {
        \Neo\NeoLog::panic_log(\Neo\NeoLog::mapPearLevel($level),"{$fn_sig}panic_r:\n" . print_r($var,true));
    } else {
        fprintf(STDERR,"***PANIC**:{$fn_sig}panic_r:\n" . print_r($var,true));
    }
}

function neo_adodb_msg($msg,$newline=true) {
    global $logger;
    $msg=str_replace("-----<hr>\n","",$msg);
    $msg=str_replace("-----<hr>","",$msg);
    $msg=trim($msg);
    $m="ADODB: $msg" . ($newline ? "\n" : "");
    \Neo\NeoLog::$logger->addDebug($m);
}


function hex_print($data, $newline="\n")
{
    static $from = '';
    static $to = '';

    static $width = 16; # number of bytes per line

        static $pad = '.'; # padding for non-visible characters

        if ($from==='')
        {
            for ($i=0; $i<=0xFF; $i++)
            {
                $from .= chr($i);
                $to .= ($i >= 0x20 && $i <= 0x7E) ? chr($i) : $pad;
            }
        }

    $hex = str_split(bin2hex($data), $width*2);
    $chars = str_split(strtr($data, $from, $to), $width);

    $offset = 0;
    foreach ($hex as $i => $line)
    {
        echo sprintf('%6X',$offset).' : '.implode(' ', str_split($line,2)) . ' [' . $chars[$i] . ']' . $newline;
        $offset += $width;
    }
}

function neo_hex_dump($data, $newline="\n")
{
    static $from = '';
    static $to = '';

    static $width = 16; # number of bytes per line

        static $pad = '.'; # padding for non-visible characters

        if ($from==='')
        {
            for ($i=0; $i<=0xFF; $i++)
            {
                $from .= chr($i);
                $to .= ($i >= 0x20 && $i <= 0x7E) ? chr($i) : $pad;
            }
        }

    $hex = str_split(bin2hex($data), $width*2);
    $chars = str_split(strtr($data, $from, $to), $width);

    $offset = 0;
    $r="";
    foreach ($hex as $i => $line)
    {
        $r.=sprintf('%6X',$offset).' : '.implode(' ', str_split($line,2)) . ' [' . $chars[$i] . ']' . $newline;
        $offset += $width;
    }
    return $r;
}

function neo_str_hex($string)
{
    $hex='';
    for ($i=0; $i < strlen($string); $i++)
    {
        $hex .= str_pad(dechex(ord($string[$i])),2,'0',STR_PAD_LEFT);
    }
    return $hex;
}

function neo_hex_str($hex){
    $string='';
    for ($i=0; $i < strlen($hex)-1; $i+=2){
        $string .= chr(hexdec($hex[$i].$hex[$i+1]));
    }
    return $string;
}
