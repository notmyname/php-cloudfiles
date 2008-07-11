<?php
/*
 * NOTE: Not thread safe!
 *
 * Requres PHP 5.x (for Exceptions)
 *
 * $auth = new CLOUDFS_Authentication($account_name,$username,$passwd,$auth_host);
 *
 * $conn = new CLOUDFS_Connection($auth);
 * $container_list = $conn->list_containers();
 * $electronica = $conn->create_container("Electronica");
 * $electronica->load_from_filename("/home/user/music/electronica/tiesto.mp3");
 *
 */

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

require("cloudfs_http.php");

define("DEFAULT_CLOUDFS_API_VERSION", 1);
define("MAX_CONTAINER_NAME_LEN", 64);
define("MAX_OBJECT_NAME_LEN", 128);
define("METADATA_HEADER", "X-Object-Meta-");

class CLOUDFS_Container
{
    public $cfs_http;
    public $name;
    public $object_count;
    public $size_used;

    function CLOUDFS_Container($cfs_http, $name, $count=0, $bytes=0)
    {
        if (strlen(rawurlencode($name)) > MAX_CONTAINER_NAME_LEN) {
            throw new Exception("Encoded container name exceeds "
                . "maximum allowed length.");
        }
        if (strpos($container_name, "/") !== False) {
            throw new Exception("Container names cannot contain a '/' character.");
        }
        $this->cfs_http = $cfs_http;
        $this->name = $name;
        $this->encoded_name = rawurlencode($name);
        $this->object_count = $count;
        $this->bytes_used = $bytes;
    }

    function __toString()
    {
        return sprintf("name: %s, count: %d, bytes: %d",
            $this->name, $this->object_count, $this->bytes_used);
    }

    function create_object($obj_name)
    {
        return new CLOUDFS_Object(&$this, $obj_name);
    }

    function get_object($obj_name)
    {
        return new CLOUDFS_Object(&$this, $obj_name, True);
    }

    function list_objects($limit=0, $offset=-1, $prefix="")
    {
        list($status, $reason, $obj_list) =
            $this->cfs_http->get_container($this->name, $limit, $offset, $prefix);
        if ($status < 200 || $status > 299) {
            throw new Exception("Invalid response: ".$this->cfs_http->get_error());
        }
        return $obj_list;
    }

    function delete_object($obj)
    {
        $obj_name = NULL;
        if (is_object($obj)) {
            if (get_class($obj) == "CLOUDFS_Object") {
                $obj_name = $obj->name;
            }
        }
        if (is_string($obj)) {
            $obj_name = $obj;
        }
        if (!$obj_name) {
            throw new Exception("Object name not set.");
        }
        $status = $this->cfs_http->delete_object($this->name, $obj_name);
        if ($status == 404) {
            $m = "Specified object '".$this->name."/".$obj_name;
            $m.= "' did not exist to delete.";
            throw new Exception($m);
        }
        if ($status != 204) {
            throw new Exception("Unexpected HTTP return code: $return_code.");
        }
        return True;
    }
}

class CLOUDFS_Object
{
    public $container;
    public $name;
    public $etag;
    public $last_modified;
    public $content_type;
    public $content_length;
    public $metadata;
    public $data;

    function CLOUDFS_Object($container, $name, $force_exists=False)
    {
        if (strlen(rawurlencode($name)) > MAX_OBJECT_NAME_LEN) {
            throw new Exception("Encoded object name exceeds "
                . "maximum allowed length.");
        }
        $this->container = $container;
        $this->name = $name;
        $this->encoded_name = rawurlencode($name);
        $this->etag = NULL;
        $this->_etag_override = False;
        $this->last_modified = NULL;
        $this->content_type = NULL;
        $this->content_length = 0;
        $this->metadata = array();
        if (!$this->_initialize() && $force_exists) {
            throw new Exception("No such object '".$name."'");
        }
    }

    function __toString()
    {
        return $this->container->name . "/" . $this->name;
    }

    function read()
    {
        list($status, $reason, $data) =
            $this->container->cfs_http->get_object_to_string($this);
        if ($status < 200 || $status > 299) {
            throw new Exception("Invalid response: "
                . $this->container->cfs_http->get_error());
        }
        return $data;
    }

    function stream(&$fp)
    {
        list($status, $reason) = 
                $this->container->cfs_http->get_object_to_stream($this, $fp);
        if ($status < 200 || $status > 299) {
            throw new Exception("Invalid response: ".$reason);
        }
        return True;
    }

    function sync_metadata()
    {
        if (!empty($this->metadata)) {
            $status = $this->container->cfs_http->update_object($this);
            if ($status != 202) {
                throw new Exception($this->container->cfs_http->get_error());
            }
            return True;
        }
        return False;
    }

    function write(&$data, $verify=True, $size=0)
    {
        if ($verify) {
            if (!$this->_etag_override) {
                $this->etag = $this->compute_md5sum($data);
            }
        } else {
            $this->etag = NULL;
        }

        if (!$this->content_type) {
            $this->content_type = "application/octet-stream";
        }

        $close_fh = False;
        if (!is_resource($data)) {
            # hack to treat string data as a file handle
            $fp = fopen("php://memory", "r+");
            fwrite($fp, $data);
            rewind($fp);
            $close_fh = True;
            $this->content_length = strlen($data);
        } else {
            if (!$size) {
                throw new Exception("Missing required size for object data.");
            } else {
                $this->content_length = $size;
            }
            $fp = $data;
        }

        list($status, $reason, $etag) =
                $this->container->cfs_http->put_object($this, $fp);
        if ($status == 412) {
            if ($close_fh) { fclose($fp); }
            throw new Exception("Missing Content-Type header");
        }
        if ($status == 422) {
            if ($close_fh) { fclose($fp); }
            throw new Exception("Supplied and computed checksums do not match.");
        }
        if ($status != 201) {
            if ($close_fh) { fclose($fp); }
            throw new Exception("Invalid response: ".
                $this->container->cfs_http->get_error());
        }
        if (!$verify) {
            $this->etag = $etag;
        }
        if ($close_fh) { fclose($fp); }
        return True;
    }

    function load_from_filename($filename, $verify=True)
    {
        $fp = fopen($filename, "r");
        $size = filesize($filename);
        $this->content_type = mime_content_type($filename);
        $this->write($fp, $verify, $size);
        fclose($fp);
        return True;
    }

    function save_to_filename($filename)
    {
        $fp = fopen($filename, "w");
        $result = $this->stream($fp);
        fclose($fp);
        return $result;
    }

    private function _initialize()
    {
        list($status, $reason, $etag, $last_modified, $content_type,
            $content_length, $metadata) =
                $this->container->cfs_http->head_object($this);

        if ($status == 404) {
            return False;
        }
        if ($status < 200 || $status > 299) {
            throw new Exception("Invalid response: ".
                $this->container->cfs_http->get_error());
        }
        $this->etag = $etag;
        $this->last_modified = $last_modified;
        $this->content_type = $content_type;
        $this->content_length = $content_length;
        $this->metadata = $metadata;
        return True;
    }

    function compute_md5sum(&$data)
    {

        if (is_file($data)) {
            $md5 = md5_file($data);
        } elseif (is_resource($data)) {
            # let's hope this isn't a BIG file
            $contents = stream_get_contents($data); # PHP 5 and up
            $md5 = md5($contents);
            rewind($data);
        } else {
            $md5 = md5($data);
        }
        return $md5;
    }

    private function _set_etag($etag)
    {
        $this->etag = $etag;
        $this->_etag_override = True;
    }
}

class CLOUDFS_Connection
{
    public $dbug;
    public $cfs_http;

    public $storage_token;
    public $storage_url;

    function CLOUDFS_Connection($cfs_auth)
    {
        $this->cfs_http = new CLOUDFS_Http(DEFAULT_CLOUDFS_API_VERSION);
        $this->storage_url = $cfs_auth->getStorageUrl();
        $this->storage_token = $cfs_auth->getStorageToken();
        if (!$this->storage_url || !$this->storage_token) {
            $e = "Need to pass in a previously authenticated ";
            $e .= "CLOUDFS_Authentication instance.";
            throw new Exception($e);
        }
        $this->cfs_http->setStorageUrl($this->storage_url);
        $this->cfs_http->setStorageToken($this->storage_token);
        $this->dbug = False;
    }

    function setDebug($bool)
    {
        $this->dbug = (boolean) $bool;
        $this->cfs_http->setDebug($this->dbug);
    }

    function create_container($container_name)
    {
        if (!$container_name) {
            throw new Exception("Container name not set.");
        }
        if (strpos($container_name, "/") !== False) {
            $r = "Container name '".$container_name;
            $r .= "' cannot contain a '/' character.";
            throw new Exception($r);
        }
        if (strlen(rawurlencode($container_name)) > MAX_CONTAINER_NAME_LEN) {
            throw new Exception(sprintf(
                "URL encoded container name exeeds %d characters.",
                MAX_CONTAINER_NAME_LEN));
        }

        $return_code = $this->cfs_http->create_container($container_name);

        if (!$return_code) {
            throw new Exception($this->cfs_http->get_error());
        }
        if ($return_code != 201 && $return_code != 202) {
            throw new Exception("Unexpected return code: $return_code");
        }
        return new CLOUDFS_Container($this->cfs_http, $container_name);
    }

    function delete_container($container)
    {
        $container_name = NULL;
        if (is_object($container)) {
            if (get_class($container) == "CLOUDFS_Container") {
                $container_name = $container->name;
            }
        }
        if (is_string($container)) {
            $container_name = $container;
        }
        if (!$container_name) {
            throw new Exception("Must specify container object or name.");
        }

        $return_code = $this->cfs_http->delete_container($container_name);

        if (!$return_code) {
            throw new Exception("Failed to obtain http response");
        }
        if ($return_code == 409) {
            throw new Exception("Container must be empty prior to removing it.");
        }
        if ($return_code == 404) {
            throw new Exception("Specified container did not exist to delete.");
        }
        if ($return_code != 204) {
            throw new Exception("Unexpected return code: $return_code");
        }
        return True;
    }

    function get_container($container_name)
    {
        list($status, $reason, $size, $bytes) =
                $this->cfs_http->head_container($container_name);
        if ($status == 404) {
            throw new Exception("Container not found.");
        }
        if ($status < 200 || $status > 299) {
            throw new Exception("Invalid response: ".$this->cfs_http->get_error());
        }
        return new CLOUDFS_Container($this->cfs_http,$container_name,$size,$bytes);
    }

    function list_containers()
    {
        list($status, $reason, $containers) = $this->cfs_http->list_containers();
        if ($status == 404) {
            throw new Exception("Invalid account.");
        }
        if ($status < 200 || $status > 299) {
            throw new Exception("Invalid response: ".$this->cfs_http->get_error());
        }
        return $containers;
    }

}

/**
 * Class for handling CloudFS Authentication
 */
class CLOUDFS_Authentication
{
    public $dbug;
    public $account;
    public $username;
    public $password;
    public $auth_host;

    public $storage_token;
    public $storage_url;

    function CLOUDFS_Authentication($account, $username, $password, $auth_host)
    {
        if (!$account || !$username || !$password || !$auth_host) {
            throw new Exception("Missing required constructor arguments.");
        }

        $this->dbug = False;
        $this->username = $username;
        $this->password = $password;
        $this->account_name = $account;
        $this->auth_host = $auth_host;

        $this->storage_token = NULL;
        $this->storage_url = NULL;

        $this->cfs_http = new CLOUDFS_Http(DEFAULT_CLOUDFS_API_VERSION);
    }

    /**
     * Initiates authentication with the remote service and returns a 
     * two-tuple containing the storage system URL and session token.
     * Accepts a single (optional) argument for the storage system
     * API version.
     */
    function authenticate($version=DEFAULT_CLOUDFS_API_VERSION)
    {
        list($status,$reason,$surl,$stoken) = 
                $this->cfs_http->authenticate($this->account_name,
                $this->username, $this->password,
                $this->auth_host);

        if ($status == 401) {
            throw new Exception("Invalid username or password.");
        }
        if ($status != 204) {
            throw new Exception("Unexpected response (".$status."): ".$reason);
        }

        if (!$surl || !$stoken) {
            throw new Exception("Expected headers missing from auth service.");
        }
        $this->storage_url = $surl;
        $this->storage_token = $stoken;

        return True;
    }

    function getStorageUrl()
    {
        return $this->storage_url;
    }

    function getStorageToken()
    {
        return $this->storage_token;
    }

    function getCloudFSHttp()
    {
        return $this->cfs_http;
    }
}

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
