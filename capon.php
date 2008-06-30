<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
* Short description for file
*
* Long description for file (if any)...
*
* PHP versions 4 and 5
*
* LICENSE: This source file is subject to version 3.0 of the PHP license
* that is available through the world-wide-web at the following URI:
* http://www.php.net/license/3_0.txt.  If you did not receive a copy of
* the PHP License and are unable to obtain it through the web, please
* send a note to license@php.net so we can mail you a copy immediately.
*
* @category   CategoryName
* @package    Freerange
* @author     EJ <ej@racklabs.com>
* @copyright  2008 Rackspace US, Inc.
* @license    http://www.php.net/license/3_0.txt  PHP License 3.0
* @version    CVS: $Id:$
* @link       http://pear.php.net/package/Freerange
* @see        NetOther, Net_Sample::Net_Sample()
* @since      File available since Release 1.2.0
* @deprecated File deprecated in Release 2.0.0
*/

define("DEFAULT_CLOUDFS_API_VERSION", 1);
define("CAPON_VERSION", "0.5");
define("USER_AGENT", sprintf("Capon/%s", CAPON_VERSION));

define("CONTAINER_OBJ_COUNT", "x-container-object-count:");
define("CONTAINER_BYTES_USED", "x-container-bytes-used:");
define("METADATA_HEADER", "X-Object-Meta-");

class CLOUDFS_Connection
{
    var $dbug;
    var $api_version;
    var $connection;
    var $storage_token;
    var $error_str;
    var $connections;

    // re-used "global" variables
    var $object_list;
    var $container_list;
    var $container_object_count;
    var $container_bytes_used;
    var $object_metadata;
    var $object_write_resource;
    var $object_write_string;

    function CLOUDFS_Connection($surl, $stoken, $saccount, $apiv=DEFAULT_CLOUDFS_API_VERSION)
    {
        $this->dbug = False;
        $this->api_version = $apiv;

        $this->storage_url = $surl;
        $this->storage_token = $stoken;
        $this->storage_account = $saccount;

        $this->object_list = array();
        $this->container_list = array();
        $this->container_object_count = 0;
        $this->container_bytes_used = 0;
        $this->object_metadata = array();
        $this->object_write_resource = NULL;
        $this->object_write_string = "";

        # Curl connections array - since there is no way to "re-set" a
        # connection and each connection has slightly different options,
        # we keep an array of unique use-cases and funnel all of those same
        # requests through the same curl connection.
        $this->connections = array(
            "PUT_OBJECT"        => NULL, # upload objects
            "GET_OBJECT_STREAM" => NULL, # download objects to stream
            "GET_OBJECT_STRING" => NULL, # download objects to string
            "PUT_CONTAINER"     => NULL, # create containers
            "LIST_OBJECTS"      => NULL, # list containers/objects
            "LIST_CONTAINERS"   => NULL, # list containers/objects
            "CUST_DELETE"       => NULL, # delete containers/objects
            "CUST_POST"         => NULL, # post objects
            "CHECK_CONTAINER"   => NULL, # head containers/objects
            "CHECK_OBJECT"      => NULL, # head containers/objects
        );
        if ($this->dbug) {
            print "=> CLOUDFS_Connection constructor\n";
            print "=> this->storage_url: ".$this->storage_url."\n";
            print "=> this->api_version: ".$this->api_version."\n";
            print "=> this->storage_account: ".$this->storage_account."\n";
        }
    }

    function _init_curl($conn_type, $force_new=False)
    {

        if (is_null($this->connections[$conn_type]) || $force_new) {
            $ch = curl_init();
        } else {
            return;
        }
        if ($this->dbug) { curl_setopt($ch, CURLOPT_VERBOSE, 1); }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        if ($conn_type == "PUT_OBJECT") {
            curl_setopt($ch, CURLOPT_PUT, 1);
            #curl_setopt($ch, CURLOPT_INFILESIZE, $size);
            #curl_setopt($ch, CURLOPT_INFILE, $fp);
        }
        if ($conn_type == "GET_OBJECT_STREAM") {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION,
                    array(&$this, '_write_object_stream'));
        }
        if ($conn_type == "GET_OBJECT_STRING") {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION,
                    array(&$this, '_write_object_string'));
        }
        if ($conn_type == "PUT_CONTAINER") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_INFILESIZE, 0);
        }
        if ($conn_type == "LIST_OBJECTS") {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, array(&$this, '_list_objects'));
        }
        if ($conn_type == "LIST_CONTAINERS") {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, array(&$this, '_list_containers'));
        }
        if ($conn_type == "CUST_DELETE") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_NOBODY, 1);
            #curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST or DELETE");
        }
        if ($conn_type == "CUST_POST") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_NOBODY, 1);
            #curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST or DELETE");
        }
        if ($conn_type == "CHECK_CONTAINER") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "HEAD");
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(&$this, '_head_container'));
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }
        if ($conn_type == "CHECK_OBJECT") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "HEAD");
            curl_setopt($ch, CURLOPT_NOBODY, 1);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(&$this, '_head_object'));
        }
        $this->connections[$conn_type] = $ch;
        return;
    }

    function _send_request($conn_type, $url_path, $hdrs)
    {
        if (!array_key_exists($conn_type, $this->connections)) {
            $this->error_str = "Invalid CURL_XXX connection type";
            return False;
        }

        $this->_init_curl($conn_type);

        curl_setopt($this->connections[$conn_type],
            CURLOPT_HTTPHEADER, $hdrs);

        curl_setopt($this->connections[$conn_type],
            CURLOPT_URL, $url_path);

        if (!curl_exec($this->connections[$conn_type])) {
            $this->error_str = curl_error($this->connections[$conn_type]) . "\n";
            return False;
        }
        return curl_getinfo($this->connections[$conn_type], CURLINFO_HTTP_CODE);
    }

    // callback function to read list of containers on an account
    function _list_containers($ch, $data)
    {
        $this->container_list[] = trim($data);
        return strlen($data);
    }

    // callback function to write object data to an open file stream
    function _write_object_stream($ch, $data)
    {
        return fwrite($this->object_write_resource, $data);
    }

    // callback function to write object data to string variable
    function _write_object_string($ch, $data)
    {
        $this->object_write_string .= $data;
        return strlen($data);
    }

    // callback function to read list of objects for a container
    function _list_objects($ch, $data)
    {
        $this->object_list[] = trim($data);
        return strlen($data);
    }

    // callback function for doing a HEAD on a container
    function _head_container($ch, $header)
    {
        if (stripos($header, CONTAINER_OBJ_COUNT) === 0) {
            $this->container_object_count = trim(substr($header,
                    strlen(CONTAINER_OBJ_COUNT)+1));
        }
        if (stripos($header, CONTAINER_BYTES_USED) === 0) {
            $this->container_bytes_used = trim(substr($header,
                    strlen(CONTAINER_BYTES_USED)+1));
        }
        return strlen($header);
    }

    // callback function for doing a HEAD on an object
    function _head_object($ch, $header)
    {
        if (stripos($header, METADATA_HEADER) === 0) {
            # strip off the leading METADATA_HEADER part
            $temp = substr($header, strlen(METADATA_HEADER));
            $parts = explode(":", $temp);
            $this->object_metadata[$parts[0]] = trim($parts[1]);
        }
        return strlen($header);
    }

    function get_error()
    {
        return $this->error_str;
    }

    function setDebug($bool)
    {
        $this->dbug = $bool;
        foreach ($this->connections as $k => $v) {
            if (!is_null($v)) {
                curl_setopt($this->connections[$k], CURLOPT_VERBOSE, $this->dbug);
            }
        }
    }

    # GET /v1/Account
    #
    function get_containers()
    {
        $conn_type = "LIST_CONTAINERS";

        $path = array();
        $path[] = $this->storage_url;
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        // re-init the container list and set the callback function
        $this->_init_curl($conn_type);
        $this->container_list = array();

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response ("
                . curl_errno($this->connections[$conn_type]) . "): "
                . curl_error($this->connections[$conn_type]);
            return False;
        }
        if ($return_code == 204) {
            $this->error_str = "Account has no Containers.";
            return False;
        }
        if ($return_code == 200) {
            return $this->container_list;
        }
        if ($return_code == 404) {
            $this->error_str = "Invalid account name.";
            return False;
        }
        $this->error_str = "Unexpected HTTP response code: $return_code";
        return False;
    }

    # GET /v1/Account/Container
    #
    function get_objects($container_name, $limit=0, $offset=-1, $prefix="")
    {
        if (!$container_name) {
            $this->error_str = "Container name not set.";
            return False;
        }
        $conn_type = "LIST_OBJECTS";
        $limit = intval($limit);
        $offset = intval($offset);

        $path = array();          # /v1/Account_123-abc/container_name
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $url_path = implode("/", $path);
        $params = array();
        if ($limit > 0) {
            $params[] = "limit=$limit";
        }
        if ($offset > 0) {
            $params[] = "offset=$offset";
        }
        if ($prefix) {
            $params[] = "prefix=".urlencode($prefix);
        }
        if (!empty($params)) {
            $url_path .= "?" . implode("&", $params);
        }

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        // re-init the object list and set the callback function
        $this->_init_curl($conn_type);
        $this->object_list = array();

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        if ($return_code == 204) {
            $this->error_str = "Container has no Objects.";
        }
        if ($return_code == 200) {
            return $this->object_list;
        }
        if ($return_code == 404) {
            $this->error_str = "Invalid account name.";
            return False;
        }
        $this->error_str = "Unexpected HTTP response code: $return_code";
        return False;

    }

    # PUT /v1/Account/Container
    #
    function create_container($container_name)
    {
        if (!$container_name) {
            $this->error_str = "Container name not set.";
            return False;
        }
        if (strpos($container_name, "/") !== False) {
            $this->error_str = "Container name cannot contain a '/' character ($container_name).";
            return False;
        }
        if (strlen(urlencode($container_name)) > 64) {
            $this->error_str = "URL encoded container name exeeds 64 characters.";
            return False;
        }
        $path = array();          # /v1/Account_123-abc/container_name
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        $return_code = $this->_send_request("PUT_CONTAINER",$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        if ($return_code != 201 && $return_code != 202) {
            $this->error_str = "Unexpected return code: $return_code";
            return False;
        }
        return True;
    }

    # DELETE /v1/Account/Container
    #
    function delete_container($container_name)
    {
        if (!$container_name) {
            $this->error_str = "Container name not set.";
            return False;
        }
        $conn_type = "CUST_DELETE";
        $path = array();
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        if ($return_code == 409) {
            $this->error_str = "Container must be empty prior to removing it.";
            return False;
        }
        if ($return_code == 404) {
            $this->error_str = "Specified container did not exist to delete.";
            return False;
        }
        if ($return_code != 204) {
            $this->error_str = "Unexpected HTTP return code: $return_code.";
            return False;
        }
        return True;
    }

    # HEAD /v1/Account/Container
    #
    function check_container($container_name)
    {
        if (!$container_name) {
            $this->error_str = "Container name not set.";
            return False;
        }
        $conn_type = "CHECK_CONTAINER";
        $path = array();
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        // reset container info and set callback function
        $this->_init_curl($conn_type);
        $this->container_object_count = 0;
        $this->container_bytes_used = 0;

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        $result = array(
            'name' => $container_name,
            'status' => "Does not exist",
            'object-count' => 0,
            'bytes-used' => 0,
        );
        if ($return_code == 404) {
            return $result;
        }
        if ($return_code == 204) {
            $result['status'] = "Does exist";
            $result['object-count'] = $this->container_object_count;
            $result['bytes-used'] = $this->container_bytes_used;
            return $result;
        }
        $this->error_str = "Unexpected HTTP return code: $return_code";
        return False;
    }

    # GET /v1/Account/Container/Object
    #
    function get_object($container_name, $object_name, &$resource=NULL)
    {
        if (!$container_name || !$object_name) {
            $this->error_str = "Container or Object name missing.";
            return False;
        }

        $path = array();
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $path[] = urlencode($object_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        // if file handle is passed in, write to that stream
        // otherwise, concat to a string
        if ($resource && is_resource($resource)) {
            $this->object_write_resource = $resource;
            $conn_type = "GET_OBJECT_STREAM";
        } else {
            $this->object_write_string = "";
            $conn_type = "GET_OBJECT_STRING";
        }

        $this->_init_curl($conn_type);
        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        if ($return_code == 404) {
            $this->error_str = "Object not found.";
            return False;
        }
        if ($return_code != 200) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return False;
        }
        return True;
    }

    # PUT /v1/Account/Container/Object
    #
    function create_object($container_name, $object_name, $metadata=array(),
    $data=NULL, $content_type=NULL, $size=0, $etag=NULL)
    {
        if (!$container_name || !$object_name) {
            $this->error_str = "Container or Object name missing.";
            return False;
        }
        if (strpos($container_name, "/") !== False) {
            $this->error_str = "Container name cannot contain a '/' character ($container_name).";
            return False;
        }
        if (strpos($object_name, "/") !== False) {
            $this->error_str = "Object name cannot contain a '/' character ($object_name).";
            return False;
        }
        if (strlen(urlencode($object_name)) > 128) {
            $this->error_str = "URL encoded object name exceeds 128 characters.";
            return False;
        }
        $conn_type = "PUT_OBJECT";

        $path = array();
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $path[] = urlencode($object_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );
        if ($etag) {
            $hdrs[] = "ETag: " . $etag;
        }
        foreach ($metadata as $k => $v) {
            $key = sprintf("%s%s", METADATA_HEADER, $k);
            if (!array_key_exists($key, $hdrs)) {
                if (strlen($k) > 128 || strlen($v) > 256) {
                    $this->error_str = "Metadata key or value exceeds maximum length: ($k: $v)";
                    return False;
                }
                $hdrs[] = sprintf("%s%s: %s", METADATA_HEADER, $k, $v);
            }
        }
        if (!$content_type) {
            $hdrs[] = "Content-Type: application/octet-stream";
        } else {
            $hdrs[] = "Content-Type: " . $content_type;
        }
        if (!is_resource($data)) {
            # hack so we can turn a string into an open filestream handle
            $fp = fopen("php://memory", "r+");
            fwrite($fp, $data);
            rewind($fp);
            $size = strlen($data);
        } else {
            $fp = $data;
            if (!$size) {
                $this->error_str = "Must supply size in bytes for data stream.";
                return False;
            }
        }
        $this->_init_curl($conn_type);
        curl_setopt($this->connections[$conn_type],
                CURLOPT_INFILE, $fp);
        if ($size) {
            curl_setopt($this->connections[$conn_type],
                    CURLOPT_INFILESIZE, $size);
        }

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        if ($return_code == 412) {
            $this->error_str = "Missing Content-Type header";
            return False;
        }
        if ($return_code == 422) {
            $this->error_str = "Derived MD5 checksum does not match supplied ETag.";
            return False;
        }
        if ($return_code != 201) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return False;
        }
        return True;
    }

    function upload_filename($container_name, $object_name, $fullpath, $metadata=array(), $content_type=NULL)
    {
        $fp = fopen($fullpath, "r");
        if (!$content_type) {
            $type = mime_content_type($fullpath);
        }
        $size = filesize($fullpath);
        return $this->create_object($container_name,$object_name,
                $metadata,$fp,$type,$size);
    }

    function download_filename($container_name, $object_name, $fullpath)
    {
        $fp = fopen($fullpath, "w");
        $size = filesize($fullpath);
        $result = $this->get_object($container_name,$object_name,$fp);
        fclose($fp);
        return $result;
    }

    # POST /v1/Account/Container/Object
    #
    function update_object($container_name, $object_name, $metadata=array())
    {
        if (empty($metadata)) {
            $this->error_str = "Metadata array is empty.";
            return False;
        }
        if (!$container_name || !$object_name) {
            $this->error_str = "Container or Object name missing.";
            return False;
        }
        $conn_type = "CUST_POST";

        $path = array();
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $path[] = urlencode($object_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );
        foreach ($metadata as $k => $v) {
            $key = sprintf("%s%s", METADATA_HEADER, $k);
            if (!array_key_exists($key, $hdrs)) {
                if (strlen($k) > 128 || strlen($v) > 256) {
                    $this->error_str = "Metadata key or value exceeds maximum length: ($k: $v)";
                    return False;
                }
                $hdrs[] = sprintf("%s%s: %s", METADATA_HEADER, $k, $v);
            }
        }

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        if ($return_code == 404) {
            $this->error_str = "Account, Container, or Object not found.";
            return False;
        }
        if ($return_code != 202) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return False;
        }
        return True;
    }

    # HEAD /v1/Account/Container/Object
    #
    function check_object($container_name, $object_name)
    {
        if (!$container_name || !$object_name) {
            $this->error_str = "Container or Object name missing.";
            return False;
        }
        $conn_type = "CHECK_OBJECT";

        $path = array();
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $path[] = urlencode($object_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        $this->_init_curl($conn_type);
        $this->object_metadata = array();

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        $result = array(
            'container' => $container_name,
            'object' => $object_name,
            'status' => "Does not exist",
            'metadata' => array(),
        );
        if ($return_code == 404) {
            return $result;
        }
        if ($return_code == 204) {
            $result['status'] = "Does exist";
            $result['metadata'] = $this->object_metadata;
            return $result;
        }
        $this->error_str = "Unexpected HTTP return code: $return_code";
        return False;
    }

    # DELETE /v1/Account/Container/Object
    #
    function delete_object($container_name, $object_name)
    {
        if (!$container_name || !$object_name) {
            $this->error_str = "Container or Object name not set.";
            return False;
        }
        $conn_type = "CUST_DELETE";
        $path = array();
        $path[] = $this->storage_url;
        $path[] = urlencode($container_name);
        $path[] = urlencode($object_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        if ($return_code == 409) {
            $this->error_str = "Container must be empty prior to removing it.";
            return False;
        }
        if ($return_code == 404) {
            $this->error_str = "Specified container did not exist to delete.";
            return False;
        }
        if ($return_code != 204) {
            $this->error_str = "Unexpected HTTP return code: $return_code.";
            return False;
        }
        return True;
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
