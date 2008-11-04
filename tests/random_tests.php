<?php
require("cloudfiles.php");
require("cloudfiles_ini.php"); # account settings

# Test variable's quantity/lengths
#
$NUM_CONTAINERS = 10;
$NUM_OBJECTS = 15;
$NUM_METADATA = 5;
$CONT_NAME_LENGTH = 20;
$OBJ_NAME_LENGTH = 15;
$META_NAME_LENGTH = 5;

# See the utf8_test.php for testing UTF-8
#
$SEED_CONT_CHARS  = " abcDEFgHiJkLmNo0123456789.~`!@#$%^&*()-_=+{}[]\|;:'>?<,'\"";
$SEED_OBJ_CHARS   = " abcDEFgHiJkLmNo0123456789.~`!@#$%^&*()-_=+{}[]\|;:'>?</,'\"";
$SEED_METAH_CHARS = " abcDEFgHiJkLmNo0123456789.~`!@#$%^&*()-_=+{}[]\|;'>?</,'\"";
$SEED_METAV_CHARS = " abcDEFgHiJkLmNo0123456789.~`!@#$%^&*()-_=+{}[]\|;'>?</,'\"";

$OBJECT_DATA = "This is some sample text as object data.";
$data_md5 = md5($OBJECT_DATA);

# Initialize and record test data
#
$test_data = array();
for ($i=0; $i < $NUM_CONTAINERS; $i++) {
    $container_name = "";
    for ($j=0; $j < $CONT_NAME_LENGTH; $j++) {
        $container_name .= $SEED_CONT_CHARS[rand(0,strlen($SEED_CONT_CHARS))];
    }

    $obj_names = array();
    for ($j=0; $j < $NUM_OBJECTS; $j++) {
        $obj_name = "";
        for ($k=0; $k < $OBJ_NAME_LENGTH; $k++) {
            $obj_name .= $SEED_OBJ_CHARS[rand(0,strlen($SEED_OBJ_CHARS))];
        }

        $meta = array();
        for ($l=0; $l < $NUM_METADATA; $l++) {
            $meta_name = $meta_val = "";
            for ($m=0; $m < $META_NAME_LENGTH; $m++) {
                $meta_name .= $SEED_METAH_CHARS[rand(0,strlen($SEED_METAH_CHARS))];
                $meta_val .= $SEED_METAV_CHARS[rand(0,strlen($SEED_METAV_CHARS))];
            }
            $meta_name = trim(strtolower($meta_name));
            $meta[$meta_name] = trim($meta_val);
        }
        $obj_names[$obj_name] = $meta;
    }
    $test_data[$container_name] = $obj_names;
}

# set up assertion support
#
function assert_callback($file, $line, $code)
{
    print "Assertion failed:\n";
    print "  File: " . $file . "\n";
    print "  Line: " . $line . "\n";
    print "  Code: " . $code . "\n";
    throw new Exception("error");
}
assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_WARNING, 0);
assert_options(ASSERT_QUIET_EVAL, 1);
assert_options(ASSERT_CALLBACK, "assert_callback");

# Authenticate and make sure we get back a valid url/token
$auth = new CF_Authentication($USER,$API_KEY,$ACCOUNT,$HOST);
$auth->authenticate();
assert('$auth->storage_url != NULL');
assert('$auth->auth_token != NULL');

# Create a connection to the backend storage system
#
$conn = new CF_Connection($auth);

# Grab the current list of containers on the account
#
if ($VERBOSE) {echo "==> FETCH LIST OF EXISTING CONTAINERS ==============\n";}
$orig_container_list = $conn->list_containers();
if ($VERBOSE) { print_r($orig_container_list); print "\n";}


if ($VERBOSE) {echo "==> CREATE TEST DATA ===============================\n";}
foreach ($test_data as $cont_name => $obj_arr) {
    if ($VERBOSE) {print "==> Container: " . $cont_name . "\n";}
    $test_cont = $conn->create_container($cont_name);
    assert('get_class($test_cont) == "CF_Container"');
    foreach ($obj_arr as $obj_name => $metadata) {
        if ($VERBOSE) {print "  +--> Object: " . $obj_name . "\n";}
        $obj = $test_cont->create_object($obj_name);
        assert('get_class($obj) == "CF_Object"');
        assert('$obj->getETag() == NULL');
        $obj->metadata = $metadata;
        $obj->write($OBJECT_DATA);
        assert('$obj->getETag() == $data_md5');
    }
    unset($obj_name);
    unset($metadata);
}
unset($cont_name);
unset($obj_arr);

if ($VERBOSE) {echo "==> FETCH TEST DATA AND COMPARE ====================\n";}
foreach ($test_data as $cont_name => $obj_arr) {
    if (!in_array($cont_name,$orig_container_list)) {
        if ($VERBOSE) {print "==> Checking container: ".$cont_name."\n";}
        $container = $conn->get_container($cont_name);
        try {
            assert('$container->name == $cont_name');
        } catch (Exception $e) {
            print "** Container: " . $container->name . "\n";
            print "** Container: " . $cont_name . "\n";
            exit();
        }
        $obj_list = $container->list_objects();
        $test_list = array_keys($test_data[$cont_name]);
        sort($obj_list); sort($test_list);
        try {
            assert('$obj_list == $test_list');
        } catch (Exception $e) {
            print " ** container: " . $cont_name . "\n";
            print " ** obj_list:\n"; print_r($obj_list);
            print " ** test_list:\n"; print_r($test_list);
            exit();
        }
        foreach ($obj_list as $idx => $obj_name) {
            if ($VERBOSE) {print "  +--> Checking object: ".$obj_name."\n";}
            $obj = $container->get_object($obj_name);
            $md = $obj->metadata;
            $test_md = $test_data[$cont_name][$obj_name];
            assert('count($md) == count($test_md)');
            asort($md, SORT_STRING); asort($test_md, SORT_STRING);
            try {
                assert('$md == $test_md');
            } catch (Exception $e) {
                print " ** container: " . $cont_name . "\n";
                print " ** object: " . $obj_name . "\n";
                print " ** md:\n"; print_r($md);
                print " ** test_md:\n"; print_r($test_md);
                exit();
            }
        }
        unset($obj_name);
        unset($idx);
    }
}
unset($cont_name);
unset($obj_arr);

if ($VERBOSE) {echo "==> PURGE ALL TEST DATA ============================\n";}
$cont_list = $conn->list_containers();
foreach ($cont_list as $idx => $cont_name) {
    if (!in_array($cont_name,$orig_container_list)) {
        $container = $conn->get_container($cont_name);
        $obj_list = $container->list_objects();
        foreach ($obj_list as $idx2 => $obj_name) {
            try {
                if ($VERBOSE) {print "  +--> Delete object: ".$obj_name."\n";}
                $container->delete_object($obj_name);
            } catch (Exception $e) {
                print "** Error deleting object: '".$obj_name."': ".$e."\n";
                exit();
            }
        }
        try {
            if ($VERBOSE) {print "==> Delete container: ".$cont_name."\n";}
            $conn->delete_container($container);
        } catch (Exception $e) {
            print "** Error deleting container: '".$container->name."': ".$e."\n";
            exit();
        }
        unset($obj_name);
        unset($idx2);
    }
}
unset($idx);
unset($cont_name);


if ($VERBOSE) {echo "==> COMPARE CURRENT AND ORIGINAL CONTAINER LISTS ===\n";}
$cont_list = $conn->list_containers();
assert('$cont_list == $orig_container_list');

if ($VERBOSE) {echo "==> Done... All test data verified/removed successfully.\n";}


/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
