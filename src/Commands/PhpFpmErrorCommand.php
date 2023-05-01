<?php
/**
 * This command will parse php-fpm-error.log on all appservers of a specific environment
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
 * Class PhpFpmErrorCommand
 * @package Pantheon\TerminusSiteLogs\Commands
 */
class PhpFpmErrorCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use StructuredListTrait;

    /**
     * @var
     */
    private $site;

    /**
     * @var
     */
    private $environment;

    /**
     * @var string
     */
    private $logPath;

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->logPath = getenv('HOME') . '/.terminus/site-logs';
    }

     /**
     * Parse php-fpm-error.log.
     * 
     * @command logs:parse:php-fpm
     * @aliases lp:php-fpm lp:pfe
     * 
     * @param string $site_env The site name and site environment. Example: foo.dev for Dev environment, foo.test for Test environment, and foo.live for Live environment.
     * @option php Parse the logs via PHP. 
     * @option shell Parse the logs using *nix built-in tools.
     * @option newrelic Shows NewRelic summary report.
     * @option filter Equivalent to "head -N" where "N" is a numeric value. By default the value is 10 which will return the latest 10 entries.
     * 
     * @usage <site>.<env> --grouped-by="{KEYWORD}"
     * 
     * Search for the latest entries.
     *   terminus logs:parse:php-fpm <site>.<env> --grouped-by=latest 
     */ 
    public function ParsePhpFpmErrorCommand($site_env, $options = ['php' => false, 'shell' => true, 'newrelic' => false, 'grouped-by' => 'latest', 'uri' => '', 'filter' => 20, 'since' => '', 'until' => '', 'method' => ''])
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

        //$this->checkSiteHeaders($site_env);
        //exit();

        // Get the logs per environment.
        $dirs = array_filter(glob($this->logPath . '/' . $site . '/' . $env . '/*'), 'is_dir');

        if (!$options['php'])
        {
            $this->log()->warning('This operation requires *nix commands like grep, cut, sort, uniq, and tail.');
            switch ($options['grouped-by'])
            {
                case 'ip':
                    $this->log()->notice('Top visitors by IP.');
                    break;
                case 'response-code':
                    $this->log()->notice('Top access by response code.');
                    break;
                case '403':
                    $this->log()->notice('Top 403 requests.');
                    break;
                case '404':
                    $this->log()->notice('Top 404 requests.');
                    break;
                case '502':
                    $this->log()->notice('Top 502 requests.');
                    break;
                case 'ip-accessing-502':
                    $this->log()->notice('Top 502 requests by IP.');
                    break;
                case 'most-requested-urls':
                    $this->log()->notice('Top most requested urls.');
                    break;
                case 'php-404':
                    $this->log()->notice('Top PHP 404 requests.');
                    break;
                case 'php-404-detailed':
                    $this->log()->notice('Full details of top PHP 404 requests.');
                    break;
                case 'request-per-second':
                    $this->log()->notice("Requests per second. These are the requests was able to bypass Global CDN.");
                    break;
                case 'request-method':
                    $this->log()->notice('Top requests by HTTP code.');
                    break;
                case 'time':
                    $this->log()->notice('Count of queries based on their time of execution. The first column is the total number of queries and the second column is the timestamp.');
                default:
                    // Do nothing.
            }
        }

        // Scanner storage.
        $container = [];

        foreach ($dirs as $dir) 
        {
            if (!$options['php'] && $options['shell'])
            {
                if (file_exists($dir . '/php-fpm-error.log'))
                {
                    $this->ParsePhpFpmErrorLog($dir, $options);
                }
            }
            else if ($options['php'] && $options['filter'])
            {
                $log = $dir . '/php-fpm-error.log';

                if (file_exists($log)) 
                {
                    $handle = fopen($log, 'r');

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
            else 
            {
                $this->log()->notice("Nothing to process.");
                exit();
            }
        }

        // Return the matches.
        if ($options['php'])
        {
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
    }

    /**
     * Parse PHP slow logs.
     */
    private function ParsePhpFpmErrorLog($dir, $options)
    {
        $php_fpm_error_log = $dir . '/php-fpm-error.log';
        $this->output()->writeln("From <info>" . $php_fpm_error_log . "</> file.");

        if ($this->CheckNixTools())
        { 
            switch ($options['grouped-by'])
            {
                case 'debug':
                    break;
                default:
                    $this->passthru("tail $php_fpm_error_log | head -{$options['filter']}");
            }
        }
    }

    /** 
     * Define site environment properties.
     * 
     * @param string $site_env Site and environment in a format of <site>.<env>.
     */
    private function DefineSiteEnv($site_env)
    {
        [$this->site, $this->environment] = $this->getSiteEnv($site_env);
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
     * Check if required tools are installed.
     */
    public function CheckNixTools() 
    {
        $commands = ['cat', 'grep', 'tail', 'cut', 'uniq', 'sort', 'awk'];
 
        $results = [];
        foreach ($commands as $command)
        {
            $results[] = shell_exec("command -v $command");
        }
        $container = array_search('', $results);
        if ($container)
        {
            $this->log()->notice('Some of the tools required is not installed.');
            return FALSE;
        }
        else
        {
            return TRUE;
        }
    }
}
