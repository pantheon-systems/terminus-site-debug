<?php
/**
 * This command will get all server logs on all appservers of a specific environment
 * specially on plans that has multiple appservers on live and test.
 *
 * Big thanks Greg Anderson. Some of the codes are from his rsync plugin
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

    protected $info;
    protected $tmpDirs = [];

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Pull all logs
     *
     * @command get-logs
     */
    public function getLogs($site_env_id, $dest = null, $options = ['exclude' => false,]) {
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
            $dest = '.';
        }

        $rsyncOptionString = '-rlIpz';

        // Get all appservers' IP address
        $dns_records = dns_get_record("appserver.$env_id.$site_id.drush.in", DNS_A);

        // Loop through the record and download the logs.
        foreach($dns_records as $record) {
            $app_server = $record['ip'];
            $dir = $dest . '/' . $app_server;

          if (!file_exists($dir)) {
              mkdir($dir);
          }

          $this->passthru("rsync $rsyncOptionString --ipv4 --exclude=.git -e 'ssh -p 2222' $src@$app_server:logs/*.log $dir >/dev/null 2>&1");
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

    protected function getSiteEnvIdFromPath($path)
    {
        return preg_replace('/:.*/', '', $path);
    }

    protected function removeSiteEnvIdFromPath($path)
    {
        return preg_replace('/^[^:]*:/', ':', $path);
    }
}
