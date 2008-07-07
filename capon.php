<?php
/*
 * NOTE: Not thread safe!
 *
 */

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

define("DEFAULT_CLOUDFS_API_VERSION", 1);
define("CAPON_VERSION", "0.5");
define("USER_AGENT", sprintf("Capon/%s", CAPON_VERSION));

define("CONTAINER_OBJ_COUNT", "x-container-object-count:");
define("CONTAINER_BYTES_USED", "x-container-bytes-used:");
define("METADATA_HEADER", "X-Object-Meta-");

class CLOUDFS_Container
{
    var $name;
    var $status;
    var $object_count;
    var $bytes_used;

    function CLOUDFS_Container($name="",$status="Does not exist",$count=0,$bytes=0)
    {
        $this->name = $name;
        $this->status = $status;
        $this->object_count = $count;
        $this->bytes_used = $bytes;
    }

    function getName()
    {
        return $this->name;
    }

    function getStatus()
    {
        return $this->status;
    }

    function getObjectCount()
    {
        return $this->object_count;
    }

    function getBytesUsed()
    {
        return $this->bytes_used;
    }

    function setName($name="")
    {
        $this->name = $name;
    }

    function setStatus($status="Does not exist")
    {
        $this->status = $status;
    }

    function setObjectCount($count=0)
    {
        $this->object_count = $count;
    }

    function setBytesUsed($bytes=0)
    {
        $this->bytes_used = $bytes;
    }
}

class CLOUDFS_Object
{
    var $container;
    var $name;
    var $etag;
    var $last_modified;
    var $content_type;
    var $content_length;
    var $metadata;
}

class CLOUDFS_Connection
{
    var $dbug;
    var $api_version;
    var $storage_token;
    var $error_str;
    var $connections;

    // re-used "global" variables
    var $_text_list;
    var $container_object_count;
    var $container_bytes_used;
    var $object_metadata;
    var $object_write_resource;
    var $object_write_string;
    var $_write_callback_type;
    var $_header_callback_type;

    function CLOUDFS_Connection($surl, $stoken, $saccount, $apiv=DEFAULT_CLOUDFS_API_VERSION)
    {
        $this->dbug = False;
        $this->api_version = $apiv;

        $this->storage_url = $surl;
        $this->storage_token = $stoken;
        $this->storage_account = $saccount;

        $this->_text_list = array();
        $this->container_object_count = 0;
        $this->container_bytes_used = 0;
        $this->object_metadata = array();
        $this->object_write_resource = NULL;
        $this->object_write_string = "";
        $this->_write_callback_type = NULL;
        $this->_header_callback_type = NULL;

        # Curl connections array - since there is no way to "re-set" a
        # connection and each connection has slightly different options,
        # we keep an array of unique use-cases and funnel all of those same
        # requests through the same curl connection.
        $this->connections = array(
            "TEXT_LIST" => NULL, # GET objects/containers/lists (CONN_1)
            "CONN_2" => NULL, # PUT object
            "HEAD" => NULL, # HEAD requests
            "PUT_CONT" => NULL, # PUT container
            "DEL_POST" => NULL, # DELETE containers/objects, POST objects
        );
        if ($this->dbug) {
            print "=> CLOUDFS_Connection constructor\n";
            print "=> this->storage_url: ".$this->storage_url."\n";
            print "=> this->api_version: ".$this->api_version."\n";
            print "=> this->storage_account: ".$this->storage_account."\n";
        }
    }

    function _make_headers($hdrs=NULL)
    {
        # NOTE: The headers array for curl_setopt must be integer indexed
        #       with values of "header: value".  This function can also take
        #       input as "$hdrs[header] = value" and convert it appropriately.
        #       It takes user-supplied headers and makes sure the user-agent
        #       and storage token are set.
        $new_headers = array();
        $has_stoken = False;
        $has_uagent = False;
        if (is_array($hdrs)) {
            foreach ($hdrs as $h => $v) {
                if (is_int($h)) {
                    $parts = explode(":", $v);
                    $header = $parts[0];
                    $value = trim($parts[1]);
                } else {
                    $header = $h;
                    $value = trim($v);
                }

                if (stripos($header, "x-storage-token") === 0) {
                    $has_stoken = True;
                }
                if (stripos($header, "user-agent") === 0) {
                    $has_uagent = True;
                }
                $new_headers[] = $header . ": " . $value;
            }
        }
        if (!$has_stoken) {
            $new_headers[] = "X-Storage-Token: " . $this->storage_token;
        }
        if (!$has_uagent) {
            $new_headers[] = "User-Agent: " . USER_AGENT;
        }
    }

    function _init($conn_type, $force_new=False)
    {
        if (!array_key_exists($conn_type, $this->connections)) {
            $this->error_str = "Invalid CURL_XXX connection type";
            return False;
        }

        if (is_null($this->connections[$conn_type]) || $force_new) {
            $ch = curl_init();
        } else {
            return;
        }
        if ($this->dbug) { curl_setopt($ch, CURLOPT_VERBOSE, 1); }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        if ($conn_type == "TEXT_LIST") {
            $this->_write_callback_type = "TEXT_LIST";
            curl_setopt($ch, CURLOPT_WRITEFUNCTION,
                    array(&$this, '_write_cb'));
        }

        if ($conn_type == "CONN_2") {
            curl_setopt($ch, CURLOPT_PUT, 1);
            ## Next two options are set within the 'put_object' method
            #curl_setopt($ch, CURLOPT_INFILESIZE, $size);
            #curl_setopt($ch, CURLOPT_INFILE, $fp);
        }
        if ($conn_type == "HEAD") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "HEAD");
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(&$this, '_header_cb'));
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }

        if ($conn_type == "PUT_CONT") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            #XXX curl_setopt($ch, CURLOPT_PUT, 1); # would rather use this setopt
            curl_setopt($ch, CURLOPT_INFILESIZE, 0);
        }
        if ($conn_type == "DEL_POST") {
            ## Next option gets set within the POST/DELETE methods
            #curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE || POST");
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }
        $this->connections[$conn_type] = $ch;
        return;
    }

    function _reset_callback_vars()
    {
        $this->_text_list = array();
        $this->_container_object_count = 0;
        $this->_container_bytes_used = 0;
    }

    /* $method is only useful for setting CUSTOMREQUEST to PUT or DELETE */
    function send_request($conn_type, $url_path, $method="GET", $hdrs=NULL)
    {
        $this->_init($conn_type);
        $this->_reset_callback_vars();

        $headers = $this->_make_headers($hdrs);

        if ($method == "DELETE") {
            curl_setopt($this->connections[$conn_type],
                CURLOPT_CUSTOMREQUEST, "DELETE");
        }
        if ($method == "POST") {
            curl_setopt($this->connections[$conn_type],
                CURLOPT_CUSTOMREQUEST, "POST");
        }

        curl_setopt($this->connections[$conn_type],
            CURLOPT_HTTPHEADER, $headers);

        curl_setopt($this->connections[$conn_type],
            CURLOPT_URL, $url_path);

        if (!curl_exec($this->connections[$conn_type])) {
            $this->error_str = "(curl error: "
                . curl_errno($this->connections[$conn_type]) . ") ";
            $this->error_str .= curl_error($this->connections[$conn_type]);
            return False;
        }
        return curl_getinfo($this->connections[$conn_type], CURLINFO_HTTP_CODE);
    }

    function get_response($conn_type)
    {
        switch ($conn_type) {
        case "TEXT_LIST":
            return $this->_text_list;
        case "HEAD":
            if ($this->_header_callback_type == "HEAD_CONTAINER") {
                return array(
                    $this->_container_object_count,
                    $this->_container_bytes_used
                    );
            }
            break;
        default:
            return False;
        }
    }

    function setCheckType($type)
    {
        switch ($type) {
        case "CONTAINER":
            $this->_header_callback_type = "HEAD_CONTAINER";
            break;
        case "OBJECT":
            $this->_header_callback_type = "HEAD_OBJECT";
            break;
        default:
            $this->_header_callback_type = NULL;
        }
    }

    function _header_cb($ch, $header)
    {
        switch ($this->_header_callback_type) {
        case "HEAD_CONTAINER":
            if (stripos($header, CONTAINER_OBJ_COUNT) === 0) {
                $this->_container_object_count = trim(substr($header,
                        strlen(CONTAINER_OBJ_COUNT)+1));
            }
            if (stripos($header, CONTAINER_BYTES_USED) === 0) {
                $this->_container_bytes_used = trim(substr($header,
                        strlen(CONTAINER_BYTES_USED)+1));
            }
            break;
        case "HEAD_OBJECT":
            if (stripos($header, METADATA_HEADER) === 0) {
                # strip off the leading METADATA_HEADER part
                $temp = substr($header, strlen(METADATA_HEADER));
                $parts = explode(":", $temp);
                $this->_object_metadata[$parts[0]] = trim($parts[1]);
            }
            if (stripos($header, "ETag") === 0) {
                $parts = explode(":", $header);
                $this->_object_metadata[$parts[0]] = trim($parts[1]);
            }
            break;
        }
        return strlen($header);
    }

    // callback function to read list of containers on an account
    function _write_cb($ch, $data)
    {
        switch ($this->_write_callback_type) {
        case "TEXT_LIST":
            $this->_text_list[] = trim($data);
            break;
        case "OBJECT_STREAM":
            return fwrite($this->object_write_resource, $data);
        case "OBJECT_STRING":
            $this->object_write_string .= $data;
        }
        return strlen($data);
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
}

class CLOUDFS_Client
{
    var $dbug;
    var $conn_mngr;

    function CLOUDFS_Client($surl,$stoken,$saccount,$apiv=DEFAULT_CLOUDFS_API_VERSION)
    {
        $this->conn_mngr = new CLOUDFS_Connection($surl,$stoken,$saccount,$apiv=DEFAULT_CLOUDFS_API_VERSION);
    }

    function setDebug($bool)
    {
        $this->dbug = (boolean) $bool;
        $this->conn_mngr->setDebug($this->dbug);
    }

    # GET /v1/Account
    #
    function get_containers()
    {
        $conn_type = "TEXT_LIST";

        $path = array();
        $path[] = $this->storage_url;
        $url_path = implode("/", $path);

        $return_code = $this->conn_mngr->send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response: "
            $this->error_str .= $this->conn_mngr->get_error();
            return False;
        }
        if ($return_code == 204) {
            $this->error_str = "Account has no Containers.";
            return False;
        }
        if ($return_code == 200) {
            return $this->conn_mngr->get_response($conn_type);
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
        $conn_type = "TEXT_LIST";
        $limit = intval($limit);
        $offset = intval($offset);

        $path = array();          # /v1/Account_123-abc/container_name?limit=3..
        $path[] = $this->storage_url;
        $path[] = rawurlencode($container_name);
        $url_path = implode("/", $path);
        $params = array();
        if ($limit > 0) {
            $params[] = "limit=$limit";
        }
        if ($offset > 0) {
            $params[] = "offset=$offset";
        }
        if ($prefix) {
            $params[] = "prefix=".rawurlencode($prefix);
        }
        if (!empty($params)) {
            $url_path .= "?" . implode("&", $params);
        }

        $return_code = $this->conn_mngr->send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        if ($return_code == 204) {
            $this->error_str = "Container has no Objects.";
        }
        if ($return_code == 200) {
            return $this->conn_mngr->get_response($conn_type);
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
            $this->error_str = "Container name '".$container_name
            $this->error_str .= "' cannot contain a '/' character.";
            return False;
        }
        if (strlen(rawurlencode($container_name)) > 64) {
            $this->error_str = "URL encoded container name exeeds 64 characters.";
            return False;
        }
        $path = array();          # /v1/Account_123-abc/container_name
        $path[] = $this->storage_url;
        $path[] = rawurlencode($container_name);
        $url_path = implode("/", $path);

        $return_code = $this->conn_mngr->send_request("PUT_CONT",$url_path);

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

        $path = array();
        $path[] = $this->storage_url;
        $path[] = rawurlencode($container_name);
        $url_path = implode("/", $path);

        $return_code = $this->_send_request("DEL_POST",$url_path,"DELETE");
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

        $conn_type = "HEAD";
        $path = array();
        $path[] = $this->storage_url;
        $path[] = rawurlencode($container_name);
        $url_path = implode("/", $path);

        $this->conn_mngr->setCheckType("CONTAINER");
        $return_code = $this->conn_mngr->send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }

        $container = new CLOUDFS_Container($container_name);
        if ($return_code == 404) {
            return $container;
        }
        if ($return_code == 204) {
            $container->setStatus("Does exist");
            list($count, $bytes) = $this->conn_mngr->get_response($conn_type);
            $container->setObjectCount($count);
            $container->setBytesUsed($bytes);
            return $container;
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
        $path[] = rawurlencode($container_name);
        $path[] = rawurlencode($object_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        // if file handle is passed in, write to that stream
        // otherwise, concat to a string
        if ($resource && is_resource($resource)) {
            $this->object_write_resource = $resource;
            $this->_write_callback_type = "OBJECT_STREAM";
        } else {
            $this->object_write_string = "";
            $this->_write_callback_type = "OBJECT_STRING";
        }
        $conn_type = "CONN_1";

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
        if (strlen(rawurlencode($object_name)) > 128) {
            $this->error_str = "URL encoded object name exceeds 128 characters.";
            return False;
        }
        $conn_type = "CONN_2";

        $path = array();
        $path[] = $this->storage_url;
        $path[] = rawurlencode($container_name);
        $path[] = rawurlencode($object_name);
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
            $size > 0 ? $put_size = $size : $put_size = strlen($data);
        } else {
            $fp = $data;
            if (!$size) {
                $this->error_str = "Must supply size in bytes for data stream.";
                return False;
            } else {
                $put_size = $size;
            }
        }
        $this->_init_curl($conn_type);
        curl_setopt($this->connections[$conn_type],
                CURLOPT_INFILE, $fp);
        curl_setopt($this->connections[$conn_type],
                CURLOPT_INFILESIZE, $put_size);

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

        $path = array();
        $path[] = $this->storage_url;
        $path[] = rawurlencode($container_name);
        $path[] = rawurlencode($object_name);
        $url_path = implode("/", $path);

        $hdrs = array();
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

        $return_code = $this->_send_request("DEL_POST",$url_path,"POST",$hdrs);
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
        $conn_type = "HEAD";

        $path = array();
        $path[] = $this->storage_url;
        $path[] = rawurlencode($container_name);
        $path[] = rawurlencode($object_name);
        $url_path = implode("/", $path);

        $hdrs = array(
            "User-Agent: " . USER_AGENT,
            "X-Storage-Token: " . $this->storage_token,
        );

        $this->_init_curl($conn_type);
        $this->object_metadata = array();
        $this->_header_callback_type = "HEAD_OBJECT";

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

        $path = array();
        $path[] = $this->storage_url;
        $path[] = rawurlencode($container_name);
        $path[] = rawurlencode($object_name);
        $url_path = implode("/", $path);

        $return_code = $this->_send_request("DEL_POST",$url_path,"DELETE");
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
