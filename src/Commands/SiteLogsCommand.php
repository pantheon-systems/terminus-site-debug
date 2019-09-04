<?php
/**
 * This command will get all server logs on all appservers of a specific environment
 * specially on plans that has multiple appservers on live and test.
 *
 * Big thanks to Greg Anderson. Some of the codes are from his rsync plugin
 * https://github.com/pantheon-systems/terminus-rsync-plugin
 */



namespace Pantheon\TerminusSiteLogs\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Pantheon\Terminus\Commands\Remote\DrushCommand;

class SiteLogsCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    private $site;
    private $environment;

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->configFile = 'term-config';
        $this->width = exec("echo $(/usr/bin/tput cols)");

        $this->logPath = getenv('HOME') . '/.terminus/site-logs';
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

    /**
     * Download the logs.
     *
     * @command logs:get
     * @aliases lg
     */
    public function GetLogs($site_env_id, $dest = null,
        $options = ['exclude' => true, 'nginx-access' => false, 'nginx-error' => false, 'php-fpm-error' => false, 'php-slow' => false, 'pyinotify' => false, 'watcher' => false, 'newrelic' => true,]) {
        
        // Create the logs directory if not present.
        if (!is_dir($this->logPath))
        {
            mkdir($this->logPath, 0777, true);
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
        if (!$dest) 
        {
            $dest = $this->logPath . '/'. $siteInfo['name'] . '/' . $env_id;
        }

        // Lists of files to be excluded.
        $rsync_options = $this->RsyncOptions($options);

        // Get all appservers' IP address
        $dns_records = dns_get_record("appserver.$env_id.$site_id.drush.in", DNS_A);

        // Loop through the record and download the logs.
        foreach($dns_records as $record) 
        {
            $app_server = $record['ip'];
            $dir = $dest . '/' . $app_server;

          if (!is_dir($dir)) 
          {
              mkdir($dir, 0777, true);
          }

          $this->log()->notice('Running {cmd}', ['cmd' => "rsync $rsync_options $src@$app_server:logs/*.log $dir"]);
          $this->passthru("rsync $rsync_options -zi --progress --ipv4 --exclude=.git -e 'ssh -p 2222' $src@$app_server:logs/*.log $dir >/dev/null 2>&1");
        }
    }

    /**
     * Passthru command. 
     */
    protected function passthru($command)
    {
        $result = 0;
        passthru($command, $result);

        if ($result != 0) 
        {
            throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $result]);
        }
    }

    /**
     * Rsync options.
     */
    private function RsyncOptions($options) 
    {
      $rsync_options = '';
      $exclude = $this->ParseExclude($options);

      foreach($exclude as $item) 
      {
        $rsync_options .= "--exclude $item ";
      }

      return $rsync_options;
    }

    /**
     * Rsync exclude options.
     */
    private function ParseExclude($options) 
    {
        $exclude = [];

        // Parse option for exclude or include-only option.
        foreach($options as $option_key => $val) 
        {
            // If option is set.
            if ($val) 
            {

                // Proccess only the filenames.
                if ($option_key !== 'exclude' && in_array($option_key, $this->logs_filename)) 
                {

                    // Add directly to exclude array if exclude tag was passed.
                    if ($options['exclude']) 
                    {
                        $exclude[] = $option_key . '.log';
                    }
                    else 
                    {
                        // If exclude tag was not passed, exclude filenames that are not passed.
                        if (empty($exclude)) 
                        {
                            // Since $exclude array is initially empty, get list form logs_filename.
                            $exclude = array_diff($this->logs_filename, array($option_key));
                        }
                        else 
                        {
                            $exclude = array_diff($exclude, array($option_key));
                        }
                    }
                }
            }
        }

        // Add .log if no --exclude tag.
        if (!$options['exclude']) 
        {
          foreach($exclude as &$item) 
          {
            $item .= '.log';
          }
        }

        return $exclude;
    }

    /**
     * Parse the logs.
     * 
     * @command logs:parse
     * @aliases lp
     * 
     * @usage <site>.<env> --type={all|nginx-access|nginx-error|php-error|php-fpm-error} --filter="{KEYWORD}"
     */
    public function ParseLogs($site_env, $options = ['type' => '', 'filter' => '', 'since' => '', 'until' => '']) 
    {
        // Get the site name and environment.
        $this->DefineSiteEnv($site_env);
        $site = $this->site->get('name');
        $env = $this->environment->id;

        // $this->test($this->input(), $this->output());

        if ($this->logPath . '/' . $site . '/' . $env)
        {
            $this->LogParser($site_env, $options);

            $this->output()->writeln('');
            $this->output()->writeln('Fetching NewRelic data.....');
            $this->output()->writeln('');

            $this->NewRelicHealthCheck($site_env);
            exit();
        }

        $this->log()->error("No data found. Please run <info>terminus logs:get $site.$env</> command.");
    }

    /**
     * List the log files.
     * 
     * @command logs:list
     * @aliases ls
     * 
     * @param string $site_env Site name and environment id.
     */
    public function LogsList($site_env) 
    {
        // Get the site name and environment id.
        $this->DefineSiteEnv($site_env);
        $site = $this->site->get('name');
        $envi = $this->environment->id;

        // Check the existence of logs directory.
        if (is_dir($this->logPath))
        {
            if (is_dir($this->logPath . '/' . $site . '/'. $envi))
            {
                $path = $this->logPath . '/' . $site . '/'. $envi;
                $dirs = array_diff(scandir($path), array('.DS_Store', '.', '..'));

                $this->log()->notice('Listing all the downloaded logs.');

                foreach ($dirs as $dir) 
                {
                    $this->output()->writeln($path . '/' . $dir);
                    $items = array_diff(scandir($path . '/' . $dir), array('.DS_Store', '.', '..'));
                    foreach($items as $item)
                    {
                        $this->output()->writeln('-- ' . $item);
                    }
                    $this->output()->writeln('');
                }
                exit();
            }
            $this->log()->error("No data found.");

            // Download the logs.
            $this->log()->notice("Downloading the logs from $envi environment.");
            passthru("terminus logs:get $site_env");

            // List the files.
            $this->log()->notice('Listing all the downloaded logs.');
            passthru("terminus logs:list $site_env");

            exit();
        } 
        $this->log()->error('Logs directory not found.');
    
        // Create the logs directory if not present.
        $this->log()->notice('Creating logs directory.');
        mkdir($this->logPath, 0777, true);

        // Download the logs.
        $this->log()->notice("Downloading the logs from $envi environment.");
        passthru("terminus logs:get $site_env");

        // List the files.
        $this->log()->notice('Listing all the downloaded logs.');
        passthru("terminus logs:list $site_env");
    }


    /**
     * Log parser.
     */
    private function LogParser($site_env, $options) 
    {
        // Define site and environment.
        $this->DefineSiteEnv($site_env);
        $site = $this->site->get('name');
        $env = $this->environment->id;

        // Get the logs per environment.
        $dirs = array_filter(glob($this->logPath . '/' . $site . '/' . $env . '/*'), 'is_dir');

        // @Todo make a universal date parameter.
        $date_filter = $this->ConvertDate($options['type'], $options['since']);
        
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
                                            if (strpos($buffer, $options['filter']) !== FALSE && strpos($buffer, $options['since'])) 
                                            {
                                                $container[$log][] = $buffer;
                                            }
                                        }
                                        else 
                                        {
                                            if (strpos($buffer, $options['filter']) !== FALSE) 
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
                                if (strpos($buffer, $options['filter']) !== FALSE && strpos($buffer, $options['since'])) 
                                {
                                    $container[$log][] = $buffer;
                                }
                            }
                            else 
                            {
                                if (strpos($buffer, $options['filter']) !== FALSE) 
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
        if (is_array(@$container)) 
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
     * 
     * TODO: Roald
     */
    public function ConvertDate($type, $date_n_time) 
    {
        $convert = $date_n_time;

        if ($type == 'nginx-access' || 'nginx-error') 
        {

        }
        elseif($type == 'php-error' || 'php-fpm-error' || 'php-slow') 
        {

        }
    }

    /**
     * Line separator.
     */
    private function line($separator) 
    {
        $line = null;
        for ($i = 1; $i <= $this->width; $i++)
        {
            ($separator == '-') ? $line .= "-" : $line .= "=";
        }

        return $line;
    }

    /** 
     * Define site environment properties.
     * 
     * @param string $site_env Site and environment in a format of <site>.<env>.
     */
    private function DefineSiteEnv($site_env)
    {
        list($this->site, $this->environment) = $this->getSiteEnv($site_env);
    }

    public function NewRelicHealthCheck($site_env) 
    {
        $this->DefineSiteEnv($site_env);
        $site = $this->site->get('name');
        $env = $this->environment->id;
    
        $this->passthru("terminus newrelic:healthcheck $site.$env");
    }
}