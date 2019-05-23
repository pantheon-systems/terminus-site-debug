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
use Pantheon\Terminus\Commands\Remote\DrushCommand;


class GetLogsCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var Site
     */
    private $site;

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->configFile = 'term-config';
        $this->width = exec("echo $(/usr/bin/tput cols)");
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

    /**
     * Download the logs.
     *
     * @command logs:get
     * @aliases lg
     */
    public function getLogs($site_env_id, $dest = null,
        $options = ['exclude' => false, 'nginx-access' => false, 'nginx-error' => false, 'php-fpm-error' => false, 'php-slow' => false, 'pyinotify' => false, 'watcher' => false, 'new-relic' => true,]) {
        
        // Get the logs directory.
        $this->loadEnvVars();

        // Get the default logs directory.
        if (getenv('TERMINUS_LOGS_DIR'))
        {
            $logsPath = getenv('TERMINUS_LOGS_DIR');
        }
         
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
            $dest = $logsPath . '/'. $siteInfo['name'] . '/' . $env_id;
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
     * @usage <site>.<env> --type TYPE --keyword "keyword"
     */
    public function parseLogs($site_env, $options = ['type' => '', 'keyword' => '', 'since' => '', 'until' => '']) 
    {
        // Load the environment variables.
        $this->loadEnvVars();

        if (getenv('TERMINUS_LOGS_DIR'))
        {
            $this->logParser($site_env, $options);
            exit();
        }

        $this->log()->error("No configuration found. Please run <info>terminus logs:set:dir</> command.");
    }

    /**
     * List files.
     * @command logs:list
     * @aliases ls
     * 
     * @param string $site_env
     * 
     * @return string Command output
     */
    public function logsList($site_env) 
    {
        if ($this->getLogsDir())
        {
            $this->defineSiteEnv($site_env);
            $site = $this->site->get('name');
            $envi = $this->environment->id;

            $path = $this->getLogsDir() . '/' . $site . '/'. $envi;
            $dirs = array_diff(scandir($path), array('.DS_Store', '.', '..'));

            foreach ($dirs as $dir) {
                $this->output()->writeln($path . '/' . $dir);
                print_r(array_diff(scandir($path . '/' . $dir), array('.DS_Store', '.', '..')));
                $this->output()->writeln('');
            }
        } 
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
                $this->log()->notice("Terminus logs directory already exist. Configuration has been updated.");
            }
            else{
                $this->passthru("mkdir $dir");
            }
        }

        // Output the logs directory path after the operation.
        $this->log()->notice("Terminus logs directory is now set to: " . getenv('TERMINUS_LOGS_DIR'));
    }

    /**
     * Get logs directory.
     */
    private function getLogsDir() 
    {
        // Load the environment variables.
        $this->loadEnvVars();

        if (getenv('TERMINUS_LOGS_DIR')) 
        {
            return getenv('TERMINUS_LOGS_DIR');
        }
        else 
        {
            $this->log()->notice('Terminus logs directory is not setup yet.');
            return NULL;
        }
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
                $this->output()->writeln($this->line('-'));
                $this->output()->writeln("Logs directory: <info>" . getenv('TERMINUS_LOGS_DIR') . "</>");
                $this->output()->writeln($this->line('-'));
            }
            else 
            {
                $this->log()->notice('Terminus logs directory is not setup yet.');
            }
        }
        else 
        {
            $this->log()->error('Configuration file is missing. Please run <info>terminus logs:set:dir</> command.');
        }
    }

    /**
     * Log parser.
     */
    private function logParser($site_env, $options) 
    {
        print_r($options);
        // Load the environment variables.
        $this->loadEnvVars();

        // Get the logs directory
        $base_path = getenv('TERMINUS_LOGS_DIR');

        // Define site and environment.
        list($this->site, $this->environment) = array_pad(explode('.', $site_env), 2, null);

        // Get the logs per environment.
        $dirs = array_filter(glob($base_path . '/' . $this->site . '/' . $this->environment. '/*'), 'is_dir');

        // @Todo make a universal date parameter.
        $date_filter = $this->convertDate($options['type'], $options['since']);
        
        foreach ($dirs as $dir) 
        {
            // Get the log file.
            if ($options['type'] == 'all') 
            {
                if ($res = opendir($dir)) 
                {
                    while (false !== ($entry = readdir($res))) 
                    {
                        if ($entry != "." && $entry != "..") 
                        {
                            $log = $dir . '/' . $entry;

                            if (file_exists($log)) 
                            {
                                $handle = fopen($log, 'r');
                                // Scan possible matches in the logs.
                                if ($handle) 
                                {
                                    while (!feof($handle)) 
                                    {
                                        $buffer = fgets($handle);

                                        if (!empty($options['since']))
                                        {
                                            if (strpos($buffer, $options['keyword']) !== FALSE && strpos($buffer, $options['since'])) 
                                            {
                                                $container[$log][] = $buffer;
                                            }
                                        }
                                        else 
                                        {
                                            if (strpos($buffer, $options['keyword']) !== FALSE) 
                                            {
                                                $container[$log][] = $buffer;
                                            }
                                        }
                                    }
                                    fclose($handle);
                                } 
                            }
                        }
                    }
                
                    closedir($res);
                }
            }
            else 
            {
                $log = $dir . '/' . $options['type'] . ".log";

                if (file_exists($log)) 
                {
                    $handle = fopen($log, 'r');

                    // Scan possible matches in the logs.
                    if ($handle) {
                        while (!feof($handle)) 
                        {
                            $buffer = fgets($handle);

                            if (!empty($options['since']))
                            {
                                if (strpos($buffer, $options['keyword']) !== FALSE && strpos($buffer, $options['since'])) 
                                {
                                    $container[$log][] = $buffer;
                                }
                            }
                            else 
                            {
                                if (strpos($buffer, $options['keyword']) !== FALSE) 
                                {
                                    $container[$log][] = $buffer;
                                }
                            }
                        }
                        fclose($handle);
                    } 
                }
            }
        }

        // Return the matches.
        if (is_array($container)) 
        {
            $count = [];

            foreach ($container as $i => $matches) 
            {
                $this->output()->writeln("From <info>" . $i . "</> file.");
                $this->output()->writeln($this->line('='));
                
                foreach ($matches as $match)
                {
                    $count[] = $match;
                    $this->output()->writeln($match);
                    $this->output()->writeln($this->line('-'));
                }
            }
            $this->log()->notice(sizeof($count) . " " . ((sizeof($count) > 1) ? 'results' : 'result') . " matched found.");
        }
        else 
        {
            $this->log()->notice("No matches found.");
        }
    }

    /**
     * 
     * Format date and time in the server logs
     */
    public function convertDate($type, $date_n_time) {
        $convert = $date_n_time;

        if ($type == 'nginx-access' || 'nginx-error') {

        }
        elseif($type == 'php-error' || 'php-fpm-error' || 'php-slow') {

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

    /**
     * Line separator.
     */
    private function line($separator) 
    {
        for ($i = 1; $i <= $this->width; $i++)
        {
            ($separator == '-') ? $line .= "-" : $line .= "=";
        }

        return $line;
    }

    /** 
     * Define site environment properties.
     * 
     * @param string Site and environment in a format of <site>.<env>.
     */
    private function defineSiteEnv($site_env)
    {
        list($this->site, $this->environment) = $this->getSiteEnv($site_env);
    }
}