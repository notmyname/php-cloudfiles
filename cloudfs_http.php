<?php
/*
 * This is an HTTP client class for CloudFS.  It uses PHP's cURL module
 * to handle the actual HTTP request/response.  This is NOT a generic HTTP
 * client class and is only used to abstract out the HTTP communication for
 * the PHP CloudFS API.
 *
 * This module was designed to re-use existing HTTP(S) connections between
 * subsequent operations.  For example, performing multiple PUT operations
 * will re-use the same connection.
 *
 * This modules also provides support for streaming content into and out
 * of CloudFS.  The majority (all?) of the PHP HTTP client modules expect
 * to read the server's response into a string variable.  This will not work
 * with large files without killing your server.  Methods like,
 * get_object_to_stream() and put_object() take an open filehandle
 * argument for streaming data out of or into CloudFS.
 *
 * Requires PHP 5.x (for Exceptions and OO syntax)
 *
 * See COPYING for license information.
 */
require_once("cloudfs_exceptions.php");

define("CAPON_VERSION", "0.6");
define("USER_AGENT", sprintf("Capon/%s", CAPON_VERSION));
define("CONTAINER_OBJ_COUNT", "X-Container-Object-Count");
define("CONTAINER_BYTES_USED", "X-Container-Bytes-Used");
define("METADATA_HEADER", "X-Object-Meta-");
define("STORAGE_URL", "X-Storage-Url");
define("STORAGE_TOK", "X-Storage-Token");
define("AUTH_USER_HEADER", "X-Storage-User");
define("AUTH_PASS_HEADER", "X-Storage-Pass");

class CLOUDFS_Http
{
    public $dbug;
    public $api_version;
    public $error_str;

    # Authentication instance variables
    #
    public $storage_token;
    public $storage_url;

    # Request/response variables
    #
    public $connections;
    public $response_status;
    public $response_reason;

    # Variables used for content/header callbacks
    #
    public $_write_callback_type;
    public $_text_list;
    public $_container_object_count;
    public $_container_bytes_used;
    public $_obj_etag;
    public $_obj_last_modified;
    public $_obj_content_type;
    public $_obj_content_length;
    public $_obj_metadata;
    public $_obj_write_resource;
    public $_obj_write_string;

    function __construct($api_version)
    {
        $this->dbug = False;
        $this->api_version = $api_version;
        $this->error_str = NULL;

        $this->storage_url = NULL;
        $this->storage_token = NULL;

        $this->response_status = NULL;
        $this->response_reason = NULL;

        # Curl connections array - since there is no way to "re-set" the
        # connection paramaters for a cURL handle, we keep an array of
        # the unique use-cases and funnel all of those same type
        # requests through the appropriate curl connection.
        #
        $this->connections = array(
            "GET_CALL"  => NULL, # GET objects/containers/lists
            "PUT_OBJ"   => NULL, # PUT object
            "HEAD"      => NULL, # HEAD requests
            "PUT_CONT"  => NULL, # PUT container
            "DEL_POST"  => NULL, # DELETE containers/objects, POST objects
        );

        $this->_write_callback_type = NULL;
        $this->_text_list = array();
        $this->_container_object_count = 0;
        $this->_container_bytes_used = 0;
        $this->_obj_write_resource = NULL;
        $this->_obj_write_string = "";
        $this->_obj_etag = NULL;
        $this->_obj_last_modified = NULL;
        $this->_obj_content_type = NULL;
        $this->_obj_content_length = NULL;
        $this->_obj_metadata = array();
    }

    # Uses separate cURL connection to authenticate
    #
    function authenticate($acct, $user, $pass, $host)
    {
        $headers = array(
            sprintf("%s: %s", AUTH_USER_HEADER, $user),
            sprintf("%s: %s", AUTH_PASS_HEADER, $pass),
            );

        $path = array();
        $path[] = $host;
        $path[] = rawurlencode(sprintf("v%d",$this->api_version));
        $path[] = rawurlencode($acct);
        $path[] = "auth";
        $url = implode("/", $path);

        $curl_ch = curl_init();
        curl_setopt($curl_ch, CURLOPT_VERBOSE, $this->dbug);
        curl_setopt($curl_ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl_ch, CURLOPT_MAXREDIRS, 4);
        curl_setopt($curl_ch, CURLOPT_HEADER, 0);
        curl_setopt($curl_ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl_ch, CURLOPT_USERAGENT, USER_AGENT);
        curl_setopt($curl_ch, CURLOPT_HEADERFUNCTION,array(&$this,'_auth_hdr_cb'));
        curl_setopt($curl_ch, CURLOPT_URL, $url);
        curl_exec($curl_ch);
        curl_close($curl_ch);

        return array($this->response_status, $this->response_reason,
            $this->storage_url, $this->storage_token);
    }

    # GET /v1/Account
    #
    function list_containers()
    {
        $conn_type = "GET_CALL";

        $path = array($this->storage_url);
        $url_path = implode("/", $path);

        $this->_write_callback_type = "TEXT_LIST";
        $return_code = $this->_send_request($conn_type, $url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain valid HTTP response.";
            return array(0,$this->error_str,array());
        }
        if ($return_code == 204) {
            return array($return_code, "Account has no containers.", array());
        }
        if ($return_code == 404) {
            $this->error_str = "Invalid account name for token.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 200) {
            return array($return_code, $this->response_reason, $this->_text_list);
        }
        $this->error_str = "Unexpected HTTP response: ".$this->response_reason;
        return array($return_code,$this->error_str,array());

    }

    # PUT /v1/Account/Container
    #
    function create_container($container_name)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }

        $url_path = $this->_make_path($container_name);
        $return_code = $this->_send_request("PUT_CONT",$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return False;
        }
        return $return_code;
    }

    # DELETE /v1/Account/Container
    #
    function delete_container($container_name)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }

        $url_path = $this->_make_path($container_name);
        $return_code = $this->_send_request("DEL_POST",$url_path,array(),"DELETE");

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
        }
        if ($return_code == 409) {
            $this->error_str = "Container must be empty prior to removing it.";
        }
        if ($return_code == 404) {
            $this->error_str = "Specified container did not exist to delete.";
        }
        if ($return_code != 204) {
            $this->error_str = "Unexpected HTTP return code: $return_code.";
        }
        return $return_code;
    }

    # GET /v1/Account/Container
    #
    function get_container($container_name,$limit=0,$offset=-1,$prefix="")
    {
        if (!$container_name) {
            $this->error_str = "Container name not set.";
            return array(0, $this->error_str, array());
        }

        $url_path = $this->_make_path($container_name);

        $limit = intval($limit);
        $offset = intval($offset);
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
 
        $conn_type = "GET_CALL";
        $this->_write_callback_type = "TEXT_LIST";
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array(0,$this->error_str,array());
        }
        if ($return_code == 204) {
            $this->error_str = "Container has no Objects.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 404) {
            $this->error_str = "Container has no Objects.";
            return array($return_code,$this->error_str,array());
        }
        if ($return_code == 200) {
            return array($return_code,$this->response_reason, $this->_text_list);
        }
        $this->error_str = "Unexpected HTTP response code: $return_code";
        return array(0,$this->error_str,array());
    }

    # HEAD /v1/Account/Container
    #
    function head_container($container_name)
    {
        if (!$container_name) {
            $this->error_str = "Container name not set.";
            return False;
        }

        $conn_type = "HEAD";

        $url_path = $this->_make_path($container_name);
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            array(0,$this->error_str,0,0);
        }
        if ($return_code == 404) {
            return array($return_code,"Container not found.",0,0);
        }
        if ($return_code == 204) {
            return array($return_code,$this->response_reason,
                $this->_container_object_count, $this->_container_bytes_used);
        }
        return array($return_code,$this->response_reason,0,0);
    }

    # GET /v1/Account/Container/Object
    #
    function get_object_to_string(&$obj)
    {
        if (!is_object($obj) || get_class($obj) != "CLOUDFS_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CLOUDFS_Object.");
        }

        $conn_type = "GET_CALL";

        $url_path = $this->_make_path($obj->container->name,$obj->name);
        $this->_write_callback_type = "OBJECT_STRING";
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array($return_code0,$this->error_str,NULL);
        }
        if ($return_code == 404) {
            $this->error_str = "Object not found.";
            return array($return_code0,$this->error_str,NULL);
        }
        if ($return_code != 200) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return array($return_code,$this->error_str,NULL);
        }
        return array($return_code,$this->response_reason, $this->_obj_write_string);
    }

    # GET /v1/Account/Container/Object
    #
    function get_object_to_stream(&$obj, &$resource=NULL)
    {
        if (!is_object($obj) || get_class($obj) != "CLOUDFS_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CLOUDFS_Object.");
        }
        if (!is_resource($resource)) {
            throw new SyntaxException(
                "Resource argument not a valid PHP resource.");
        }

        $conn_type = "GET_CALL";

        $url_path = $this->_make_path($obj->container->name,$obj->name);
        $this->_obj_write_resource = $resource;
        $this->_write_callback_type = "OBJECT_STREAM";
        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array($return_code,$this->error_str);
        }
        if ($return_code == 404) {
            $this->error_str = "Object not found.";
            return array($return_code,$this->error_str);
        }
        if ($return_code != 200) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return array($return_code,$this->error_str);
        }
        return array($return_code,$this->response_reason);
    }

    # PUT /v1/Account/Container/Object
    #
    function put_object(&$obj, &$fp)
    {
        if (!is_object($obj) || get_class($obj) != "CLOUDFS_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CLOUDFS_Object.");
        }

        if (!$obj->content_length) {
            throw new SyntaxException(
                "Missing required content_length on object");
        }

        if (!is_resource($fp)) {
            throw new SyntaxException(
                "File pointer argument is not a valid resource.");
        }

        $conn_type = "PUT_OBJ";

        $url_path = $this->_make_path($obj->container->name,$obj->name);

        $hdrs = $this->_metadata_headers($obj);
        if ($etag) {
            $hdrs[] = "ETag: " . $etag;
        }
        if (!$obj->content_type) {
            $hdrs[] = "Content-Type: application/octet-stream";
        } else {
            $hdrs[] = "Content-Type: " . $obj->content_type;
        }

        $this->_init($conn_type);
        curl_setopt($this->connections[$conn_type],
                CURLOPT_INFILE, $fp);
        curl_setopt($this->connections[$conn_type],
                CURLOPT_INFILESIZE, $obj->content_length);

        $return_code = $this->_send_request($conn_type,$url_path,$hdrs);
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array(0,$this->error_str,NULL);
        }
        if ($return_code == 412) {
            $this->error_str = "Missing Content-Type header";
            return array(0,$this->error_str,NULL);
        }
        if ($return_code == 422) {
            $this->error_str = "Derived and computed checksums do not match.";
            return array(0,$this->error_str,NULL);
        }
        if ($return_code != 201) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
            return array(0,$this->error_str,NULL);
        }
        return array($return_code,$this->response_reason,$this->_obj_etag);
    }

    # POST /v1/Account/Container/Object
    #
    function update_object(&$obj)
    {
        if (!is_object($obj) || get_class($obj) != "CLOUDFS_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CLOUDFS_Object.");
        }

        if (!is_array($obj->metadata) || empty($obj->metadata)) {
            $this->error_str = "Metadata array is empty.";
            return 0;
        }

        $url_path = $this->_make_path($obj->container->name,$obj->name);

        $hdrs = $this->_metadata_headers($obj);
        $return_code = $this->_send_request("DEL_POST",$url_path,$hdrs,"POST");
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return 0;
        }
        if ($return_code == 404) {
            $this->error_str = "Account, Container, or Object not found.";
        }
        if ($return_code != 202) {
            $this->error_str = "Unexpected HTTP return code: $return_code";
        }
        return $return_code;
    }

    # HEAD /v1/Account/Container/Object
    #
    function head_object(&$obj)
    {
        if (!is_object($obj) || get_class($obj) != "CLOUDFS_Object") {
            throw new SyntaxException(
                "Method argument is not a valid CLOUDFS_Object.");
        }

        $conn_type = "HEAD";

        $url_path = $this->_make_path($obj->container->name,$obj->name);
        $return_code = $this->_send_request($conn_type,$url_path);

        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return array(0, $this->error_str." ".$this->response_reason,
                NULL, NULL, NULL, NULL, array());
        }

        if ($return_code == 404) {
            return array($return_code, $this->response_reason,
                NULL, NULL, NULL, NULL, array());
        }
        if ($return_code == 204) {
            return array($return_code,$this->response_reason,
                $this->_obj_etag,
                $this->_obj_last_modified,
                $this->_obj_content_type,
                $this->_obj_content_length,
                $this->_obj_metadata);
        }
        $this->error_str = "Unexpected HTTP return code: $return_code";
        return array($return_code, $this->error_str." ".$this->response_reason,
                NULL, NULL, NULL, NULL, array());
    }

    # DELETE /v1/Account/Container/Object
    #
    function delete_object($container_name, $object_name)
    {
        if (!$container_name || !$object_name) {
            $this->error_str = "Container or Object name not set.";
            return 0;
        }

        $url_path = $this->_make_path($container_name,$object_name);
        $return_code = $this->_send_request("DEL_POST",$url_path,NULL,"DELETE");
        if (!$return_code) {
            $this->error_str = "Failed to obtain http response";
            return 0;
        }
        if ($return_code == 409) {
            $this->error_str = "Container must be empty prior to removing it.";
        }
        if ($return_code == 404) {
            $this->error_str = "Specified container did not exist to delete.";
        }
        if ($return_code != 204) {
            $this->error_str = "Unexpected HTTP return code: $return_code.";
        }
        return $return_code;
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

    function setStorageUrl($surl)
    {
        $this->storage_url = $surl;
    }

    function setStorageToken($stok)
    {
        $this->storage_token = $stok;
    }


    private function _header_cb($ch, $header)
    {
        preg_match("/^HTTP\/1\.[01] (\d{3}) (.*)/", $header, $matches);
        if ($matches[1]) {
            $this->response_status = $matches[1];
        }
        if ($matches[2]) {
            $this->response_reason = $matches[2];
        }

        if (stripos($header, CONTAINER_OBJ_COUNT) === 0) {
            $this->_container_object_count = trim(substr($header,
                    strlen(CONTAINER_OBJ_COUNT)+1));
            return strlen($header);
        }
        if (stripos($header, CONTAINER_BYTES_USED) === 0) {
            $this->_container_bytes_used = trim(substr($header,
                    strlen(CONTAINER_BYTES_USED)+1));
            return strlen($header);
        }
        if (stripos($header, METADATA_HEADER) === 0) {
            $temp = substr($header, strlen(METADATA_HEADER));
            $parts = explode(":", $temp);
            $this->_obj_metadata[strtolower($parts[0])] = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "ETag") === 0) {
            $parts = explode(":", $header);
            $this->_obj_etag = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "Last-Modified") === 0) {
            $parts = explode(":", $header);
            $this->_obj_last_modified = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "Content-Type") === 0) {
            $parts = explode(":", $header);
            $this->_obj_content_type = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "Content-Length") === 0) {
            $parts = explode(":", $header);
            $this->_obj_content_length = trim($parts[1]);
            return strlen($header);
        }
        if (stripos($header, "ETag") === 0) {
            $parts = explode(":", $header);
            $this->_obj_etag = trim($parts[1]);
            return strlen($header);
        }
        return strlen($header);
    }

    private function _write_cb($ch, $data)
    {
        switch ($this->_write_callback_type) {
        case "TEXT_LIST":
            $this->_text_list[] = rtrim($data, "\r\n\0\x0B"); # keep tab,space
            break;
        case "OBJECT_STREAM":
            return fwrite($this->_obj_write_resource, $data);
        case "OBJECT_STRING":
            $this->_obj_write_string .= $data;
        }
        return strlen($data);
    }

    private function _auth_hdr_cb($ch, $header)
    {
        preg_match("/^HTTP\/1\.[01] (\d{3}) (.*)/", $header, $matches);
        if ($matches[1]) {
            $this->response_status = $matches[1];
        }
        if ($matches[2]) {
            $this->response_reason = $matches[2];
        }
        if (stripos($header, STORAGE_URL) === 0) {
            $this->storage_url = trim(substr($header,
                strlen(STORAGE_URL)+1));
        }
        if (stripos($header, STORAGE_TOK) === 0) {
            $this->storage_token = trim(substr($header,
                strlen(STORAGE_TOK)+1));
        }
        return strlen($header);
    }

    private function _make_headers($hdrs=NULL)
    {
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

                if (stripos($header, STORAGE_TOK) === 0) {
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
        return $new_headers;
    }

    private function _init($conn_type, $force_new=False)
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
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(&$this, '_header_cb'));

        if ($conn_type == "GET_CALL") {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION,
                    array(&$this, '_write_cb'));
        }

        if ($conn_type == "PUT_OBJ") {
            curl_setopt($ch, CURLOPT_PUT, 1);
        }
        if ($conn_type == "HEAD") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "HEAD");
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }
        if ($conn_type == "PUT_CONT") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_INFILESIZE, 0);
        }
        if ($conn_type == "DEL_POST") {
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }
        $this->connections[$conn_type] = $ch;
        return;
    }

    private function _reset_callback_vars()
    {
        $this->_text_list = array();
        $this->_container_object_count = 0;
        $this->_container_bytes_used = 0;
        $this->_obj_etag = NULL;
        $this->_obj_last_modified = NULL;
        $this->_obj_content_type = NULL;
        $this->_obj_content_length = NULL;
        $this->_obj_metadata = array();
        $this->_obj_write_string = "";
        $this->response_status = 0;
        $this->response_reason = "";
    }

    private function _make_path($c,$o=NULL)
    {
        $path = array();
        $path[] = $this->storage_url;
        $path[] = rawurlencode($c);
        if ($o) {
            # mimic Python's urllib.quote() feature of a "safe" '/' character
            #
            $path[] = str_replace("%2F","/",rawurlencode($o));
        }
        return implode("/",$path);
    }

    private function _metadata_headers(&$obj)
    {
        $hdrs = array();
        foreach ($obj->metadata as $k => $v) {
            if (strpos($k,":") !== False || strpos($v,":") !== False) {
                throw new SyntaxException(
                    "Metadata cannot contain a ':' character.");
            }
            $k = strtolower(trim($k));
            $key = sprintf("%s%s", METADATA_HEADER, $k);
            if (!array_key_exists($key, $hdrs)) {
                if (strlen($k) > 128 || strlen($v) > 256) {
                    $this->error_str = "Metadata key or value exceeds ";
                    $this->error_str .= "maximum length: ($k: $v)";
                    return 0;
                }
                $hdrs[] = sprintf("%s%s: %s", METADATA_HEADER, $k, trim($v));
            }
        }
        return $hdrs;
    }

    private function _send_request($conn_type, $url_path, $hdrs=NULL, $method="GET")
    {
        $this->_init($conn_type);
        $this->_reset_callback_vars();

        $headers = $this->_make_headers($hdrs);

        switch ($method) {
        case "DELETE":
            curl_setopt($this->connections[$conn_type],
                CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
        case "POST":
            curl_setopt($this->connections[$conn_type],
                CURLOPT_CUSTOMREQUEST, "POST");
        default:
            break;
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
}

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
