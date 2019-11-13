<?php
/**
 * This command will parse nginx-error.log on all appservers of a specific environment
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
 * Class NginxErrorCommand
 * @package Pantheon\TerminusSiteLogs\Commands
 */
class NginxErrorCommand extends TerminusCommand implements SiteAwareInterface
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
     * Parse nginx-error.log.
     * 
     * @command logs:parse:nginx-error
     * @aliases lp:nginx-error lp:ne
     * 
     * @param string $site_env The site name and site environment. Example: foo.dev for Dev environment, foo.test for Test environment, and foo.live for Live environment.
     * @option php Parse the logs via PHP.
     * @option shell Parse the logs using *nix commands.
     * @option newrelic Shows NewRelic summary report.
     * @option filter Equivalent to "head -N" where "N" is a numeric value. By default the value is 10 which will return the latest 10 entries.
     * 
     * @usage <site>.<env> --grouped-by="{KEYWORD}"
     * 
     * Search nginx-error.log for "access forbidden" error:
     *   terminus logs:parse:nginx-error <site>.<env> --grouped-by="access forbidden"
     * 
     * Search nginx-error.log for "SSL_shutdown" error:
     *   terminus logs:parse:nginx-error <site>.<env> --grouped-by="SSL_shutdown'
     * 
     * Search nginx-error.log for "worker_connections" error. This error means that the site has no enough PHP workers. 
     * Consider upgrading to a higher plan to add more appservers.
     *   terminus logs:parse:nginx-error <site>.<env> --grouped-by='worker_connections"
     * 
     * To get the latest entries. You can adjust the results by passing a numeric value to "--filter" which has a default value of 10.
     *   terminus logs:parse:nginx-error <site>.<env>
     */
    public function ParseNginxErrorCommand($site_env, $options = ['php' => false, 'shell' => true, 'newrelic' => false, 'grouped-by' => 'latest', 'uri' => '', 'filter' => 10, 'since' => '', 'until' => '', 'method' => ''])
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
            if (file_exists($dir . '/nginx-error.log'))
            {
                // Parse nginx-error log using *nix commands.
                $this->ParseNginxErrorLog($dir, $options);
            }
        }
    }

    /**
     * Parse Nginx error logs.
     */
    private function ParseNginxErrorLog($dir, $options)
    {
        if (('which cat') && ('which grep') && ('which tail') && ('which cut') && ('which uniq') && ('which sort'))
        {
            if (!$options['filter']) 
            {
                $this->log()->notice("You need to specify the filter."); 
                exit();
            }
            
            if (file_exists($dir . '/nginx-error.log'))
            {
                $nginx_error_log = $dir . '/nginx-error.log';
                $keyword = $options['grouped-by'];
                switch ($options['grouped-by'])
                {
                    case 'access forbidden':
                        $this->output()->writeln("From <info>" . $nginx_error_log . "</> file.");
                        $this->passthru("cat $nginx_error_log | grep \"$keyword\" | awk '{print $16}' | sort -n | uniq -c | sort -nr | head -20");
                        break;
                    case 'SSL_shutdown':
                        $this->output()->writeln("From <info>" . $nginx_error_log . "</> file.");
                        $this->passthru("cat $nginx_error_log | grep \"$keyword\" | sort -nr | head -10");
                        break;
                    case 'worker_connections':
                        $this->output()->writeln("From <info>" . $nginx_error_log . "</> file.");
                        $this->passthru("cat $nginx_error_log | grep \"$keyword\" | sort -nr | head -10");
                        break;
                    default:
                        $this->output()->writeln("From <info>" . $nginx_error_log . "</> file.");
                        $this->passthru("tail $nginx_error_log | head -{$options['filter']}");
                }
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