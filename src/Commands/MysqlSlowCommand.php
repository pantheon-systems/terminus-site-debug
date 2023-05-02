<?php
/**
 * This command will parse mysqld-slow.log on all appservers of a specific environment
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
 * Class MysqlSlowCommand
 * @package Pantheon\TerminusSiteLogs\Commands
 */
class MysqlSlowCommand extends TerminusCommand implements SiteAwareInterface
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
     * Parse mysqld-slow.log.
     * 
     * @command logs:parse:mysql-slow
     * @aliases lp:mysql-slow lp:ms
     * 
     * @param string $site_env The site name and site environment. Example: foo.dev for Dev environment, foo.test for Test environment, and foo.live for Live environment.
     * @option php Parse the logs via PHP. 
     * @option shell Parse the logs using *nix built-in tools.
     * @option newrelic Shows NewRelic summary report.
     * @option filter Show the most executed queries. 
     * 
     * @usage <site>.<env> 
     * 
     * Count of queries based on their time of execution:
     *   terminus logs:parse:mysql-slow <site>.<env> --grouped-by=time 
     * 
     * Display only the first N queries in the output. Sort output by count i.e. number of times query found in mysqld-slow-query.log.
     * This queries might be a good option for caching the result.
     *   terminus logs:parse:mysql-slow <site>.<env> --grouped-by=query-count 
     * 
     * Display only the first N queries in the output. Top queries which returned maximum rows.
     *   terminus logs:parse:mysql-slow <site>.<env> --grouped-by=average-rows-sent
     * 
     * Display only the first N queries in the output. Sort by query time or average query time.
     *   terminus logs:parse:mysql-slow <site>.<env> --grouped-by=average-time
     * 
     * Display everything:
     *   terminus logs:parse:mysql-slow <site>.<env>
     */ 
    public function MysqlSlowCommand($site_env, $options = ['php' => false, 'shell' => true, 'newrelic' => false, 'grouped-by' => '', 'filter' => '5'])
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
            if (file_exists($dir . '/' . 'mysqld-slow-query.log'))
            {
                $this->ParseMysqlSlowLog($dir, $options);
            }
        }
    }

    /**
     * Parse MySQL slow log.
     */
    private function ParseMysqlSlowLog($dir, $options)
    {
        $mysql_slow_log = $dir . '/' . "mysqld-slow-query.log";

        // Parse MySQL slow log.
        if ('which pt-query-digest')
        {
            switch ($options['grouped-by']) 
            {
                case false:
                    $this->PtQueryInfo();
                    $this->passthru("pt-query-digest $mysql_slow_log");
                    break;
                case 'time':
                    $this->passthru("grep -A1 Query_time $mysql_slow_log | grep SET | awk -F '=' '{ print date -r $2 }' | sort | uniq -c | sort -nr");
                    break;
                case 'query-count':
                    $this->passthru("mysqldumpslow -a -s c -t {$options['filter']} {$mysql_slow_log}");
                    break;
                case 'average-rows-sent':
                    $this->passthru("mysqldumpslow -a -s r -t {$options['filter']} {$mysql_slow_log}");
                    break;
                case 'average-time':
                    $this->passthru("mysqldumpslow -a -s t -t {$options['filter']} {$mysql_slow_log}");
                    break;
                default:   
                    $this->log()->notice("You've reached the great beyond.");
            }
            exit();
        }
        else
        {
            $this->log()->error("You don't have Percona tool installed. If you're on MacOS you can install percona-toolkit using Brew.");
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
     * Pt-query-digest info.
     */
    protected function PtQueryInfo()
    {
        $this->log()->notice('Percona Toolkit Terms Meaning.');
        $this->output()->writeln("
            Column        Meaning
            ============  ==========================================================
            Rank          The query's rank within the entire set of queries analyzed
            Query ID      The query's fingerprint
            Response time The total response time, and percentage of overall total
            Calls         The number of times this query was executed
            R/Call        The mean response time per execution
            V/M           The Variance-to-mean ratio of response time
            Item          The distilled query
        ");
    }
}
