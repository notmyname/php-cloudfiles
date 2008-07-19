<?php

require("capon.php");
$ACCOUNT = "PicsWall";
$USER    = "pics";
$PASS    = "picswall";
$HOST    = "http://auth.stg.racklabs.com";

$auth = new CLOUDFS_Authentication($ACCOUNT,$USER,$PASS,$HOST);
$auth->authenticate();
$conn = new CLOUDFS_Connection($auth);

echo "======= LIST CONTAINERS =====================================\n";
$containers = $conn->list_containers();
print_r($containers);

echo "======= CREATE CONTAINER ====================================\n";
$container = $conn->create_container("php-capon");
if ($container) {
    print $container . "\n";
} else {
    print "ERROR: " . $conn->get_error() . "\n";
}

echo "======= CREATE CONTAINER (WITH '/' IN NAME) =================\n";
try {
    $bad_cont = $conn->create_container("php/capon");
} catch (Exception $e) {
    print "SUCCESS: do not allow '/' in container name\n";
}

echo "======= CREATE OBJECT (WITH '/' IN NAME) ====================\n";
$o0 = $container->create_object("slash/name");
if ($o0) {
    print $o0 . "\n";
} else {
    print "ERROR: " . $container->get_error() . "\n";
}

echo "======= UPLOAD STRING CONTENT FOR OBJECT(0) =================\n";
$text = "Some sample text.";
$md5 = md5($text);
$o0->content_type = "text/plain";
$result = $o0->write($text);
if ($result) {
    $o0->etag == $md5 ? print "SUCCESS\n" : print "FAIL: MD5sums do not match\n";
} else {
    print "ERROR: " . $o0->get_error() . "\n";
}

echo "======= CREATE OBJECT =======================================\n";
$o1 = $container->create_object("fuzzy.txt");
if ($o1) {
    print $o1 . "\n";
} else {
    print "ERROR: " . $container->get_error() . "\n";
}

echo "======= UPLOAD STRING CONTENT FOR OBJECT(1) =================\n";
$text = "This is some sample text.";
$md5 = md5($text);
$o1->content_type = "text/plain";
$result = $o1->write($text);
if ($result) {
    $o1->etag == $md5 ? print "SUCCESS\n" : print "FAIL: MD5sums do not match\n";
} else {
    print "ERROR: " . $o1->get_error() . "\n";
}

echo "======= RE-UPLOAD STRING CONTENT FOR OBJECT WITH METADATA ===\n";
$text = "This is some different sample text.";
$md5 = md5($text);
$o1->content_type = "text/plain";
$o1->metadata = array(
    "Foo" => "This is foo",
    "Bar" => "This is bar");
$result = $o1->write($text);
if ($result) {
    $o1->etag == $md5 ? print "SUCCESS\n" : print "FAIL: MD5sums do not match\n";
} else {
    print "ERROR: " . $o1->get_error() . "\n";
}

echo "======= UPLOAD OBJECT FROM FILE =============================\n";
$md5 = md5_file("capon.php");
$o2 = $container->create_object("capon.php");
$result = $o2->load_from_filename("capon.php");
if ($result) {
    $o2->etag == $md5 ? print "SUCCESS\n" : print "FAIL: MD5sums do not match\n";
} else {
    print "ERROR: " . $o2->get_error() . "\n";
}

echo "======= GET CONTAINER =======================================\n";
$cont2 = $conn->get_container("php-capon");
if ($cont2) {
    print $cont2 . "\n";
} else {
    print "ERROR: " . $conn->get_error() . "\n";
}

echo "======= GET OBJECT ==========================================\n";
$o3 = $container->get_object("fuzzy.txt");
if ($o3->etag == $o1->etag) {
    print "SUCCESS\n";
    print "  etag: " . $o3->etag . "\n";
    print "  content-type: " . $o3->content_type . "\n";
    print "  content-length: " . $o3->content_length . "\n";
    print_r($o3->metadata);
} else {
    print "ERROR: " . $o3->get_error() . "\n";
}

echo "======= UPDATE OBJECT METADATA ==============================\n";
$o3->metadata = array(
    "NewFoo" => "This is new foo",
    "NewBar" => "This is new bar");
$result = $o3->sync_metadata();
if ($result) {
    print "SUCCESS\n";
} else {
    print "ERROR: " . $o3->get_error() . "\n";
}

echo "======= VERIFY UPDATED METADATA =============================\n";
$o4 = $container->get_object("fuzzy.txt");
if ($o4->etag == $o3->etag) {
    print "SUCCESS\n";
    print_r($o3->metadata);
    print_r($o4->metadata);
} else {
    print "ERROR: " . $o4->get_error() . "\n";
}

echo "======= CREATE OBJECT =======================================\n";
$o5 = $container->create_object("fubar.txt");
if ($o5) {
    print $o5 . "\n";
} else {
    print "ERROR: " . $container->get_error() . "\n";
}

echo "======= UPLOAD STRING CONTENT FOR OBJECT(1) =================\n";
$text = "This is more sample text for a different object.";
$md5 = md5($text);
$o5->content_type = "text/plain";
$result = $o5->write($text);
if ($result) {
    $o5->etag == $md5 ? print "SUCCESS\n" : print "FAIL: MD5sums do not match\n";
} else {
    print "ERROR: " . $o1->get_error() . "\n";
}

echo "======= DOWNLOAD OBJECT TO FILENAME =========================\n";
$result = $o4->save_to_filename("/tmp/fuzzy.txt");
if ($result) {
    print "WROTE DATA TO /tmp/fuzzy.txt, cat /tmp/fuzzy.txt\n";
    passthru("cat /tmp/fuzzy.txt");
    print "\n";
}

echo "======= DOWNLOAD OBJECT TO STRING ===========================\n";
$data = $o4->read();
$data ?  print "SUCCESS: " . $data . "\n" : print "FAIL\n";

echo "======= LIST OBJECTS (ALL) ==================================\n";
$obj_list = $container->list_objects();
print_r($obj_list);

echo "======= FIND OBJECTS (LIMIT) ================================\n";
$obj_list = $container->list_objects(1);
print_r($obj_list);

echo "======= FIND OBJECTS (LIMIT,OFFSET) =========================\n";
$obj_list = $container->list_objects(1,1);
print_r($obj_list);

echo "======= FIND OBJECTS (PREFIX='fu') ==========================\n";
$obj_list = $container->list_objects(0,-1,"fu");
print_r($obj_list);

echo "======= DELETE CONTAINER (FAIL) =============================\n";
try {
    $conn->delete_container($container);
} catch (Exception $e) {
    print "SUCCESS: " . $e->getMessage() . "\n";
}

echo "======= DELETE OBJECTS ======================================\n";
$container->delete_object($o0) ? print "SUCCESS: deleted\n" : print "FAIL\n";
$container->delete_object($o1) ? print "SUCCESS: deleted\n" : print "FAIL\n";
$container->delete_object($o2) ? print "SUCCESS: deleted\n" : print "FAIL\n";
#$container->delete_object($o3); $o3 is the same as $o1
#$container->delete_object($o4); $o3 is the same os $o4
$container->delete_object($o5) ? print "SUCCESS: deleted\n" : print "FAIL\n";

echo "======= LIST OBJECTS ========================================\n";
$obj_list = $container->list_objects();
empty($obj_list) ? print "SUCCESS: empty container\n" : print "FAIL\n";

echo "======= DELETE CONTAINER (PASS) =============================\n";
$conn->delete_container($container) ? print "SUCCESS: deleted\n" : print "FAIL\n";

echo "======= LIST CONTAINERS =====================================\n";
$containers = $conn->list_containers();
print_r($containers);

?>
