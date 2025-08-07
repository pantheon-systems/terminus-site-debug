<?php
/**
 * This command will get all server logs on all appservers of a specific environment
 * specially on plans that has multiple appservers on live and test.
 *
 * Big thanks to Greg Anderson. Some of the codes are from his rsync plugin
 * https://github.com/pantheon-systems/terminus-rsync-plugin
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

use Pantheon\TerminusSiteLogs\Utility\Commons;

/**
 * Class SiteLogsCommand
 * @package Pantheon\TerminusSiteLogs\Commands
 */
class SiteLogsCommand extends TerminusCommand implements SiteAwareInterface
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
     * @var false|string
     */
    private $width;

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

        $this->width = exec("echo $(/usr/bin/tput cols)");
        $this->logPath = getenv('HOME') . '/.terminus/site-logs';
    }

    /**
     * @var string[]
     */
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
     *
     * @usage <site>.<env> [dest]
     *
     * @option progress Show the progress of the download.
     *
     * To get all the logs - including archived logs.
     *   terminus logs:get <site>.<env> --all
     *
     * To store logs in a custom location.
     *   terminus logs:get <site>.<env> [/path/to/folder]
     */
    public function GetLogs($site_env, $dest = null,
        $options = ['exclude' => true, 'all' => false, 'nginx-access' => false, 'nginx-error' => false, 'php-fpm-error' => false, 'php-slow' => false, 'pyinotify' => false, 'watcher' => false, 'newrelic' => true, 'progress' => false]) {

        // Create the logs directory if not present.
        if (!is_dir($this->logPath))
        {
            $this->log()->error('Logs directory not found.');
            // Create the logs directory if not present.
            $this->log()->notice('Creating logs directory.');
            mkdir($this->logPath, 0777, true);
        }

        // Get env_id and site_id.
        $this->DefineSiteEnv($site_env);
        $site = $this->site->get('name');
        $env = $this->environment->id;
        $env_id = $this->environment->get('id');
        $site_id = $this->site->get('id');

        // Set src and files.
        $src = "$env_id.$site_id";
        $files = '*.log';

        // only send output to /dev/null if the --progress option wasn't passed
        $devnull = '>/dev/null 2>&1';
        if ($options['progress'])
        {
            $devnull = '';
        }

        // If the destination parameter is empty, set destination to ~/.terminus/site-logs/[sitename]/[env]/.
        if (!$dest)
        {
            $dest = $this->logPath . '/'. $site . '/' . $env;
        }

        // Lists of files to be excluded.
        $rsync_options = $this->RsyncOptions($options);

        // Get all appservers' IP address.
        $appserver_dns_records = dns_get_record("appserver.$env_id.$site_id.drush.in", DNS_A);
        // Get dbserver IP address.
        $dbserver_dns_records = dns_get_record("dbserver.$env_id.$site_id.drush.in", DNS_A);

        $this->log()->notice('Downloading logs from appserver...');
        // Appserver - Loop through the record and download the logs.
        foreach($appserver_dns_records as $appserver)
        {
            $app_server_ip = $appserver['ip'];
            $dir = $dest . '/' . $app_server_ip;

            if (!is_dir($dir))
            {
                mkdir($dir, 0777, true);
            }

            if ($options['all'])
            {
                $this->log()->notice('Running {cmd}', ['cmd' => "rsync $rsync_options $src@$app_server_ip:logs/ $dir"]);
                $this->passthru("rsync $rsync_options -zi --progress --ipv4 --exclude=.git -e 'ssh -p 2222' $src@$app_server_ip:logs/ $dir $devnull");
            }
            else
            {
                $this->log()->notice('Running {cmd}', ['cmd' => "rsync $rsync_options $src@$app_server_ip:logs/nginx/ $dir"]);
                $this->passthru("rsync $rsync_options -zi --progress --ipv4 --exclude=.git -e 'ssh -p 2222' $src@$app_server_ip:logs/nginx/ $dir $devnull");

                $this->log()->notice('Running {cmd}', ['cmd' => "rsync $rsync_options $src@$app_server_ip:logs/php/ $dir"]);
                $this->passthru("rsync $rsync_options -zi --progress --ipv4 --exclude=.git -e 'ssh -p 2222' $src@$app_server_ip:logs/php/ $dir $devnull");
            }
        }

        // DBserver - Loop through the record and download the logs.
        foreach($dbserver_dns_records as $dbserver)
        {
            $db_server_ip = $dbserver['ip'];
            $dir = $dest . '/' . $db_server_ip;

            if (!is_dir($dir))
            {
                mkdir($dir, 0777, true);
            }

            $this->log()->notice('Downloading logs from dbserver...');
            $this->log()->notice('Running {cmd}', ['cmd' => "rsync $rsync_options $src@$db_server_ip:logs/*.log $dir"]);
            $this->passthru("rsync $rsync_options -zi --progress --ipv4 --exclude=.git -e 'ssh -p 2222' --include='*.log' --exclude='*' $src@$db_server_ip:logs/ $dir >/dev/null 2>&1");
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
     * @param string $site_env The site name and site environment. Example: foo.dev for Dev environment, foo.test for Test environment, and foo.live for Live environment.
     * @option php Parse the logs via PHP.
     * @option shell Parse the logs using *nix commands.
     * @option newrelic Shows NewRelic summary report.
     * @option type Type of logs to parse (php-error, php-fpm-error, nginx-access, nginx-error, mysqld-slow-query). It should be the filename of the log without the .log extension. To parse all the logs just use "all".
     * @option uri The uri from nginx-access.log.
     *
     * @usage <site>.<env> --type={all|nginx-access|nginx-error|php-error|php-fpm-error|php-slow} --shell --grouped-by="{KEYWORD}"
     *
     * To get the top visitors by IP:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=ip
     *
     * To get top responses by HTTP status:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=response-code
     *
     * To get top 403 requests:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=403
     *
     * To get top 404 requests:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=404
     *
     * To get PHP top 404 requests:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=php-404
     *
     * Top PHP 404 requests in full details:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=php-404-detailed
     *
     * To get 502 requests:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=502
     *
     * Top IPs accessing 502 (requires "terminus logs:parse site_name.env --type=nginx-access --shell --grouped-by=502" to get the SITE_URI):
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=ip-accessing-502 --uri={SITE_URI}
     *
     * To count the request that hits the appserver per second:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=request-per-second
     *
     * Top request by HTTP code:
     *   terminus logs:parse <site>.<env> --type=nginx-access --shell --grouped-by=request-method --code=[200|403|404|502]
     */
    public function ParseLogs($site_env, $options = ['php' => false, 'shell' => false, 'newrelic' => false, 'type' => '', 'grouped-by' => '', 'uri' => '', 'filter' => '', 'since' => '', 'until' => '', 'method' => ''])
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
            $path = $this->logPath . '/' . $site . '/'. $envi;
            if (is_dir($path))
            {
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

        //$this->checkSiteHeaders($site_env);
        //exit();

        // Get the logs per environment.
        $dirs = array_filter(glob($this->logPath . '/' . $site . '/' . $env . '/*'), 'is_dir');

        // @Todo make a universal date parameter.
        $date_filter = $this->ConvertDate($options['type'], $options['since']);

        // Make sure the type option is not empty.
        if (preg_match("/^[a-z\-]+$/", $options['type']))
        {
            if ($options['type'] !== 'all' && $options['shell'])
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

                if ($options['type'] === 'mysql' && $options['grouped-by'] === 'all')
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
        }
        else
        {
            $this->log()->error('Type value is missing.');
            exit();
        }

        // Scanner storage.
        $container = [];

        foreach ($dirs as $dir)
        {
            // Get the log file.
            if ($options['type'] === 'all' && $options['php'])
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
            else if ($options['type'] === 'mysql' && $options['shell'])
            {
                if (file_exists($dir . '/' . 'mysqld-slow-query.log'))
                {
                    $this->ParseMysqlSlowLog($dir, $options);
                }
            }
            else if ($options['type'] === 'php-slow' && $options['shell'])
            {
                if (file_exists($dir . '/' . $options['type'] . '.log'))
                {
                    // Parse php-slow log using *nix commands.
                    $this->ParsePhpSlowLog($dir, $options);
                }
            }
            else if ($options['type'] === 'nginx-access' && $options['shell'])
            {
                if (file_exists($dir . '/' . $options['type'] . '.log'))
                {
                    $this->ParseNginxAccessLog($dir, $options);
                }
            }
            else if ($options['type'] === 'nginx-error' && $options['shell'])
            {
                if (!$options['filter']) {
                    $this->log()->notice("You need to specify the filter.");
                    exit();
                }

                if (file_exists($dir . '/' . $options['type'] . '.log'))
                {
                    $nginx_error_log = $dir . '/' . $options['type'] . '.log';
                    $filter = $options['filter'];
                    if ($options['filter'] == 'access forbidden')
                    {
                        $this->output()->writeln("From <info>" . $nginx_error_log . "</> file.");
                        $this->passthru("cat $nginx_error_log | grep \"$filter\" | awk '{print $16, $11}' | sort -n | uniq -c | sort -nr | head -20");
                    }
                    if ($options['filter'] == 'SSL_shutdown')
                    {
                        $this->output()->writeln("From <info>" . $nginx_error_log . "</> file.");
                        $this->passthru("cat $nginx_error_log | grep \"$filter\" | sort -nr | head -10");
                    }
                }
            }
            else if ($options['type'] !== 'all' && $options['php'])
            {
                $log = $dir . '/' . $options['type'] . ".log";

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
        [$this->site, $this->environment] = $this->getSiteEnv($site_env);
    }

    /**
     * @param $site_env
     *
     * @return void
     */
    public function NewRelicHealthCheck($site_env)
    {
        $this->DefineSiteEnv($site_env);
        $site = $this->site->get('name');
        $env = $this->environment->id;

        $this->passthru("terminus newrelic:healthcheck $site.$env");
    }

    /**
     * Parse PHP slow log.
     */
    private function ParsePhpSlowLog($dir, $options)
    {
        if (('which cat') && ('which grep') && ('which tail') && ('which cut') && ('which uniq') && ('which sort'))
        {
            $php_slow_log = $dir . '/' . $options['type'] . '.log';
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
                    $this->passthru("pt-query-digest $mysql_slow_log");
                    break;
                case 'time':
                    $this->passthru("grep -A1 Query_time $mysql_slow_log | grep SET | awk -F '=' '{ print $2 }' | sort | uniq -c | sort -nr");
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
     * Parse Nginx access log.
     */
    private function ParseNginxAccessLog($dir, $options)
    {
        // Parse php-slow log using *nix commands.
        if (('which cat') && ('which awk') && ('which uniq') && ('which sort'))
        {
            $nginx_access_log = $dir . '/' . $options['type'] . '.log';
            $uri = $options['uri'];
            $response_status = ($options['code']) ? $options['code'] : '';
            $this->output()->writeln("From <info>" . $nginx_access_log . "</> file.");
            switch ($options['grouped-by'])
            {
                case 'ip':
                    $this->passthru('cat ' . $nginx_access_log . '| awk -F\\" \'{print $8}\' | awk \'{print $1}\' | sort -n | uniq -c | sort -nr | head -20');
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
                    $this->passthru("cat $nginx_access_log | grep \"$response_status\" | grep -v robots.txt | grep -v '\\.css' | grep -v '\\.jss*' | grep -v '\\.png' | grep -v '\\.ico' | awk '{print $6}' | cut -d'\"' -f2 | sort | uniq -c | awk '{print $1, $2}'");
                    break;
                default:
                    $this->log()->notice("You've reached the great beyond.");
            }
        }
    }

    /**
     * Get the response header.
     *
     * @return RowsOfFields
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     */
    private function checkSiteHeaders($site_env)
    {
        [, $env] = $this->getSiteEnv($site_env);
        $domains = $env->getDomains()->filter(
            function ($domain) {
                return $domain->get('type') === 'custom';
            }
        )->all();
        foreach ($domains as $domain) {
            //print_r($domain->getDNSRecords()->serialize());
            get_class_methods($domain->getUrl());
            //$settings = array_merge($settings, $domain->getDNSRecords()->serialize());
        }
        //return new RowsOfFields($settings);
    }

    /**
     * List all the sites in the logs.
     *
     * @command logs:list:sites
     * @aliases ll:sites lls
     *
     */
    public function ListSites()
    {
        $sites = array_diff(scandir($this->logPath), $this->Exclude());

        foreach ($sites as $site)
        {
            $this->output()->writeln("- {$site}");
            $envs = array_diff(scandir($this->logPath . '/' . $site), $this->Exclude());
            foreach ($envs as $env)
            {
                $this->output()->writeln("<info>  * </> {$env}");
            }
        }
    }

    /**
     * Exclude files and dirs.
     */
    private function Exclude()
    {
        return ['.DS_Store', '.', '..'];
    }
}
