<?php
/**
 * This command will get all server logs on all appservers of a specific environment
 * specially on plans that has multiple appservers on live and test.
 *
 * Big thanks to Greg Anderson. Some of the codes are from his rsync plugin
 * https://github.com/pantheon-systems/terminus-rsync-plugin
 */



namespace Pantheon\TerminusGetLogs\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;


class GetLogsCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    // protected $info;
    // protected $tmpDirs = [];

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->configFile = 'term-config';
    }

    private $logs_filename = [
        'nginx-access',
        'nginx-error',
        'php-error',
        'php-fpm-error',
        'php-slow',
        'pyinotify',
        'watcher',
        'newrelic',
    ];

    private $config_path = NULL;
    public static $configFile = 'term-config';


    /**
     * Download the logs.
     *
     * @command logs:get
     * @aliases lg:get
     */
    public function getLogs($site_env_id, $dest = null,
        $options = ['exclude' => false, 'nginx-access' => false, 'nginx-error' => false, 'php-fpm-error' => false, 'php-slow' => false, 'pyinotify' => false, 'watcher' => false, 'new-relic' => false,]) {
        // Get env_id and site_id.
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();

        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];

        // Set src and files.
        $src = "$env_id.$site_id";
        $files = '*.log';

        // Set destination to cwd if not specified.
        if (!$dest) {
            $dest = $siteInfo['name'] . '/' . $env_id;
        }

        // Lists of files to be excluded.
        $rsync_options = $this->generate_rsync_options($options);

        // Get all appservers' IP address
        $dns_records = dns_get_record("appserver.$env_id.$site_id.drush.in", DNS_A);

        // Loop through the record and download the logs.
        foreach($dns_records as $record) {
            $app_server = $record['ip'];
            $dir = $dest . '/' . $app_server;

          if (!is_dir($dir)) {
              mkdir($dir, 0777, true);
          }

          $this->log()->notice('Running {cmd}', ['cmd' => "rsync $rsync_options $src@$app_server:logs/*.log $dir"]);
          $this->passthru("rsync $rsync_options-zi --progress --ipv4 --exclude=.git -e 'ssh -p 2222' $src@$app_server:logs/*.log $dir >/dev/null 2>&1");
        }
    }

    protected function passthru($command)
    {
        $result = 0;
        passthru($command, $result);

        if ($result != 0) {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
    }

    private function generate_rsync_options($options) 
    {
      $rsync_options = '';
      $exclude = $this->parse_exclude($options);

      foreach($exclude as $item) {
        $rsync_options .= "--exclude $item ";
      }

      return $rsync_options;
    }

    private function parse_exclude($options) 
    {
        $exclude = [];

        // Parse option for exclude or include-only option.
        foreach($options as $option_key => $val) {
            // If option is set.
            if ($val) {

                // Proccess only the filenames.
                if ($option_key !== 'exclude' && in_array($option_key, $this->logs_filename)) {

                    // Add directly to exclude array if exclude tag was passed.
                    if ($options['exclude']) {
                        $exclude[] = $option_key . '.log';
                    }
                    else {
                        // If exclude tag was not passed, exclude filenames that are not passed.
                        if (empty($exclude)) {
                            // Since $exclude array is initially empty, get list form logs_filename.
                            $exclude = array_diff($this->logs_filename, array($option_key));
                        }
                        else {
                            $exclude = array_diff($exclude, array($option_key));
                        }
                    }
                }
            }
        }

        // Add .log if no --exclude tag.
        if (!$options['exclude']) {
          foreach($exclude as &$item) {
            $item .= '.log';
          }
        }

        return $exclude;
    }

    /**
     * Parse logs.
     * 
     * @command logs:parse
     * @aliases lg:parse
     * 
     * string $type
     *   Type of logs.
     * 
     * string $keyword
     *   What kind of logs to check.
     */
    public function parseLogs($siteenv, $type, $keyword) 
    {
        $this->logParser($siteenv, $type, $keyword);
    }

    /**
     * Define logs directory.
     * 
     * @command logs:set:dir
     * @aliases logsd
     * 
     * The parameter should be an absolute path.
     */
    public function setLogsDir($dir) 
    {
        $status = $this->checkConfigFile();
        
        switch ($status)
        {
            case '404':
                // Creat if config file don't exist.
                $this->createConfigFile();

                // Set the environment variable.
                $this->setEnv($dir, $this->configFile);
                break;

            case 'empty':
                // Set the environment variable.
                $this->setEnv($dir, $this->configFile);
                break;

            case 'set':
                // Reset the environment variable (TERMINUS_LOGS_DIR) to the new directory.
                $this->resetEnv($this->configFile);
    
                // Set the environment variable.
                $this->setEnv($dir, $this->configFile);
                break;
        }
        
        // Load the environment variables.
        $this->loadEnvVars();

        if (getenv('TERMINUS_LOGS_DIR'))
        {
            // Verify if the $dir already exist.
            if (is_dir($dir))
            {
                print "Terminus logs directory already exist. Configuration has been updated.\n";
            }
            else{
                $this->passthru("mkdir $dir");
            }
        }

        // Output the logs directory path after the operation.
        print "Terminus logs directory is now set to: " . getenv('TERMINUS_LOGS_DIR') . "\n";
    }

    /**
     * @command logs:info
     * @aliases logsi
     */
    public function terminusLogsInfo() 
    {
        $status = $this->checkConfigFile();

        if ($status == 'set')
        {
            // Load the environment variables.
            $this->loadEnvVars();
        
            if (getenv('TERMINUS_LOGS_DIR')) 
            {
                print "---------------------------------------------------------------------------------------------------------------------------\n";
                print "Terminus logs directory: " . getenv('TERMINUS_LOGS_DIR') . "\n";
                print "---------------------------------------------------------------------------------------------------------------------------\n";
                exit();
            }
            print "Terminus logs directory is not setup yet. \n";
        }

        print "Configuration has not been set. Please run the 'logs:set:dir' command.\n";
    }

    /**
     * Log parser.
     */
    public function logParser($siteenv, $type, $keyword) 
    {
        // Load the environment variables.
        $this->loadEnvVars('term-config');

        $base_path = getenv('TERMINUS_LOGS_DIR');

        list($site, $env) = explode('.', $siteenv);

        $dirs = array_filter(glob($base_path . $site . '/' . $env . '/*'), 'is_dir');

        print_r($dirs);

        exit();

        foreach ($dirs as $dir) {
            // Get the log file.
            $log = $dir . '/' . $type . ".log";

            if (file_exists($log)) {
                $handle = fopen($log, 'r');

                echo "From " . $log . "\n";
                
                // Scan possible matches in the logs.
                if ($handle) {
                    while (!feof($handle)) {
                        $buffer = fgets($handle);
                        if (strpos($buffer, $keyword) !== FALSE)
                            $matches[] = $buffer;
                    }
                    fclose($handle);

                    // Return the matches.
                    if (is_array($matches)) {
                        foreach ($matches as $match) {
                            echo "--------------------------\n";
                            echo $match;
                        }
                        echo "--------------------------\n";
                    }
                    else {
                        echo "No matches found.\n";
                    }

                    // Make sure the data placeholder is clear before the next loop.
                    unset($matches);
                } 

                // Terminate the operation.
                exit("Operation done!."); 
            }
            //throw new TerminusException('Unimplemented status {status} for domain {domain}.', ['command' => $command, 'status' => $result]);
            exit("Invalid arguments. Please make sure that the parameters are correct.");
        }
    }

    /**
     * Sync the environment variables.
     */
    private function loadEnvVars()
    {
        // Sync the newly created environment variable to systems environment variables.
        require dirname(__FILE__) . '/' . '../../vendor/autoload.php';
        $dotenv = \Dotenv\Dotenv::create(__DIR__, 'term-config');
        $dotenv->load();
    }

    /**
     * Set environment variable.
     */
    private function setEnv($dir) 
    {
        $f = @fopen(dirname(__FILE__) . '/' . $this->configFile, 'wb');
        fwrite($f, "TERMINUS_LOGS_DIR=$dir");
        fclose($f);
    }

    /**
     * Reset environment variable.
     */
    private function resetEnv()
    {
        $f = @fopen(dirname(__FILE__) . '/' . $this->configFile, 'r+');
        if ($f !== false) 
        {
            ftruncate($f, 0);
            fclose($f);
        }
    }

    /**
     * Check config file.
     */
    private function checkConfigFile()
    {
        $status = 'set';
        $config = dirname(__FILE__) . '/' . $this->configFile;

        // Check if $this->configFile already exist.
        if (!file_exists($config))
        {
            $status = '404';
        }

        // Check if $this->configFile is empty.
        if (file_exists($config) && filesize($config) == 0)
        {
            $status = 'empty';
        }

        return $status;
    }

    /** 
     * Create the config file.
     */
    private function createConfigFile()
    {
        $f = @fopen(dirname(__FILE__) . '/' . $this->configFile, 'wb');
        fclose($f);
    }
}


/**
 * TODO:
 * 
 *    * Logs on the other file is being ignored by the script. Make sure that the script is able to return all the matches in the logs.
 */