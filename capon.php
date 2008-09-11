<?php
/*
 * This is the CloudFS PHP API.
 *
 * It uses the supporting "cloudfs_http.php" module for HTTP(s) support and
 * allows for connection re-use and streaming of content into/out of CloudFS.
 *
 *   # Authenticate to CloudFS
 *   #
 *   $auth = new CLOUDFS_Authentication($user,$passwd);
 *   $auth->authenticate();
 *
 *   # Establish a connection to the storage cluster0
 *   #
 *   $conn = new CLOUDFS_Connection($auth);
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
 *
 * See the included examples/tests for additional usage tips.
 *
 * Requres PHP 5.x (for Exceptions and OO syntax)
 *
 * See COPYING for license information.
 */
require_once("cloudfs_exceptions.php");
require("cloudfs_http.php");

define("DEFAULT_CLOUDFS_API_VERSION", 1);
define("MAX_CONTAINER_NAME_LEN", 64);
define("MAX_OBJECT_NAME_LEN", 128);

/*
 * Class for handling CloudFS Authentication, call it's authenticate() method
 * to obtain the storage url and token.
 *
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

    function __construct($username, $password, $account=NULL, $auth_host=NULL)
    {
        if (!$username || !$password) {
            throw new SyntaxException("Missing required constructor arguments.");
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

    /*
     * Initiates authentication with the remote service and returns a 
     * two-tuple containing the storage system URL and session token.
     * Accepts a single (optional) argument for the storage system
     * API version.
     */
    function authenticate($version=DEFAULT_CLOUDFS_API_VERSION)
    {
        list($status,$reason,$surl,$stoken) = 
                $this->cfs_http->authenticate($this->username, $this->password,
                $this->account_name, $this->auth_host);

        if ($status == 401) {
            throw new AuthenticationException("Invalid username or password.");
        }
        if ($status != 204) {
            throw new InvalidResponseException(
                "Unexpected response (".$status."): ".$reason);
        }

        if (!$surl || !$stoken) {
            throw new InvalidResponseException(
                "Expected headers missing from auth service.");
        }
        $this->storage_url = $surl;
        $this->storage_token = $stoken;

        return True;
    }

    /*
     * Accessor methods for instance variables
     */
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
 * Class for establishing connections to the CloudFS storage system.  Connection
 * instances are used to communicate with the storage system at the account
 * level; listing and deleting Containers and returning Container instances.
 *
 */
class CLOUDFS_Connection
{
    public $dbug;
    public $cfs_http;

    public $storage_token;
    public $storage_url;

    /*
     * Pass in a previously authenticated CLOUDFS_Authentication instance.
     */
    function __construct($cfs_auth)
    {
        $this->cfs_http = new CLOUDFS_Http(DEFAULT_CLOUDFS_API_VERSION);
        $this->storage_url = $cfs_auth->getStorageUrl();
        $this->storage_token = $cfs_auth->getStorageToken();
        if (!$this->storage_url || !$this->storage_token) {
            $e = "Need to pass in a previously authenticated ";
            $e .= "CLOUDFS_Authentication instance.";
            throw new AuthenticationException($e);
        }
        $this->cfs_http->setStorageUrl($this->storage_url);
        $this->cfs_http->setStorageToken($this->storage_token);
        $this->dbug = False;
    }

    /*
     * Toggle debugging of instance and back-end HTTP module
     */
    function setDebug($bool)
    {
        $this->dbug = (boolean) $bool;
        $this->cfs_http->setDebug($this->dbug);
    }

    /*
     * Return an array of two integers (or possibly floats if the value
     * overflows PHP's 32-bit integer); number of containers on the account
     * and total bytes used for the account.
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

    /*
     * Given a Container name, return a Container instance, creating a new
     * remote Container if it does not exit.
     */
    function create_container($container_name)
    {
        if (!$container_name) {
            throw new SyntaxException("Container name not set.");
        }
        if (strpos($container_name, "/") !== False) {
            $r = "Container name '".$container_name;
            $r .= "' cannot contain a '/' character.";
            throw new SyntaxException($r);
        }
        if (strlen(rawurlencode($container_name)) > MAX_CONTAINER_NAME_LEN) {
            throw new SyntaxException(sprintf(
                "URL encoded container name exeeds %d characters.",
                MAX_CONTAINER_NAME_LEN));
        }

        $return_code = $this->cfs_http->create_container($container_name);
        if (!$return_code) {
            throw new InvalidResponseException($this->cfs_http->get_error());
        }
        if ($return_code != 201 && $return_code != 202) {
            throw new InvalidResponseException(
                "Unexpected return code: $return_code");
        }
        return new CLOUDFS_Container($this->cfs_http, $container_name);
    }

    /*
     * Given either a Container instance or name, remove the remote Container.
     */
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

    /*
     * For the given name, return a Container instance if the remote Container
     * exists, otherwise throw a Not Found exception.
     */
    function get_container($container_name)
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
        return new CLOUDFS_Container($this->cfs_http,$container_name,$size,$bytes);
    }

    /*
     * Return an array listing containing the names of all remote Containers.
     */
    function list_containers()
    {
        list($status, $reason, $containers) = $this->cfs_http->list_containers();
        if ($status == 404) {
            throw new NoSuchAccountException("Invalid account.");
        }
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response: ".$this->cfs_http->get_error());
        }
        return $containers;
    }
}

/*
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
 */
class CLOUDFS_Container
{
    public $cfs_http;
    public $name;
    public $object_count;
    public $size_used;

    function __construct($cfs_http, $name, $count=0, $bytes=0)
    {
        if (strlen(rawurlencode($name)) > MAX_CONTAINER_NAME_LEN) {
            throw new SyntaxException("Encoded container name exceeds "
                . "maximum allowed length.");
        }
        if (strpos($container_name, "/") !== False) {
            throw new SyntaxException(
                "Container names cannot contain a '/' character.");
        }
        $this->cfs_http = $cfs_http;
        $this->name = $name;
        $this->encoded_name = rawurlencode($name);
        $this->object_count = $count;
        $this->bytes_used = $bytes;
    }

    /*
     * Pretty print the Container instance.
     */
    function __toString()
    {
        return sprintf("name: %s, count: %.0f, bytes: %.0f",
            $this->name, $this->object_count, $this->bytes_used);
    }

    /*
     * Return a new Object instance.  If the remote storage Object exists,
     * the instance's attributes are populated.
     */
    function create_object($obj_name)
    {
        return new CLOUDFS_Object(&$this, $obj_name);
    }

    /*
     * Given an name, return a Object instance representing the
     * remote storage object.
     */
    function get_object($obj_name)
    {
        return new CLOUDFS_Object(&$this, $obj_name, True);
    }

    /*
     * Return an array list of remote Object names in this Container.
     *   $limit (int) - used to limit the number of names returned
     *   $offset (int) - ignore the first $offset names
     *   $prefix (str) - only return matching Objects whos names begin with prefix
     */
    function list_objects($limit=0, $offset=-1, $prefix="")
    {
        list($status, $reason, $obj_list) =
            $this->cfs_http->get_container($this->name, $limit, $offset, $prefix);
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException(
                "Invalid response: ".$this->cfs_http->get_error());
        }
        return $obj_list;
    }

    /*
     * Given an Object instance or name, permanently remove the remote Object.
     */
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


/*
 * Object operations
 *
 * An Object is analogous to a file on a conventional filesystem. You can
 * read data from, or write data to your Objects. You can also associate 
 * arbitrary metadata with them.
 *
 */
class CLOUDFS_Object
{
    public $container;
    public $name;
    public $last_modified;
    public $content_type;
    public $content_length;
    public $metadata;
    private $etag;          /* must use set_etag() for user-supplied value */

    function __construct($container, $name, $force_exists=False)
    {
        if (strlen(rawurlencode($name)) > MAX_OBJECT_NAME_LEN) {
            throw new SyntaxException("Encoded object name exceeds "
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
            throw new NoSuchObjectException("No such object '".$name."'");
        }
    }

    /*
     * Pretty print the Object's location and name
     */
    function __toString()
    {
        return $this->container->name . "/" . $this->name;
    }

    /*
     * Returns the Object's data.  This is useful for smaller Objects such
     * as images or office documents.  Object's with larger content should use
     * the stream() method below.
     */
    function read()
    {
        list($status, $reason, $data) =
            $this->container->cfs_http->get_object_to_string($this);
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException("Invalid response: "
                . $this->container->cfs_http->get_error());
        }
        return $data;
    }

    /*
     * Given an open PHP resource (see PHP's fopen() method), fetch the Object's
     * data and write it to the open resource handle.  This is useful for
     * streaming an Object's content to the browser (videos, images) or for
     * fetching content to a local file.
     */
    function stream(&$fp)
    {
        list($status, $reason) = 
                $this->container->cfs_http->get_object_to_stream($this, $fp);
        if ($status < 200 || $status > 299) {
            throw new InvalidResponseException("Invalid response: ".$reason);
        }
        return True;
    }

    /*
     * Write's an Object's metadata to the remote Object.  This will overwrite
     * an prior Object metadata.
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

    /*
     * Write data to the remote Object.  The $data argument can either be a
     * PHP resource open for reading (see PHP's fopen() method) or an in-memory
     * variable.  If passing in a PHP resource, you must also include the $size
     * parameter.
     */
    function write($data, $size=0, $verify=True)
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

    /*
     * Upload the data from a local filename.  A True value for $verify will
     * cause the method to compute the Object's MD5 checksum prior to uploading.
     */
    function load_from_filename($filename, $verify=True)
    {
        $fp = fopen($filename, "r");
        $size = filesize($filename);
        $this->content_type = mime_content_type($filename);
        $this->write($fp, $size, $verify);
        fclose($fp);
        return True;
    }

    /*
     * Given a local filename, the Object's data will be written to the newly
     * created file.
     */
    function save_to_filename($filename)
    {
        $fp = fopen($filename, "w");
        $result = $this->stream($fp);
        fclose($fp);
        return $result;
    }

    /*
     * Manually set the Object's ETag.  Including the ETag is mandatory for
     * CloudFS to perform end-to-end verification.  Omitting the ETag forces
     * the user to handle any data integrity checks.
     */
    public function set_etag($etag)
    {
        $this->etag = $etag;
        $this->_etag_override = True;
    }

    public function getETag()
    {
        return $this->etag;
    }

    /*
     * Calculate the MD5 checksum on either a PHP resource or data.
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

    /*
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
