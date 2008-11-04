<?php
/**
 * This is the PHP Cloud Files API.
 *
 * It uses the supporting "cloudfiles_http.php" module for HTTP(s) support and
 * allows for connection re-use and streaming of content into/out of Cloud Files
 * via PHP's cURL module.
 *
 * <code>
 *   # Authenticate to Cloud Files
 *   #
 *   $auth = new CF_Authentication($username, $api_key);
 *   $auth->authenticate();
 *
 *   # Establish a connection to the storage system
 *   #
 *   $conn = new CF_Connection($auth);
 *
 *   # Create a remote Container and storage Object
 *   #
 *   $electronica = $conn->create_container("Electronica");
 *   $tiesto = $electronica->create_object("tiesto.mp3");
 *
 *   # Upload content from a local file by streaming it
 *   #
 *   $fname = "/home/user/music/electronica/tiesto.mp3";   # filename to upload
 *   $size = filesize($fname);
 *   $fp = open($fname, "r");
 *   $tiesto->write($fp, $size);
 *
 *   # Or... use a convenience function instead
 *   #
 *   $tiesto->load_from_filename("/home/user/music/electronica/tiesto.mp3");
 * </code>
 *
 * See the included examples/tests for additional usage tips.
 *
 * Requres PHP 5.x (for Exceptions and OO syntax)
 *
 * See COPYING for license information.
 *
 * @author Eric "EJ" Johnson <ej@racklabs.com>
 * @version 1.1.0
 * @copyright Copyright (c) 2008, Rackspace US, Inc.
 * @package php-cloudfiles
 */

/**
 */
require_once("cloudfiles_exceptions.php");
require("cloudfiles_http.php");
define("DEFAULT_CF_API_VERSION", 1);
define("MAX_CONTAINER_NAME_LEN", 64);
define("MAX_OBJECT_NAME_LEN", 128);

/**
 * Class for handling Cloud Files Authentication, call it's {@link authenticate()}
 * method to obtain authorized service urls and authentication token.
 * @package php-cloudfiles
 */
class CF_Authentication
{
    public $dbug;
    public $username;
    public $api_key;
    public $auth_host;
    public $account;

    /**
     * Instance variables that are set after successful authentication
     */
    public $auth_token;
    public $storage_url;

    /**
     * Class constructor (PHP 5 syntax)
     *
     * @param string $username Mosso username
     * @param string $api_key Mosso API Access Key
     * @param string $account <b>Deprecated</b> <i>Account name</i>
     * @param string $auth_host <b>Deprecated</b> <i>Authentication service URI</i>
     */
    function __construct($username, $api_key, $account=NULL, $auth_host=NULL)
    {
        if (!$username || !$api_key) {
            throw new SyntaxException("Missing required constructor arguments.");
        }

        $this->dbug = False;
        $this->username = $username;
        $this->api_key = $api_key;
        $this->account_name = $account;
        $this->auth_host = $auth_host;

        $this->auth_token = NULL;
        $this->storage_url = NULL;

        $this->cfs_http = new CF_Http(DEFAULT_CF_API_VERSION);
    }

    /**
     * Attempt to validate Username/API Access Key
     *
     * Attempts to validate credentials with the authentication service.  It
     * either returns <kbd>True</kbd> or throws an Exception.  Accepts a single
     * (optional) argument for the storage system API version.
     *
     * @param string $version API version for Auth service (optional)
     * @return boolean <kbd>True</kbd> if successfully authenticated
     * @throws AuthenticationException invalid credentials
     * @throws InvalidResponseException invalid response
     */
    function authenticate($version=DEFAULT_CF_API_VERSION)
    {
        list($status,$reason,$surl,$atoken) = 
                $this->cfs_http->authenticate($this->username, $this->api_key,
                $this->account_name, $this->auth_host);

        if ($status == 401) {
            throw new AuthenticationException("Invalid username or access key.");
        }
        if ($status != 204) {
            throw new InvalidResponseException(
                "Unexpected response (".$status."): ".$reason);
        }

        if (!$surl || !$atoken) {
            throw new InvalidResponseException(
                "Expected headers missing from auth service.");
        }
        $this->storage_url = $surl;
        $this->auth_token = $atoken;
        return True;
    }

    /**
     * Toggle debugging - set cURL verbose flag
     */
    function setDebug($bool)
    {
        $this->dbug = $bool;
        $this->cfs_http->setDebug($bool);
    }
}

/**
 * Class for establishing connections to the Cloud Files storage system.  Connection
 * instances are used to communicate with the storage system at the account
 * level; listing and deleting Containers and returning Container instances.
 * @package php-cloudfiles
 */
class CF_Connection
{
    public $dbug;
    public $cfs_http;

    public $auth_token;
    public $storage_url;

    /**
     * Pass in a previously authenticated CF_Authentication instance.
     *
     * @param obj $cfs_auth previously authenticated CF_Authentication instance
     * @throws AuthenticationException not authenticated
     */
    function __construct($cfs_auth)
    {
        $this->cfs_http = new CF_Http(DEFAULT_CF_API_VERSION);
        $this->storage_url = $cfs_auth->storage_url;
        $this->auth_token = $cfs_auth->auth_token;
        if (!$this->storage_url || !$this->auth_token) {
            $e = "Need to pass in a previously authenticated ";
            $e .= "CF_Authentication instance.";
            throw new AuthenticationException($e);
        }
        $this->cfs_http->setStorageUrl($this->storage_url);
        $this->cfs_http->setAuthToken($this->auth_token);
        $this->dbug = False;
    }

    /**
     * Toggle debugging of instance and back-end HTTP module
     *
     * @param boolean $bool enable/disable cURL debugging
     */
    function setDebug($bool)
    {
        $this->dbug = (boolean) $bool;
        $this->cfs_http->setDebug($this->dbug);
    }

    /**
     * Cloud Files account information
     *
     * Return an array of two integers (or possibly floats if the value
     * overflows PHP's 32-bit integer); number of containers on the account
     * and total bytes used for the account.
     *
     * @return array (number of containers, total bytes stored)
     * @throws InvalidResponseException unexpected response
     */
    function get_info()
    {
        list($status, $reason, $container_count, $total_bytes) =
                $this->cfs_http->head_account();
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response: ".$this->cfs_http->get_error());
        }
        return array($container_count, $total_bytes);
    }

    /**
     * Create a Container
     *
     * Given a Container name, return a Container instance, creating a new
     * remote Container if it does not exit.
     *
     * @param string $container_name container name
     * @return CF_Container
     * @throws SyntaxException invalid name
     * @throws InvalidResponseException unexpected response
     */
    function create_container($container_name=NULL)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }
        if (strpos($container_name, "/") !== False) {
            $r = "Container name '".$container_name;
            $r .= "' cannot contain a '/' character.";
            throw new SyntaxException($r);
        }
        if (mb_strlen($container_name, "UTF-8") > MAX_CONTAINER_NAME_LEN) {
            throw new SyntaxException(sprintf(
                "Container name exeeds %d characters.",
                MAX_CONTAINER_NAME_LEN));
        }

        $return_code = $this->cfs_http->create_container($container_name);
        if (!$return_code) {
            throw new InvalidResponseException($this->cfs_http->get_error());
        }
        if ($return_code != 201 && $return_code != 202) {
            throw new InvalidResponseException(
                "Unexpected return code: ".$return_code);
        }
        return new CF_Container($this->cfs_http, $container_name);
    }

    /**
     * Delete a Container
     *
     * Given either a Container instance or name, remove the remote Container.
     * The Container must be empty prior to removing it.
     *
     * @param string|obj $container container name or instance
     * @return boolean <kbd>True</kbd> if successfully deleted
     * @throws SyntaxException missing proper argument
     * @throws InvalidResponseException invalid response
     * @throws NonEmptyContainerException container not empty
     * @throws NoSuchContainerException remote container does not exist
     */
    function delete_container($container=NULL)
    {
        if (is_object($container)) {
            if (get_class($container) == "CF_Container") {
                $container_name = $container->name;
            }
        }
        if (is_string($container)) {
            $container_name = $container;
        }
        if (!$container_name) {
            throw new SyntaxException("Must specify container object or name.");
        }

        $return_code = $this->cfs_http->delete_container($container_name);

        if (!$return_code) {
            throw new InvalidResponseException("Failed to obtain http response");
        }
        if ($return_code == 409) {
            throw new NonEmptyContainerException(
                "Container must be empty prior to removing it.");
        }
        if ($return_code == 404) {
            throw new NoSuchContainerException(
                "Specified container did not exist to delete.");
        }
        if ($return_code != 204) {
            throw new InvalidResponseException(
                "Unexpected return code: $return_code");
        }
        return True;
    }

    /**
     * Return a Container instance
     *
     * For the given name, return a Container instance if the remote Container
     * exists, otherwise throw a Not Found exception.
     *
     * @param string $container_name name of the remote Container
     * @return container CF_Container instance
     * @throws NoSuchContainerException thrown if no remote Container
     * @throws InvalidResponseException unexpected response
     */
    function get_container($container_name=NULL)
    {
        list($status, $reason, $size, $bytes) =
                $this->cfs_http->head_container($container_name);
        if ($status == 404) {
            throw new NoSuchContainerException("Container not found.");
        }
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response: ".$this->cfs_http->get_error());
        }
        return new CF_Container($this->cfs_http,$container_name,$size,$bytes);
    }

    /**
     * Return list of remote Containers
     *
     * Return an array of strings containing the names of all remote Containers.
     *
     * @return array list of remote Containers
     * @throws InvalidResponseException unexpected response
     */
    function list_containers()
    {
        list($status, $reason, $containers) = $this->cfs_http->list_containers();
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response: ".$this->cfs_http->get_error());
        }
        return $containers;
    }
}

/**
 * Container operations
 *
 * Containers are storage compartments where you put your data (objects).
 * A container is similar to a directory or folder on a conventional filesystem
 * with the exception that they exist in a flat namespace, you can not create
 * containers inside of containers.
 *
 * NOTE: Due to the possible overflow of PHP's 32-bit integer, the Container's
 *       object_count and size_used instance variables may either be a
 *       integer or float.
 *
 * @package php-cloudfiles
 */
class CF_Container
{
    public $cfs_http;
    public $name;
    public $object_count;
    public $size_used;

    /**
     * Class constructor
     *
     * Constructor for Container
     *
     * @param obj $cfs_http HTTP connection manager
     * @param string $name name of Container
     * @param int $count number of Objects stored in this Container
     * @param int $bytes number of bytes stored in this Container
     * @throws SyntaxException invalid Container name
     */
    function __construct(&$cfs_http, $name, $count=0, $bytes=0)
    {
        if (mb_strlen($name, "UTF-8") > MAX_CONTAINER_NAME_LEN) {
            throw new SyntaxException("Container name exceeds "
                . "maximum allowed length.");
        }
        if (strpos($container_name, "/") !== False) {
            throw new SyntaxException(
                "Container names cannot contain a '/' character.");
        }
        $this->cfs_http = $cfs_http;
        $this->name = $name;
        $this->object_count = $count;
        $this->bytes_used = $bytes;
    }

    /**
     * String representation of Container
     *
     * Pretty print the Container instance.
     *
     * @return string Container details
     */
    function __toString()
    {
        $me = sprintf("name: %s, count: %.0f, bytes: %.0f",
            $this->name, $this->object_count, $this->bytes_used);
        return $me;
    }

    /**
     * Create a new remote storage Object
     *
     * Return a new Object instance.  If the remote storage Object exists,
     * the instance's attributes are populated.
     *
     * @param string $obj_name name of storage Object
     * @return obj CF_Object instance
     */
    function create_object($obj_name=NULL)
    {
        return new CF_Object($this, $obj_name);
    }

    /**
     * Return an Object instance for the remote storage Object
     *
     * Given a name, return a Object instance representing the
     * remote storage object.
     *
     * @param string $obj_name name of storage Object
     * @return obj CF_Object instance
     */
    function get_object($obj_name=NULL)
    {
        return new CF_Object($this, $obj_name, True);
    }

    /**
     * Return a list of Objects
     *
     * Return an array of strings listing the Object names in this Container.
     *
     * @param int $limit <i>optional</i> only return $limit names
     * @param int $offset <i>optional</i> subset of names starting at $offset
     * @param string $prefix <i>optional</i> Objects whose names begin with $prefix
     * @return array array of strings
     * @throws InvalidResponseException unexpected response
     */
    function list_objects($limit=0, $offset=-1, $prefix="")
    {
        list($status, $reason, $obj_list) =
            $this->cfs_http->get_container($this->name,
                    $limit, $offset, $prefix);
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response: ".$this->cfs_http->get_error());
        }
        return $obj_list;
    }

    /**
     * Delete a remote storage Object
     *
     * Given an Object instance or name, permanently remove the remote Object
     * and all associated metadata.
     *
     * @param obj $obj name or instance of Object to delete
     * @return boolean <kbd>True</kbd> if successfully removed
     * @throws SyntaxException invalid Object name
     * @throws NoSuchObjectException remote Object does not exist
     * @throws InvalidResponseException unexpected response
     */
    function delete_object($obj)
    {
        $obj_name = NULL;
        if (is_object($obj)) {
            if (get_class($obj) == "CF_Object") {
                $obj_name = $obj->name;
            }
        }
        if (is_string($obj)) {
            $obj_name = $obj;
        }
        if (!$obj_name) {
            throw new SyntaxException("Object name not set.");
        }
        $status = $this->cfs_http->delete_object($this->name, $obj_name);
        if ($status == 404) {
            $m = "Specified object '".$this->name."/".$obj_name;
            $m.= "' did not exist to delete.";
            throw new NoSuchObjectException($m);
        }
        if ($status != 204) {
            throw new InvalidResponseException(
                "Unexpected HTTP return code: $return_code.");
        }
        return True;
    }
}


/**
 * Object operations
 *
 * An Object is analogous to a file on a conventional filesystem. You can
 * read data from, or write data to your Objects. You can also associate 
 * arbitrary metadata with them.
 *
 * @package php-cloudfiles
 */
class CF_Object
{
    public $container;
    public $name;
    public $last_modified;
    public $content_type;
    public $content_length;
    public $metadata;
    private $etag;

    /**
     * Class constructor
     *
     * @param obj $container CF_Container instance
     * @param string $name name of Object
     * @param boolean $force_exists if set, throw an error if Object doesn't exist
     */
    function __construct(&$container, $name, $force_exists=False)
    {
        if (mb_strlen($name, "UTF-8") > MAX_OBJECT_NAME_LEN) {
            throw new SyntaxException("Object name exceeds "
                . "maximum allowed length.");
        }
        $this->container = $container;
        $this->name = $name;
        $this->etag = NULL;
        $this->_etag_override = False;
        $this->last_modified = NULL;
        $this->content_type = NULL;
        $this->content_length = 0;
        $this->metadata = array();
        if (!$this->_initialize() && $force_exists) {
            throw new NoSuchObjectException("No such object '".$name."'");
        }
    }

    /**
     * String representation of Container
     *
     * Pretty print the Object's location and name
     *
     * @return string Object information
     */
    function __toString()
    {
        return $this->container->name . "/" . $this->name;
    }

    /**
     * Read the remote Object's data
     *
     * Returns the Object's data.  This is useful for smaller Objects such
     * as images or office documents.  Object's with larger content should use
     * the stream() method below.
     *
     * Pass in $hdrs array to set specific custom HTTP headers such as
     * If-Match, If-None-Match, If-Modified-Since, Range, etc.
     *
     * @param array $hdrs user-defined headers (Range, If-Match, etc.)
     * @return string Object's data
     * @throws InvalidResponseException unexpected response
     */
    function read($hdrs=array())
    {
        list($status, $reason, $data) =
            $this->container->cfs_http->get_object_to_string($this,$hdrs);
        if (($status < 200) || ($status > 299
                && $status != 412 && $status != 304)) {
            throw new InvalidResponseException("Invalid response: "
                . $this->container->cfs_http->get_error());
        }
        return $data;
    }

    /**
     * Streaming read of Object's data
     *
     * Given an open PHP resource (see PHP's fopen() method), fetch the Object's
     * data and write it to the open resource handle.  This is useful for
     * streaming an Object's content to the browser (videos, images) or for
     * fetching content to a local file.
     *
     * Pass in $hdrs array to set specific custom HTTP headers such as
     * If-Match, If-None-Match, If-Modified-Since, Range, etc.
     *
     * @param resource $fp open resource for writing data to
     * @param array $hdrs user-defined headers (Range, If-Match, etc.)
     * @return string Object's data
     * @throws InvalidResponseException unexpected response
     */
    function stream(&$fp, $hdrs=array())
    {
        list($status, $reason) = 
                $this->container->cfs_http->get_object_to_stream($this,
                        $fp,$hdrs);
        if (($status < 200) || ($status > 299
                && $status != 412 && $status != 304)) {
            throw new InvalidResponseException("Invalid response: ".$reason);
        }
        return True;
    }

    /**
     * Store new Object metadata
     *
     * Write's an Object's metadata to the remote Object.  This will overwrite
     * an prior Object metadata.
     *
     * @return boolean <kbd>True</kbd> if successful, <kbd>False</kbd> otherwise
     * @throws InvalidResponseException unexpected response
     */
    function sync_metadata()
    {
        if (!empty($this->metadata)) {
            $status = $this->container->cfs_http->update_object($this);
            if ($status != 202) {
                throw new InvalidResponseException(
                    $this->container->cfs_http->get_error());
            }
            return True;
        }
        return False;
    }

    /**
     * Upload Object's data to Cloud Files
     *
     * Write data to the remote Object.  The $data argument can either be a
     * PHP resource open for reading (see PHP's fopen() method) or an in-memory
     * variable.  If passing in a PHP resource, you must also include the $size
     * parameter.
     *
     * @param string|resource $data string or open resource
     * @param int $size amount of data to upload (required for resources)
     * @param boolean $verify generate, send, and compare MD5 checksums
     * @return boolean <kbd>True</kbd> when data uploaded successfully
     * @throws SyntaxException missing required parameters
     * @throws MisMatchedChecksumException $verify is set and checksums unequal
     * @throws InvalidResponseException unexpected response
     */
    function write($data=NULL, $size=0, $verify=True)
    {
        if (!$data) {
            throw new SyntaxException("Missing required data source.");
        }
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
                throw new SyntaxException("Missing required size for object data.");
            } else {
                $this->content_length = $size;
            }
            $fp = $data;
        }

        list($status, $reason, $etag) =
                $this->container->cfs_http->put_object($this, $fp);
        if ($status == 412) {
            if ($close_fh) { fclose($fp); }
            throw new SyntaxException("Missing Content-Type header");
        }
        if ($status == 422) {
            if ($close_fh) { fclose($fp); }
            throw new MisMatchedChecksumException(
                "Supplied and computed checksums do not match.");
        }
        if ($status != 201) {
            if ($close_fh) { fclose($fp); }
            throw new InvalidResponseException("Invalid response: ".
                $this->container->cfs_http->get_error());
        }
        if (!$verify) {
            $this->etag = $etag;
        }
        if ($close_fh) { fclose($fp); }
        return True;
    }

    /**
     * Upload Object data from local filename
     *
     * This is a convenience function to upload the data from a local file.  A
     * True value for $verify will cause the method to compute the Object's MD5
     * checksum prior to uploading.
     *
     * @param string $filename full path to local file
     * @param boolean $verify enable local/remote MD5 checksum validation
     * @return boolean <kbd>True</kbd> if data uploaded successfully
     * @throws SyntaxException missing required parameters
     * @throws MisMatchedChecksumException $verify is set and checksums unequal
     * @throws InvalidResponseException unexpected response
     * @throws IOException error opening file
     */
    function load_from_filename($filename, $verify=True)
    {
        $fp = @fopen($filename, "r");
        if (!$fp) {
            throw new IOException("Could not open file for reading: " . $filename);
        }
        $size = filesize($filename);
        $this->content_type = mime_content_type($filename);
        $this->write($fp, $size, $verify);
        fclose($fp);
        return True;
    }

    /**
     * Save Object's data to local filename
     *
     * Given a local filename, the Object's data will be written to the newly
     * created file.
     *
     * @param string $filename name of local file to write data to
     * @return boolean <kbd>True</kbd> if successful
     * @throws IOException error opening file
     * @throws InvalidResponseException unexpected response
     */
    function save_to_filename($filename)
    {
        $fp = @fopen($filename, "w");
        if (!$fp) {
            throw new IOException("Could not open file for writing: " . $filename);
        }
        $result = $this->stream($fp);
        fclose($fp);
        return $result;
    }

    /**
     * Set Object's MD5 checksum
     *
     * Manually set the Object's ETag.  Including the ETag is mandatory for
     * Cloud Files to perform end-to-end verification.  Omitting the ETag forces
     * the user to handle any data integrity checks.
     *
     * @param string $etag MD5 checksum hexidecimal string
     */
    public function set_etag($etag)
    {
        $this->etag = $etag;
        $this->_etag_override = True;
    }

    /**
     * Object's MD5 checksum
     *
     * Accessor method for reading Object's private ETag attribute.
     *
     * @return string MD5 checksum hexidecimal string
     */
    public function getETag()
    {
        return $this->etag;
    }

    /**
     * Compute the MD5 checksum
     *
     * Calculate the MD5 checksum on either a PHP resource or data.  The argument
     * may either be a local filename, open resource for reading, or a string.
     *
     * <b>WARNING:</b> If $data is a resource, the entire contents are read
     * into a local variable (memory) before computing the checksum!
     *
     * @param filename|obj|string $data filename, open resource, or string
     * @return string MD5 checksum hexidecimal string
     */
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

    /**
     * PRIVATE: fetch information about the remote Object if it exists
     */
    private function _initialize()
    {
        list($status, $reason, $etag, $last_modified, $content_type,
            $content_length, $metadata) =
                $this->container->cfs_http->head_object($this);

        if ($status == 404) {
            return False;
        }
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException("Invalid response: ".
                $this->container->cfs_http->get_error());
        }
        $this->etag = $etag;
        $this->last_modified = $last_modified;
        $this->content_type = $content_type;
        $this->content_length = $content_length;
        $this->metadata = $metadata;
        return True;
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
