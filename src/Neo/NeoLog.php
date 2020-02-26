<?php

namespace Neo {

    use \Monolog\Logger;
    use \Monolog\Handler\StreamHandler;


    class NeoLogger extends \Monolog\Logger {

        const NOTICE = 250;

        protected static $levels = array(
                100 => 'DEBUG',
                200 => 'INFO',
                250 => 'NOTICE',
                300 => 'WARNING',
                400 => 'ERROR',
                500 => 'CRITICAL',
                550 => 'ALERT',
                );


        public function __construct($name) {
            parent::__construct($name);

            //add a "NOTICE" level
            NeoLogger::$levels[250]='NOTICE';
        }

        function addRecord($level,$msg, array $context=array())
        {
            if (!is_array($context))
                $context=array( ''.gettype($context)=>$context);
            parent::addRecord($level,$msg,$context);
        }

        public function addDebug($message, array $context = array())
        {
            if (!is_array($context))
                $context=array( ''.gettype($context)=>$context);
            return $this->addRecord(self::DEBUG, $message, $context);
        }

        public function addInfo($message, array $context = array())
        {
            if (!is_array($context))
                $context=array( ''.gettype($context)=>$context);
            return $this->addRecord(self::INFO, $message, $context);
        }

        public function addNotice($message, array $context = array())
        {
            if (!is_array($context))
                $context=array( ''.gettype($context)=>$context);
            return $this->addRecord(self::NOTICE, $message, $context);
        }

        public function addWarning($message, array $context = array())
        {
            if (!is_array($context))
                $context=array( ''.gettype($context)=>$context);
            return $this->addRecord(self::WARNING, $message, $context);
        }

        public function addError($message, array $context = array())
        {
            if (!is_array($context))
                $context=array( ''.gettype($context)=>$context);
            return $this->addRecord(self::ERROR, $message, $context);
        }

        public function addCritical($message, array $context = array())
        {
            if (!is_array($context))
                $context=array( ''.gettype($context)=>$context);
            return $this->addRecord(self::CRITICAL, $message, $context);
        }

        public function addAlert($message, array $context = array())
        {
            if (!is_array($context))
                $context=array( ''.gettype($context)=>$context);
            return $this->addRecord(self::ALERT, $message, $context);
        }


        //this should really be standard for Logger
        public function setLevel($level) {
            foreach ($this->handlers as $key => $handler) {
                $handler->setLevel($level);
            }
            $this->last_level=$level;
        }

        public function getLevel() {
            return $this->handlers[0]->getLevel();
        }

    }

    class NeoColoredLineFormatter extends \Monolog\Formatter\LineFormatter {

        protected $level_colors = array(
                100 => "\033[1;34m", //DEBUG = Dark Blue
                200 => "\033[0;36m", //INFO = Light Blue
                250 => "\033[1;36m", //NOTICE = Cyan
                300 => "\033[1;33m", //WARNING = Yellow
                400 => "\033[1;31m", //ERROR = Light Red
                500 => "\033[0;37m\033[1;41m", //CRITICAL = Light Red Background, white text
                550 => "\033[1;33m\033[1;41m", //ALERT = blinkenlichten
                );
        protected $color_off="\033[0m";

        public function __construct($format = null, $dateFormat = null)
        {
            if (empty($format))
                $format="[%datetime%] %channel%.%level_name%: %message% %context% trace:%extra%";
            parent::__construct($format, $dateFormat,true);
        }

        public function format(array $record)
        {
            $context=$record['context'];
            $record['context']=array();
            $output=parent::format($record);
            //$output=print_r($record,true);
            $color=@$this->level_colors[$record['level']];
            $output=str_replace('trace:[]','',trim($output));
            $output=str_replace('[]','',trim($output));
            if (!empty($context))
                $context=print_r($context,true);
            else
                $context="";
            return  $color . $output . " $context " . $this->color_off . "\n";
        }
    }

    class NeoIntrospectionProcessor
    {
        /**
         * @param array $record
         * @return array
         */
        public function __invoke(array $record)
        {

            //don't bother backtracing if we're not at debug, or info level
            //if ( NeoLog::getLevel()>NeoLog::INFO ) {
            //return $record;
            //}

            $trace = debug_backtrace();

            // skip first since it's always the current method
            array_shift($trace);
            // the call_user_func call is also skipped
            array_shift($trace);

            $i = 0; $stop=0;
            while ( isset($trace[$i]['class']) && !$stop ) {
                if (
                        preg_match('/Monolog\\\/',$trace[$i]['class']) ||
                        preg_match('/Neo\\\NeoLog/',$trace[$i]['class'])  ||
                        preg_match('/ADOConnection/',$trace[$i]['class'])
                   ) {
                    //echo "$i= {$trace[$i]['class']} \n";
                    $i++;
                } else {
                    $stop=1;
                }
            }

            //special for adodb
            while ( isset($trace[$i+1]) &&  isset($trace[$i+1]['class']) && ( @$trace[$i+1]['class']=="ADOConnection" || @$trace[$i+1]['function']=="_adodb_debug_execute" )) {
                $i++;
            }

            // we should have a proper call source now
            $backtrace=array(
                    'file'      => isset($trace[$i]['file']) ? $trace[$i]['file'] : null,
                    'line'      => isset($trace[$i]['line']) ? $trace[$i]['line'] : null,
                    'class'     => isset($trace[$i+1]['class']) ? $trace[$i+1]['class'] : null,
                    'function'  => isset($trace[$i+1]['function']) ? $trace[$i+1]['function'] : null,
                    );

            //if we're on debug level, add the 'extra' array
            if (NeoLog::getLevel()<=NeoLog::DEBUG) {
                $record['extra'] = array_merge(
                        $record['extra'],
                        $backtrace
                        );
            }

            //if we're on info level, just add the method/class to the message
            //if (NeoLog::getLevel()<=NeoLog::INFO) {
            if ( isset($backtrace['class']) ) {

                $record['message']="({$backtrace['class']}::{$backtrace['function']}) {$record['message']}";
            } else if ( isset($backtrace['function']) )  {
                $record['message']="({$backtrace['function']}) {$record['message']}";
            }
            //}

            return $record;
        }
    }

    class NeoLog {

        static $name='unknown';

        static $logdir='/var/log';
        static $logfile="";
        static $panic_logfile="";
        static $panic_hook=null;
        static $cleanexit=false;

        static $logger=null;
        static $logger_handler=null;

        static $panic_logger=null;
        static $panic_logger_handler=null;

        static $console_logging_active=false;
        static $file_logging_active=false;
        static $console_stream=STDOUT;

        const DEBUG = 100;
        const INFO = 200;
        const NOTICE = 250;
        const WARNING = 300;
        const ERROR = 400;
        const CRITICAL = 500;
        const ALERT = 550;



        static function init($name,$logdir='',$force_console=false,$level=Logger::WARNING)
        {
            if (!empty($logdir))
                NeoLog::$logdir=$logdir;

            NeoLog::$name=$name;

            if ( defined('STDIN') ) {
                //try php 7.2 tty check first
                if ( function_exists("stream_isatty") ) {
                    if ( stream_isatty(STDIN) || $force_console ) {
                        NeoLog::initConsoleLogging();
                    } else {
                        NeoLog::initFileLogging();
                    }
                //fallback to posix otherwise
                }  else if ( @posix_isatty(STDIN) || $force_console)  {
                        NeoLog::initConsoleLogging();
                } else {
                    NeoLog::initFileLogging();    
                }
            } else {
                NeoLog::initFileLogging();
            }

            NeoLog::initErrorHandlers();

            //maintain some backward compatible constants
            if (!defined('PEAR_LOG_EMERG')) {
                define('PEAR_LOG_EMERG',    0);     /* System is unusable */
                define('PEAR_LOG_ALERT',    1);     /* Immediate action required */
                define('PEAR_LOG_CRIT',     2);     /* Critical conditions */
                define('PEAR_LOG_ERR',      3);     /* Error conditions */
                define('PEAR_LOG_WARNING',  4);     /* Warning conditions */
                define('PEAR_LOG_NOTICE',   5);     /* Normal but significant */
                define('PEAR_LOG_INFO',     6);     /* Informational */
                define('PEAR_LOG_DEBUG',    7);     /* Debug-level messages */
            }

        }

        static public function exitCleanly($msg,$context) {
            NeoLog::$cleanexit=true;
            NeoLog::$logger->addNotice($msg,$context);
        }

        static function enableIntrospection() {
            $processor=new NeoIntrospectionProcessor();

            if (NeoLog::$logger_handler)
                NeoLog::$logger_handler->pushProcessor($processor);
            if (NeoLog::$panic_logger_handler)
                NeoLog::$panic_logger_handler->pushProcessor($processor);
        }

        static function disableIntrospection($handler) {
            if (NeoLog::$logger_handler)
                NeoLog::$logger_handler->popProcessor();
            if (NeoLog::$panic_logger_handler)
                NeoLog::$panic_logger_handler->popProcessor();
        }

        static function initConsoleLogging()
        {
            NeoLog::$console_logging_active=true;
            NeoLog::$logger = new NeoLogger(NeoLog::$name);

            $handler=new StreamHandler(NeoLog::$console_stream, Logger::DEBUG);
            $handler->setFormatter( new NeoColoredLineFormatter() );
            NeoLog::$logger->pushHandler($handler);
            NeoLog::$logger_handler=$handler;

            NeoLog::$panic_logger=NeoLog::$logger;

            NeoLog::enableIntrospection();
        }

        static function initFileLogging()
        {

            NeoLog::$file_logging_active=true;
            NeoLog::$panic_logfile=NeoLog::$logdir.'/panic.log';
            NeoLog::$logfile=NeoLog::$logdir."/".NeoLog::$name.".log";

            if (is_file(NeoLog::$logdir)) {
                throw new Exception("Unable to log to directory: {NeoLog::$logdir}, it is a file.");
            }


            //plain logger
            NeoLog::$logger = new NeoLogger(NeoLog::$name);
            $handler=new StreamHandler(NeoLog::$logfile, Logger::DEBUG);
            $handler->setFormatter( new NeoColoredLineFormatter() );
            NeoLog::$logger->pushHandler($handler);
            NeoLog::$logger_handler=$handler;

            NeoLog::$panic_logger = new NeoLogger(NeoLog::$name);
            $handler=new StreamHandler(NeoLog::$panic_logfile, Logger::DEBUG);
            NeoLog::$panic_logger->pushHandler($handler);
            NeoLog::$panic_logger_handler=$handler;

            NeoLog::enableIntrospection();
        }

        static function initErrorHandlers()
        {
            register_shutdown_function('\Neo\NeoLog::shutdownHandler');
            error_reporting(E_ALL);
            set_error_handler("\Neo\NeoLog::errorHandler",E_ALL);
        }

        static function errorHandler($code, $message, $file, $line)
        {
            global $logger,$module_name;

            //Ignore stuff supressed by the @ operator
            if (!error_reporting())
                return;


            /* Map the PHP error to a Log priority. */
            switch ($code) {
                case E_WARNING:
                case E_USER_WARNING:
                    $priority = NeoLogger::WARNING;
                    break;
                case E_NOTICE:
                case E_USER_NOTICE:
                    $priority = NeoLogger::WARNING; //map to warning, so we get ppl to fix their code
                    //undefined variables or indexes we thunk to INFO level
                    if (preg_match("/Undefined variable/",$message) || preg_match("/Undefined index/",$message)) {
                        $priority = NeoLogger::INFO;
                    }
                    break;
                case E_ERROR:
                case E_USER_ERROR:
                    $priority = NeoLogger::ERROR;
                    break;
                case E_DEPRECATED:
                    $priority=NeoLogger::DEBUG;
                    break;
                default:
                    $priority = NeoLogger::INFO;
            }

            $l="($message) in $file at line $line";
            if (NeoLog::$logger)  {
                NeoLog::$logger->addRecord($priority,$l);
            } else {
                fprintf(STDERR,"(no logger)" . $l );
            }
        }

        static function FriendlyErrorType($type)
        {
            switch($type)
            {
                case E_ERROR: // 1 //
                    return 'E_ERROR';
                case E_WARNING: // 2 //
                    return 'E_WARNING';
                case E_PARSE: // 4 //
                    return 'E_PARSE';
                case E_NOTICE: // 8 //
                    return 'E_NOTICE';
                case E_CORE_ERROR: // 16 //
                    return 'E_CORE_ERROR';
                case E_CORE_WARNING: // 32 //
                    return 'E_CORE_WARNING';
                case E_CORE_ERROR: // 64 //
                    return 'E_COMPILE_ERROR';
                case E_CORE_WARNING: // 128 //
                    return 'E_COMPILE_WARNING';
                case E_USER_ERROR: // 256 //
                    return 'E_USER_ERROR';
                case E_USER_WARNING: // 512 //
                    return 'E_USER_WARNING';
                case E_USER_NOTICE: // 1024 //
                    return 'E_USER_NOTICE';
                case E_STRICT: // 2048 //
                    return 'E_STRICT';
                case E_RECOVERABLE_ERROR: // 4096 //
                    return 'E_RECOVERABLE_ERROR';
                case E_DEPRECATED: // 8192 //
                    return 'E_DEPRECATED';
                case E_USER_DEPRECATED: // 16384 //
                    return 'E_USER_DEPRECATED';
            }
            return "";
        }

        static function shutdownHandler()
        {
            if (NeoLog::$cleanexit)
                return;
            if(is_null($e = error_get_last()) === false) {
                if (isset($e['type'])) {
                    $error_type=NeoLog::FriendlyErrorType($e['type']);
                } else {
                    $error_type="unknown";
                }
                if ($error_type!="E_DEPRECATED" && $error_type!="E_NOTICE") {
                    $msg="Shutdown, Error($error_type):(" . print_r($e, true). ")";
                    NeoLog::panic($msg);
                    if (is_callable(NeoLog::$panic_hook)) {
                        call_user_func(NeoLog::$panic_hook,$e);
                    }
                }
            } else {
                //NeoLog::info("Shutdown");
            }
        }
        static function setPanicHook($callback_panic_hook)
        {
            NeoLog::$panic_hook=$callback_panic_hook;
        }
        static function setLevel($level) {
            NeoLog::$logger->setLevel($level);
        }

        static function getLevel() {
            return NeoLog::$logger->getLevel();
        }

        static function log($level,$msg,$context=array())
        {
            NeoLog::$logger->addRecord($level,$msg,$context);
        }

        static function panic_log($level,$msg,$context=array())
        {
            NeoLog::$logger->addRecord($level,"**PANIC**:$msg",$context);
            NeoLog::$panic_logger->addRecord($level,"**PANIC**:$msg",$context);
        }

        static function panic($msg,$context=array()) {
            NeoLog::panic_log(self::ALERT,$msg,$context);
        }

        static function debug($msg,$context=array())
        {
            NeoLog::$logger->addDebug($msg,$context);
        }

        static function info($msg,$context=array())
        {
            NeoLog::$logger->addInfo($msg,$context);
        }

        static function notice($msg,$context=array())
        {
            NeoLog::$logger->addNotice($msg,$context);
        }

        static function warning($msg,$context=array())
        {
            NeoLog::$logger->addWarning($msg,$context);
        }

        static function error($msg,$context=array())
        {
            NeoLog::$logger->addError($msg,$context);
        }

        static function critical($msg,$context=array())
        {
            NeoLog::$logger->addCritical($msg,$context);
        }

        static function alert($msg,$context=array())
        {
            NeoLog::$logger->addAlert($msg,$context);
        }

        static function mapPearLevel($level) {
            switch ($level) {
                case PEAR_LOG_INFO   : return NeoLogger::INFO;
                case PEAR_LOG_DEBUG  : return NeoLogger::DEBUG;
                case PEAR_LOG_NOTICE : return NeoLogger::NOTICE;

                case PEAR_LOG_EMERG : return NeoLogger::CRITICAL;
                case PEAR_LOG_ALERT : return NeoLogger::ALERT;
                case PEAR_LOG_CRIT  : return NeoLogger::CRITICAL;
                case PEAR_LOG_ERR   : return NeoLogger::ERROR;
                case PEAR_LOG_WARNING: return NeoLogger::WARNING;
            }
            return DEBUG;
        }

    }


} //namespace Neo;

namespace {
    use Neo\NeoLog;


    function l_debug($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addDebug($msg,$context);
    }

    function l_hex($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addInfo("\n" . hex_dump($msg),$context);
    }

    function l_info($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addInfo($msg,$context);
    }

    function l_notice($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addNotice($msg,$context);
    }

    function l_warning($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addWarning($msg,$context);
    }

    function l_warn($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addWarning($msg,$context);
    }

    function l_error($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addError($msg,$context);
    }
    function l_err($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addError($msg,$context);
    }

    function l_critical($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addCritical($msg,$context);
    }
    function l_crit($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addCritical($msg,$context);
    }

    function l_alert($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::$logger->addAlert($msg,$context);
    }

    function l_panic($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::panic($msg,$context);
    }

    //log a message, and exit cleanly
    function l_exit($msg,$context=array())
    {
        if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
        NeoLog::exitCleanly($msg,$context);
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
        NeoLog::log(NeoLog::mapPearLevel($level),"{$fn_sig}{$msg}");
    }

    function msg_r($var,$level=PEAR_LOG_DEBUG,$method="",$function="") {
        global $logger;
        $fn_sig="";
        if ($method!="")
            $fn_sig="$method:";
        if ($function!="")
            $fn_sig="$method::$function:";

        NeoLog::log(NeoLog::mapPearLevel($level), "{$fn_sig}msg_r:\n" . print_r($var,true));
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

        NeoLog::log(NeoLog::mapPearLevel($level), "{$fn_sig}:msg_raddump:\n" . print_r($tmp,true));
    }

    function panic($msg,$level=PEAR_LOG_ALERT,$method="",$function="") {
        global $panic_logger;
        $fn_sig="";
        if ($method!="")
            $fn_sig="$method:";
        if ($function!="")
            $fn_sig="$method::$function:";

        if (NeoLog::$panic_logger) {
            NeoLog::panic_log(NeoLog::mapPearLevel($level),"{$fn_sig}{$msg}");
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

        if (NeoLog::$panic_logger) {
            NeoLog::panic_log(NeoLog::mapPearLevel($level),"{$fn_sig}panic_r:\n" . print_r($var,true));
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
        NeoLog::$logger->addDebug($m);
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

    function hex_dump($data, $newline="\n")
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

    function str_hex($string)
    {
        $hex='';
        for ($i=0; $i < strlen($string); $i++)
        {
            $hex .= dechex(ord($string[$i]));
        }
        return $hex;
    }

    function hex_str($hex){
        $string='';
        for ($i=0; $i < strlen($hex)-1; $i+=2){
            $string .= chr(hexdec($hex[$i].$hex[$i+1]));
        }
        return $string;
    }
}


