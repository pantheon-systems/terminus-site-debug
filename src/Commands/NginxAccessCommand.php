<?php
/**
 * This command will parse nginx-access.log on all appservers of a specific environment
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
 * Class NginxAccessCommand
 * @package Pantheon\TerminusSiteLogs\Commands
 */
class NginxAccessCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use StructuredListTrait;

    private $site;
    private $environment;
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
     * Parse nginx-access.log.
     * 
     * @command logs:parse:nginx-access
     * @aliases lp:nginx-access lp:na
     * 
     * @param string $site_env The site name and site environment. Example: foo.dev for Dev environment, foo.test for Test environment, and foo.live for Live environment.
     * @option php Parse the logs via PHP.
     * @option shell Parse the logs using *nix commands.
     * @option newrelic Shows NewRelic summary report.
     * @option type Type of logs to parse (php-error, php-fpm-error, nginx-access, nginx-error, mysqld-slow-query). It should be the filename of the log without the .log extension. To parse all the logs just use "all".
     * @option uri The uri from nginx-access.log.
     * 
     * @usage <site>.<env> --grouped-by="{KEYWORD}"
     * 
     * To get the top visitors by IP:
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=ip
     * 
     * To get top responses by HTTP status:
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=response-code 
     * 
     * To get top 403 requests:
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=403
     * 
     * To get top 404 requests: 
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=404
     * 
     * To get PHP top 404 requests:
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=php-404
     * 
     * Top PHP 404 requests in full details:
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=php-404-detailed
     * 
     * To get 502 requests:
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=502
     * 
     * Top IPs accessing 502 (requires "terminus logs:parse site_name.env --shell --grouped-by=502" to get the SITE_URI):
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=ip-accessing-502 --uri={SITE_URI}
     * 
     * To count the request that hits the appserver per second:
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=request-per-second
     * 
     * Top request by HTTP code:
     *   terminus logs:parse:nginx-access <site>.<env> --grouped-by=request-method --code=[200|403|404|502]
     */ 
    public function ParseNginxCommand($site_env, $options = ['php' => false, 'shell' => true, 'newrelic' => false, 'grouped-by' => '', 'uri' => '', 'filter' => '', 'since' => '', 'until' => '', 'method' => ''])
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

        if ($options['shell'])
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
            // Parse Nginx access logs using *nix built-in tools.
            if ($options['shell'])
            {
                if (file_exists($dir . '/nginx-access.log'))
                {
                    $this->ParseNginxAccessLog($dir, $options);
                }
            }
            
            // Parse Nginx access logs using PHP.
            if ($options['php'])
            {
                $log = $dir . '/nginx-access.log';

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
     * Parse Nginx access log.
     */
    private function ParseNginxAccessLog($dir, $options)
    {
        // Parse php-slow log using *nix commands.
        if (('which cat') && ('which awk') && ('which uniq') && ('which sort') && ('which sed'))
        {
            $nginx_access_log = $dir . '/nginx-access.log';
            $uri = $options['uri'];
            $this->output()->writeln("From <info>" . $nginx_access_log . "</> file.");
            
            switch ($options['grouped-by'])
            {
                case 'ip':
                    $this->passthru('cat ' . $nginx_access_log . '| awk -F\\" \'{print $8}\' | awk \'{print $1}\' | sort -n | uniq -c | sort -nr | head -20');
                    $this->output()->writeln("");
                    break;
                case 'response-code':
                    $this->passthru("cat $nginx_access_log | cut -d '\"' -f3 | cut -d ' ' -f2 | sort | uniq -c | sort -rn");
                    break;
                case '403':
                    //$this->passthru("awk '($9 ~ /403/)' $nginx_access_log | awk '{print $7}' | sort | uniq -c | sort -rn");
                    // This one is much better than the one above.
                    $this->passthru("awk '($9 ~ /403/)' nginx-access.log | awk -F\\\" '($2 ~ \"^GET *\"){print $2, $8}' | awk '{print $4, $2}' | sed 's/,//g' | sort | uniq -c | sort -rn");
                    break;
                case '404':
                    $this->passthru("awk '($9 ~ /404/)' $nginx_access_log | awk '{print $7}' | sort | uniq -c | sort -rn");
                    break;
                case '502':
                    $this->passthru("awk '($9 ~ /502/)' $nginx_access_log | awk '{print $7}' | sort | uniq -c | sort -r");
                    break;
                case 'ip-accessing-502':
                    $this->passthru("awk '($9 ~ /502/)' $nginx_access_log | awk -F\\\" '($2 ~ \"/$uri\"){print $1}' | awk '{print $1}' | sort | uniq -c | sort -rn");
                    break;
                case 'php-404':
                    $this->passthru("awk '($9 ~ /404/)' $nginx_access_log | awk -F\\\" '($2 ~ \"^GET .*\\.php\")' | awk '{print $7}' | sort | uniq -c | sort -rn | head -n 20");
                    break;
                case 'php-404-detailed':
                    $this->passthru("cat $nginx_access_log  | grep '[GET|POST] .*\.php' | awk '($9 ~ /404/)'");
                    break;
                case 'most-requested-urls':
                    $this->passthru("awk -F\\\" '{print $2}' $nginx_access_log | awk '{print $2}' | sort | uniq -c | sort -rn | head -20");
                    break;
                case 'most-requested-containing-xyz':
                    $this->passthru("awk -F\\\" '($2 ~ \"xml\"){print $2}' $nginx_access_log | awk '{print $2}' | sort | uniq -c | sort -rn | head -20");
                    break;
                case 'request-per-second':
                    $this->passthru("cat $nginx_access_log | awk '{print $4}' | sed 's/\[//g' | uniq -c | sort -rn | head -10");
                    break;
                case 'request-method':
                    $response_status = ($options['code']) ? $options['code'] : '200';
                    $this->passthru("cat $nginx_access_log | grep \"$response_status\" | grep -v robots.txt | grep -v '\\.css' | grep -v '\\.jss*' | grep -v '\\.png' | grep -v '\\.ico' | awk '{print $6}' | cut -d'\"' -f2 | sort | uniq -c | awk '{print $1, $2}'");
                    break;
                default:
                    $this->log()->notice("You've reached the great beyond.");
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