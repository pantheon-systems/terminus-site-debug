<?php
/**
 * This command will parse php-slow.log on all appservers of a specific environment
 * specially on plans that has multiple appservers on live and test.
 */

namespace Pantheon\TerminusSiteLogs\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\StructuredListTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Pantheon\Terminus\Commands\Remote\DrushCommand;

/**
 * Class PhpSlowCommand
 * @package Pantheon\TerminusSiteLogs\Commands
 */
class PhpSlowCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use StructuredListTrait;

    private $site;
    private $environment;

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->logPath = getenv('HOME') . '/.terminus/site-logs';
    }
    
    /**
     * Parse php-slow.log.
     * 
     * @command logs:parse:php-slow
     * @aliases lp:php-slow lp:ps
     * 
     * @param string $site_env The site name and site environment. Example: foo.dev for Dev environment, foo.test for Test environment, and foo.live for Live environment.
     * @option php Parse the logs via PHP. 
     * @option shell Parse the logs using *nix built-in tools.
     * @option newrelic Shows NewRelic summary report.
     * @option uri The uri from nginx-access.log.
     * 
     * @usage <site>.<env> --shell --grouped-by="{KEYWORD}"
     * 
     * Search for the latest entries.
     *   terminus logs:parse:php-slow <site>.<env> --shell --grouped-by=latest 
     * 
     * Top functions by number of times they called:
     *   terminus logs:parse:php-slow <site>.<env> --shell --grouped-by=function
     * 
     * Slow requests grouped by minute:
     *   terminus logs:parse:php-slow <site>.<env> --shell --grouped-by=minute
     */ 
    public function ParsePhpSlowCommand($site_env, $options = ['php' => false, 'shell' => true, 'newrelic' => false, 'grouped-by' => '', 'uri' => '', 'filter' => '', 'since' => '', 'until' => '', 'method' => ''])
    {
        // Get the site name and environment.
        $this->DefineSiteEnv($site_env);
        $site = $this->site->get('name');
        $env = $this->environment->id;

        if ($this->logPath . '/' . $site . '/' . $env)
        {
            $this->LogParser($site_env, $options);
            
            if ($options['newrelic'])
            {
                $this->output()->writeln('');
                $this->output()->writeln('Fetching NewRelic data.....');
                $this->output()->writeln('');
                $this->NewRelicHealthCheck($site_env);
            }
            exit();
        }

        $this->log()->error("No data found. Please run <info>terminus logs:get $site.$env</> command.");
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

        foreach ($dirs as $dir) 
        {
            if (file_exists($dir . '/php-slow.log'))
            {
                // Parse php-slow log using *nix commands.
                $this->ParsePhpSlowLog($dir, $options);
            }
        }
    }

    /**
     * Parse PHP slow log.
     */
    private function ParsePhpSlowLog($dir, $options)
    {
        if (('which cat') && ('which grep') && ('which tail') && ('which cut') && ('which uniq') && ('which sort'))
        {
            $php_slow_log = $dir . '/php-slow.log';
            $php_fpm_error_log = $dir . '/php-fpm-error.log';

            $this->output()->writeln("From <info>" . $php_slow_log . "</> file.");
            
            switch ($options['grouped-by'])
            {
                case 'latest':
                    $this->passthru("tail +$(($(grep -nE ^$ $php_slow_log | tail -n1 | sed  -e 's/://g')+1)) $php_slow_log");
                    $pid = exec("tail +$(($(grep -nE ^$ $php_slow_log | tail -n1 | sed  -e 's/://g')+1)) $php_slow_log | head -n 1 | awk '{print $6}'");
                    $this->output()->writeln("<info>--</> Looking for additional information of <info>pid $pid</> in <info>$php_fpm_error_log</> log.");
                    sleep(6);
                    exec("grep -n \"WARNING: \[pool www\] child $pid\" $php_fpm_error_log > /tmp/temp_php_fpm_error_log_file");
                    
                    $fn = fopen("/tmp/temp_php_fpm_error_log_file", "r");
  
                    while(!feof($fn))  {
                        $result = fgets($fn);
                        preg_match('#^(\d+):(.*)$#', $result, $pid_matches);

                        $line_number = ($pid_matches[1]??'');
                        $log_message = ($pid_matches[2]??'');
                        preg_match('#request: \"(GET|POST|HEAD) (.*?)\"#', $log_message, $log_message_matches);
                        preg_match_all('#\(([^\)]+)\)#', $log_message, $time);
                        if (!empty($line_number) && !empty($log_message)) 
                        {
                            $this->output()->writeln("<info>Line number:</> {$line_number}");
                            $this->output()->writeln("<info>Request method:</>  {$log_message_matches[1]}");
                            $this->output()->writeln("<info>Request URI:</> {$log_message_matches[2]}");
                            if (isset($time[1]) && isset($time[1][1]))
                            {
                                $this->output()->writeln("<info>Time spent:</> {$time[1][1]}");
                            }  
                        }
                    }
                    fclose($fn);
                    
                    $this->passthru("rm -rf /tmp/temp_php_fpm_error_log_file");
                    $this->output()->writeln("");
                    break;
                case 'function':
                    $this->passthru("cat $php_slow_log | grep -A 1 script_filename | grep -v script_filename | grep -v -e '--' | cut -c 22- | sort | uniq -c | sort -nr");
                    break;
                case 'minute':
                    $this->passthru("cat $php_slow_log | grep 'pool www' | cut -d' ' -f2 | sort | cut -d: -f1,2 | uniq -c");
                    break;
                default:
                    $this->log()->notice("You've reached the great beyond.");
            }
        } 
        else 
        {
            $this->log()->error("Required utilities are not installed.");
        }
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
}