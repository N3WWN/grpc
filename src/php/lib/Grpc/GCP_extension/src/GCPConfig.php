<?php

namespace Grpc\GCP;

//require_once(dirname(__FILE__).'/generated/Grpc_gcp/ExtensionConfig.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/AffinityConfig_Command.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/ApiConfig.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/ChannelPoolConfig.php');
//require_once(dirname(__FILE__).'/generated/Grpc_gcp/MethodConfig.php');
//require_once(dirname(__FILE__).'/generated/GPBMetadata/GrpcGcp.php');

use Google\Auth\ApplicationDefaultCredentials;
use Psr\Cache\CacheItemPoolInterface;

class Config
{
  private $gcp_channel;
  private $gcp_call_invoker;

  public function __construct($conf, CacheItemPoolInterface $cacheItemPool = null)
  {
    $gcp_channel = null;
    $channel_pool_key = 'gcp_channel' . getmypid();
    $gcp_call_invoker = new GCPCallInvoker();

    $item = $cacheItemPool->getItem($channel_pool_key);

    if ($item->isHit()) {
      print_r($item);
      echo "GCP channel has already created by this PHP-FPM worker process\n";
      $gcp_channel = $item->get();
      $gcp_channel->reCreateCredentials();
    } else {
      echo "GCP channel has not created by this worker process before\n";
      $affinity_conf = $this->parseConfObject($conf);
      // Create GCP channel based on the information.
      $hostname = $affinity_conf['target'][0];
      $credentials = \Grpc\ChannelCredentials::createSsl();
      $auth = ApplicationDefaultCredentials::getCredentials();
      $opts = [
        'credentials' => $credentials,
        'update_metadata' => $auth->getUpdateMetadataFunc(),
        'affinity_conf' => $affinity_conf,
      ];
      $gcp_channel = new \Grpc\GCP\GrpcExtensionChannel($hostname, $opts);
    }

    $this->gcp_channel = $gcp_channel;
    $this->gcp_call_invoker = $gcp_call_invoker;

    register_shutdown_function(function ($cacheItemPool, $item, $channel, $channel_pool_key) {
      // Push the current gcp_channel back into the pool when the script finishes.
      //  $affinity_conf['gcp_channel'.getmypid()] = $channel;
      echo "register_shutdown_function " . $channel->version . "\n";
      $item->set($channel);
      $cacheItemPool->save($item);
      print_r($item);

    }, $cacheItemPool, $item, $gcp_channel, $channel_pool_key);
  }

  public function channel() {
    return $this->gcp_channel;
  }

  public function callInvoker() {
    return $this->gcp_call_invoker;
  }

  private function parseConfObject($conf_object) {
    $config = json_decode($conf_object->serializeToJsonString(), true);
    // TODO(ddyihai): support multiple apis.
    $api_conf = $config['api'][0];
    $affinity_conf['target'] = $api_conf['target'];
    $affinity_conf['channelPool'] = $api_conf['channelPool'];
    $aff_by_method = array();
    for ($i = 0; $i < count($api_conf['method']); $i++) {
      // In proto3, if the value is default, eg 0 for int, it won't be serialized.
      // Thus serialized string may not have `command` if the value is default 0(BOUND).
      if (!array_key_exists('command', $api_conf['method'][$i]['affinity'])) {
        $api_conf['method'][$i]['affinity']['command'] = 'BOUND';
      }
      $aff_by_method[$api_conf['method'][$i]['name'][0]] = $api_conf['method'][$i]['affinity'];
    }
    $affinity_conf['affinity_by_method'] = $aff_by_method;
    return $affinity_conf;
  }

}
