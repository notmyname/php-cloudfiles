<?php
## ---------------------------------------------------------------------------- ##
## WARNING!!  WARNING!!  WARNING!!  WARNING!!  WARNING!!  WARNING!!  WARNING!! 
##
##   This is a development script only - it will permanently remove ALL
##    storage objects/containers in CloudFS for the specified account!
##
## WARNING!!  WARNING!!  WARNING!!  WARNING!!  WARNING!!  WARNING!!  WARNING!! 
## ---------------------------------------------------------------------------- ##
require("capon.php");

$VERBOSE = True;                        # informational messages to stdout
$ACCOUNT = NULL;                        # account name
$USER    = "Username";                  # account's username
$PASS    = "Password";                  # user's password
$HOST    = NULL;                        # authentication host URL

# Authenticate and make sure we get back a valid url/token
$auth = new CLOUDFS_Authentication($USER,$PASS,$ACCOUNT,$HOST);
$auth->authenticate();
print $auth->getStorageUrl();

# Create a connection to the backend storage system
#
$conn = new CLOUDFS_Connection($auth);

# Grab the current list of containers on the account
#
if ($VERBOSE) {echo "==> LIST CONTAINERS ================================\n";}
$orig_container_list = $conn->list_containers();
if ($VERBOSE) { print_r($orig_container_list); print "\n";}

foreach ($orig_container_list as $idx => $cont_name) {
    try {
        $container = $conn->get_container($cont_name);
    } catch (Exception $e) {
        print "**ERROR** get_container(".$cont_name.")\n";
        continue;
    }
    $obj_list = $container->list_objects();
    foreach ($obj_list as $idx2 => $obj_name) {
        if ($VERBOSE) {
            echo "==> PURGING OBJECT: ".$container->name . "/" .$obj_name."\n";
        }
        try {
            $container->delete_object($obj_name);
        } catch (Exception $e) {
            print $container->name . " :: ";
            print rawurlencode($container->name) . " SEP/SEP ";
            print $obj_name . " :: " . rawurlencode($obj_name) . "\n";
            print $e . "\n";
            continue;
        }
    }
    if ($VERBOSE) {echo "==> PURGING CONTAINER: ".$cont_name."\n";}
    try {
        $conn->delete_container($cont_name);
    } catch (Exception $e) {
        print $cont_name." :: ".rawurlencode($cont_name)."\n";
        print $e."\n";
    }
}

if ($VERBOSE) {echo "==> Done...\n";}

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
