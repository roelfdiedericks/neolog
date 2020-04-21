<?php

namespace Neo;

use Monolog\Logger as MLogger;
use Monolog\Handler\StreamHandler;
use \Bramus\Monolog\Formatter\ColoredLineFormatter;

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
        static $console_stream=null;

        const DEBUG = 100;
        const INFO = 200;
        const NOTICE = 250;
        const WARNING = 300;
        const ERROR = 400;
        const CRITICAL = 500;
        const ALERT = 550;



        static function init($name,$logdir='',$force_console=false,$level=MLogger::WARNING)
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


        static public function exitCleanly($msg,$context) {
            NeoLog::$cleanexit=true;
            NeoLog::$logger->addNotice($msg,$context);
        }

        

        static function initConsoleLogging()
        {
            NeoLog::$console_logging_active=true;
            NeoLog::$logger = new MLogger(NeoLog::$name);
	    if (defined(STDOUT)) {
		NeoLog::$console_stream=STDOUT;
		}
            $handler=new StreamHandler(NeoLog::$console_stream, MLogger::DEBUG);
            $handler->setFormatter( new ColoredLineFormatter() );
            NeoLog::$logger->pushHandler($handler);
            NeoLog::$logger_handler=$handler;

            NeoLog::$panic_logger=NeoLog::$logger;

            //NeoLog::enableIntrospection();
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
            NeoLog::$logger = new NeoLog(NeoLog::$name);
            $handler=new StreamHandler(NeoLog::$logfile, MLogger::DEBUG);
            $handler->setFormatter( new ColoredLineFormatter() );
            NeoLog::$logger->pushHandler($handler);
            NeoLog::$logger_handler=$handler;

            NeoLog::$panic_logger = new NeoLog(NeoLog::$name);
            $handler=new StreamHandler(NeoLog::$panic_logfile, MLogger::DEBUG);
            NeoLog::$panic_logger->pushHandler($handler);
            NeoLog::$panic_logger_handler=$handler;

            //NeoLog::enableIntrospection();
        }

        static function initErrorHandlers()
        {
            register_shutdown_function('Neo\NeoLog::shutdownHandler');
            error_reporting(E_ALL);
            set_error_handler("NeoLog::errorHandler",E_ALL);
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
                    $priority = self::WARNING;
                    break;
                case E_NOTICE:
                case E_USER_NOTICE:
                    $priority = self::WARNING; //map to warning, so we get ppl to fix their code
                    //undefined variables or indexes we thunk to INFO level
                    if (preg_match("/Undefined variable/",$message) || preg_match("/Undefined index/",$message)) {
                        $priority = self::INFO;
                    }
                    break;
                case E_ERROR:
                case E_USER_ERROR:
                    $priority = self::ERROR;
                    break;
                case E_DEPRECATED:
                    $priority=self::DEBUG;
                    break;
                default:
                    $priority = self::INFO;
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
            //todo: somehow fix this
            //NeoLog::$logger->setLevel($level);
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
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::$logger->debug($msg, $context);
        }

        static function info($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::$logger->info($msg, $context);
        }

        static function notice($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::$logger->notice($msg, $context);
        }

        static function warning($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::$logger->warning($msg, $context);
        }

        static function error($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::$logger->error($msg, $context);
        }

        static function critical($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::$logger->addRecord(self::CRITICAL, $msg, $context);
        }

        static function alert($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::$logger->addRecord(self::ALERT, $msg, $context);
        }

        static function mapPearLevel($level) {
            switch ($level) {
                case PEAR_LOG_INFO   : return NeoLog::INFO;
                case PEAR_LOG_DEBUG  : return NeoLog::DEBUG;
                case PEAR_LOG_NOTICE : return NeoLog::NOTICE;

                case PEAR_LOG_EMERG : return NeoLog::CRITICAL;
                case PEAR_LOG_ALERT : return NeoLog::ALERT;
                case PEAR_LOG_CRIT  : return NeoLog::CRITICAL;
                case PEAR_LOG_ERR   : return NeoLog::ERROR;
                case PEAR_LOG_WARNING: return NeoLog::WARNING;
            }
            return DEBUG;
        }

    }


include_once(__DIR__ . "/neo_globals.php");
