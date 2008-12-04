<?php

if (!$USER || !$API_KEY) {
    # Running the tests from PHP command-line
    require("cloudfiles_ini.php");  # account settings
    $HTML_OUT = False;
} else {
    # Running tests from index.php
    $HTML_OUT = True;
}

require("cloudfiles.php");
require("test_utils.php");

# Variables for random tests
$NUM_CONTAINERS = 4;
$NUM_OBJECTS = 8;
$NUM_METADATA = 16;
$CONT_NAME_LENGTH = 32;
$OBJ_NAME_LENGTH = 64;
$META_NAME_LENGTH = 8;
$META_VALUE_LENGTH = 32;
$OBJECT_DATA = "This is some sample text as object data.";


$TEMP_NAM = tempnam("/tmp", "php-cloudfiles");

function assert_callback($file, $line, $code)
{
    global $TEMP_NAM;
    unlink($TEMP_NAM);
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

if ($HTML_OUT) {
    print "<pre>\n";
}

echo "======= AUTHENTICATING ======================================\n";
$auth = new CF_Authentication($USER,$API_KEY,$ACCOUNT,$HOST);
//$auth->setDebug(1);  # toggle to enable cURL verbose output
$auth->authenticate();
assert('$auth->storage_url != NULL');
assert('$auth->cdnm_url != NULL');
assert('$auth->auth_token != NULL');
$conn = new CF_Connection($auth);
//$conn->setDebug(1);  # toggle to enable cURL verbose output


echo "======= ORIGINAL ACCOUNT INFO ===============================\n";
$orig_info = $conn->get_info();
$orig_container_list = $conn->list_containers();
assert('is_array($orig_info)');
print_r($orig_info);


echo "======= STARTING FUNCTIONAL TESTS ===========================\n";

echo "======= LIST CONTAINERS =====================================\n";
$orig_containers = $conn->list_containers();
assert('is_array($orig_containers)');
print_r($orig_containers);


echo "======= CREATE NEW LONG CONTAINER ===========================\n";
$long_name = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
$long_name .= "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
$long_name .= "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
try {
    $container = $conn->create_container($long_name);
} catch (SyntaxException $e) {
    print "SUCCESS: disallow long container names\n";
}


echo "======= CREATE EMPTY CONTAINER ==============================\n";
try {
    $container = $conn->create_container();
} catch (SyntaxException $e) {
    print "SUCCESS: do not allow empty in container name\n";
}


echo "======= CREATE CONTAINER ====================================\n";
$container = $conn->create_container("php-cloudfiles");
assert('$container');
print $container."\n";


echo "======= CREATE CONTAINER (with ' ' in name) =================\n";
$space_container = $conn->create_container("php cloudfiles");
assert('$space_container');
print $space_container."\n";


echo "======= DELETE CONTAINER (with ' ' in name) =================\n";
$result = $conn->delete_container($space_container);
assert('$result');
print "SUCCESS: space container deleted\n";


echo "======= CREATE NEW LONG OBJECT ==============================\n";
$long_name = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
$long_name .= "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
$long_name .= "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
try {
    $o0 = $container->create_object($long_name);
} catch (SyntaxException $e) {
    print "SUCCESS: disallow long object names\n";
}


echo "======= FETCH NON-EXISTENT CONTAINER ========================\n";
try {
    $no_container = $conn->get_container("7HER3_1S_N0_5PO0N");
} catch (NoSuchContainerException $e) {
    print "SUCCESS: can't delete a non-existent container\n";
}


echo "======= DELETE NON-EXISTENT CONTAINER =======================\n";
try {
    $result = $conn->delete_container("7HER3_1S_N0_5PO0N");
} catch (NoSuchContainerException $e) {
    print "SUCCESS: can't delete a non-existent container\n";
}


echo "======= DELETE NON-SPECIFIED CONTAINER ======================\n";
try {
    $result = $conn->delete_container();
} catch (SyntaxException $e) {
    print "SUCCESS: must specify container\n";
}


echo "======= DELETE NON-EXISTENT OBJECT ==========================\n";
try {
    $result = $container->delete_object("7HER3_1S_N0_5PO0N");
} catch (NoSuchObjectException $e) {
    print "SUCCESS: can't delete a non-existent object\n";
}


echo "======= CREATE CONTAINER (WITH '/' IN NAME) =================\n";
try {
    $bad_cont = $conn->create_container("php/cloudfiles");
} catch (SyntaxException $e) {
    print "SUCCESS: do not allow '/' in container name\n";
}

echo "======= CREATE EMPTY OBJECT =================================\n";
$o0 = $container->create_object("empty_object");
try {
    $result = $o0->write();
} catch (SyntaxException $e) {
    print "SUCCESS: do upload empty objects\n";
}


echo "======= CREATE OBJECT (WITH '/' IN NAME) ====================\n";
$o0 = $container->create_object("slash/name");
assert('$o0');
print $o0 . "\n";


echo "======= UPLOAD STRING CONTENT FOR OBJECT(0) =================\n";
$text = "Some sample text.";
$md5 = md5($text);
$o0->content_type = "text/plain";
$result = $o0->write($text);
assert('$result');
assert('$o0->getETag() == $md5');
print $o0."\n";


echo "======= CREATE OBJECT (WITH ' ' IN NAME) ====================\n";
$ospace = $container->create_object("space name");
assert('$ospace');
print $ospace . "\n";


echo "======= UPLOAD STRING CONTENT FOR OBJECT(SPACE) =============\n";
$text = "Some sample text.";
$md5 = md5($text);
$ospace->content_type = "text/plain";
$result = $ospace->write($text);
assert('$result');
assert('$ospace->getETag() == $md5');
print $ospace."\n";


echo "======= RANGE TEST ==========================================\n";
$orange = $container->get_object("space name");
$partial = $orange->read(array("Range"=>"bytes=0-10"));
assert('strlen($partial) == 11');
assert('$partial == "Some sample"');
print "Range[0-10]: ".$partial."\n";


echo "======= CREATE OBJECT =======================================\n";
$o1 = $container->create_object("fuzzy.txt");
assert('$o1');
print $o1 . "\n";


echo "======= UPLOAD STRING CONTENT FOR OBJECT(1) =================\n";
$text = "This is some sample text.";
$md5 = md5($text);
$o1->content_type = "text/plain";
$result = $o1->write($text);
assert('$result');
assert('$o1->getETag() == $md5');
print $o1."\n";


echo "======= UPLOAD STRING CONTENT FOR OBJECT(2) =================\n";
$o1->content_type = "text/plain";
$result = $o1->write("Even more sample text.");
assert('$result');
print $o1."\n";


echo "======= RE-UPLOAD STRING CONTENT FOR OBJECT WITH METADATA ===\n";
$text = "This is some different sample text.";
$md5 = md5($text);
$o1->content_type = "text/plain";
$o1->metadata = array(
    "Foo" => "This is foo",
    "Bar" => "This is bar");
$result = $o1->write($text);
assert('$result');
assert('$o1->getETag() == $md5');
print $o1."\n";


echo "======= IF-MATCH (MATCHED MD5) ==============================\n";
$ifmatch = $container->get_object($o1->name);
$ifdata = $ifmatch->read(array("If-Match" => $md5));
assert('$ifdata == $text');
print "If-Match passes (matched)\n";


echo "======= IF-MATCH (UNMATCHED MD5) ============================\n";
$ifmatch = $container->get_object($o1->name);
$ifdata = $ifmatch->read(array("If-Match" => "foo"));
assert('$ifdata != $text'); # an HTML response entity is returned. :-(
print "If-Match passes (unmatched)\n";


echo "======= IF-NONE-MATCH (UNMATCHED MD5) =======================\n";
$ifmatch = $container->get_object($o1->name);
$ifdata = $ifmatch->read(array("If-None-Match" => "if-none-match"));
assert('$ifdata == $text');
print "If-None-Match passes (unmatched)\n";


echo "======= IF-NONE-MATCH (MATCHED MD5) =========================\n";
$ifmatch = $container->get_object($o1->name);
$ifdata = $ifmatch->read(array("If-None-Match" => $md5));
assert('!$ifdata');
print "If-None-Match passes (matched)\n";


echo "======= IF-MODIFIED-SINCE (PAST TIMESTAMP) ==================\n";
$ifmatch = $container->get_object($o1->name);
$ifdata = $ifmatch->read(array("If-Modified-Since" => httpDate(time()-86400)));
assert('$ifdata == $text');
print "If-Modified-Since passes (old timestamp)\n";


echo "======= IF-MODIFIED-SINCE (FUTURE TIMESTAMP) ================\n";
$ifmatch = $container->get_object($o1->name);
$ifdata = $ifmatch->read(array("If-Modified-Since" => httpDate(time()+86400)));
assert('$ifdata != $text');
print "If-Modified-Since passes (future timestamp)\n";


echo "======= IF-UNMODIFIED-SINCE (PAST TIMESTAMP) ================\n";
$ifmatch = $container->get_object($o1->name);
$ifdata = $ifmatch->read(array("If-Unmodified-Since" => httpDate(time()-86400)));
assert('$ifdata != $text');
print "If-Unmodified-Since passes (old timestamp)\n";


echo "======= IF-UNMODIFIED-SINCE (FUTURE TIMESTAMP) ==============\n";
$ifmatch = $container->get_object($o1->name);
$ifdata = $ifmatch->read(array("If-Unmodified-Since" => httpDate(time()+86400)));
assert('$ifdata == $text');
print "If-Unmodified-Since passes (future timestamp)\n";


echo "======= UPLOAD OBJECT FROM FILE =============================\n";
$fname = basename(__FILE__);
$md5 = md5_file($fname);
$o2 = $container->create_object($fname);
$result = $o2->load_from_filename($fname);
assert('$result');
assert('$o2->getETag() == $md5');
print $o2."\n";


echo "======= GET CONTAINER =======================================\n";
$cont2 = $conn->get_container("php-cloudfiles");
assert('$cont2');
print $cont2 . "\n";


echo "======= GET OBJECT ==========================================\n";
$o3 = $container->get_object("fuzzy.txt");
assert('$o3->getETag() == $o1->getETag()');
print $o3."\n";
print "  etag: " . $o3->getETag() . "\n";
print "  content-type: " . $o3->content_type . "\n";
print "  content-length: " . $o3->content_length . "\n";
print_r($o3->metadata);


echo "======= UPDATE OBJECT METADATA ==============================\n";
$o3->metadata = array(
    "NewFoo" => "This is new foo",
    "NewBar" => "This is new bar");
$result = $o3->sync_metadata();
assert('$result');
print $o3."\n";


echo "======= VERIFY UPDATED METADATA =============================\n";
$o4 = $container->get_object("fuzzy.txt");
assert('$o4->getETag() == $o3->getETag()');
print "SUCCESS\n";
print_r($o3->metadata);
print_r($o4->metadata);


echo "======= CREATE OBJECT =======================================\n";
$o5 = $container->create_object("fubar.txt");
assert('$o5');
print $o5."\n";


echo "======= UPLOAD STRING CONTENT FOR OBJECT(1) =================\n";
$text = "This is more sample text for a different object.";
$md5 = md5($text);
$o5->content_type = "text/plain";
$result = $o5->write($text);
assert('$result');
assert('$o5->getETag() == $md5');
print $o5."\n";


echo "======= DOWNLOAD OBJECT TO FILENAME =========================\n";
$result = $o4->save_to_filename($TEMP_NAM);
assert('$result');
print "WROTE DATA TO ".$TEMP_NAM.", cat ".$TEMP_NAM."\n";
passthru("cat ".$TEMP_NAM);
print "\n";


echo "======= DOWNLOAD OBJECT TO STRING ===========================\n";
$data = $o4->read();
assert('strlen($data) == 35');
print $data . "\n";


echo "======= LIST OBJECTS (ALL) ==================================\n";
$obj_list = $container->list_objects();
assert('is_array($obj_list) && !empty($obj_list)');
print_r($obj_list);


echo "======= CHECK ACCOUNT INFO ==================================\n";
list($num_containers, $total_bytes) = $conn->get_info();
assert('$num_containers >= 1');
assert('$total_bytes >= 7478');
print "num_containers: " . $num_containers . "\n";
print "   total bytes: " . $total_bytes . "\n";


echo "======= FIND OBJECTS (LIMIT) ================================\n";
$obj_list = $container->list_objects(1);
assert('is_array($obj_list) && !empty($obj_list)');
print_r($obj_list);


echo "======= FIND OBJECTS (LIMIT,OFFSET) =========================\n";
$obj_list = $container->list_objects(1,1);
assert('is_array($obj_list) && !empty($obj_list)');
print_r($obj_list);


echo "======= FIND OBJECTS (PREFIX='fu') ==========================\n";
$obj_list = $container->list_objects(0,-1,"fu");
assert('is_array($obj_list) && !empty($obj_list)');
print_r($obj_list);


echo "======= DELETE CONTAINER (FAIL) =============================\n";
try {
    $conn->delete_container($container);
} catch (NonEmptyContainerException $e) {
    print "SUCCESS: " . $e->getMessage() . "\n";
}


echo "======= DELETE OBJECTS ======================================\n";
$obj_list = $container->list_objects();
print_r($obj_list);
foreach ($obj_list as $obj) {
    $result = $container->delete_object($obj);
    assert('$result');
}

echo "======= LIST OBJECTS ========================================\n";
$obj_list = $container->list_objects();
assert('empty($obj_list)');
print "SUCCESS: empty container\n";


echo "======= DELETE CONTAINER (PASS) =============================\n";
$result = $conn->delete_container($container);
assert('$result');
print "SUCCESS: container deleted\n";


echo "======= ACCOUNT INFO AFTER FUNCTIONAL TESTS =================\n";
$info = $conn->get_info();
assert('$info == $orig_info');


echo "\n\n\n\n";


echo "======= CHECK ACCOUNT INFO BEFORE CDN TESTS =================\n";
$cnames = array();
$cdn_info = $conn->get_info();
print_r($cdn_info);

echo "======= CREATE NEW TEST CONTAINER (ASCII) ===================\n";
$n1 = "cdn-ascii-test";
$ascii_cont = $conn->create_container($n1);
$cnames[$n1] = $ascii_cont;
assert('$ascii_cont');
print $ascii_cont . "\n";


echo "======= CREATE NEW GOOP CONTAINER (ASCII) ===================\n";
$n2 = "#$%^&*()-_=+{}[]\|;:'><,'";
$goop_cont = $conn->create_container($n2);
$cnames[$n2] = $goop_cont;
assert('$goop_cont');
print $goop_cont . "\n";


echo "======= CREATE NEW TEST CONTAINER (UTF-8) ===================\n";
$n3 = "©Ï&mMMÂaxÔ¾¶Áºá±â÷³¡YDéBSQÜO´ãánÉ¤°Bxn¹tðÁVètØBñü+3Pe-¹ùðVÚ_";
$utf8_cont = $conn->create_container($n3);
$cnames[$n3] = $utf8_cont;
assert('$utf8_cont');
print $utf8_cont . "\n";


# Test CDN-enabling each container for an hour
#
echo "======= CDN-ENABLE CONTAINERS ===============================\n";
foreach ($cnames as $name => $cont) {
    $uri = $cont->make_public(3600);
    assert('$cont->is_public()');
    print $uri . "\n";
    print $cont . "\n";
}

echo "======= TEST CONTAINER ATTRIBUTES ===========================\n";
foreach ($cnames as $name => $cont) {
    $tcont = $conn->get_container($name);    
    print $tcont . "\n";
    print $cont . "\n";
    assert('$tcont->is_public()');
    assert('$tcont->name == $name');
    assert('$tcont->cdn_uri == $cont->cdn_uri');
    assert('$tcont->cdn_ttl == $cont->cdn_ttl');
}

echo "======= ADJUST TTL ==========================================\n";
foreach ($cnames as $name => $cont) {
    $uri = $cont->make_public(7200);
    assert('$cont->is_public()');
    print $uri . "\n";
    print $cont . "\n";
}

echo "======= TEST CONTAINER ATTRIBUTES ===========================\n";
foreach ($cnames as $name => $cont) {
    $tcont = $conn->get_container($name);    
    print $tcont . "\n";
    print $cont . "\n";
    assert('$tcont->is_public()');
    assert('$tcont->name == $name');
    assert('$tcont->cdn_uri == $cont->cdn_uri');
    assert('$tcont->cdn_ttl == $cont->cdn_ttl');
}

echo "======= UPLOAD STORAGE OBJECT AND FETCH FROM CDN ============\n";
$contents = "This is a sample text file.";
$o = $ascii_cont->create_object("foo.txt");
$o->write($contents);
sleep(2);
print $o->public_uri() . "\n";
$fp = fopen($o->public_uri(), "r");
$cdn_contents = fread($fp, 1024);
fclose($fp);
assert('$contents == substr($cdn_contents, -strlen($contents))');


echo "======= DISABLE CDN =========================================\n";
foreach ($cnames as $name => $cont) {
    $uri = $cont->make_private();
    assert('$cont->is_public() == False');
    print $cont . "\n";
    $tcont = $conn->get_container($name);
    assert('$tcont->is_public() == False');
}

echo "======= CLEAN-UP AND DELETE =================================\n";
$ascii_cont->delete_object("foo.txt");
foreach ($cnames as $name => $cont) {
    $conn->delete_container($cont);
}

echo "======= CHECK ACCOUNT INFO AFTER CDN TESTS ==================\n";
$info = $conn->get_info();
assert('$info == $cdn_info');

echo "\n\n\n\n";

echo "======= STARTING RANDOM CHARACTER TESTS =====================\n";
$data_md5 = md5($OBJECT_DATA);

echo "======= CHECK ACCOUNT INFO BEFORE RANDOM TESTS ==============\n";
$random_info = $conn->get_info();

# Initialize and record test data
#
$test_data = array();
for ($i=0; $i < $NUM_CONTAINERS; $i++) {
    $container_name = genUTF8($CONT_NAME_LENGTH, array(47,63)); # skip '/','?'

    $obj_names = array();
    for ($j=0; $j < $NUM_OBJECTS; $j++) {
        $obj_name = genUTF8($OBJ_NAME_LENGTH, array(63)); # skip '?'

        $meta = array();
        for ($l=0; $l < $NUM_METADATA; $l++) {
            $meta_name = genUTF8($META_NAME_LENGTH, array(58)); # skip ':'
            $meta_val = genUTF8($META_VALUE_LENGTH, array(58)); # skip ':'
            $meta_name = trim(strtolower($meta_name));
            $meta[$meta_name] = trim($meta_val);
        }
        $obj_names[$obj_name] = $meta;
    }
    $test_data[$container_name] = $obj_names;
}


echo "==> CREATE TEST DATA ===============================\n";
foreach ($test_data as $cont_name => $obj_arr) {
    print "==> Container: " . $cont_name . "\n";
    $test_cont = $conn->create_container($cont_name);
    assert('get_class($test_cont) == "CF_Container"');
    foreach ($obj_arr as $obj_name => $metadata) {
        print "  +--> Object: " . $obj_name . "\n";
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

echo "==> FETCH TEST DATA AND COMPARE ====================\n";
foreach ($test_data as $cont_name => $obj_arr) {
    if (!in_array($cont_name,$orig_container_list)) {
        print "==> Check container: ".$cont_name."\n";
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
            print "  +--> Check object: ".$obj_name."\n";
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

echo "==> PURGE ALL TEST DATA ============================\n";
$cont_list = $conn->list_containers();
foreach ($cont_list as $idx => $cont_name) {
    if (!in_array($cont_name,$orig_container_list)) {
        $container = $conn->get_container($cont_name);
        $obj_list = $container->list_objects();
        foreach ($obj_list as $idx2 => $obj_name) {
            try {
                print "  +--> Delete obj: ".$obj_name."\n";
                $container->delete_object($obj_name);
            } catch (Exception $e) {
                print "** Error deleting object: '".$obj_name."': ".$e."\n";
                exit();
            }
        }
        try {
            print "==> Delete container: ".$cont_name."\n";
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

echo "======= CHECK ACCOUNT INFO AFTER RANDOM TESTS ===============\n";
$info = $conn->get_info();
assert('$info == $random_info');


echo "======= CHECK ACCOUNT INFO AFTER ALL TESTING ================\n";
print_r($orig_info);
print_r($info);
assert('$info == $orig_info');
print "=> Done...\n";

if ($HTML_OUT) {
    print "</pre>\n";
}
unlink($TEMP_NAM);

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * c-hanging-comment-ender-p: nil
 * End:
 */
?>
