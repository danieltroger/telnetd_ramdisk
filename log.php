<?php


define('LOGTYPE_DEBUG', 0);
define('LOGTYPE_INFO', 1);
define('LOGTYPE_WARNING', 2);
define('LOGTYPE_ERROR', 3);
define('LOGTYPE_INPUT', 90);
define('LOGTYPE_OUTPUT', 91);

define('LOGLEVEL_VERBOSE', 0);
define('LOGLEVEL_NORMAL', 1);
define('LOGLEVEL_ERRORS', 3);

Class Log
{
  public static $sharedInstance;
  public $logLevel;
  private $syms;
  private $logFile = null;
  private $stdout = null;

  private function __construct(int $log_level, string $log_file = null)
  {
    $this->logLevel = $log_level;
    $this->syms = array(
      LOGTYPE_ERROR => '#',
      LOGTYPE_WARNING => '!',
      LOGTYPE_INFO => '+',
      LOGTYPE_DEBUG => '*',
      LOGTYPE_INPUT => '<',
      LOGTYPE_OUTPUT => '>'
    );
    if($log_file)
    {
      $this->logFile = fopen($log_file, 'w');
      if(!$this->logFile)
      {
        throw new \Exception("Couldn't open the specified log file: $log_file");
        return null;
      }
    }
    $this->stdout = fopen('php://stdout', 'w');
    if(!$this->stdout)
    {
      throw new \Exception("Couldn't open stdout");
      return null;
    }
  }

  public function terminate()
  {
    if($this->stdout) fclose($this->stdout);
    if($this->logFile) fclose($this->logFile);
    Log::$sharedInstance = null;
  }

  public static function main()
  {
    if(self::$sharedInstance == null)
    {
      self::$sharedInstance = new Log(1, 'log_telnet');
    }
    return self::$sharedInstance;
  }

  public function writeLog(string $line, int $log_type = 1, bool $write_stdout = true)
  {
    if(!strlen($line)) return;
    if(empty($this->syms[$log_type]))
    {
      $log_type = LOGTYPE_INFO;
    }
    $sym = $this->syms[$log_type];
    $line = (($line[0] === "\n") ? "\n" : '') . "[$sym] " . ltrim($line) . PHP_EOL;
    if($write_stdout)
    {
      fwrite($this->stdout, $line);
    }
    if($this->logFile)
    {
      fwrite($this->logFile, time() . ": " . $line);
    }
  }

  public static function writeInfo(string $line)
  {
    $log = self::main();
    $log->writeLog($line, 1);
  }

  public static function writeVerboseInfo(string $line)
  {
    $log = self::main();
    $log->writeLog($line, 0);
  }

  public static function writeWarning(string $line)
  {
    $log = self::main();
    $log->writeLog($line, 2);
  }

  public static function writeError(string $line)
  {
    $log = self::main();
    $log->writeLog($line, 3);
  }
}





?>
