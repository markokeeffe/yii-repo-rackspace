<?php namespace MOK\RepoRackspace;

use OpenCloud\Rackspace;

class RackspaceRepository extends \CApplicationComponent implements RemoteRepositoryInterface
{

  /**
   * Config array for this API
   * @var array
   */
  public $config;

  /**
   * Number of times to attempt HTTP requests
   * @var int
   */
  public $requestRetries = 3;

  /**
   * The Rackspace API
   * @var \OpenCloud\Rackspace
   */
  protected $api;

  /**
   * The Swift Object Store
   * @var \OpenCloud\ObjectStore\Service
   */
  protected $swift;

  /**
   * The cloud files container
   * @var \OpenCloud\ObjectStore\Container
   */
  protected $container;

  /**
   * Get the path to a file from the app 'storage' directory
   *
   * @param $file
   *
   * @return string
   */
  public function getFromStorage($file)
  {
    return dirname(__FILE__).DS.'..'.DS.'..'.DS.$file;
  }

  /**
   * Instantiate the Rackspace open cloud API
   *
   * @return \OpenCloud\Rackspace
   */
  public function api()
  {
    if ($this->api === null) {
      $credentials = array(
        'username' => $this->config['username'],
        'apiKey' => $this->config['api_key'],
      );
      $curlopts = array(
//        CURLOPT_VERBOSE => true,
        CURLOPT_CAINFO => $this->getFromStorage($this->config['cacert']),
      );
      $this->api = new Rackspace($this->config['endpoint'], $credentials, $curlopts);
      $this->api->SetDefaults('ObjectStore','cloudFiles','LON','publicURL');
      \OpenCloud\setDebug(false);
    }
    return $this->api;
  }

  /**
   * Retrieve the swift cloud files object store
   *
   * @return \OpenCloud\ObjectStore\Service
   */
  public function swift()
  {
    if ($this->swift === null) {
      $this->swift = $this->api()->objectStore('cloudFiles', 'LON');
    }
    return $this->swift;
  }

  /**
   * Retrieve the container as specified in the config
   * e.g. 'laravel_image_uploader'
   *
   * @return \OpenCloud\ObjectStore\Container
   */
  public function cont()
  {
    if ($this->container === null) {
      $this->container = $this->getContainer($this->config['container']);
    }
    return $this->container;
  }

  /**
   * Return a list of objects in the container
   *
   * @param null $prefix
   *
   * @return \OpenCloud\Common\Collection
   */
  public function listObjects($prefix = null)
  {
    if ($prefix) {
      $objects = $this->cont()->ObjectList(array('prefix' => $prefix));
    } else {
      $objects = $this->cont()->ObjectList();
    }

    $list = array();
    while($object = $objects->Next()) {
      $list[] = $object;
    }
    return $list;
  }

  /**
   * Check the container has an object matching the desired name
   *
   * @param $name
   *
   * @return bool
   */
  public function hasObject($name)
  {
    $search = $this->listObjects($name);
    if (is_array($search) && count($search)) {
      foreach ($search as $result) {
        if ($result->name === $name) {
          return true;
        }
      }
    }
    return false;
  }

  /**
   * Get an object by its name
   *
   * @param $name
   *
   * @return bool|\OpenCloud\ObjectStore\DataObject
   */
  public function getObject($name)
  {
    try {
      $object = $this->cont()->DataObject($name);
    } catch (\Exception $e) {
      return false;
    }
    return $object;
  }

  /**
   * Save an object with a specified name
   *
   * @param $name
   * @param $data
   *
   * @return bool|\OpenCloud\ObjectStore\DataObject
   */
  public function saveObject($name, $data)
  {
    $object = $this->cont()->DataObject();

    $params = array(
      'name' => $name,
      'content_type' => $data['content_type'],
    );

    try {
      if (isset($data['path'])) {
        $object->Create($params, $data['path']);
      } elseif (isset($data['data'])) {
        $object->SetData($data['data']);
        $object->Create($params);
      } else {
        return false;
      }
    } catch (\Exception $e) {
      return false;
    }

    return $object;
  }

  /**
   * Delete an object by its name
   *
   * @param $name
   *
   * @return bool
   */
  public function deleteObject($name)
  {
    if ($object = $this->getObject($name)) {
      return ($object->Delete() ? true : false);
    }
    return false;
  }

  /**
   * Purge an object from the CDN
   *
   * @param $name
   *
   * @return bool
   */
  public function purgeObject($name)
  {
//    if ($object = $this->getObject($name)) {
//      $object->purgeCDN('mark.ok@me.com');
//      return true;
//    }
//    return false;
    return true;
  }

  /**
   * Get a cloud files container by its name, or create one if it does not exist
   *
   * @param $name
   *
   * @return mixed
   */
  public function getContainer($name)
  {
    $container = $this->swift()->Container($name);
    if (!$container) {
      $container = $this->swift()->Container();
      $container->Create($name);
    }
    return $container;
  }

  /**
   * Get the public URL to the CDN container
   *
   * @throws \Exception
   * @return mixed
   */
  public function getContainerUrl()
  {
    if (isset($this->config['cdnUrl'])) {
      $url = $this->config['cdnUrl'];
    } else if (isset($this->config['containerUrl'])) {
      $url = $this->config['containerUrl'];
    } else {
      throw new \Exception('CDN container URL not set. Set config variable "containerUrl".');
    }

    $protocol = (\Yii::app()->request->isSecureConnection ? 'https' : 'http');

    return $protocol.':'.$url;

  }

  /**
   * Get the public URL for an object
   *
   * @param string $name
   * @param string $type (SSL|STREAMING)
   *
   * @param int    $tries
   *
   * @throws \OpenCloud\Common\Exceptions\HttpError
   * @return mixed
   */
  public function getUrl($name, $type=null, $tries=0)
  {
    try {
      if ($object = $this->getObject($name)) {
        $pubUrl = $object->PublicURL($type);
        if (isset($this->config['containerUrl'], $this->config['cdnUrl'])) {
          return str_replace($this->config['containerUrl'], $this->config['cdnUrl'], $pubUrl);
        }
        return $pubUrl;
      }
    } catch (\OpenCloud\Common\Exceptions\HttpError $e) {
      if ($tries < $this->requestRetries) {
        usleep(10000);
        return $this->getUrl($name, $type, $tries + 1);
      } else {
        throw new \OpenCloud\Common\Exceptions\HttpError('[RETRIES:'.$tries.'] '.$e->getMessage());
      }
    }

    return false;
  }

}
