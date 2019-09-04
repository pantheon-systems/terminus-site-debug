<?php
/**
 * This command will fetch an new relic overview of the project 
 * in a specific environment
 */
namespace Pantheon\TerminusSiteLogs\Commands;

use Pantheon\Terminus\Commands\Site\SiteCommand;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use League\CLImate\CLImate;

class NewRelicCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    /**
     * Object constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    private $new_relic_monitoring = [
        'overview',
        'plan',
    ];

    /**
     * Pull new-relic data per site
     *
     * @command newrelic:healthcheck
     */
    public function NewRelicHealthCheck($site_env_id, $plan = null, $options = ['all' => false, 'overview' => false]) 
    {

        $climate = new CLImate;
        $progress = $climate->progress()->total(100);
        $progress->advance();

        // Get env_id and site_id.
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();
        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];
        $newrelic = $env->getBindings()->getByType('newrelic');
        $progress->current(10);
        $nr_data = array_pop($newrelic);

        if (!empty($nr_data)) 
        {
            $api_key = $nr_data->get('api_key');
            $pop = $this->fetch_newrelic_data($api_key, $env_id);
            if (isset($pop)) 
            {
                $items[] = $pop;
                $progress->current(100);
                $climate->table($items);
            }
        }
    }

    /**
     * Pull new-relic data info per site
     *
     * @command newrelic-data:info
     */
    public function info($site_env_id, $plan = null, $options = ['custom_name' => false]) 
    {
        // Get env_id and site_id.
        list($site, $env) = $this->getSiteEnv($site_env_id);
        $env_id = $env->getName();

        $siteInfo = $site->serialize();
        $site_id = $siteInfo['id'];
        $newrelic = $env->getBindings()->getByType('newrelic');

        $nr_data = array_pop($newrelic);
        if (!empty($nr_data)) 
        {
            $api_key = $nr_data->get('api_key');
            $nr_id = $nr_data->get('account_id');
             
            $pop = $this->fetch_newrelic_info($api_key, $nr_id, $env_id);
            if (isset($pop)) 
            {
                $items[] = $pop;

                return $items;
            }
        }

        return false;
    }

    /**
     * Color Status based on New-relic
     */
    public function HealthStatus($color) 
    {
        switch ($color) 
        {
            case "green":
                return "Healthy Condition";
                break;

            case "red":
                return "<blink><red>Critical Condition</red></blink>";
                break;

            case "yellow":
                return "<yellow>Warning Condition</yellow>";
                break;

            case "gray":
                return "Not Reporting";
                break;

            default:
                return "Unknown";
                break;
        }
    }

    /**
     * Object constructor
     */
    public function CallAPI($method, $url, $api_key, $data = false) 
    {
        $header[] = 'X-Api-Key:' . $api_key;
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        $data = curl_exec($ch);
        curl_close($ch);;

        return $data;
    }

    public function multi_sort($items) 
    {
        foreach ($items as $key => $row) 
        {
            if (isset($row['Appserver Response Time'])) 
            {
                $resp[$key]  = $row['Appserver Response Time'];
            }
        }
        // Sort the items with responsetime descending, throughput ascending
        // Add $items as the last parameter, to sort by the common key
        array_multisort($resp, SORT_DESC, $items);

        return $items;
    }

    public function check_array_keys($obj, $status, $reporting) 
    {
        $arr_components = array(
            "response_time" => "Appserver Response Time",
            "throughput" => "Appserver Throughput",
            "error_rate" => "Error Rate",
            "apdex_target" => "Apdex Target",
            "browser_loadtime" => "Browser Load Time",
            "avg_browser_loadtime" => "Avg Page Load Time",
            "host_count" => "Number of Hosts",
            "instance_count" => "Number of Instance",
        );

        $items = array(
            "Name" => $obj['name'],
            "Appserver Response Time" => "--",
            "Appserver Throughput" => "--",
            "Error Rate" => "--",
            "Apdex Target" => "--",
            "Browser Load Time" => "--",
            "Avg Page Load Time" => "--",
            "Number of Hosts" => "--",
            "Number of Instance" => "--",
            "Health Status" => $status,
        );

        if ((!empty($reporting) OR $reporting != 'Not Reporting') AND isset($obj['application_summary'])) 
        {
            // Put the unit after the value.
            $apdex_score = ($obj['application_summary']['apdex_score'] <= 1) ? ' second' : ' seconds';
            $apdex_target = ($obj['application_summary']['apdex_target'] <= 1) ? ' second' : ' seconds';
            $nr_fetched_data = array(
                'response_time' => $obj['application_summary']['response_time'] . ' ms',
                'throughput' => $obj['application_summary']['throughput'] . ' rpm',
                'error_rate' => $obj['application_summary']['error_rate'],
                'apdex_target' => $obj['application_summary']['apdex_target'] . $apdex_target,
                'apdex_score' => $obj['application_summary']['apdex_score'] . $apdex_score,
                'host_count' => $obj['application_summary']['host_count'],
                'instance_count' => $obj['application_summary']['instance_count'],
            );    
            foreach ($arr_components as $key => $val) 
            {
                if (array_key_exists($key, $nr_fetched_data)) 
                {
                    if ($key == 'response_time')
                    {
                        $val = 'Appserver Response Time';
                    }
                    if ($key == 'throughput')
                    {
                        $val = 'Appserver Throughput';
                    }

                    $items[$val] = $nr_fetched_data[$key];
                }
                if (isset($obj['end_user_summary']))
                {
                    $end_user_obj = $obj['end_user_summary'];
                    print-r($end_user_obj);
                    if (array_key_exists($key, $end_user_obj)) 
                    {
                        if ($key == 'response_time')
                        {
                            $val = 'Browser Load Time';
                        }
                        if ($key == 'throughput')
                        {
                            $val = 'Avg Page Load Time';
                        }

                        $items[$val] = $end_user_obj[$key];
                    }
                }
            }
        }

        return $items;
    }

    public function fetch_newrelic_data($api_key, $env_id) 
    {
        $url =  'https://api.newrelic.com/v2/applications.json';
        $result = $this->CallAPI('GET', $url, $api_key, $data = false);
        $obj_result = json_decode($result, true);

        if (isset($obj_result['applications'])) 
        {
            foreach ($obj_result['applications'] as $key => $val) 
            {
                $url =  "https://api.newrelic.com/v2/applications/" . $val['id'] . ".json";
                $myresult = $this->CallAPI('GET', $url, $api_key, $data = false);
                $item_obj = json_decode($myresult, true);
                if (strstr($item_obj['application']['name'], $env_id)) 
                {
                    $obj = $item_obj['application'];
                    $status = $this->HealthStatus($obj['health_status']);
                    $reporting = $this->HealthStatus($obj['reporting']);

                    return $this->check_array_keys($obj, $status, $reporting);
                }
            }
        }

        return false;
    }

    public function fetch_newrelic_info($api_key, $nr_id, $env_id) 
    {
        $url =  'https://api.newrelic.com/v2/applications.json';
        $count=0;

        $result = $this->CallAPI('GET', $url . "?filter[name]=+(live)", $api_key, $data = false);
        $obj_result = json_decode($result, true);
        if (isset($obj_result['applications'])) 
        {
            $count = count($obj_result['applications']);
            $this->log()->notice($count);
            foreach($obj_result['applications'] as $key => $val) 
            {
                $isMatched = strstr(strtolower($val['name']), '(' . strtolower($env_id) . ')');
                if ($isMatched != "") 
                {
                    $url =  "https://api.newrelic.com/v2/applications/" . $val['id'] . ".json";
                    $myresult = $this->CallAPI('GET', $url, $api_key, $data = false);
                    
                    return json_encode(array_merge(json_decode($myresult, true), array("api_key" => $api_key, "nr_id" => $nr_id)));
                }
            }
        }

        return false;
    }
}